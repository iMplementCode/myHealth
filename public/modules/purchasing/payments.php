<?php

/**
 * ============================================================
 *  Supplier Payments
 * ------------------------------------------------------------
 *  Money paid out against purchase orders, each with its proof.
 *
 *  The mirror of Invoice Payments on the buying side, and it
 *  works the same way: an order's paid figure is never typed —
 *  it is the sum of these disbursements, kept in step by a
 *  database trigger, and the payment status follows from it.
 *
 *  Every payment here also writes a cash-book entry, which is
 *  what puts a figure on the daily position's "paid to
 *  suppliers" line. Reversing a payment takes that entry with
 *  it, so the cash book never shows money that is no longer
 *  recorded as spent.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/purchasing.php';
require_once __DIR__ . '/../../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('purchase_orders', 'purchase_payments', 'cash_accounts');

/* ── POST: reverse a payment (admins only) ──────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'reverse') {
    csrf_check();
    require_role(ROLE_ADMIN);

    $paymentId = input_int($_POST, 'payment_id');
    $payment   = $paymentId !== null ? db_one(
        "SELECT p.*, po.po_number FROM purchase_payments p
           JOIN purchase_orders po ON po.po_id = p.po_id
          WHERE p.payment_id = :id",
        [':id' => $paymentId]
    ) : null;

    if (!$payment) {
        flash('error', 'That payment no longer exists.');
        redirect('modules/purchasing/payments.php');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        // The ledger entry goes with it, or the cash book would still
        // show money leaving that is no longer recorded as paid.
        cash_unpost('purchase_payment', $paymentId);
        db_run("DELETE FROM purchase_payments WHERE payment_id = :id", [':id' => $paymentId]);
        $pdo->commit();

        audit_log('purchase_payment.reverse', 'purchase_payments', $paymentId, [
            'po' => $payment['po_number'], 'amount' => $payment['amount'],
        ]);
        flash('success', money($payment['amount']) . ' reversed on ' . $payment['po_number']
            . '. The order\'s payment status has been recalculated.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[SUPPLIER PAY] reverse failed: ' . $ex->getMessage());
        flash('error', 'Could not reverse that payment.');
    }
    redirect('modules/purchasing/payments.php?po=' . (int) $payment['po_id']);
}

/* ── Filters ────────────────────────────────────────────────── */
$poFilter     = input_int($_GET, 'po');
$focus        = input_int($_GET, 'focus');
$search       = input($_GET, 'q');
$supplierId   = input_int($_GET, 'supplier_id');
$methodFilter = input($_GET, 'method');
$proofFilter  = input($_GET, 'proof');
$dateFrom     = input($_GET, 'from');
$dateTo       = input($_GET, 'to');
$perPage      = per_page();

$isDate   = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$dateFrom = $isDate($dateFrom) ? $dateFrom : '';
$dateTo   = $isDate($dateTo) ? $dateTo : '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$where  = [];
$params = [];
if ($poFilter !== null) {
    $where[] = "p.po_id = :po";
    $params[':po'] = $poFilter;
}
// "Show me this one" — how the cash book links back to the payment
// behind a line. Clear gets out of it.
if ($focus !== null) {
    $where[] = "p.payment_id = :focus";
    $params[':focus'] = $focus;
}
// One box for the three things anyone searches a disbursement by:
// the PO number, the supplier, and the code on the proof.
if ($search !== '') {
    $where[] = "(LOWER(po.po_number) LIKE :q
                 OR LOWER(COALESCE(p.reference, '')) LIKE :q
                 OR LOWER(COALESCE(p.proof_filename, '')) LIKE :q
                 OR LOWER(COALESCE(s.name, '')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($supplierId !== null) {
    $where[] = "po.supplier_id = :sup";
    $params[':sup'] = $supplierId;
}
if (isset(PAYMENT_METHODS[$methodFilter]) && $methodFilter !== 'salary_deduction') {
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

$joins = "FROM purchase_payments p
          JOIN purchase_orders po ON po.po_id = p.po_id
          LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id";

if (wants_export()) {
    export_deliver(export_filename('supplier-payments'), [[
        'name'  => 'Payments',
        'title' => 'Payments made to suppliers',
        'meta'  => export_meta([
            'Period' => ($dateFrom !== '' || $dateTo !== '')
                ? (($dateFrom ?: 'the beginning') . ' to ' . ($dateTo ?: 'today')) : 'all dates',
            'Method' => $methodFilter !== '' ? payment_method_label($methodFilter) : 'any',
            'Search' => $search,
        ]),
        'columns' => [
            ['label' => 'Date',      'key' => 'payment_date', 'type' => 'date'],
            ['label' => 'Order',     'key' => 'po_number'],
            ['label' => 'Supplier',  'key' => 'supplier_name'],
            ['label' => 'Method',    'key' => 'method', 'value' => fn($r) => payment_method_label($r['method'])],
            ['label' => 'Reference', 'key' => 'reference'],
            ['label' => 'Paid from', 'key' => 'account_name'],
            ['label' => 'Amount',    'key' => 'amount', 'type' => 'money'],
            ['label' => 'Order balance', 'key' => 'bal', 'type' => 'money', 'total' => false,
             'value' => fn($r) => (float) $r['total_amount'] - (float) $r['amount_paid']],
            ['label' => 'Proof',     'key' => 'proof_url', 'value' => fn($r) => $r['proof_url'] ? 'yes' : ''],
            ['label' => 'Recorded by', 'key' => 'recorded_by_name'],
        ],
        'rows' => db_all(
            "SELECT p.*, po.po_number, po.total_amount, po.amount_paid,
                    s.name AS supplier_name, a.name AS account_name,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS recorded_by_name
             $joins
             LEFT JOIN cash_accounts a ON a.cash_account_id = p.cash_account_id
             LEFT JOIN users u ON u.user_id = p.recorded_by
             $whereSql
             ORDER BY p.payment_date DESC, p.payment_id DESC",
            $params
        ),
        'total' => true,
    ]]);
}

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params);
$pg    = paginate($total, $perPage);

$payments = db_all(
    "SELECT p.*, po.po_number, po.total_amount, po.amount_paid, po.status AS po_status,
            po.payment_status,
            s.name AS supplier_name,
            a.name AS account_name,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS recorded_by_name
     $joins
     LEFT JOIN cash_accounts a ON a.cash_account_id = p.cash_account_id
     LEFT JOIN users u ON u.user_id = p.recorded_by
     $whereSql
     ORDER BY p.payment_date DESC, p.payment_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$paidTotal = (float) db_value("SELECT COALESCE(SUM(p.amount), 0) $joins $whereSql", $params);
$withProof = (int) db_value(
    "SELECT COUNT(*) $joins $whereSql" . ($whereSql ? ' AND' : ' WHERE') . " p.proof_url IS NOT NULL",
    $params
);
$outstanding = finance_trade_payables(date('Y-m-d'));

$filtersActive = $search !== '' || $supplierId !== null || $methodFilter !== ''
    || $proofFilter !== '' || $dateFrom !== '' || $dateTo !== '' || $poFilter !== null
    || $focus !== null;

$supplierOptions = db_all(
    "SELECT DISTINCT po.supplier_id, s.name
       FROM purchase_payments p
       JOIN purchase_orders po ON po.po_id = p.po_id
       LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
      WHERE s.name IS NOT NULL
      ORDER BY s.name"
);

$pageTitle    = 'Supplier Payments';
$pageSubtitle = 'Money paid against purchase orders, with proof of payment';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Purchasing'], ['label' => 'Supplier Payments']];

require __DIR__ . '/../../../includes/header.php';

$baseQuery = array_filter([
    'po' => $poFilter, 'focus' => $focus, 'q' => $search, 'supplier_id' => $supplierId,
    'method' => $methodFilter, 'proof' => $proofFilter,
    'from' => $dateFrom, 'to' => $dateTo,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Paid (filtered)</span><span class="mini-value tone-out"><?= e(money($paidTotal)) ?></span></div>
    <div class="mini-card">
        <span class="mini-label">Trade payables now</span>
        <span class="mini-value"><?= e(money($outstanding)) ?></span>
        <span class="mini-note">goods received, not yet paid</span>
    </div>
    <div class="mini-card"><span class="mini-label">Payments</span><span class="mini-value"><?= e(num($total)) ?></span></div>
    <div class="mini-card"><span class="mini-label">With proof attached</span><span class="mini-value"><?= e(num($withProof)) ?> of <?= e(num($total)) ?></span></div>
</section>

<form class="filter-bar" method="GET" action="<?= e(url('modules/purchasing/payments.php')) ?>">
    <div class="filter-row">
        <div class="toolbar-search filter-grow">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="PO number, supplier, or proof-of-payment code…">
        </div>
        <?= export_button('modules/purchasing/payments.php', $baseQuery) ?>
        <a href="<?= e(url('modules/purchasing/manage_pos.php')) ?>" class="btn btn-ghost">
            <?= icon('file') ?> Purchase Orders
        </a>
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
            <span class="filter-label">Supplier</span>
            <select name="supplier_id" class="form-control" data-searchable="Search suppliers">
                <option value="">All suppliers</option>
                <?php foreach ($supplierOptions as $s): ?>
                    <option value="<?= (int) $s['supplier_id'] ?>" <?= $supplierId === (int) $s['supplier_id'] ? 'selected' : '' ?>>
                        <?= e($s['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
        <?php if ($poFilter !== null): ?>
            <input type="hidden" name="po" value="<?= (int) $poFilter ?>">
        <?php endif; ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Filter</button>
            <?php if ($filtersActive): ?>
                <a href="<?= e(url('modules/purchasing/payments.php')) ?>" class="btn btn-ghost"><?= icon('x') ?> Clear</a>
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
                    <th>Purchase order</th>
                    <th>Supplier</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Paid from</th>
                    <th class="ta-right">Amount</th>
                    <th class="ta-right">Order balance</th>
                    <th>Proof</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$payments): ?>
                    <tr><td colspan="10" class="table-empty">
                        No supplier payments recorded. Use <strong>Record a payment</strong>
                        on a purchase order.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($payments as $p):
                    $balance = (float) $p['total_amount'] - (float) $p['amount_paid'];
                ?>
                    <tr>
                        <td class="cell-muted"><?= e(fmt_date($p['payment_date'])) ?></td>
                        <td>
                            <span class="cell-title"><?= e($p['po_number']) ?></span>
                            <span class="cell-sub"><?= e(po_payment_label($p['payment_status'])) ?></span>
                        </td>
                        <td><?= e($p['supplier_name'] ?: '—') ?></td>
                        <td><span class="role-pill"><?= e(payment_method_label($p['method'])) ?></span></td>
                        <td class="cell-muted"><?= e($p['reference'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e($p['account_name'] ?: '—') ?></td>
                        <td class="ta-right tone-out"><strong><?= e(money($p['amount'])) ?></strong></td>
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
                                <form method="POST" action="<?= e(url('modules/purchasing/payments.php')) ?>" class="inline-form"
                                      data-confirm="Reverse this payment of <?= e(money($p['amount'])) ?> on <?= e($p['po_number']) ?>? The order's payment status and the cash book both change.">
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

    <?php pagination_nav($pg, $baseQuery, 'payment'); ?>
</div>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
