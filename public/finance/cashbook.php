<?php

/**
 * ============================================================
 *  Cash Book
 * ------------------------------------------------------------
 *  The day-by-day transaction ledger the business has kept by
 *  hand: date, who, in or out, amount, running balance, what
 *  for. Rows are grouped under their date exactly as on the
 *  sheet, and the balance column is computed, never typed.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('cash_accounts', 'cash_transactions');

/* ── AJAX: record a movement ────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $txnId    = input_int($_POST, 'cash_txn_id');
    $date     = input($_POST, 'txn_date') ?: date('Y-m-d');
    $acctId   = input_int($_POST, 'cash_account_id');
    $category = input($_POST, 'category');
    $amount   = (float) ($_POST['amount'] ?? 0);
    $party    = trim(input($_POST, 'party'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid date.']);
    }
    if (!isset(CASH_CATEGORIES[$category])) {
        json_response(['success' => false, 'message' => 'Choose what kind of movement this is.']);
    }
    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'The amount must be more than zero.']);
    }
    $account = $acctId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $acctId])
        : null;
    if (!$account) {
        json_response(['success' => false, 'message' => 'Choose the account this affects.']);
    }
    if (!$account['is_active'] && $txnId === null) {
        json_response(['success' => false, 'message' => $account['name'] . ' is no longer active.']);
    }

    // Direction follows the category, except where the category
    // genuinely allows both — the form must then say which.
    $fixed = CASH_CATEGORIES[$category]['dir'];
    $dir   = $fixed === 'both' ? input($_POST, 'direction') : $fixed;
    if (!in_array($dir, ['in', 'out'], true)) {
        json_response(['success' => false, 'message' => 'Say whether the money came in or went out.']);
    }

    // Some categories are not a movement of cash on their own —
    // they are a movement against a document, and the document has
    // to be named for the entry to mean anything. A receipt with no
    // invoice behind it leaves the customer still owing the money
    // on their statement, which is precisely the mistake this
    // picker exists to prevent.
    $docMeta = CASH_DOCUMENT_CATEGORIES[$category] ?? null;
    $docId   = $docMeta ? input_int($_POST, $docMeta['field']) : null;

    if ($docMeta && $docMeta['required'] && $docId === null) {
        json_response(['success' => false, 'message' => $category === 'invoice_payment'
            ? 'Choose the invoice this payment is for. Money received with no invoice behind '
              . 'it is a customer advance, not an invoice payment.'
            : 'Choose the document this entry is for.']);
    }

    // How it was paid. The account already implies most of the
    // answer, so an unanswered or nonsense value falls back to it
    // rather than stopping the entry.
    $payMethod = input($_POST, 'pay_method');
    if (!isset(PAYMENT_METHODS[$payMethod]) || $payMethod === 'salary_deduction') {
        $payMethod = cash_method_for_account((string) $account['account_type']);
    }

    // A transfer is two halves of one movement: out of one
    // account and into another. Recording only one half is what
    // makes a cash book stop balancing, so both are written here
    // or neither is.
    $destId = input_int($_POST, 'destination_account_id');
    if ($category === 'transfer' && $txnId === null) {
        if ($destId === null || $destId === $acctId) {
            json_response(['success' => false, 'message' => 'Choose a different account to transfer to.']);
        }
        $dest = db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $destId]);
        if (!$dest) {
            json_response(['success' => false, 'message' => 'That destination account no longer exists.']);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // ── Recorded against a document ──────────────────────────
        // The entry goes through that document's ledger, not
        // straight into the book: the invoice's balance falls, the
        // customer's statement follows, and the cash-book row this
        // writes is stamped with where it came from — which is why
        // it can afterwards only be reversed on the document, never
        // quietly edited here.
        if ($docId !== null) {
            // Editing a loose entry into a proper payment: the
            // money never moved, only the record of it. The plain
            // row is dropped and written again through the ledger.
            if ($txnId !== null) {
                $existing = db_one("SELECT * FROM cash_transactions WHERE cash_txn_id = :id", [':id' => $txnId]);
                if (!$existing) {
                    throw new RuntimeException('That entry no longer exists.');
                }
                if ($existing['source_type'] !== null) {
                    throw new RuntimeException(
                        'This entry already belongs to ' . cash_source_label((string) $existing['source_type'])
                        . ' and is edited there, not here.'
                    );
                }
                db_run("DELETE FROM cash_transactions WHERE cash_txn_id = :id", [':id' => $txnId]);
            }

            $shared = [
                'date'        => $date,
                'amount'      => $amount,
                'account_id'  => $acctId,
                'method'      => $payMethod,
                'reference'   => input($_POST, 'reference'),
                'party'       => $party,
                'description' => input($_POST, 'description'),
                'notes'       => 'Recorded from the cash book.',
            ];

            if ($category === 'invoice_payment') {
                $result  = cash_record_invoice_receipt($shared + ['invoice_id' => $docId]);
                $number  = $result['invoice']['invoice_number'];
                $message = sprintf(
                    '%s received against %s. %s',
                    money($amount),
                    $number,
                    $result['outstanding'] > 0.005
                        ? money($result['outstanding']) . ' still outstanding.'
                        : 'The invoice is now settled in full.'
                );
                $auditKey = ['invoice_payment.record', 'invoice_payments', $result['payment_id']];
            } else {
                $result  = cash_record_supplier_payment($shared + ['po_id' => $docId]);
                $number  = $result['order']['po_number'];
                $message = sprintf(
                    '%s paid against %s. %s',
                    money($amount),
                    $number,
                    $result['outstanding'] > 0.005
                        ? money($result['outstanding']) . ' still to pay.'
                        : 'The order is now paid in full.'
                );
                $auditKey = ['purchase_payment.record', 'purchase_payments', $result['payment_id']];
            }

            $pdo->commit();
            audit_log($auditKey[0], $auditKey[1], $auditKey[2], [
                'document' => $number, 'amount' => $amount, 'from' => 'cash book',
            ]);
            json_response(['success' => true, 'message' => $message]);
        }

        if ($txnId !== null) {
            $existing = db_one("SELECT * FROM cash_transactions WHERE cash_txn_id = :id", [':id' => $txnId]);
            if (!$existing) {
                throw new RuntimeException('That entry no longer exists.');
            }
            if ($existing['source_type'] !== null) {
                throw new RuntimeException(
                    'This entry came from ' . cash_source_label((string) $existing['source_type'])
                    . ' and is edited on that document, not here.'
                );
            }
            $pdo->prepare(
                "UPDATE cash_transactions
                    SET txn_date = :d, cash_account_id = :acct, direction = :dir,
                        amount = :amt, category = :cat, party = :party,
                        description = :descr, updated_at = NOW()
                  WHERE cash_txn_id = :id"
            )->execute([
                ':d' => $date, ':acct' => $acctId, ':dir' => $dir, ':amt' => $amount,
                ':cat' => $category, ':party' => $party ?: null,
                ':descr' => input($_POST, 'description') ?: null, ':id' => $txnId,
            ]);
            $message = 'Entry updated.';
        } else {
            $group = null;
            if ($category === 'transfer') {
                $group = (int) db_value("SELECT nextval('cash_transfer_group_seq')");
            }

            $ins = $pdo->prepare(
                "INSERT INTO cash_transactions
                    (txn_date, cash_account_id, direction, amount, category,
                     party, description, transfer_group, created_by)
                 VALUES (:d, :acct, :dir, :amt, :cat, :party, :descr, :grp, :by)"
            );
            $ins->execute([
                ':d' => $date, ':acct' => $acctId, ':dir' => $dir, ':amt' => $amount,
                ':cat' => $category, ':party' => $party ?: null,
                ':descr' => input($_POST, 'description') ?: null,
                ':grp' => $group, ':by' => current_user()['id'],
            ]);

            if ($category === 'transfer') {
                // The opposite leg, on the other account.
                $ins->execute([
                    ':d' => $date, ':acct' => $destId,
                    ':dir' => $dir === 'out' ? 'in' : 'out',
                    ':amt' => $amount, ':cat' => 'transfer',
                    ':party' => $party ?: null,
                    ':descr' => input($_POST, 'description') ?: null,
                    ':grp' => $group, ':by' => current_user()['id'],
                ]);
            }
            $message = $category === 'transfer' ? 'Transfer recorded on both accounts.' : 'Entry recorded.';
        }

        $pdo->commit();
        audit_log($txnId !== null ? 'cash_txn.update' : 'cash_txn.create', 'cash_transactions', $txnId ?? 0, [
            'date' => $date, 'amount' => $amount, 'category' => $category,
        ]);
        json_response(['success' => true, 'message' => $message]);
    } catch (PDOException $ex) {
        // Must be caught before RuntimeException, which PDOException
        // extends — otherwise a raw SQL error reaches the user.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CASH] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not save this entry.')], 500);
    } catch (RuntimeException $ex) {
        // Raised by this handler, with a message written for the user.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CASH] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not save this entry.'], 500);
    }
}

/* ── POST: delete an entry (admins only) ────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    require_role(ROLE_ADMIN);
    $id  = input_int($_POST, 'id');
    $row = $id !== null ? db_one("SELECT * FROM cash_transactions WHERE cash_txn_id = :id", [':id' => $id]) : null;

    if (!$row) {
        flash('error', 'That entry no longer exists.');
    } elseif ($row['source_type'] !== null) {
        flash('error', 'This entry belongs to ' . cash_source_label((string) $row['source_type'])
            . '. Reverse it there and this row goes with it.');
    } else {
        // Deleting one leg of a transfer would leave the book
        // unbalanced, so both legs go together.
        if ($row['transfer_group'] !== null) {
            db_run("DELETE FROM cash_transactions WHERE transfer_group = :g", [':g' => $row['transfer_group']]);
            flash('success', 'Transfer removed from both accounts.');
        } else {
            db_run("DELETE FROM cash_transactions WHERE cash_txn_id = :id", [':id' => $id]);
            flash('success', 'Entry deleted.');
        }
        audit_log('cash_txn.delete', 'cash_transactions', $id, [
            'date' => $row['txn_date'], 'amount' => $row['amount'], 'category' => $row['category'],
        ]);
    }
    redirect('finance/cashbook.php');
}

/* ── Filters ────────────────────────────────────────────────── */
$accounts = cash_accounts(false);
$search   = input($_GET, 'q');
$acctFlt  = input_int($_GET, 'account');
$catFlt   = input($_GET, 'category');
$dirFlt   = input($_GET, 'direction');
$dateFrom = input($_GET, 'from');
$dateTo   = input($_GET, 'to');

// Default to the current month, which is the window the sheet was
// kept in. An explicit blank date clears it.
if ($dateFrom === '' && !isset($_GET['from'])) {
    $dateFrom = date('Y-m-01');
}

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(t.party) LIKE :q OR LOWER(t.description) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($acctFlt !== null) {
    $where[] = "t.cash_account_id = :acct";
    $params[':acct'] = $acctFlt;
}
if (isset(CASH_CATEGORIES[$catFlt])) {
    $where[] = "t.category = :cat";
    $params[':cat'] = $catFlt;
}
if (in_array($dirFlt, ['in', 'out'], true)) {
    $where[] = "t.direction = :dir";
    $params[':dir'] = $dirFlt;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = "t.txn_date >= :from";
    $params[':from'] = $dateFrom;
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = "t.txn_date <= :to";
    $params[':to'] = $dateTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// The running balance only makes sense read in sequence. Oldest
// first reads downwards like the spreadsheet; newest first puts
// today's entries at the top, which is what you want when adding
// them. Both are useful, so both are offered.
$sort    = input($_GET, 'sort') === 'oldest' ? 'oldest' : 'newest';
$sortSql = $sort === 'oldest' ? 'ASC' : 'DESC';
$perPage = per_page();

/* ── Export ─────────────────────────────────────────────────── */
// The running balance belongs to the whole book, so an export of a
// filtered slice would carry a balance column that means nothing.
// The export therefore leaves it out and gives the movements only.
if (wants_export()) {
    export_deliver(export_filename('cash-book'), [[
        'name'  => 'Cash book',
        'title' => 'Cash book',
        'meta'  => export_meta([
            'Period'   => ($dateFrom !== '' || $dateTo !== '')
                ? (($dateFrom ?: 'the beginning') . ' to ' . ($dateTo ?: 'today')) : 'all dates',
            'Account'  => $acctFlt !== null
                ? (string) db_value("SELECT name FROM cash_accounts WHERE cash_account_id = :a", [':a' => $acctFlt]) : 'all',
            'Category' => $catFlt !== '' ? cash_category_label($catFlt) : 'any',
            'Direction'=> $dirFlt !== '' ? ($dirFlt === 'in' ? 'Money in' : 'Money out') : 'both',
            'Search'   => $search,
        ]),
        'columns' => [
            ['label' => 'Date',     'key' => 'txn_date', 'type' => 'date'],
            ['label' => 'Account',  'key' => 'account_name'],
            ['label' => 'Category', 'key' => 'category', 'value' => fn($r) => cash_category_label($r['category'])],
            ['label' => 'Party',    'key' => 'party'],
            ['label' => 'Description', 'key' => 'description'],
            ['label' => 'Money in',  'key' => 'in',  'type' => 'money',
             'value' => fn($r) => $r['direction'] === 'in'  ? $r['amount'] : null],
            ['label' => 'Money out', 'key' => 'out', 'type' => 'money',
             'value' => fn($r) => $r['direction'] === 'out' ? $r['amount'] : null],
            ['label' => 'Source',   'key' => 'source_type',
             'value' => fn($r) => $r['source_type'] ? ucwords(str_replace('_', ' ', (string) $r['source_type'])) : 'Entered by hand'],
        ],
        'rows' => db_all(
            "SELECT t.*, a.name AS account_name
               FROM cash_transactions t
               JOIN cash_accounts a ON a.cash_account_id = t.cash_account_id
             " . ($whereSql ? str_replace('b.', 't.', $whereSql) : '') . "
             ORDER BY t.txn_date $sortSql, t.cash_txn_id $sortSql",
            $params
        ),
        'total' => true,
    ]]);
}

/* ── Data ───────────────────────────────────────────────────── */
$total = (int) db_value(
    "SELECT COUNT(*) FROM cash_transactions t
       JOIN cash_accounts a ON a.cash_account_id = t.cash_account_id
     " . ($whereSql ? str_replace('b.', 't.', $whereSql) : ''),
    $params
);
$pg = paginate($total, $perPage);

// The running balance is the balance of all cash accounts, so it
// has to be summed over every cash movement in date order — not
// only the filtered ones. The window function runs over the whole
// book; the filter is applied afterwards, in the outer query.
$rows = db_all(
    "WITH book AS (
        SELECT t.*, a.name AS account_name, a.account_type,
               SUM(CASE WHEN a.account_type = 'cash'
                        THEN CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END
                        ELSE 0 END)
                 OVER (ORDER BY t.txn_date, t.cash_txn_id
                       ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_movement
          FROM cash_transactions t
          JOIN cash_accounts a ON a.cash_account_id = t.cash_account_id
     )
     SELECT b.*,
            b.running_movement + (SELECT COALESCE(SUM(opening_balance), 0)
                                    FROM cash_accounts WHERE account_type = 'cash') AS running_balance,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS created_by_name
       FROM book b
       LEFT JOIN users u ON u.user_id = b.created_by
       " . ($whereSql ? str_replace('t.', 'b.', $whereSql) : '') . "
      ORDER BY b.txn_date $sortSql, b.cash_txn_id $sortSql
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Group by date for the sheet's layout, and total each day.
$byDate = [];
foreach ($rows as $r) {
    $byDate[$r['txn_date']][] = $r;
}

// Totals describe everything the filters match, not just the rows
// on this page — a summary that changed as you paged would be
// worse than no summary at all.
$liquidList = "'" . implode("','", cash_liquid_types()) . "'";
$sums = db_one(
    "SELECT COALESCE(SUM(t.amount) FILTER (WHERE t.direction = 'in'), 0)  AS money_in,
            COALESCE(SUM(t.amount) FILTER (WHERE t.direction = 'out'), 0) AS money_out
       FROM cash_transactions t
       JOIN cash_accounts a ON a.cash_account_id = t.cash_account_id
     " . ($whereSql ? str_replace('b.', 't.', $whereSql) . ' AND' : 'WHERE') . "
       a.account_type IN ($liquidList)",
    $params
) ?: ['money_in' => 0, 'money_out' => 0];
$windowIn  = (float) $sums['money_in'];
$windowOut = (float) $sums['money_out'];
$closingBalance = $rows ? (float) $rows[0]['running_balance'] : (float) db_value(
    "SELECT COALESCE(SUM(opening_balance), 0) FROM cash_accounts WHERE account_type = 'cash'"
);

// What an entry can be recorded against. Only documents with a
// balance appear: an invoice that is settled cannot take another
// receipt, and offering it would only invite the mistake.
//
// Counted, not fetched. The pickers below are lookup_select(), which
// sends a page of rows and asks for the rest as the user types — all
// this needs to know is whether there is anything to offer at all.
// Rendering every open invoice made this page 37 MB.
$hasOpenInvoices = lookup_count('open_invoices') > 0;
$hasOpenOrders   = lookup_count('open_purchase_orders') > 0;

$pageTitle    = 'Cash Book';
$pageSubtitle = 'Every shilling in and out, with a running balance';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js', 'cashbook.js'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Cash Book']];

require __DIR__ . '/../../includes/header.php';

// Carried onto every page link, so paging does not quietly drop the
// filters the user chose. Without this the pagination links below
// were building on an undefined variable and the page died outright
// the moment there was a second page to link to.
$baseQuery = array_filter([
    'q'         => $search,
    'account'   => $acctFlt,
    'category'  => $catFlt,
    'direction' => $dirFlt,
    'from'      => $dateFrom,
    'to'        => $dateTo,
    'sort'      => $sort !== 'newest' ? $sort : null,
    'per_page'  => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');

?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Money in (filtered)</span><span class="mini-value tone-in"><?= e(money($windowIn)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Money out (filtered)</span><span class="mini-value tone-out"><?= e(money($windowOut)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Cash at hand now</span><span class="mini-value"><?= e(money($closingBalance)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Entries matched</span><span class="mini-value"><?= e(num($total)) ?></span></div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('finance/cashbook.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search name or description…">
        <select name="account" class="form-control toolbar-select" data-autosubmit data-searchable="Search accounts">
            <option value="">All accounts</option>
            <?php foreach ($accounts as $a): ?>
                <option value="<?= (int) $a['cash_account_id'] ?>" <?= $acctFlt === (int) $a['cash_account_id'] ? 'selected' : '' ?>>
                    <?= e($a['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="category" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any type</option>
            <?php foreach (CASH_CATEGORIES as $key => $meta): ?>
                <option value="<?= e($key) ?>" <?= $catFlt === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="direction" class="form-control toolbar-select" data-autosubmit>
            <option value="">In and out</option>
            <option value="in"  <?= $dirFlt === 'in'  ? 'selected' : '' ?>>In only</option>
            <option value="out" <?= $dirFlt === 'out' ? 'selected' : '' ?>>Out only</option>
        </select>
        <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control toolbar-select" data-autosubmit title="From date">
        <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control toolbar-select" data-autosubmit title="To date">
        <select name="sort" class="form-control toolbar-select" data-autosubmit title="Order">
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
        </select>
        <?= toolbar_apply('finance/cashbook.php') ?>
    </div>
    <div class="toolbar-actions">
        <?= export_button('finance/cashbook.php', $baseQuery) ?>
    <button type="button" class="btn btn-primary" data-modal-open="tplCashEntry" data-modal-title="Record Entry" data-modal-auto>

        <?= icon('plus') ?> Record Entry
    </button>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table cashbook-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Name</th>
                    <th>Account</th>
                    <th class="ta-center">In / Out</th>
                    <th class="ta-right">Amount</th>
                    <th class="ta-right">Balance</th>
                    <th>Description</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="table-empty">
                        No entries for these filters. Record the first one with <strong>Record Entry</strong>.
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($byDate as $date => $entries):
                    $dayIn  = 0.0;
                    $dayOut = 0.0;
                    foreach ($entries as $en) {
                        if ($en['account_type'] !== 'cash') {
                            continue;
                        }
                        $en['direction'] === 'in' ? $dayIn += (float) $en['amount'] : $dayOut += (float) $en['amount'];
                    }
                ?>
                    <tr class="cashbook-daybreak">
                        <th scope="rowgroup" colspan="4"><?= e(fmt_date($date, 'l, d M Y')) ?></th>
                        <td class="ta-right tone-in"><?= $dayIn > 0 ? '+' . e(num($dayIn, 2)) : '' ?></td>
                        <td class="ta-right tone-out"><?= $dayOut > 0 ? '−' . e(num($dayOut, 2)) : '' ?></td>
                        <td colspan="2"></td>
                    </tr>

                    <?php foreach ($entries as $i => $t):
                        $prefill = json_encode([
                            'cash_txn_id'     => $t['cash_txn_id'],
                            'txn_date'        => $t['txn_date'],
                            'cash_account_id' => $t['cash_account_id'],
                            'category'        => $t['category'],
                            'direction'       => $t['direction'],
                            'amount'          => $t['amount'],
                            'party'           => $t['party'],
                            'description'     => $t['description'],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    ?>
                        <tr>
                            <td class="cell-muted"><?= $i === 0 ? e(fmt_date($t['txn_date'])) : '' ?></td>
                            <td><strong><?= e($t['party'] ?: '—') ?></strong></td>
                            <td class="cell-muted">
                                <?= e($t['account_name']) ?>
                                <?php if ($t['account_type'] !== 'cash'): ?>
                                    <span class="badge badge--pending"><?= e(cash_account_type_label($t['account_type'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="ta-center">
                                <span class="inout inout--<?= e($t['direction']) ?>"><?= strtoupper(e($t['direction'])) ?></span>
                            </td>
                            <td class="ta-right <?= $t['direction'] === 'in' ? 'tone-in' : 'tone-out' ?>">
                                <?= e(money($t['amount'])) ?>
                            </td>
                            <td class="ta-right cell-muted">
                                <?= $t['account_type'] === 'cash' ? e(money($t['running_balance'])) : '—' ?>
                            </td>
                            <td>
                                <?= e($t['description'] ?: cash_category_label($t['category'])) ?>
                                <?php if ($t['source_type']): ?>
                                    <span class="badge badge--active">from <?= e(cash_source_noun($t['source_type'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="ta-right">
                                <div class="row-actions">
                                    <?php // A line the system posted for you is not edited here — it
                                          // is edited on the document that produced it, or cash and
                                          // the ledger start disagreeing. What it gets instead is the
                                          // way back to that document.
                                          $sourceUrl = $t['source_type']
                                              ? cash_source_url($t['source_type'], (int) $t['source_id'])
                                              : null; ?>
                                    <?php if ($sourceUrl !== null): ?>
                                        <a class="icon-btn" href="<?= e($sourceUrl) ?>"
                                           title="Open the <?= e(cash_source_noun($t['source_type'])) ?> this came from"><?= icon('report') ?></a>
                                    <?php elseif ($t['source_type']): ?>
                                        <span class="cell-muted" title="Posted from <?= e(cash_source_label($t['source_type'])) ?>; edit it there">—</span>
                                    <?php endif; ?>
                                    <?php if (!$t['source_type']): ?>
                                        <button type="button" class="icon-btn" title="Edit"
                                                data-modal-open="tplCashEntry" data-modal-title="Edit Entry"
                                                data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                    <?php endif; ?>
                                    <?php if (is_admin() && !$t['source_type']): ?>
                                        <form method="POST" action="<?= e(url('finance/cashbook.php')) ?>" class="inline-form"
                                              data-confirm="Delete this entry of <?= e(money($t['amount'])) ?><?= $t['transfer_group'] ? ' and its matching leg on the other account' : '' ?>? This cannot be undone.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $t['cash_txn_id'] ?>">
                                            <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php pagination_nav($pg, $baseQuery, 'entry'); ?>
</div>

<!-- Record / edit modal -->
<template id="tplCashEntry">
    <form method="POST" action="<?= e(url('finance/cashbook.php')) ?>" data-ajax data-cash-entry>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="cash_txn_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Date <span class="req">*</span></label>
                <input type="date" name="txn_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Type <span class="req">*</span></label>
                <select name="category" class="form-control" required>
                    <?php foreach (CASH_CATEGORIES as $key => $meta): ?>
                        <option value="<?= e($key) ?>" data-dir="<?= e($meta['dir']) ?>"
                                data-help="<?= e($meta['help']) ?>"
                                <?= $key === 'expense' ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint" data-cash-help><?= e(CASH_CATEGORIES['expense']['help']) ?></p>
            </div>
        </div>

        <!-- Shown only for categories that name a document -->
        <div class="form-group" data-cash-doc="invoice_payment" hidden>
            <label class="form-label">
                <?= e(CASH_DOCUMENT_CATEGORIES['invoice_payment']['label']) ?> <span class="req">*</span>
            </label>
            <?php if (!$hasOpenInvoices): ?>
                <p class="form-hint form-hint--warn">
                    <?= e(CASH_DOCUMENT_CATEGORIES['invoice_payment']['empty']) ?>
                </p>
            <?php else: ?>
                <?php lookup_select('open_invoices', 'invoice_id', null, [
                    'prompt'   => '— Select invoice —',
                    'label'    => 'Search invoices',
                    'required' => true,
                    'empty'    => 'No open invoice matches that.',
                    'attrs'    => ['data-doc-select' => ''],
                ]); ?>
                <p class="form-hint" data-doc-summary><?= e(CASH_DOCUMENT_CATEGORIES['invoice_payment']['help']) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-group" data-cash-doc="purchase" hidden>
            <label class="form-label">
                <?= e(CASH_DOCUMENT_CATEGORIES['purchase']['label']) ?> <span class="opt">(optional)</span>
            </label>
            <?php if (!$hasOpenOrders): ?>
                <p class="form-hint"><?= e(CASH_DOCUMENT_CATEGORIES['purchase']['empty']) ?></p>
            <?php else: ?>
                <?php lookup_select('open_purchase_orders', 'po_id', null, [
                    'prompt' => '— None: a counter purchase —',
                    'label'  => 'Search purchase orders',
                    'empty'  => 'No open order matches that.',
                    'attrs'  => ['data-doc-select' => ''],
                ]); ?>
                <p class="form-hint" data-doc-summary><?= e(CASH_DOCUMENT_CATEGORIES['purchase']['help']) ?></p>
            <?php endif; ?>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Account <span class="req">*</span></label>
                <select name="cash_account_id" class="form-control" required data-searchable="Search accounts">
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $a): if (!$a['is_active']) { continue; } ?>
                        <option value="<?= (int) $a['cash_account_id'] ?>" data-type="<?= e($a['account_type']) ?>">
                            <?= e($a['name']) ?> · <?= e(cash_account_type_label($a['account_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
            </div>
        </div>

        <!-- Shown only once a document has been named: these two are
             kept on the payment itself, and mean nothing without one.
             The wrapper is a plain block so [hidden] applies to it. -->
        <div data-cash-payinfo hidden>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Paid by</label>
                    <select name="pay_method" class="form-control">
                        <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                            <?php if ($key === 'salary_deduction') { continue; } ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Reference <span class="opt">(code on the slip)</span></label>
                    <input type="text" name="reference" class="form-control" maxlength="160"
                           placeholder="e.g. QJK4H8T2Z9, cheque no.">
                </div>
            </div>
        </div>

        <!-- Shown only for transfers -->
        <div class="form-group" data-cash-destination hidden>
            <label class="form-label">Transfer to <span class="req">*</span></label>
            <select name="destination_account_id" class="form-control" data-searchable="Search accounts">
                <option value="">— Select account —</option>
                <?php foreach ($accounts as $a): if (!$a['is_active']) { continue; } ?>
                    <option value="<?= (int) $a['cash_account_id'] ?>">
                        <?= e($a['name']) ?> · <?= e(cash_account_type_label($a['account_type'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">
                Both sides are written at once. To lend an employee money, transfer
                from their float to their staff-loan account.
            </p>
        </div>

        <!-- Shown only where the category allows both directions -->
        <div class="form-group" data-cash-direction hidden>
            <label class="form-label">Direction <span class="req">*</span></label>
            <select name="direction" class="form-control">
                <option value="out">Out of this account</option>
                <option value="in">Into this account</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Name <span class="opt">(who it was with)</span></label>
            <input type="text" name="party" class="form-control" maxlength="160" placeholder="e.g. Data Connect, Philis, Simon">
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control" placeholder="e.g. Purchase of 1000 pcs RJ45">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save entry</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
