<?php

/**
 * ============================================================
 *  Invoices — List
 * ------------------------------------------------------------
 *  Server-side-paginated invoice list with search and status
 *  filtering. Invoices are created by converting proformas.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/export.php';
require_login();
require_once __DIR__ . '/../../includes/workflow.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_tables('invoices', 'invoice_items');

$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$delivery = input($_GET, 'delivery');
$from    = input($_GET, 'from');
$to      = input($_GET, 'to');
$custId  = input_int($_GET, 'customer_id');
$payment = input($_GET, 'payment');
$perPage = per_page();

/**
 * Payment state, measured from the money rather than the status.
 *
 * `status` can be nudged by hand; `balance_due` cannot — it is
 * generated from the receipts, the credit notes and the refunds
 * (migration 028). Filtering on it means "outstanding" is always
 * true of what it selects.
 *
 * These used to read `total_amount - amount_paid`, which is blind
 * to credit notes, so an invoice with an item returned went on
 * being called outstanding after it had settled.
 */
const INVOICE_PAYMENT_FILTERS = [
    // Plain words. "Settled in full" is what an accountant calls it
    // and it is what this said, so nobody looking for "fully paid"
    // found it — the filter was here all along and read as missing.
    'outstanding' => ['label' => 'Not fully paid (anything owed)',
                      'sql'   => "i.balance_due > 0.005"],
    'unpaid'      => ['label' => 'Not paid at all',
                      'sql'   => "i.amount_paid <= 0.005 AND i.balance_due > 0.005"],
    'partial'     => ['label' => 'Partly paid',
                      'sql'   => "i.amount_paid > 0.005 AND i.balance_due > 0.005"],
    'settled'     => ['label' => 'Fully paid',
                      'sql'   => "i.balance_due <= 0.005"],
];

// Only accept well-formed dates so a stray value can never reach the
// query, and swap them if the user picks the range back to front.
$isDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from   = $isDate($from) ? $from : '';
$to     = $isDate($to) ? $to : '';
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(i.invoice_number) LIKE :q
                 OR LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if (in_array($status, INVOICE_STATUSES, true)) {
    $where[] = "i.status = :status";
    $params[':status'] = $status;
} else {
    $status = '';
}
if (isset(INVOICE_PAYMENT_FILTERS[$payment])) {
    // A cancelled or draft invoice is owed by nobody, so it never
    // belongs in a payment-state answer.
    $where[] = '(' . INVOICE_PAYMENT_FILTERS[$payment]['sql']
             . " AND i.status NOT IN ('cancelled', 'draft'))";
} else {
    $payment = '';
}
if (in_array($delivery, DELIVERY_STATES, true)) {
    $where[] = "i.delivery_status = :dstat";
    $params[':dstat'] = $delivery;
} else {
    $delivery = '';
}
if ($from !== '') {
    $where[] = "i.issue_date >= :from";
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[] = "i.issue_date <= :to";
    $params[':to'] = $to;
}
if ($custId !== null) {
    $where[] = "i.customer_id = :cust";
    $params[':cust'] = $custId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$filtersActive = $search !== '' || $status !== '' || $payment !== '' || $delivery !== ''
    || $from !== '' || $to !== '' || $custId !== null;

if (wants_export()) {
    export_deliver(export_filename('invoices'), [[
        'name'  => 'Invoices',
        'title' => 'Invoices',
        'meta'  => export_meta([
            'Period'   => ($from !== '' || $to !== '')
                ? (($from ?: 'the beginning') . ' to ' . ($to ?: 'today')) : 'all dates',
            'Status'   => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'any',
            'Payment'  => $payment !== '' ? ucwords(str_replace('_', ' ', $payment)) : 'any',
            'Delivery' => $delivery !== '' ? ucwords(str_replace('_', ' ', $delivery)) : 'any',
            'Search'   => $search,
        ]),
        'columns' => [
            ['label' => 'Invoice',   'key' => 'invoice_number'],
            ['label' => 'Customer',  'key' => 'customer_name'],
            ['label' => 'Issued',    'key' => 'issue_date',  'type' => 'date'],
            ['label' => 'Due',       'key' => 'due_date',    'type' => 'date'],
            ['label' => 'Status',    'key' => 'status',      'value' => fn($r) => ucwords(str_replace('_',' ', (string) $r['status']))],
            ['label' => 'Delivery',  'key' => 'delivery_status', 'value' => fn($r) => ucwords(str_replace('_',' ', (string) $r['delivery_status']))],
            ['label' => 'Total',     'key' => 'total_amount', 'type' => 'money'],
            ['label' => 'Paid',      'key' => 'amount_paid',  'type' => 'money'],
            ['label' => 'Balance',   'key' => 'balance_due',  'type' => 'money'],
        ],
        'rows'  => db_all(
            "SELECT i.*, pi.pi_number,
                    " . customer_name_sql('c') . " AS customer_name
               FROM invoices i
               LEFT JOIN customers c ON c.customer_id = i.customer_id
               LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
               $whereSql
              ORDER BY i.issue_date DESC, i.invoice_id DESC",
            $params
        ),
        'total' => true,
    ]]);
}

$total = (int) db_value(
    "SELECT COUNT(*) FROM invoices i LEFT JOIN customers c ON c.customer_id = i.customer_id $whereSql",
    $params
);
$pg = paginate($total, $perPage);

// Value of the whole filtered set (not just the current page), so the
// figures here reconcile with the dashboard's sales cards.
$filteredValue = (float) db_value(
    "SELECT COALESCE(SUM(i.total_amount),0)
     FROM invoices i LEFT JOIN customers c ON c.customer_id = i.customer_id
     $whereSql" . ($whereSql ? ' AND' : ' WHERE') . " i.status NOT IN ('cancelled','draft')",
    $params
);

// GREATEST, because a negative balance is a refund we owe, not a
// receivable — adding it in would understate what is outstanding.
$filteredOutstanding = (float) db_value(
    "SELECT COALESCE(SUM(GREATEST(i.balance_due, 0)),0)
     FROM invoices i LEFT JOIN customers c ON c.customer_id = i.customer_id
     $whereSql" . ($whereSql ? ' AND' : ' WHERE') . " i.status NOT IN ('cancelled','draft')",
    $params
);

$invoices = db_all(
    "SELECT i.*, pi.pi_number,
            " . customer_name_sql('c') . " AS customer,
            -- Anything still to send: invoiced, less delivered, less
            -- credited. An invoice with nothing outstanding is not
            -- offered a delivery note, whether that is because it all
            -- went out or because it was all credited away.
            --
            -- Written as a LATERAL rather than the EXISTS it obviously
            -- wants to be, and the difference is 200-fold. PostgreSQL
            -- plans a correlated EXISTS before it knows a LIMIT 10 is
            -- coming, decides hashing the whole subquery is cheaper
            -- than looping, and sequentially scans all 300,000 invoice
            -- lines to draw ten rows. LATERAL says loop, so it probes
            -- ten times instead:
            --
            --     EXISTS, no index        166ms
            --     EXISTS, partial index    84ms
            --     LATERAL, no index        54ms
            --     LATERAL, partial index  0.8ms
            --
            -- The index is ix_invoice_items_undelivered (migration
            -- 052) and it only earns its keep with this form.
            (o.x IS NOT NULL) AS has_outstanding
     FROM invoices i
     LEFT JOIN customers c ON c.customer_id = i.customer_id
     LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
     LEFT JOIN LATERAL (
            SELECT 1 AS x FROM invoice_items ii
             WHERE ii.invoice_id = i.invoice_id
               AND ii.quantity - ii.delivered_quantity - ii.credited_quantity > 0.0005
             LIMIT 1
     ) o ON TRUE
     $whereSql
     ORDER BY i.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Accounts a receipt can be paid into, and any advances the
// customer has already paid, for the record-payment modal.
$payAccounts = table_exists('cash_accounts') ? cash_liquid_accounts() : [];
$openAdvances = table_exists('customer_advances') ? db_all(
    "SELECT a.advance_id, a.advance_number, a.customer_id,
            a.amount - COALESCE(u.used, 0) AS remaining
       FROM customer_advances a
       LEFT JOIN (
            SELECT from_advance_id, SUM(amount) AS used
              FROM invoice_payments WHERE from_advance_id IS NOT NULL
             GROUP BY from_advance_id
       ) u ON u.from_advance_id = a.advance_id
      WHERE a.status = 'open'
        AND a.amount - COALESCE(u.used, 0) > 0
      ORDER BY a.received_on"
) : [];

$pageTitle    = 'Invoices';
$pageSubtitle = 'All issued invoices';
$pageStyles   = ['forms.css', 'finance.css'];
$pageScripts  = ['invoice-payment.js'];
$breadcrumbs  = [['label' => 'Invoices']];
$canCancel    = is_admin() || user_has_role(ROLE_MANAGER);
$canRecordPay = is_admin() || user_has_role(ROLE_MANAGER);

require __DIR__ . '/../../includes/header.php';
$baseQuery = array_filter([
    'q'           => $search,
    'status'      => $status,
    'payment'     => $payment,
    'delivery'    => $delivery,
    'from'        => $from,
    'to'          => $to,
    'customer_id' => $custId,
], fn($v) => $v !== '' && $v !== null);

// Preset ranges mirroring the dashboard's sales cards.
$presets = [
    'Today'      => [date('Y-m-d'), date('Y-m-d')],
    'This week'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'This year'  => [date('Y-01-01'), date('Y-m-d')],
];
?>

<form class="filter-bar" method="GET" action="<?= e(url('invoices/index.php')) ?>">
    <div class="filter-row">
        <div class="toolbar-search filter-grow">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="Search number or customer…">
        </div>
        <a href="<?= e(url('proformas/index.php')) ?>" class="btn btn-ghost"><?= icon('file') ?> Proformas</a>
    </div>

    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">Customer</span>
            <?php lookup_select('invoiced_customers', 'customer_id', $custId, [
                'prompt' => 'All customers',
                'label'  => 'Search customers',
                'empty'  => 'No customer matches that.',
            ]); ?>
        </label>
        <label class="filter-field">
            <span class="filter-label">Payment</span>
            <select name="payment" class="form-control">
                <option value="">Any payment state</option>
                <?php foreach (INVOICE_PAYMENT_FILTERS as $key => $meta): ?>
                    <option value="<?= e($key) ?>" <?= $payment === $key ? 'selected' : '' ?>>
                        <?= e($meta['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">Status</span>
            <select name="status" class="form-control">
                <option value="">Any status</option>
                <?php foreach (INVOICE_STATUSES as $st): ?>
                    <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">Delivery</span>
            <select name="delivery" class="form-control">
                <option value="">Any delivery</option>
                <?php foreach (DELIVERY_STATES as $ds): ?>
                    <option value="<?= e($ds) ?>" <?= $delivery === $ds ? 'selected' : '' ?>><?= e(status_label($ds)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Filter</button>
            <?= export_button('invoices/index.php', $baseQuery) ?>
            <?php if ($filtersActive): ?>
                <a href="<?= e(url('invoices/index.php')) ?>" class="btn btn-ghost"><?= icon('x') ?> Clear</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="filter-row filter-row--presets">
        <span class="filter-label">Quick range</span>
        <?php foreach ($presets as $label => [$pFrom, $pTo]): ?>
            <?php $isOn = $from === $pFrom && $to === $pTo; ?>
            <a class="chip <?= $isOn ? 'chip--on' : '' ?>"
               href="?<?= e(http_build_query(array_filter([
                        'q' => $search, 'status' => $status, 'delivery' => $delivery,
                        'customer_id' => $custId, 'from' => $pFrom, 'to' => $pTo,
                    ], fn($v) => $v !== '' && $v !== null))) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</form>

<?php if ($filtersActive): ?>
    <p class="filter-summary">
        <strong><?= e(num($total)) ?></strong> invoice<?= $total === 1 ? '' : 's' ?> matched
        · total <strong><?= e(money($filteredValue)) ?></strong>
        <?php if ($filteredOutstanding > 0.005): ?>
            · outstanding <strong class="tone-out"><?= e(money($filteredOutstanding)) ?></strong>
        <?php endif; ?>
        <span class="filter-summary-note">(excludes cancelled and draft)</span>
    </p>
<?php endif; ?>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Customer</th>
                    <th>From Proforma</th>
                    <th>Issued</th>
                    <th>Due</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th class="ta-right">Total</th>
                    <th class="ta-right">Paid</th>
                    <th class="ta-right">Balance</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$invoices): ?>
                    <tr><td colspan="11" class="table-empty">No invoices yet. Convert a proforma or sales order to create one.</td></tr>
                <?php endif; ?>
                <?php foreach ($invoices as $inv):
                    $paid    = (float) $inv['amount_paid'];
                    // Straight off the row: credits and refunds are
                    // already in it (migration 028). Never total - paid.
                    $balance = (float) $inv['balance_due'];
                    // A receipt only makes sense on a live invoice that
                    // is not already settled.
                    $payable = $canRecordPay
                        && !in_array($inv['status'], ['draft', 'cancelled'], true)
                        && $balance > 0.005;
                    $payFill = json_encode([
                        'invoice_id'   => $inv['invoice_id'],
                        'amount'       => number_format($balance, 2, '.', ''),
                        'payment_date' => date('Y-m-d'),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($inv['invoice_number']) ?></span></td>
                        <td><?= e($inv['customer']) ?></td>
                        <td class="cell-muted"><?= e($inv['pi_number'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($inv['issue_date'])) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($inv['due_date'])) ?></td>
                        <td>
                            <span class="badge badge--<?= e(status_tone($inv['status'])) ?>"><?= e(status_label($inv['status'])) ?></span>
                            <?php // "Paid" on its own does not say whether the money came
                                  // in or the goods went back. An invoice settled by a
                                  // return is settled, but nobody paid for it.
                                  if (($inv['credit_status'] ?? 'none') !== 'none'): ?>
                                <span class="badge badge--warn"
                                      title="<?= $inv['credit_status'] === 'credited'
                                          ? 'Credited in full — the sale was undone'
                                          : 'Part of this invoice has been credited back' ?>">
                                    <?= $inv['credit_status'] === 'credited' ? 'Credited' : 'Part credited' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge--<?= e(status_tone($inv['delivery_status'])) ?>"><?= e(status_label($inv['delivery_status'])) ?></span></td>
                        <td class="ta-right"><strong><?= e(money($inv['total_amount'])) ?></strong></td>
                        <td class="ta-right <?= $paid > 0 ? 'tone-in' : 'cell-muted' ?>"><?= e(money($paid)) ?></td>
                        <td class="ta-right">
                            <?php if ($balance > 0.005): ?>
                                <strong class="tone-out"><?= e(money($balance)) ?></strong>
                            <?php else: ?>
                                <span class="cell-muted">Settled</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($payable): ?>
                                    <button type="button" class="icon-btn" title="Record payment"
                                            data-modal-open="tplRecordPayment"
                                            data-modal-title="Record payment · <?= e($inv['invoice_number']) ?>"
                                            data-prefill="<?= e($payFill) ?>"><?= icon('money') ?></button>
                                <?php endif; ?>
                                <?php if ($paid > 0): ?>
                                    <a href="<?= e(url('invoices/payments.php?invoice=' . (int) $inv['invoice_id'])) ?>"
                                       class="icon-btn" title="Payment history"><?= icon('report') ?></a>
                                <?php endif; ?>
                                <form method="POST" action="<?= e(url('invoices/status.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="invoice_id" value="<?= (int) $inv['invoice_id'] ?>">
                                    <select name="status" class="form-control status-select" data-autosubmit
                                            aria-label="Change status of <?= e($inv['invoice_number']) ?>">
                                        <option value="<?= e($inv['status']) ?>" selected><?= e(status_label($inv['status'])) ?></option>
                                        <?php foreach (allowed_transitions('invoice', $inv['status']) as $st): ?>
                                            <?php if ($st === 'cancelled' && !$canCancel) continue; ?>
                                            <option value="<?= e($st) ?>"><?= e(status_label($st)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <a href="<?= e(url('invoices/view.php?id=' . (int) $inv['invoice_id'])) ?>" class="icon-btn" title="View / print"><?= icon('file') ?></a>
                                <?php /*  Straight from the list, because that is where
                                          somebody stands when a customer asks for a
                                          slip — one tap rather than opening the
                                          invoice first. */ ?>
                                <a href="<?= e(url('invoices/receipt.php?id=' . (int) $inv['invoice_id'])) ?>"
                                   target="_blank" rel="noopener"
                                   class="icon-btn" title="Print till receipt"><?= icon('invoice') ?></a>
                                <a href="<?= e(url('invoices/pdf.php?id=' . (int) $inv['invoice_id'] . '&dl=1')) ?>" class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                                <?php if (!in_array($inv['status'], ['cancelled','draft'], true) && !empty($inv['has_outstanding'])): ?>
                                    <a href="<?= e(url('delivery_notes/create.php?source=invoice&id=' . (int) $inv['invoice_id'])) ?>"
                                       class="icon-btn" title="Create delivery note"><?= icon('truck') ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'invoice'); ?>
</div>

<?php if ($canRecordPay): ?>
<!-- Record a receipt against an invoice -->
<template id="tplRecordPayment">
    <form method="POST" action="<?= e(url('invoices/payments.php')) ?>" data-ajax
          enctype="multipart/form-data" data-invoice-payment>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="record">
        <input type="hidden" name="invoice_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Amount received (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
                <p class="form-hint">
                    Pre-filled with the outstanding balance. Enter less for a part
                    payment — the remainder stays under trade receivables.
                </p>
            </div>
            <div class="form-group">
                <label class="form-label">Payment date <span class="req">*</span></label>
                <input type="date" name="payment_date" class="form-control" required max="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Where the money came from</label>
            <select name="source" class="form-control" data-payment-source>
                <option value="direct">New payment into an account</option>
                <option value="advance"<?= $openAdvances ? '' : ' disabled' ?>>
                    Apply an advance the customer already paid<?= $openAdvances ? '' : ' — none available' ?>
                </option>
            </select>
        </div>

        <div class="form-grid-2" data-payment-direct>
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
                <select name="cash_account_id" class="form-control" data-searchable="Search accounts">
                    <option value="">— Select account —</option>
                    <?php foreach ($payAccounts as $a): ?>
                        <option value="<?= (int) $a['cash_account_id'] ?>">
                            <?= e($a['name']) ?> · <?= e(cash_account_type_label($a['account_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group" data-payment-advance hidden>
            <label class="form-label">Advance to apply <span class="req">*</span></label>
            <select name="from_advance_id" class="form-control" data-searchable="Search advances">
                <option value="">— Select advance —</option>
                <?php foreach ($openAdvances as $adv): ?>
                    <option value="<?= (int) $adv['advance_id'] ?>" data-customer="<?= (int) $adv['customer_id'] ?>">
                        <?= e($adv['advance_number']) ?> · <?= e(money($adv['remaining'])) ?> remaining
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">
                No new money moves: the cash arrived when the advance was received.
                An advance can only settle its own customer's invoices.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Reference <span class="opt">(transaction code, cheque or slip number)</span></label>
            <input type="text" name="reference" class="form-control" maxlength="160" placeholder="e.g. SJK7YT2P9Q">
        </div>

        <div class="form-group">
            <label class="form-label">Proof of payment <span class="opt">(image or PDF, up to 5 MB)</span></label>
            <input type="file" name="proof" class="form-control" accept="image/*,application/pdf">
            <p class="form-hint">
                The M-Pesa message, bank slip or statement page. This is the first
                thing asked for when a payment is disputed.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record payment</button>
        </div>
    </form>
</template>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
