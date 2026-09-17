<?php

/**
 * ============================================================
 *  Sales Analysis
 * ------------------------------------------------------------
 *  Where the revenue actually came from — which products, and
 *  which customers. A total tells you how much; this tells you
 *  what to stock more of and who is worth keeping happy.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);

['from' => $from, 'to' => $to] = report_period($_GET);

// No cap: the whole period is read so the totals are the period's
// totals, and the tables are paged rather than truncated. A "top 25"
// silently hides the tail, which is exactly where a slow-moving line
// worth dropping tends to sit.
/*  Revenue answers "what sells"; profit answers "what is worth
 *  selling". They are different lists, and a business only ever
 *  looked at the first one. */
$order = in_array($_GET['order'] ?? '', ['revenue', 'profit', 'worst', 'margin', 'units'], true)
    ? $_GET['order'] : 'revenue';
$products  = report_sales_by_product($from, $to, null, $order);
$customers = report_sales_by_customer($from, $to);
$revenue   = report_revenue($from, $to);

/*  Searching narrows the PRODUCT TABLE and nothing else. The cards
 *  above it are the period's totals, and a screenshot reading "net
 *  revenue 40,000" while a filter was quietly on would be a lie the
 *  page told. What the matched rows come to is answered underneath
 *  the search box instead, where it cannot be mistaken for the
 *  period.
 *
 *  Filtered in PHP rather than SQL because the whole period is
 *  already in memory by design — see the note above — and a second
 *  query would be a second definition of the same list. */
$search  = trim((string) input($_GET, 'q'));
$matched = $search === '' ? $products : array_values(array_filter(
    $products,
    static fn($r) => mb_stripos((string) $r['name'], $search) !== false
                  || mb_stripos((string) ($r['sku'] ?? ''), $search) !== false
));

[$pgProd, $pageProducts]  = paginate_rows($matched, per_page());
[$pgCust, $pageCustomers] = paginate_rows($customers, per_page(), (int) ($_GET['c_page'] ?? 1));
$baseQuery = array_filter([
    'from' => $from, 'to' => $to, 'q' => $search,
    'order' => $order !== 'revenue' ? $order : null,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

// What the matched rows come to. Only shown while searching, and
// never in place of the period cards.
$matchRevenue  = array_sum(array_map(fn($r) => (float) $r['revenue'], $matched));
$matchCost     = array_sum(array_map(fn($r) => (float) $r['cost'], $matched));
$matchInvoiced = array_sum(array_map(fn($r) => (float) $r['invoiced'], $matched));
$matchCredited = array_sum(array_map(fn($r) => (float) $r['credited'], $matched));

// Whether the credit columns are worth showing is a question about
// the period, not about a search — hiding them because the matched
// rows happen to have no credits would move the columns around under
// somebody typing.
$productCredited = array_sum(array_map(fn($r) => (float) $r['credited'], $products));
$anyCredits      = $productCredited > 0.005
                || array_sum(array_map(fn($r) => (float) $r['credited'], $customers)) > 0.005;

if (wants_export()) {
    export_deliver(export_filename('sales-analysis'), [
        [
            'name'  => 'By product',
            'title' => 'Sales analysis — ' . report_period_label($from, $to),
            // Named in the meta: a spreadsheet holding three products
            // must not be filed as the month's sales.
            'meta'  => export_meta(array_filter([
                'Period'      => report_period_label($from, $to),
                'Filtered by' => $search !== ''
                    ? sprintf('product matching "%s" — %d of %d products',
                        $search, count($matched), count($products))
                    : null,
                'Invoiced'    => money($revenue['invoiced']),
                'Credited'    => money($revenue['credited']),
                'Net revenue' => money($revenue['net']),
                'Basis'       => 'Net of VAT and net of credit notes',
            ], fn($v) => $v !== null)),
            'columns' => [
                ['label' => 'Product',  'key' => 'name'],
                ['label' => 'SKU',      'key' => 'sku'],
                ['label' => 'Quantity', 'key' => 'quantity', 'type' => 'number'],
                ['label' => 'Invoiced', 'key' => 'invoiced', 'type' => 'money'],
                ['label' => 'Credited', 'key' => 'credited', 'type' => 'money'],
                ['label' => 'Revenue',  'key' => 'revenue',  'type' => 'money'],
                ['label' => 'Cost',     'key' => 'cost',     'type' => 'money'],
                ['label' => 'Margin',   'key' => 'margin',   'type' => 'money',
                 'value' => fn($r) => (float) $r['revenue'] - (float) $r['cost']],
                // Not the sum of the column — the margin on everything
                // sold, which is total margin over total revenue.
                ['label' => 'Margin %', 'key' => 'pc', 'type' => 'percent',
                 'total' => ['of' => 'margin', 'over' => 'revenue', 'scale' => 100],
                 'value' => fn($r) => (float) $r['revenue'] > 0.005
                     ? round(((float) $r['revenue'] - (float) $r['cost']) / (float) $r['revenue'] * 100, 1)
                     : null],
            ],
            'rows'  => $matched,
            'total' => true,
        ],
        [
            'name'  => 'By customer',
            'columns' => [
                ['label' => 'Customer', 'key' => 'name'],
                ['label' => 'Invoices', 'key' => 'invoices', 'type' => 'integer'],
                ['label' => 'Invoiced', 'key' => 'invoiced', 'type' => 'money'],
                ['label' => 'Credited', 'key' => 'credited', 'type' => 'money'],
                ['label' => 'Revenue',  'key' => 'revenue',  'type' => 'money'],
            ],
            'rows'  => $customers,
            'total' => true,
        ],
    ]);
}

$pageTitle    = 'Sales Analysis';
$pageSubtitle = report_period_label($from, $to);
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Sales Analysis']];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_period_bar.php';
?>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">Invoiced</span>
        <span class="mini-value tone-in"><?= e(money($revenue['invoiced'])) ?></span>
        <span class="mini-note">excluding VAT</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Credited back</span>
        <span class="mini-value <?= $revenue['credited'] > 0.005 ? 'tone-out' : '' ?>"><?= e(money($revenue['credited'])) ?></span>
        <span class="mini-note">returns and cancellations</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Net revenue</span>
        <span class="mini-value"><?= e(money($revenue['net'])) ?></span>
        <span class="mini-note">what was really sold</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Products sold</span>
        <span class="mini-value"><?= e(num(count($products))) ?></span>
        <span class="mini-note">distinct lines invoiced</span>
    </div>
</section>

<?php
$orders = [
    'revenue' => 'Most revenue',
    'profit'  => 'Most profit',
    'worst'   => 'Losing money first',
    'margin'  => 'Best margin',
    'units'   => 'Most units',
];
// The sort links keep the search; changing the order of a search
// result should not silently widen it back out to everything.
$orderQuery = array_filter(['from' => $from, 'to' => $to, 'q' => $search,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null],
    fn($v) => $v !== null && $v !== '');
?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">By product</h2>
            <p class="panel-sub"><?= e($orders[$order]) ?> first</p>
        </div>
        <div class="panel-actions">
            <?php foreach ($orders as $key => $label): ?>
                <a class="btn <?= $order === $key ? 'btn-primary' : 'btn-ghost' ?>"
                   href="?<?= e(http_build_query(['order' => $key] + $orderQuery)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php /* Inside the product panel, so what it filters is not in
             doubt: the customer table below is untouched by it. */ ?>
    <form method="GET" class="u-pad no-print" action="<?= e(url('reports/sales.php')) ?>">
        <input type="hidden" name="from" value="<?= e($from) ?>">
        <input type="hidden" name="to" value="<?= e($to) ?>">
        <?php if ($order !== 'revenue'): ?>
            <input type="hidden" name="order" value="<?= e($order) ?>">
        <?php endif; ?>
        <div class="filter-row">
            <div class="toolbar-search filter-grow">
                <?= icon('search', 'toolbar-search-ic') ?>
                <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                       placeholder="Find a product by name or SKU…">
            </div>
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-ghost"
                   href="?<?= e(http_build_query(array_diff_key($orderQuery, ['q' => 1])
                        + ($order !== 'revenue' ? ['order' => $order] : []))) ?>"><?= icon('x') ?> Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($search !== ''): ?>
        <?php /* What the matched rows come to, said here rather than in
                 the cards above — those are the period's totals and
                 must not move because somebody typed in a box. */ ?>
        <p class="filter-summary u-pad">
            <strong><?= e(num(count($matched))) ?></strong>
            of <?= e(num(count($products))) ?> product<?= count($products) === 1 ? '' : 's' ?>
            matched &ldquo;<?= e($search) ?>&rdquo;<?= $matched
                ? ', totalled at the foot of the table' : '' ?>.
            The cards above are still the whole period.
        </p>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="ta-right">Quantity</th>
                    <?php if ($anyCredits): ?>
                        <th class="ta-right">Invoiced</th>
                        <th class="ta-right">Credited</th>
                    <?php endif; ?>
                    <th class="ta-right">Revenue</th>
                    <th class="ta-right">Cost</th>
                    <th class="ta-right">Margin</th>
                    <th class="ta-right">Margin %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$matched): ?>
                    <tr><td colspan="<?= $anyCredits ? 8 : 6 ?>" class="table-empty">
                        <?= $search !== ''
                            ? 'No product matching &ldquo;' . e($search) . '&rdquo; was invoiced in this period.'
                            : 'Nothing was invoiced in this period.' ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($pageProducts as $p):
                    $rev    = (float) $p['revenue'];
                    $cost   = (float) $p['cost'];
                    $margin = $rev - $cost;
                ?>
                    <tr>
                        <td>
                            <a class="cell-title panel-link"
                               href="<?= e(url('modules/inventory/product_card.php?id=' . (int) $p['product_id'])) ?>"><?= e($p['name']) ?></a>
                            <span class="cell-sub"><?= e($p['sku'] ?: '—') ?></span>
                        </td>
                        <td class="ta-right"><?= e(num($p['quantity'], 2)) ?></td>
                        <?php if ($anyCredits): ?>
                            <td class="ta-right cell-muted"><?= e(money($p['invoiced'])) ?></td>
                            <td class="ta-right <?= (float) $p['credited'] > 0.005 ? 'tone-out' : 'cell-muted' ?>">
                                <?= (float) $p['credited'] > 0.005 ? e(money($p['credited'])) : '—' ?>
                            </td>
                        <?php endif; ?>
                        <td class="ta-right"><strong><?= e(money($rev)) ?></strong></td>
                        <td class="ta-right cell-muted"><?= e(money($cost)) ?></td>
                        <td class="ta-right <?= $margin >= 0 ? 'tone-in' : 'tone-out' ?>"><?= e(money($margin)) ?></td>
                        <td class="ta-right cell-muted"><?= $rev > 0.005 ? e(num($margin / $rev * 100, 1)) . '%' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($matched): ?>
                <?php /* Totals what the table holds, which while a search
                         is on is the matched rows. Quoting every product's
                         revenue underneath a single filtered row reads as
                         that row's total at a glance. */ ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="2">
                            <?= $search !== '' ? 'Matching products' : 'All products' ?>
                            <span class="cell-muted">every page</span>
                        </th>
                        <?php if ($anyCredits): ?>
                            <td class="ta-right"><strong><?= e(money($matchInvoiced)) ?></strong></td>
                            <td class="ta-right tone-out"><strong><?= e(money($matchCredited)) ?></strong></td>
                        <?php endif; ?>
                        <td class="ta-right"><strong><?= e(money($matchRevenue)) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money($matchCost)) ?></strong></td>
                        <td class="ta-right <?= $matchRevenue - $matchCost >= 0 ? '' : 'tone-out' ?>">
                            <strong><?= e(money($matchRevenue - $matchCost)) ?></strong></td>
                        <td class="ta-right cell-muted"><?= abs($matchRevenue) > 0.005
                            ? e(num(($matchRevenue - $matchCost) / $matchRevenue * 100, 1)) . '%' : '—' ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php pagination_nav($pgProd, $baseQuery + ['c_page' => $pgCust['page']], 'product'); ?>
    </div>
</div>

<div class="panel">
    <h2 class="panel-title u-pad">By customer <span class="panel-sub">highest value first</span></h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th class="ta-right">Invoices</th>
                    <th class="ta-right">Invoiced</th>
                    <?php if ($anyCredits): ?>
                        <th class="ta-right">Credited</th>
                    <?php endif; ?>
                    <th class="ta-right">Revenue</th>
                    <th class="ta-right">Statement</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$customers): ?>
                    <tr><td colspan="<?= $anyCredits ? 6 : 5 ?>" class="table-empty">Nothing was invoiced in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($pageCustomers as $c): ?>
                    <tr>
                        <td><span class="cell-title"><?= e($c['name']) ?></span></td>
                        <td class="ta-right cell-muted"><?= e(num($c['invoices'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(money($c['invoiced'])) ?></td>
                        <?php if ($anyCredits): ?>
                            <td class="ta-right <?= (float) $c['credited'] > 0.005 ? 'tone-out' : 'cell-muted' ?>">
                                <?= (float) $c['credited'] > 0.005 ? e(money($c['credited'])) : '—' ?>
                            </td>
                        <?php endif; ?>
                        <td class="ta-right"><strong><?= e(money($c['revenue'])) ?></strong></td>
                        <td class="ta-right">
                            <?php if ($c['customer_id'] !== null): ?>
                                <a class="icon-btn" title="Customer statement"
                                   href="<?= e(url('reports/statement.php?customer=' . (int) $c['customer_id'])) ?>"><?= icon('file') ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($customers): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="2">All customers <span class="cell-muted">every page</span></th>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['invoiced'], $customers)))) ?></strong></td>
                        <?php if ($anyCredits): ?>
                            <td class="ta-right tone-out"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['credited'], $customers)))) ?></strong></td>
                        <?php endif; ?>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['revenue'], $customers)))) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php pagination_nav($pgCust, $baseQuery + ['page' => $pgProd['page']], 'customer', 'c_page'); ?>
    </div>
</div>

<p class="form-hint u-pad">
    <strong>Revenue is invoiced less credited</strong>, because a credit note
    gives the money back to the customer — the sale was reversed and it is not
    revenue. Both tables and every card are <strong>net of VAT</strong>: tax is
    collected for the revenue authority and was never the business's money.
    Goods that came back are out of the quantity and out of the cost too; a
    <em>money only</em> credit note is a price correction, so the customer keeps
    the item and only the money comes off.
    Margin uses what the goods cost <em>on the day they were invoiced</em>, recorded
    on the line since migration 049, so it no longer moves when a buying price is
    updated.<?php $est = array_sum(array_map(fn($r) => (int) ($r['estimated_lines'] ?? 0), $products));
    if ($est > 0): ?>
    <?= e(num($est)) ?> line<?= $est === 1 ? '' : 's' ?> in this period predate that and
    still fall back to today's cost price.<?php endif; ?>
    Open a product to see every receipt, every sale and what it has made.
</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
