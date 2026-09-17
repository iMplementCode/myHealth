<?php

/**
 * ============================================================
 *  Aged Payables
 * ------------------------------------------------------------
 *  The mirror of the receivables report: what is owed to
 *  suppliers for goods already received, aged from the day
 *  they arrived.
 *
 *  An order placed but not delivered does not appear, however
 *  large. You owe from delivery, not from ordering — until the
 *  goods turn up it is a commitment, not a debt.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_once __DIR__ . '/../../includes/purchasing.php';
require_role(ROLE_MANAGER);
require_tables('purchase_orders', 'purchase_payments', 'grns');

$asOf = input($_GET, 'as_of');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = date('Y-m-d');
}

$rows    = report_payables($asOf);
$totals  = report_ageing_totals($rows);

// A "Returned" column on every row of a business that has never sent
// anything back is a column of dashes. It earns its place only when
// there is something in it.
$returnedTotal = array_sum(array_map(fn($r) => (float) ($r['debited'] ?? 0), $rows));
$anyReturns    = $returnedTotal > 0.005;

// The other side of the same coin: orders paid ahead of delivery.
// They are an asset, not a debt, so they are kept out of the ageing
// above — but they are the figure people query on the daily
// position, so the report has to be able to show its working.
// Arriving from the dashboard's "Paid For, Goods Not Yet In" card
// means you came for one thing. Showing the ageing tables first and
// the answer half a page down is not what was clicked.
$prepaidOnly  = input($_GET, 'view') === 'prepayments';
$prepaid      = report_supplier_prepayments($asOf);
$prepaidTotal = array_sum(array_map(fn($r) => (float) $r['prepaid'], $prepaid));

// One line per supplier, which is how payment runs are decided.
$bySupplier = [];
foreach ($rows as $r) {
    $id = (int) $r['supplier_id'];
    if (!isset($bySupplier[$id])) {
        $bySupplier[$id] = [
            // Kept as a value, not only as the key: array_values below
            // throws the keys away, and the statement link needs it.
            'supplier_id' => $id,
            'name' => $r['supplier_name'] ?: 'Supplier', 'orders' => 0,
            'total' => 0.0, 'oldest' => 0,
        ];
    }
    $bySupplier[$id]['orders']++;
    $bySupplier[$id]['total']  += (float) $r['balance'];
    $bySupplier[$id]['oldest']  = max($bySupplier[$id]['oldest'], (int) $r['days_overdue']);
}
uasort($bySupplier, fn($a, $b) => $b['total'] <=> $a['total']);
$bySupplier = array_values($bySupplier);

// Bucket totals and the cards cover the whole ledger; only the
// rows on screen are paged.
[$pgSup, $pageSups] = paginate_rows($bySupplier, per_page());
[$pgPo,  $pagePos]  = paginate_rows($rows, per_page(), (int) ($_GET['po_page'] ?? 1));
$baseQuery = array_filter([
    'as_of' => $asOf,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

if (wants_export()) {
    export_deliver(export_filename('aged-payables'), [
        [
            'name'  => 'By supplier',
            'title' => 'Aged payables as at ' . fmt_date($asOf, 'd F Y'),
            'meta'  => export_meta(['As at' => $asOf, 'Total owed' => money($totals['total'])]),
            'columns' => [
                ['label' => 'Supplier', 'key' => 'name'],
                ['label' => 'Orders',   'key' => 'orders', 'type' => 'integer'],
                ['label' => 'Oldest receipt (days)', 'key' => 'oldest', 'type' => 'integer', 'total' => false],
                ['label' => 'Owed',     'key' => 'total',  'type' => 'money'],
            ],
            'rows'  => $bySupplier,
            'total' => true,
        ],
        [
            'name'  => 'By order',
            'columns' => [
                ['label' => 'Order',        'key' => 'po_number'],
                ['label' => 'Supplier',     'key' => 'supplier_name'],
                ['label' => 'Ordered',      'key' => 'issue_date',   'type' => 'date'],
                ['label' => 'Last receipt', 'key' => 'last_receipt', 'type' => 'date'],
                ['label' => 'Days',         'key' => 'days_overdue', 'type' => 'integer', 'total' => false],
                ['label' => 'Order total',  'key' => 'total_amount', 'type' => 'money'],
                ['label' => 'Received',     'key' => 'received',     'type' => 'money'],
                ['label' => 'Returned',     'key' => 'debited',      'type' => 'money'],
                ['label' => 'Paid',         'key' => 'paid',         'type' => 'money'],
                ['label' => 'Owed',         'key' => 'balance',      'type' => 'money'],
            ],
            'rows'  => $rows,
            'total' => true,
        ],
        [
            'name'  => 'Prepaid orders',
            'title' => 'Supplier prepayments as at ' . fmt_date($asOf, 'd F Y'),
            'meta'  => export_meta(['As at' => $asOf, 'Total prepaid' => money($prepaidTotal)]),
            'columns' => [
                ['label' => 'Order',       'key' => 'po_number'],
                ['label' => 'Supplier',    'key' => 'supplier_name'],
                ['label' => 'Ordered',     'key' => 'issue_date',   'type' => 'date'],
                ['label' => 'Last paid',   'key' => 'last_payment', 'type' => 'date'],
                ['label' => 'Waiting (days)', 'key' => 'days_waiting', 'type' => 'integer', 'total' => false],
                ['label' => 'Order total', 'key' => 'ordered',      'type' => 'money'],
                ['label' => 'Paid',        'key' => 'paid',         'type' => 'money'],
                ['label' => 'Goods in',    'key' => 'received',     'type' => 'money'],
                ['label' => 'Goods notes', 'key' => 'grn_count',    'type' => 'integer', 'total' => false],
                ['label' => 'Prepaid',     'key' => 'prepaid',      'type' => 'money'],
            ],
            'rows'  => $prepaid,
            'total' => true,
        ],
    ]);
}

$pageTitle    = $prepaidOnly ? 'Supplier Prepayments' : 'Aged Payables';
$pageSubtitle = ($prepaidOnly ? 'Paid for, goods not yet in — as at ' : 'As at ')
              . fmt_date($asOf, 'd F Y');
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Aged Payables']];

require __DIR__ . '/../../includes/header.php';
?>

<form class="filter-bar" method="GET" action="<?= e(url('reports/payables.php')) ?>">
    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">As at</span>
            <input type="date" name="as_of" value="<?= e($asOf) ?>" class="form-control" max="<?= e(date('Y-m-d')) ?>">
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Apply</button>
            <?= export_button('reports/payables.php', $baseQuery) ?>
            <button type="button" class="btn btn-ghost" data-print><?= icon('file') ?> Print</button>
            <a class="btn btn-ghost" href="<?= e(url('modules/purchasing/payments.php')) ?>"><?= icon('money') ?> Pay a supplier</a>
            <a class="btn btn-ghost" href="<?= e(url('reports/index.php')) ?>"><?= icon('grid') ?> All reports</a>
        </div>
    </div>
</form>

<?php if (!$prepaidOnly): ?>
<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">Owed to suppliers</span>
        <span class="mini-value"><?= e(money($totals['total'])) ?></span>
        <span class="mini-note"><?= e(num(count($rows))) ?> order<?= count($rows) === 1 ? '' : 's' ?>, <?= e(num(count($bySupplier))) ?> supplier<?= count($bySupplier) === 1 ? '' : 's' ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Over 30 days</span>
        <span class="mini-value"><?= e(money($totals['d60'] + $totals['d90'] + $totals['older'])) ?></span>
        <span class="mini-note">since the goods arrived</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Over 90 days</span>
        <span class="mini-value <?= $totals['older'] > 0.005 ? 'tone-out' : '' ?>"><?= e(money($totals['older'])) ?></span>
        <span class="mini-note">long overdue</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Within 30 days</span>
        <span class="mini-value"><?= e(money($totals['current'] + $totals['d30'])) ?></span>
        <span class="mini-note">recently received</span>
    </div>
</section>

<div class="panel">
    <h2 class="panel-title u-pad">By supplier</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Orders</th>
                    <th>Oldest receipt</th>
                    <th class="ta-right">Owed</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$bySupplier): ?>
                    <tr><td colspan="5" class="table-empty">Nothing owed. Every receipt is paid for.</td></tr>
                <?php endif; ?>
                <?php foreach ($pageSups as $s): ?>
                    <tr>
                        <td><span class="cell-title"><?= e($s['name']) ?></span></td>
                        <td class="cell-muted"><?= e(num($s['orders'])) ?></td>
                        <td class="cell-muted"><?= $s['oldest'] > 0 ? e(num($s['oldest'])) . ' days ago' : 'today' ?></td>
                        <td class="ta-right"><strong><?= e(money($s['total'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a class="icon-btn" title="Statement of account"
                                   href="<?= e(url('reports/supplier_statement.php?supplier=' . (int) $s['supplier_id'] . '&to=' . $asOf)) ?>"><?= icon('file') ?></a>
                                <a class="icon-btn" title="Pay this supplier"
                                   href="<?= e(url('modules/purchasing/payments.php?q=' . urlencode((string) $s['name']))) ?>"><?= icon('money') ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php pagination_nav($pgSup, $baseQuery, 'supplier'); ?>
    </div>
</div>

<div class="panel">
    <h2 class="panel-title u-pad">By purchase order</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Supplier</th>
                    <th>Last receipt</th>
                    <th>Age</th>
                    <th class="ta-right">Received</th>
                    <?php if ($anyReturns): ?><th class="ta-right">Returned</th><?php endif; ?>
                    <th class="ta-right">Paid</th>
                    <th class="ta-right">Owed</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= $anyReturns ? 8 : 7 ?>" class="table-empty">Nothing owed.</td></tr>
                <?php endif; ?>
                <?php foreach ($pagePos as $r):
                    $days   = (int) $r['days_overdue'];
                    $bucket = ageing_bucket($days);
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($r['po_number']) ?></span></td>
                        <td><?= e($r['supplier_name'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($r['last_receipt'])) ?></td>
                        <td>
                            <span class="badge badge--<?= $bucket === 'current' ? 'active' : ($bucket === 'older' ? 'inactive' : 'pending') ?>">
                                <?= $days > 0 ? e(num($days)) . ' days' : 'today' ?>
                            </span>
                        </td>
                        <td class="ta-right cell-muted"><?= e(money($r['received'])) ?></td>
                        <?php if ($anyReturns): ?>
                            <td class="ta-right <?= (float) $r['debited'] > 0.005 ? 'tone-in' : 'cell-muted' ?>">
                                <?= (float) $r['debited'] > 0.005 ? e(money($r['debited'])) : '—' ?>
                            </td>
                        <?php endif; ?>
                        <td class="ta-right cell-muted"><?= e(money($r['paid'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($r['balance'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="<?= $anyReturns ? 7 : 6 ?>">Total owed to suppliers <span class="cell-muted">every page</span></th>
                        <td class="ta-right"><strong><?= e(money($totals['total'])) ?></strong></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php // A second table on one page needs its own page key,
              // or paging one would silently page the other. ?>
        <?php pagination_nav($pgPo, $baseQuery + ['page' => $pgSup['page']], 'order', 'po_page'); ?>
    </div>
</div>

<p class="form-hint u-pad">
    <strong>Owed = received − returned − paid</strong>, order by order. Age runs
    from the most recent delivery on the order, not from the order date, because
    that is when the debt was actually incurred. Goods sent back on a
    <a href="<?= e(url('modules/purchasing/list_debit_notes.php')) ?>">debit note</a>
    come off what is owed the moment the note is raised — that is when the stock
    leaves the shelf, and you do not owe for goods you no longer hold. An order
    paid ahead of delivery is not a debt at all — it is a prepayment, and it is
    listed below.
</p>
<?php else: ?>
    <p class="form-hint u-pad">
        Money already paid to suppliers with no goods against it yet — the
        <strong>Supplier prepayments</strong> figure on the daily position, order
        by order. <a href="<?= e(url('reports/payables.php?as_of=' . $asOf)) ?>">See
        what is owed to suppliers instead</a>.
    </p>
<?php endif; ?>

<?php // ── Supplier prepayments ──────────────────────────────
      // The figure people query on the daily position, with its
      // working shown. ?>
<div class="panel" id="prepayments">
    <div class="panel-head">
        <h2>Supplier prepayments <span class="cell-muted">paid, goods not yet in</span></h2>
        <strong><?= e(money($prepaidTotal)) ?></strong>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Supplier</th>
                    <th>Last paid</th>
                    <th class="ta-right">Waiting</th>
                    <th class="ta-right">Order total</th>
                    <th class="ta-right">Paid</th>
                    <th class="ta-right">Goods in</th>
                    <th class="ta-right">Prepaid</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$prepaid): ?>
                    <tr><td colspan="9" class="table-empty">
                        Nothing is paid ahead of delivery. Every shilling paid to a
                        supplier has goods received against it.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($prepaid as $r):
                    $days    = (int) $r['days_waiting'];
                    $noGoods = (int) $r['grn_count'] === 0;
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($r['po_number']) ?></span></td>
                        <td><?= e($r['supplier_name'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($r['last_payment'])) ?></td>
                        <td class="ta-right">
                            <span class="badge badge--<?= $days > 30 ? 'inactive' : ($days > 7 ? 'pending' : 'active') ?>">
                                <?= $days > 0 ? e(num($days)) . ' days' : 'today' ?>
                            </span>
                        </td>
                        <td class="ta-right cell-muted"><?= e(money($r['ordered'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(money($r['paid'])) ?></td>
                        <td class="ta-right <?= $noGoods ? 'tone-out' : 'cell-muted' ?>">
                            <?php // No goods note at all is the usual reason this
                                  // figure looks too big: the stock arrived and
                                  // nobody booked it in. ?>
                            <?= $noGoods ? 'nothing received' : e(money($r['received'])) ?>
                        </td>
                        <td class="ta-right"><strong><?= e(money($r['prepaid'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a class="icon-btn" title="Book the goods in against this order"
                                   href="<?= e(url('modules/purchasing/manage_grns.php?q=' . urlencode((string) $r['po_number']))) ?>"><?= icon('truck') ?></a>
                                <a class="icon-btn" title="Open the order"
                                   href="<?= e(url('modules/purchasing/manage_pos.php?q=' . urlencode((string) $r['po_number']))) ?>"><?= icon('file') ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($prepaid): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="7">Total prepaid to suppliers</th>
                        <td class="ta-right"><strong><?= e(money($prepaidTotal)) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<p class="form-hint u-pad">
    <strong>Prepaid = paid − goods received</strong>, order by order, where that
    is positive. "Goods in" is the value of the goods received notes against the
    order, not the order's own total: pay 100,000 against a 100,000 order and
    you are still prepaid 60,000 until 100,000 of goods have been booked in.
    <strong>An order reading "nothing received" when the stock is on your shelf
    means the goods received note was never raised</strong> — that is the usual
    reason this figure looks larger than it should, and raising the note clears
    it.
</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
