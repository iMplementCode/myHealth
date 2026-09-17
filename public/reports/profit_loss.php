<?php

/**
 * ============================================================
 *  Profit and Loss
 * ------------------------------------------------------------
 *  Revenue less what the goods cost, less what it cost to run
 *  the place. The report the spreadsheet was reaching for when
 *  it totalled sales against purchases and expenses — but for a
 *  period, and adding up by construction.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);

['from' => $from, 'to' => $to] = report_period($_GET);
$pl = report_profit_and_loss($from, $to);

if (wants_export()) {
    $plRows = [
        ['item' => 'Sales invoiced (excluding VAT)', 'amount' => $pl['revenue']['invoiced']],
        ['item' => 'less credit notes',              'amount' => -$pl['revenue']['credited']],
        ['item' => 'Revenue',                        'amount' => $pl['revenue']['net']],
        ['item' => 'Cost of sales',                  'amount' => -$pl['cost_of_sales']],
        ['item' => 'Gross profit',                   'amount' => $pl['gross_profit']],
    ];
    foreach ($pl['expenses'] as $e) {
        $plRows[] = ['item' => '  ' . $e['category'], 'amount' => -(float) $e['amount']];
    }
    $plRows[] = ['item' => 'Total expenses', 'amount' => -$pl['expenses_total']];
    $plRows[] = ['item' => $pl['net_profit'] >= 0 ? 'Net profit' : 'Net loss',
                 'amount' => $pl['net_profit']];

    export_deliver(export_filename('profit-and-loss'), [[
        'name'  => 'Profit and loss',
        'title' => 'Profit and loss — ' . report_period_label($from, $to),
        'meta'  => export_meta([
            'Period'         => report_period_label($from, $to),
            'Goods received' => money($pl['goods_received']),
            'Note'           => (function () use ($from, $to) {
                $est = report_cost_of_sales_estimated($from, $to);
                return $est > 0
                    ? sprintf('Cost of sales is what the goods cost on the day they were '
                        . 'invoiced. %d line(s) predate that record and use today\'s cost price.', $est)
                    : 'Cost of sales is what the goods cost on the day they were invoiced.';
            })(),
        ]),
        'columns' => [
            ['label' => 'Item',   'key' => 'item'],
            ['label' => 'Amount', 'key' => 'amount', 'type' => 'money', 'total' => false],
        ],
        'rows' => $plRows,
    ]]);
}

$pageTitle    = 'Profit and Loss';
$pageSubtitle = report_period_label($from, $to);
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Profit and Loss']];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_period_bar.php';
?>

<div class="position-grid">
    <table class="position-table">
        <tbody>
            <tr class="position-head"><th colspan="2">Trading</th></tr>
            <tr>
                <th>Sales invoiced <span class="cell-muted">excluding VAT</span></th>
                <td class="ta-right"><?= e(money($pl['revenue']['invoiced'])) ?></td>
            </tr>
            <?php if ($pl['revenue']['credited'] > 0.005): ?>
                <tr class="position-sub">
                    <th>less credit notes</th>
                    <td class="ta-right">(<?= e(money($pl['revenue']['credited'])) ?>)</td>
                </tr>
            <?php endif; ?>
            <tr class="position-subtotal">
                <th>Revenue</th>
                <td class="ta-right"><?= e(money($pl['revenue']['net'])) ?></td>
            </tr>
            <tr>
                <th>Cost of sales <span class="cell-muted">goods sold, at cost</span></th>
                <td class="ta-right">(<?= e(money($pl['cost_of_sales'])) ?>)</td>
            </tr>
            <tr class="position-highlight">
                <th>Gross profit</th>
                <td class="ta-right">
                    <?= e(money($pl['gross_profit'])) ?>
                    <?php if ($pl['revenue']['net'] > 0.005): ?>
                        <span class="cell-muted"><?= e(num($pl['gross_margin'], 1)) ?>%</span>
                    <?php endif; ?>
                </td>
            </tr>

            <tr class="position-head"><th colspan="2">Operating expenses</th></tr>
            <?php if (!$pl['expenses']): ?>
                <tr><td colspan="2" class="table-empty">No expenses recorded in this period.</td></tr>
            <?php endif; ?>
            <?php foreach ($pl['expenses'] as $e): ?>
                <tr>
                    <th><?= e($e['category']) ?> <span class="cell-muted"><?= e(num($e['entries'])) ?></span></th>
                    <td class="ta-right"><?= e(money($e['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="position-subtotal">
                <th>Total expenses</th>
                <td class="ta-right">(<?= e(money($pl['expenses_total'])) ?>)</td>
            </tr>

            <tr class="position-valuation">
                <th><?= $pl['net_profit'] >= 0 ? 'Net profit' : 'Net loss' ?></th>
                <td class="ta-right <?= $pl['net_profit'] >= 0 ? 'tone-in' : 'tone-out' ?>">
                    <?= e(money(abs($pl['net_profit']))) ?>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="position-table">
        <tbody>
            <tr class="position-head"><th colspan="2">Stock bought in the period</th></tr>
            <tr>
                <th>Goods received <span class="cell-muted">from GRNs, at cost</span></th>
                <td class="ta-right"><?= e(money($pl['goods_received'])) ?></td>
            </tr>
            <tr>
                <th>Cost of what was sold</th>
                <td class="ta-right"><?= e(money($pl['cost_of_sales'])) ?></td>
            </tr>
            <tr class="position-subtotal">
                <th>Added to (taken from) stock</th>
                <td class="ta-right"><?= e(money($pl['goods_received'] - $pl['cost_of_sales'])) ?></td>
            </tr>
            <tr>
                <td colspan="2" class="report-note">
                    Buying stock is not a cost until it is sold — it is one asset
                    turning into another. That is why purchases sit here rather
                    than in the expenses above, and why a month of heavy buying
                    does not show as a loss.
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="panel report-caveat">
    <h3 class="report-caveat-title"><?= icon('alert') ?> How to read the cost of sales</h3>
    <?php $estLines = report_cost_of_sales_estimated($from, $to); ?>
    <?php if ($estLines > 0): ?>
        <p>
            Each line sold is valued at what the goods cost <strong>on the day it
            was invoiced</strong>. <?= e(num($estLines)) ?> line<?= $estLines === 1 ? '' : 's' ?>
            in this period were invoiced before the system began recording that,
            and fall back to the product's cost price as it stands today &mdash; so
            that much of the gross margin still drifts when a buying price is
            updated. It stops drifting as those months age out.
        </p>
    <?php else: ?>
        <p>
            Each line sold is valued at what the goods cost <strong>on the day it
            was invoiced</strong>, recorded on the line at the time. The gross
            margin for this period is settled: editing a buying price today
            changes what the next sale costs, not what a past one did.
        </p>
    <?php endif; ?>
    <p>
        Expenses are counted on the date they were <em>incurred</em>, not the
        date they were paid, so rent settled late still belongs to the month it
        covers. The cash book records the payment separately, on the day the
        money actually left.
    </p>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
