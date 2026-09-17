<?php

/**
 * ============================================================
 *  Invoice payments
 * ------------------------------------------------------------
 *  Receipts against an invoice, each with its proof of payment.
 *
 *  An invoice's paid amount is no longer typed by anyone: it is
 *  the sum of its receipts, kept in step by a database trigger,
 *  and the status follows from it. Whatever is left unpaid is a
 *  trade receivable and appears as such on the daily position —
 *  a partly-paid invoice leaves only its balance there.
 *
 *  A receipt either brings new money into an account, or draws
 *  down an advance the customer paid earlier. Only the first
 *  posts to the cash book; the money for the second arrived when
 *  the advance did, and posting it twice would invent cash.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('invoices', 'invoice_payments', 'cash_accounts');

/* ── AJAX: record a receipt ─────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'record') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    // Either new money into an account, or an advance drawn down.
    $useAdvance = ($_POST['source'] ?? 'direct') === 'advance';
    $advanceId  = input_int($_POST, 'from_advance_id');
    $amount     = (float) ($_POST['amount'] ?? 0);
    $method     = input($_POST, 'method');

    if ($useAdvance && $advanceId === null) {
        json_response(['success' => false, 'message' => 'Choose which advance to apply.']);
    }

    // Proof of payment: optional, but the whole point of the
    // feature, so failures to attach are reported rather than
    // silently swallowed.
    $proof = ['file' => null, 'error' => null];
    if (!empty($_FILES['proof']['name'])) {
        $proof = store_uploaded_document($_FILES['proof'], 'payments');
        if ($proof['error']) {
            json_response(['success' => false, 'message' => $proof['error']]);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Every rule about what may be received, and how much, lives
        // with the ledger rather than with this page — so a receipt
        // entered here and one entered on the cash book are the same
        // event recorded the same way.
        $result = cash_record_invoice_receipt([
            'invoice_id' => input_int($_POST, 'invoice_id'),
            'date'       => input($_POST, 'payment_date'),
            'amount'     => $amount,
            'method'     => $method,
            'reference'  => input($_POST, 'reference'),
            'account_id' => $useAdvance ? null : input_int($_POST, 'cash_account_id'),
            'advance_id' => $useAdvance ? $advanceId : null,
            'notes'      => input($_POST, 'notes'),
            'proof_url'      => $proof['file']['url'] ?? null,
            'proof_filename' => $proof['file']['filename'] ?? null,
        ]);

        $pdo->commit();

        audit_log('invoice_payment.record', 'invoice_payments', $result['payment_id'], [
            'invoice' => $result['invoice']['invoice_number'], 'amount' => $amount, 'method' => $method,
        ]);
        json_response([
            'success' => true,
            'message' => sprintf(
                '%s received against %s. %s',
                money($amount),
                $result['invoice']['invoice_number'],
                $result['outstanding'] > 0.005
                    ? money($result['outstanding']) . ' still outstanding.'
                    : 'The invoice is now settled in full.'
            ),
        ]);
    } catch (PDOException $ex) {
        // Caught before RuntimeException, which PDOException extends.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PAY] record failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the payment.')], 500);
    } catch (RuntimeException $ex) {
        // Raised by the ledger, with a message written for the user.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PAY] record failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the payment.'], 500);
    }
}

/* ── POST: reverse a receipt (admins only) ──────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'reverse') {
    csrf_check();
    require_role(ROLE_ADMIN);

    $paymentId = input_int($_POST, 'payment_id');
    $payment   = $paymentId !== null ? db_one(
        "SELECT p.*, i.invoice_number FROM invoice_payments p
           JOIN invoices i ON i.invoice_id = p.invoice_id
          WHERE p.payment_id = :id",
        [':id' => $paymentId]
    ) : null;

    if (!$payment) {
        flash('error', 'That payment no longer exists.');
        redirect('invoices/index.php');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        // The ledger entry goes with it, or the cash book would
        // still show money that is no longer recorded as received.
        cash_unpost('invoice_payment', $paymentId);
        db_run("DELETE FROM invoice_payments WHERE payment_id = :id", [':id' => $paymentId]);
        $pdo->commit();

        audit_log('invoice_payment.reverse', 'invoice_payments', $paymentId, [
            'invoice' => $payment['invoice_number'], 'amount' => $payment['amount'],
        ]);
        flash('success', money($payment['amount']) . ' reversed on ' . $payment['invoice_number']
            . '. The invoice status has been recalculated.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PAY] reverse failed: ' . $ex->getMessage());
        flash('error', 'Could not reverse that payment.');
    }
    redirect('invoices/payments.php?invoice=' . (int) $payment['invoice_id']);
}

/* ── Data ───────────────────────────────────────────────────── */
$invoiceFilter = input_int($_GET, 'invoice');
$focus         = input_int($_GET, 'focus');
$search        = input($_GET, 'q');
$custId        = input_int($_GET, 'customer_id');
$methodFilter  = input($_GET, 'method');
$proofFilter   = input($_GET, 'proof');
$dateFrom      = input($_GET, 'from');
$dateTo        = input($_GET, 'to');
$perPage       = per_page();

$isDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$dateFrom = $isDate($dateFrom) ? $dateFrom : '';
$dateTo   = $isDate($dateTo) ? $dateTo : '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$where  = [];
$params = [];
if ($invoiceFilter !== null) {
    $where[] = "p.invoice_id = :inv";
    $params[':inv'] = $invoiceFilter;
}
// "Show me this one" — how the cash book links back to the receipt
// behind a line. Clear gets out of it.
if ($focus !== null) {
    $where[] = "p.payment_id = :focus";
    $params[':focus'] = $focus;
}
// One box covering the three things anyone actually searches a
// receipt by: the invoice number, the customer, and the code on
// the proof of payment.
if ($search !== '') {
    $where[] = "(LOWER(i.invoice_number) LIKE :q
                 OR LOWER(COALESCE(p.reference, '')) LIKE :q
                 OR LOWER(COALESCE(p.proof_filename, '')) LIKE :q
                 OR LOWER(COALESCE(c.company_name, '')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name, ' ', c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($custId !== null) {
    $where[] = "i.customer_id = :cust";
    $params[':cust'] = $custId;
}
if (isset(PAYMENT_METHODS[$methodFilter])) {
    $where[] = "p.method = :method";
    $params[':method'] = $methodFilter;
} else {
    $methodFilter = '';
}
if ($proofFilter === 'with') {
    $where[] = "p.proof_url IS NOT NULL";
} elseif ($proofFilter === 'without') {
    $where[] = "p.proof_url IS NULL";
} else {
    $proofFilter = '';
}
if ($dateFrom !== '') {
    $where[] = "p.payment_date >= :from";
    $params[':from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "p.payment_date <= :to";
    $params[':to'] = $dateTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if (wants_export()) {
    export_deliver(export_filename('invoice-payments'), [[
        'name'  => 'Receipts',
        'title' => 'Invoice payments received',
        'meta'  => export_meta([
            'Period'   => ($dateFrom !== '' || $dateTo !== '')
                ? (($dateFrom ?: 'the beginning') . ' to ' . ($dateTo ?: 'today')) : 'all dates',
            'Method'   => $methodFilter !== '' ? payment_method_label($methodFilter) : 'any',
            'Search'   => $search,
        ]),
        'columns' => [
            ['label' => 'Date',      'key' => 'payment_date', 'type' => 'date'],
            ['label' => 'Invoice',   'key' => 'invoice_number'],
            ['label' => 'Customer',  'key' => 'customer_name'],
            ['label' => 'Method',    'key' => 'method', 'value' => fn($r) => payment_method_label($r['method'])],
            ['label' => 'Reference', 'key' => 'reference'],
            ['label' => 'Paid into', 'key' => 'account_name',
             'value' => fn($r) => $r['advance_number'] ? 'From advance ' . $r['advance_number'] : $r['account_name']],
            ['label' => 'Amount',    'key' => 'amount', 'type' => 'money'],
            ['label' => 'Proof',     'key' => 'proof_filename', 'value' => fn($r) => $r['proof_url'] ? 'yes' : ''],
            ['label' => 'Recorded by', 'key' => 'recorded_by_name'],
        ],
        'rows'  => db_all(
            "SELECT p.*, i.invoice_number,
                    " . customer_name_sql('c') . " AS customer_name,
                    a.name AS account_name, adv.advance_number,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS recorded_by_name
               FROM invoice_payments p
               JOIN invoices i ON i.invoice_id = p.invoice_id
               LEFT JOIN customers c ON c.customer_id = i.customer_id
               LEFT JOIN cash_accounts a ON a.cash_account_id = p.cash_account_id
               LEFT JOIN customer_advances adv ON adv.advance_id = p.from_advance_id
               LEFT JOIN users u ON u.user_id = p.recorded_by
             $whereSql
             ORDER BY p.payment_date DESC, p.payment_id DESC",
            $params
        ),
        'total' => true,
    ]]);
}

$total = (int) db_value(
    "SELECT COUNT(*) FROM invoice_payments p
       JOIN invoices i ON i.invoice_id = p.invoice_id
       LEFT JOIN customers c ON c.customer_id = i.customer_id
     $whereSql",
    $params
);
$pg = paginate($total, $perPage);

$payments = db_all(
    "SELECT p.*, i.invoice_number, i.total_amount, i.amount_paid, i.balance_due,
            i.status AS invoice_status,
            " . customer_name_sql('c') . " AS customer_name,
            a.name AS account_name,
            adv.advance_number,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS recorded_by_name
       FROM invoice_payments p
       JOIN invoices i ON i.invoice_id = p.invoice_id
       LEFT JOIN customers c ON c.customer_id = i.customer_id
       LEFT JOIN cash_accounts a ON a.cash_account_id = p.cash_account_id
       LEFT JOIN customer_advances adv ON adv.advance_id = p.from_advance_id
       LEFT JOIN users u ON u.user_id = p.recorded_by
     $whereSql
     ORDER BY p.payment_date DESC, p.payment_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$receivedTotal = (float) db_value(
    "SELECT COALESCE(SUM(p.amount), 0) FROM invoice_payments p
       JOIN invoices i ON i.invoice_id = p.invoice_id
       LEFT JOIN customers c ON c.customer_id = i.customer_id
     $whereSql",
    $params
);
$outstanding = finance_trade_receivables(date('Y-m-d'));
$withProof   = (int) db_value(
    "SELECT COUNT(*) FROM invoice_payments p
       JOIN invoices i ON i.invoice_id = p.invoice_id
       LEFT JOIN customers c ON c.customer_id = i.customer_id
     $whereSql" . ($whereSql ? ' AND' : ' WHERE') . " p.proof_url IS NOT NULL",
    $params
);

$filtersActive = $search !== '' || $custId !== null || $methodFilter !== ''
    || $proofFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $invoiceFilter !== null
    || $focus !== null;

$pageTitle    = 'Invoice Payments';
$pageSubtitle = 'Receipts against invoices, with proof of payment';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Sales'], ['label' => 'Invoice Payments']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter([
    'invoice' => $invoiceFilter, 'focus' => $focus, 'q' => $search, 'customer_id' => $custId,
    'method' => $methodFilter, 'proof' => $proofFilter,
    'from' => $dateFrom, 'to' => $dateTo,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Received (filtered)</span><span class="mini-value tone-in"><?= e(money($receivedTotal)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Trade receivables now</span><span class="mini-value"><?= e(money($outstanding)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Receipts</span><span class="mini-value"><?= e(num($total)) ?></span></div>
    <div class="mini-card"><span class="mini-label">With proof attached</span><span class="mini-value"><?= e(num($withProof)) ?> of <?= e(num($total)) ?></span></div>
</section>

<form class="filter-bar" method="GET" action="<?= e(url('invoices/payments.php')) ?>">
    <div class="filter-row">
        <div class="toolbar-search filter-grow">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="Invoice number, customer, or proof-of-payment code…">
        </div>
        <?= export_button('invoices/payments.php', $baseQuery) ?>
        <a href="<?= e(url('invoices/index.php')) ?>" class="btn btn-ghost"><?= icon('file') ?> Invoices</a>
    </div>

    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">Customer</span>
            <?php lookup_select('paying_customers', 'customer_id', $custId, [
                'prompt' => 'All customers',
                'label'  => 'Search customers',
                'empty'  => 'No customer matches that.',
            ]); ?>
        </label>
        <label class="filter-field">
            <span class="filter-label">Method</span>
            <select name="method" class="form-control">
                <option value="">Any method</option>
                <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                    <?php if ($key === 'salary_deduction') { continue; } ?>
                    <option value="<?= e($key) ?>" <?= $methodFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">Proof</span>
            <select name="proof" class="form-control">
                <option value="">With or without</option>
                <option value="with"    <?= $proofFilter === 'with' ? 'selected' : '' ?>>Proof attached</option>
                <option value="without" <?= $proofFilter === 'without' ? 'selected' : '' ?>>No proof yet</option>
            </select>
        </label>
        <?php if ($invoiceFilter !== null): ?>
            <input type="hidden" name="invoice" value="<?= (int) $invoiceFilter ?>">
        <?php endif; ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Filter</button>
            <?php if ($filtersActive): ?>
                <a href="<?= e(url('invoices/payments.php')) ?>" class="btn btn-ghost"><?= icon('x') ?> Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Paid into</th>
                    <th class="ta-right">Amount</th>
                    <th class="ta-right">Invoice balance</th>
                    <th>Proof</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$payments): ?>
                    <tr><td colspan="10" class="table-empty">
                        No receipts recorded. Use <strong>Record payment</strong> on an invoice.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($payments as $p):
                    // The invoice's own balance, which counts the credit
                    // notes against it. total - paid showed a returned item
                    // as still owing.
                    $balance = (float) $p['balance_due'];
                ?>
                    <tr>
                        <td class="cell-muted"><?= e(fmt_date($p['payment_date'])) ?></td>
                        <td>
                            <a href="<?= e(url('invoices/view.php?id=' . (int) $p['invoice_id'])) ?>"
                               class="cell-title"><?= e($p['invoice_number']) ?></a>
                        </td>
                        <td><?= e($p['customer_name'] ?: '—') ?></td>
                        <td><span class="role-pill"><?= e(payment_method_label($p['method'])) ?></span></td>
                        <td class="cell-muted"><?= e($p['reference'] ?: '—') ?></td>
                        <td class="cell-muted">
                            <?php if ($p['advance_number']): ?>
                                <span class="badge badge--pending">From advance <?= e($p['advance_number']) ?></span>
                            <?php else: ?>
                                <?= e($p['account_name'] ?: '—') ?>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right tone-in"><strong><?= e(money($p['amount'])) ?></strong></td>
                        <td class="ta-right <?= $balance > 0.005 ? '' : 'cell-muted' ?>">
                            <?= $balance > 0.005 ? e(money($balance)) : 'Settled' ?>
                        </td>
                        <td>
                            <?php if ($p['proof_url']): ?>
                                <a href="<?= e(private_document_url($p['proof_url'])) ?>" target="_blank" rel="noopener"
                                   class="icon-btn" title="View proof of payment"><?= icon('file') ?></a>
                            <?php else: ?>
                                <span class="cell-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <?php if (is_admin()): ?>
                                <form method="POST" action="<?= e(url('invoices/payments.php')) ?>" class="inline-form"
                                      data-confirm="Reverse this receipt of <?= e(money($p['amount'])) ?> on <?= e($p['invoice_number']) ?>? The invoice status and the cash book both change.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="reverse">
                                    <input type="hidden" name="payment_id" value="<?= (int) $p['payment_id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Reverse"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'receipt'); ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
