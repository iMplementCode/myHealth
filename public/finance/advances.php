<?php

/**
 * ============================================================
 *  Customer Advances
 * ------------------------------------------------------------
 *  Money a customer pays before the invoice exists.
 *
 *  This is not income and it is not a sale. The cash is real —
 *  it is banked like any other receipt — but the business now
 *  owes goods or services for it, so it sits on the liability
 *  side of the position until an invoice draws it down. Booking
 *  it as revenue on the day it arrives overstates both the sales
 *  figure and the net asset value.
 *
 *  Applying an advance to an invoice moves no cash: it converts
 *  the liability into settled revenue. That is why applying it
 *  writes an invoice payment with no account attached.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_role(ROLE_MANAGER);
require_tables('customer_advances', 'cash_accounts', 'customers');

/* ── AJAX: record an advance ────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'record') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $customerId = input_int($_POST, 'customer_id');
    $amount     = (float) ($_POST['amount'] ?? 0);
    $date       = input($_POST, 'received_on') ?: date('Y-m-d');
    $method     = input($_POST, 'method');
    $accountId  = input_int($_POST, 'cash_account_id');

    $customer = $customerId !== null ? db_one(
        "SELECT customer_id,
                " . customer_name_sql('') . " AS name
           FROM customers WHERE customer_id = :id",
        [':id' => $customerId]
    ) : null;

    if (!$customer) {
        json_response(['success' => false, 'message' => 'Choose the customer who paid.']);
    }
    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'The amount must be more than zero.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'Please provide a valid date, not in the future.']);
    }
    if (!isset(PAYMENT_METHODS[$method]) || $method === 'salary_deduction') {
        json_response(['success' => false, 'message' => 'Choose how the money was paid.']);
    }
    $account = $accountId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
        : null;
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        json_response(['success' => false, 'message' => 'Choose the account the money was paid into.']);
    }

    $proof = ['file' => null, 'error' => null];
    if (!empty($_FILES['proof']['name'])) {
        $proof = store_uploaded_document($_FILES['proof'], 'advances');
        if ($proof['error']) {
            json_response(['success' => false, 'message' => $proof['error']]);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $number = next_document_number('customer_advances', 'advance_number', 'ADV');
        $stmt = $pdo->prepare(
            "INSERT INTO customer_advances
                (advance_number, customer_id, received_on, amount, method, reference,
                 cash_account_id, proof_url, proof_filename, notes, recorded_by)
             VALUES (:num, :cust, :d, :amt, :m, :ref, :acct, :purl, :pname, :notes, :by)
             RETURNING advance_id"
        );
        $stmt->execute([
            ':num' => $number, ':cust' => $customerId, ':d' => $date, ':amt' => $amount,
            ':m' => $method, ':ref' => input($_POST, 'reference') ?: null, ':acct' => $accountId,
            ':purl' => $proof['file']['url'] ?? null,
            ':pname' => $proof['file']['filename'] ?? null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by' => current_user()['id'],
        ]);
        $advanceId = (int) $stmt->fetchColumn();

        // The cash is real and is banked now. What it represents —
        // an obligation rather than a sale — is carried by the
        // advances table, not by the ledger entry.
        cash_post([
            'date'        => $date,
            'account_id'  => $accountId,
            'direction'   => 'in',
            'amount'      => $amount,
            'category'    => 'customer_advance',
            'party'       => $customer['name'],
            'description' => 'Advance ' . $number . ' from ' . $customer['name'],
            'customer_id' => $customerId,
            'source_type' => 'customer_advance',
            'source_id'   => $advanceId,
        ]);

        $pdo->commit();
        audit_log('customer_advance.record', 'customer_advances', $advanceId, [
            'number' => $number, 'customer' => $customer['name'], 'amount' => $amount,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s received from %s as %s. It is held as a liability until an invoice draws it down.',
            money($amount), $customer['name'], $number
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ADV] record failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the advance.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ADV] record failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the advance.'], 500);
    }
}

/* ── AJAX: refund an unused advance ─────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'refund') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $advanceId = input_int($_POST, 'advance_id');
    $advance   = $advanceId !== null ? db_one(
        "SELECT a.*, COALESCE(u.used, 0) AS used,
                " . customer_name_sql('c') . " AS customer_name
           FROM customer_advances a
           LEFT JOIN customers c ON c.customer_id = a.customer_id
           LEFT JOIN (SELECT from_advance_id, SUM(amount) AS used FROM invoice_payments
                       WHERE from_advance_id IS NOT NULL GROUP BY from_advance_id) u
                  ON u.from_advance_id = a.advance_id
          WHERE a.advance_id = :id",
        [':id' => $advanceId]
    ) : null;

    if (!$advance) {
        json_response(['success' => false, 'message' => 'That advance no longer exists.']);
    }
    if ($advance['status'] === 'refunded') {
        json_response(['success' => false, 'message' => $advance['advance_number'] . ' has already been refunded.']);
    }

    $remaining = (float) $advance['amount'] - (float) $advance['used'];
    if ($remaining < 0.005) {
        json_response(['success' => false, 'message' =>
            $advance['advance_number'] . ' has been fully applied to invoices, so there is nothing to refund.']);
    }

    $date      = input($_POST, 'refund_date') ?: date('Y-m-d');
    $accountId = input_int($_POST, 'cash_account_id');
    $account   = $accountId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
        : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid refund date.']);
    }
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        json_response(['success' => false, 'message' => 'Choose the account the refund is paid from.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        cash_post([
            'date'        => $date,
            'account_id'  => $accountId,
            'direction'   => 'out',
            'amount'      => $remaining,
            'category'    => 'refund',
            'party'       => $advance['customer_name'],
            'description' => 'Refund of unused advance ' . $advance['advance_number'],
            'customer_id' => $advance['customer_id'],
        ]);
        db_run(
            "UPDATE customer_advances SET status = 'refunded', updated_at = NOW() WHERE advance_id = :id",
            [':id' => $advanceId]
        );
        $pdo->commit();

        audit_log('customer_advance.refund', 'customer_advances', $advanceId, [
            'number' => $advance['advance_number'], 'amount' => $remaining,
        ]);
        json_response(['success' => true, 'message' =>
            money($remaining) . ' refunded to ' . $advance['customer_name'] . '.']);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ADV] refund failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the refund.'], 500);
    }
}

/* ── Data ───────────────────────────────────────────────────── */
$statusFilter = input($_GET, 'status');
$search       = input($_GET, 'q');
$focus        = input_int($_GET, 'focus');
$perPage      = per_page();

$where  = [];
$params = [];
// "Show me this one" — how the cash book links back to the advance
// behind a line. Clear gets out of it.
if ($focus !== null) {
    $where[] = "a.advance_id = :focus";
    $params[':focus'] = $focus;
}
if (in_array($statusFilter, ['open', 'applied', 'refunded'], true)) {
    $where[] = "a.status = :st";
    $params[':st'] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(LOWER(a.advance_number) LIKE :q OR LOWER(a.reference) LIKE :q
                 OR LOWER(COALESCE(c.company_name, '')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value(
    "SELECT COUNT(*) FROM customer_advances a LEFT JOIN customers c ON c.customer_id = a.customer_id $whereSql",
    $params
);
$pg = paginate($total, $perPage);

$advances = db_all(
    "SELECT a.*, COALESCE(u.used, 0) AS used,
            a.amount - COALESCE(u.used, 0) AS remaining,
            " . customer_name_sql('c') . " AS customer_name,
            acct.name AS account_name
       FROM customer_advances a
       LEFT JOIN customers c ON c.customer_id = a.customer_id
       LEFT JOIN cash_accounts acct ON acct.cash_account_id = a.cash_account_id
       LEFT JOIN (SELECT from_advance_id, SUM(amount) AS used FROM invoice_payments
                   WHERE from_advance_id IS NOT NULL GROUP BY from_advance_id) u
              ON u.from_advance_id = a.advance_id
     $whereSql
     ORDER BY a.received_on DESC, a.advance_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$heldNow   = finance_customer_advances(date('Y-m-d'));
$receivedT = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM customer_advances WHERE status <> 'refunded'");

$accounts = cash_liquid_accounts();

$pageTitle    = 'Customer Advances';
$pageSubtitle = 'Paid before invoicing — held as a liability until drawn down';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Customer Advances']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter(['status' => $statusFilter, 'q' => $search, 'focus' => $focus], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Held for customers</span><span class="mini-value tone-out"><?= e(money($heldNow)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Received in total</span><span class="mini-value"><?= e(money($receivedT)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Advances on record</span><span class="mini-value"><?= e(num($total)) ?></span></div>
    <div class="mini-card">
        <span class="mini-label">Where it shows</span>
        <span class="mini-value mini-value--text">Liabilities</span>
    </div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('finance/advances.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search advance, customer or reference…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <option value="open"     <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="applied"  <?= $statusFilter === 'applied' ? 'selected' : '' ?>>Fully applied</option>
            <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Refunded</option>
        </select>
        <?= toolbar_apply('finance/advances.php') ?>
    </div>
    <button type="button" class="btn btn-primary" data-modal-open="tplAdvanceForm" data-modal-title="Record Advance" data-modal-auto>
        <?= icon('plus') ?> Record Advance
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Advance</th>
                    <th>Customer</th>
                    <th>Received</th>
                    <th>Method</th>
                    <th>Paid into</th>
                    <th class="ta-right">Amount</th>
                    <th class="ta-right">Applied</th>
                    <th class="ta-right">Remaining</th>
                    <th>Status</th>
                    <th>Proof</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$advances): ?>
                    <tr><td colspan="11" class="table-empty">
                        No advances recorded. Use this when a customer pays before you invoice them.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($advances as $a):
                    $refundFill = json_encode([
                        'advance_id'  => $a['advance_id'],
                        'refund_date' => date('Y-m-d'),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    $tone = ['open' => 'pending', 'applied' => 'completed', 'refunded' => 'inactive'][$a['status']] ?? 'pending';
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($a['advance_number']) ?></span></td>
                        <td><?= e($a['customer_name']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($a['received_on'])) ?></td>
                        <td><span class="role-pill"><?= e(payment_method_label($a['method'])) ?></span></td>
                        <td class="cell-muted"><?= e($a['account_name'] ?: '—') ?></td>
                        <td class="ta-right"><strong><?= e(money($a['amount'])) ?></strong></td>
                        <td class="ta-right cell-muted"><?= e(money($a['used'])) ?></td>
                        <td class="ta-right">
                            <?= $a['status'] === 'refunded'
                                ? '<span class="cell-muted">Refunded</span>'
                                : ((float) $a['remaining'] > 0.005 ? '<strong>' . e(money($a['remaining'])) . '</strong>' : '<span class="cell-muted">—</span>') ?>
                        </td>
                        <td><span class="badge badge--<?= e($tone) ?>"><?= e(ucfirst($a['status'])) ?></span></td>
                        <td>
                            <?php if ($a['proof_url']): ?>
                                <a href="<?= e(private_document_url($a['proof_url'])) ?>" target="_blank" rel="noopener"
                                   class="icon-btn" title="View proof"><?= icon('file') ?></a>
                            <?php else: ?>
                                <span class="cell-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($a['status'] === 'open' && (float) $a['remaining'] > 0.005): ?>
                                    <a class="icon-btn" title="Apply to an invoice"
                                       href="<?= e(url('invoices/index.php?customer_id=' . (int) $a['customer_id'])) ?>"><?= icon('check') ?></a>
                                    <?php if (is_admin()): ?>
                                        <button type="button" class="icon-btn icon-btn--danger" title="Refund the unused balance"
                                                data-modal-open="tplAdvanceRefund"
                                                data-modal-title="Refund <?= e($a['advance_number']) ?>"
                                                data-prefill="<?= e($refundFill) ?>"><?= icon('x') ?></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'advance'); ?>
</div>

<p class="form-hint u-pad">
    To apply an advance, open the customer's invoice and choose
    <strong>Apply an advance</strong> when recording the payment. No cash moves:
    the money arrived when the advance did.
</p>

<template id="tplAdvanceForm">
    <form method="POST" action="<?= e(url('finance/advances.php')) ?>" data-ajax enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="record">

        <p class="form-hint">
            The cash is banked now, but it is not income yet — the business owes
            goods or services for it. It shows under liabilities until an invoice
            draws it down.
        </p>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Customer <span class="req">*</span></label>
                <?php /* This loop read $c while iterating $co, so every
                         option rendered blank and the log filled with
                         20,000 undefined-variable warnings a page. */ ?>
                <?php lookup_select('customers', 'customer_id', null, [
                    'prompt'   => '— Select customer —',
                    'label'    => 'Search customers',
                    'required' => true,
                    'empty'    => 'No customer matches that.',
                ]); ?>
            </div>
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Date received <span class="req">*</span></label>
                <input type="date" name="received_on" class="form-control" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Method <span class="req">*</span></label>
                <select name="method" class="form-control" required>
                    <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                        <?php if ($key === 'salary_deduction') { continue; } ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Paid into <span class="req">*</span></label>
                <select name="cash_account_id" class="form-control" required data-searchable="Search accounts">
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $ac): ?>
                        <option value="<?= (int) $ac['cash_account_id'] ?>">
                            <?= e($ac['name']) ?> · <?= e(cash_account_type_label($ac['account_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Reference</label>
            <input type="text" name="reference" class="form-control" maxlength="160" placeholder="Transaction code or slip number">
        </div>

        <div class="form-group">
            <label class="form-label">Proof of payment <span class="opt">(image or PDF)</span></label>
            <input type="file" name="proof" class="form-control" accept="image/*,application/pdf">
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="What the advance is towards">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record advance</button>
        </div>
    </form>
</template>

<template id="tplAdvanceRefund">
    <form method="POST" action="<?= e(url('finance/advances.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="refund">
        <input type="hidden" name="advance_id" value="">

        <p class="form-hint">
            Refunds the whole unused balance and closes the advance. Anything
            already applied to an invoice stays applied.
        </p>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Paid from <span class="req">*</span></label>
                <select name="cash_account_id" class="form-control" required data-searchable="Search accounts">
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $ac): ?>
                        <option value="<?= (int) $ac['cash_account_id'] ?>">
                            <?= e($ac['name']) ?> · <?= e(cash_account_type_label($ac['account_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date <span class="req">*</span></label>
                <input type="date" name="refund_date" class="form-control" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record refund</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
