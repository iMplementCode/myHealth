<?php

/**
 * ============================================================
 *  Stock and Reorder
 * ------------------------------------------------------------
 *  What is on the shelf, what it is worth, and what has fallen
 *  to its reorder level.
 *
 *  Every product has carried a `low_quantity_threshold` since
 *  the beginning and nothing has ever read it. It is the
 *  reorder line, and this is where it finally does some work:
 *  a shop that only finds out it is out of stock when a
 *  customer asks is losing sales it never sees.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);

$filter = input($_GET, 'show');
if (!in_array($filter, ['all', 'low', 'out', 'held'], true)) {
    $filter = 'all';
}
$rows = report_stock($filter);

// Totals are always the whole catalogue: a valuation filtered
// down to the low-stock lines would be a meaningless number.
$all       = $filter === 'all' ? $rows : report_stock('all');
$costTotal = array_sum(array_map(fn($r) => (float) $r['value_at_cost'], $all));
$retail    = array_sum(array_map(fn($r) => (float) $r['value_at_retail'], $all));
$lowRows   = array_filter($all, fn($r) => $r['stock_state'] === 'low');
$outRows   = array_filter($all, fn($r) => $r['stock_state'] === 'out');

// The valuation covers the whole catalogue whatever is filtered or
// paged; only the table is cut to a page.
[$pg, $pageRows] = paginate_rows($rows, per_page());
$baseQuery = array_filter([
    'show' => $filter !== 'all' ? $filter : null,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

if (wants_export()) {
    export_deliver(export_filename('stock-valuation'), [[
        'name'  => 'Stock',
        'title' => 'Stock and reorder',
        'meta'  => export_meta([
            'Showing'         => ['all' => 'everything', 'low' => 'at or below reorder level',
                                  'out' => 'out of stock', 'held' => 'in stock only'][$filter],
            'Stock at cost'   => money($costTotal),
            'Stock at retail' => money($retail),
            'To reorder'      => num(count($lowRows) + count($outRows)),
        ]),
        'columns' => [
            ['label' => 'Product',        'key' => 'name'],
            ['label' => 'SKU',            'key' => 'sku'],
            ['label' => 'Brand',          'key' => 'brand'],
            ['label' => 'Category',       'key' => 'category'],
            ['label' => 'In stock',       'key' => 'stock_quantity',         'type' => 'number'],
            ['label' => 'Reorder at',     'key' => 'low_quantity_threshold', 'type' => 'number', 'total' => false],
            ['label' => 'State',          'key' => 'stock_state',
             'value' => fn($r) => ['out' => 'Out of stock', 'low' => 'Reorder', 'ok' => 'In stock'][$r['stock_state']]],
            ['label' => 'Cost each',      'key' => 'cost_price',      'type' => 'money', 'total' => false],
            ['label' => 'Sell each',      'key' => 'selling_price',   'type' => 'money', 'total' => false],
            ['label' => 'Value at cost',  'key' => 'value_at_cost',   'type' => 'money'],
            ['label' => 'Value at retail','key' => 'value_at_retail', 'type' => 'money'],
        ],
        'rows'  => $rows,
        'total' => true,
    ]]);
}

$pageTitle    = 'Stock and Reorder';
$pageSubtitle = num(count($all)) . ' active products';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Stock and Reorder']];

require __DIR__ . '/../../includes/header.php';
?>

<form class="filter-bar" method="GET" action="<?= e(url('reports/stock.php')) ?>">
    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">Show</span>
            <select name="show" class="form-control" data-autosubmit>
                <option value="all"  <?= $filter === 'all'  ? 'selected' : '' ?>>Everything</option>
                <option value="low"  <?= $filter === 'low'  ? 'selected' : '' ?>>At or below reorder level</option>
                <option value="out"  <?= $filter === 'out'  ? 'selected' : '' ?>>Out of stock</option>
                <option value="held" <?= $filter === 'held' ? 'selected' : '' ?>>In stock only</option>
            </select>
        </label>
        <div class="filter-actions">
            <?= export_button('reports/stock.php', $baseQuery) ?>
            <button type="button" class="btn btn-ghost" data-print><?= icon('file') ?> Print</button>
            <a class="btn btn-ghost" href="<?= e(url('modules/purchasing/manage_pos.php')) ?>"><?= icon('truck') ?> Raise a purchase order</a>
            <a class="btn btn-ghost" href="<?= e(url('reports/index.php')) ?>"><?= icon('grid') ?> All reports</a>
        </div>
    </div>
</form>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">Stock at cost</span>
        <span class="mini-value"><?= e(money($costTotal)) ?></span>
        <span class="mini-note">what it is worth in the books</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Stock at retail</span>
        <span class="mini-value tone-in"><?= e(money($retail)) ?></span>
        <span class="mini-note"><?= e(money($retail - $costTotal)) ?> margin if it all sells</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">At reorder level</span>
        <span class="mini-value <?= $lowRows ? 'tone-out' : '' ?>"><?= e(num(count($lowRows))) ?></span>
        <span class="mini-note">running low, still have some</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Out of stock</span>
        <span class="mini-value <?= $outRows ? 'tone-out' : '' ?>"><?= e(num(count($outRows))) ?></span>
        <span class="mini-note">cannot be sold today</span>
    </div>
</section>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th class="ta-right">In stock</th>
                    <th class="ta-right">Reorder at</th>
                    <th>State</th>
                    <th class="ta-right">Cost each</th>
                    <th class="ta-right">Value at cost</th>
                    <th class="ta-right">Value at retail</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="table-empty">Nothing matches that filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($pageRows as $r): ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($r['name']) ?></span>
                            <span class="cell-sub"><?= e($r['sku'] ?: '—') ?><?php if ($r['brand']): ?> · <?= e($r['brand']) ?><?php endif; ?></span>
                        </td>
                        <td class="cell-muted"><?= e($r['category'] ?: '—') ?></td>
                        <td class="ta-right"><strong><?= e(num($r['stock_quantity'], 2)) ?></strong></td>
                        <td class="ta-right cell-muted"><?= e(num($r['low_quantity_threshold'], 2)) ?></td>
                        <td>
                            <?php if ($r['stock_state'] === 'out'): ?>
                                <span class="badge badge--inactive">Out of stock</span>
                            <?php elseif ($r['stock_state'] === 'low'): ?>
                                <span class="badge badge--pending">Reorder</span>
                            <?php else: ?>
                                <span class="badge badge--active">In stock</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right cell-muted"><?= e(money($r['cost_price'])) ?></td>
                        <td class="ta-right"><?= e(money($r['value_at_cost'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(money($r['value_at_retail'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($rows && $filter === 'all'): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="6">Whole catalogue <span class="cell-muted">every page</span></th>
                        <td class="ta-right"><strong><?= e(money($costTotal)) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money($retail)) ?></strong></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php pagination_nav($pg, $baseQuery, 'product'); ?>
    </div>
</div>

<p class="form-hint u-pad">
    The reorder level is each product's <strong>low quantity threshold</strong>,
    set on the product itself. Stock at cost is the same figure the daily
    position reports as inventory.
</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
