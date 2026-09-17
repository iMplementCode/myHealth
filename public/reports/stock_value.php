<?php

/**
 * ============================================================
 *  Stock Value
 * ------------------------------------------------------------
 *  What the stock you can actually sell is worth — the question
 *  asked at a year end, by a bank, and by anybody wondering how
 *  much of the business's money is sitting on a shelf.
 *
 *  Distinct from Stock and Reorder, which is an operational
 *  page about what to buy next. This one is about money.
 *
 *  ── Saleable ────────────────────────────────────────────────
 *  Active, and carrying a selling price. A product with no
 *  selling price is not something the business sells — a
 *  consumable, a fitting used on a job, a line set up and never
 *  priced — and counting it inflates a figure that is supposed
 *  to answer *if we sold what is on the shelf, what would it
 *  come to*.
 *
 *  Anything excluded is counted and listed at the foot, because
 *  a valuation that quietly omits things is worse than one that
 *  says what it omitted.
 *
 *  ── Two values, and the gap between them ────────────────────
 *    at cost    what the business paid. The balance-sheet
 *               figure, and the one the daily position carries.
 *    at retail  what it would fetch at today's prices.
 *
 *  The difference is margin **not yet earned**. It is not
 *  profit, nothing has been sold, and it must never be added to
 *  one. The page says so rather than leaving it to be assumed.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('products');

// Held-only is the default: a line at zero is worth nothing and
// only makes the list longer. "Everything saleable" is there for
// a stocktake, where the zeroes matter.
$scope    = input($_GET, 'scope') === 'all' ? 'all' : 'held';
$heldOnly = $scope === 'held';

// Both panels take the same switch. They are one page under one
// control, and a summary counting lines the table below it is not
// showing is a page arguing with itself.
$rows      = report_stock_value('saleable', $heldOnly);
$totals    = report_stock_value_totals($rows);
$byCat     = report_stock_value_by_category($heldOnly);
$excludedHeld = report_stock_value('excluded', true);
$excludedCost = array_sum(array_map(fn($r) => (float) $r['value_at_cost'], $excludedHeld));

// How this ties to the daily position, which values *active* stock
// whether or not it is priced. Somebody will compare the two, so the
// page had better be able to explain the difference.
$unpricedActive = array_sum(array_map(
    fn($r) => $r['is_active'] ? (float) $r['value_at_cost'] : 0.0, $excludedHeld));
$inactiveHeld   = array_sum(array_map(
    fn($r) => $r['is_active'] ? 0.0 : (float) $r['value_at_cost'], $excludedHeld));

// Cards, categories and the totals row cover the whole valuation;
// only the product table is cut to a page. Its footer says so.
[$pg, $pageRows] = paginate_rows($rows, per_page());
$baseQuery = array_filter([
    'scope'    => $scope !== 'held' ? $scope : null,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

if (wants_export()) {
    export_deliver(export_filename('stock-value'), [
        [
            'name'  => 'By product',
            'title' => 'Stock value — saleable items, as at ' . fmt_date(date('Y-m-d'), 'd F Y'),
            'meta'  => export_meta([
                'Scope'        => $scope === 'held' ? 'Items holding stock' : 'Everything saleable',
                'Saleable'     => 'Active, with a selling price',
                'At cost'      => money($totals['at_cost']),
                'At retail'    => money($totals['at_retail']),
                'Excluded'     => count($excludedHeld) . ' item(s) holding '
                                  . money($excludedCost) . ' at cost',
            ]),
            'columns' => [
                ['label' => 'Product',        'key' => 'name'],
                ['label' => 'SKU',            'key' => 'sku'],
                ['label' => 'Category',       'key' => 'category'],
                ['label' => 'Quantity',       'key' => 'stock_quantity',  'type' => 'number'],
                ['label' => 'Unit',           'key' => 'uom'],
                ['label' => 'Cost price',     'key' => 'cost_price',      'type' => 'money', 'total' => false],
                ['label' => 'Value at cost',  'key' => 'value_at_cost',   'type' => 'money'],
                ['label' => 'Selling price',  'key' => 'selling_price',   'type' => 'money', 'total' => false],
                ['label' => 'Value at retail','key' => 'value_at_retail', 'type' => 'money'],
                ['label' => 'Margin',         'key' => 'margin',          'type' => 'money'],
                ['label' => 'Margin %',       'key' => 'margin_pct',      'type' => 'percent',
                 'total' => ['of' => 'margin', 'over' => 'value_at_retail', 'scale' => 100]],
            ],
            'rows'  => $rows,
            'total' => true,
        ],
        [
            'name'  => 'By category',
            'columns' => [
                ['label' => 'Category',        'key' => 'category'],
                ['label' => 'Products',        'key' => 'products',        'type' => 'integer'],
                ['label' => 'Quantity',        'key' => 'quantity',        'type' => 'number'],
                ['label' => 'Value at cost',   'key' => 'value_at_cost',   'type' => 'money'],
                ['label' => 'Value at retail', 'key' => 'value_at_retail', 'type' => 'money'],
                ['label' => 'Margin',          'key' => 'margin',          'type' => 'money'],
            ],
            'rows'  => $byCat,
            'total' => true,
        ],
        [
            'name'  => 'Not counted',
            'title' => 'Stock held against items that are not saleable',
            'meta'  => export_meta(['Why' => 'No selling price, or not active']),
            'columns' => [
                ['label' => 'Product',       'key' => 'name'],
                ['label' => 'SKU',           'key' => 'sku'],
                ['label' => 'Quantity',      'key' => 'stock_quantity', 'type' => 'number'],
                ['label' => 'Cost price',    'key' => 'cost_price',     'type' => 'money', 'total' => false],
                ['label' => 'Value at cost', 'key' => 'value_at_cost',  'type' => 'money'],
                ['label' => 'Active',        'key' => 'is_active',
                 'value' => fn($r) => $r['is_active'] ? 'yes' : 'no'],
            ],
            'rows'  => $excludedHeld,
            'total' => true,
        ],
    ]);
}

$pageTitle    = 'Stock Value';
$pageSubtitle = 'What the saleable stock is worth, as at ' . fmt_date(date('Y-m-d'), 'd F Y');
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Stock Value']];

require __DIR__ . '/../../includes/header.php';
?>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">Value at cost</span>
        <span class="mini-value"><?= e(money($totals['at_cost'])) ?></span>
        <span class="mini-note">what it was bought for</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Value at retail</span>
        <span class="mini-value"><?= e(money($totals['at_retail'])) ?></span>
        <span class="mini-note">what it would fetch</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Margin not yet earned</span>
        <span class="mini-value tone-in"><?= e(money($totals['margin'])) ?></span>
        <span class="mini-note"><?= $totals['at_retail'] > 0.005
            ? e(num($totals['margin'] / $totals['at_retail'] * 100, 1)) . '% of retail'
            : '—' ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Saleable lines</span>
        <span class="mini-value"><?= e(num($totals['products'])) ?></span>
        <span class="mini-note"><?= e(num($totals['quantity'], 2)) ?> units</span>
    </div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('reports/stock_value.php')) ?>">
    <div class="toolbar-search">
        <label class="toolbar-inline-label">Show</label>
        <select name="scope" class="form-control toolbar-select" data-autosubmit>
            <option value="held" <?= $scope === 'held' ? 'selected' : '' ?>>Items holding stock</option>
            <option value="all"  <?= $scope === 'all'  ? 'selected' : '' ?>>Everything saleable, including zeroes</option>
        </select>
        <?= toolbar_apply('reports/stock_value.php') ?>
    </div>
    <div class="toolbar-actions">
        <?= export_button('reports/stock_value.php', $baseQuery) ?>
        <a class="btn btn-ghost" href="<?= e(url('reports/stock.php')) ?>"><?= icon('box') ?> Stock &amp; Reorder</a>
        <a class="btn btn-ghost" href="<?= e(url('reports/index.php')) ?>"><?= icon('grid') ?> All reports</a>
    </div>
</form>

<p class="form-hint u-pad">
    <strong>Saleable</strong> means active and carrying a selling price — the
    stock the business could actually turn into revenue. The gap between cost and
    retail is <strong>margin not yet earned</strong>: nothing here has been sold,
    so it is not profit and must not be added to one. <strong>Value at cost</strong>
    is the figure that belongs on a balance sheet, and it is the one the
    <a href="<?= e(url('finance/position.php')) ?>">daily position</a> carries.
</p>

<p class="form-hint u-pad">
    <strong>Cost is what you actually paid</strong>, taken from the goods received
    notes rather than from a price typed in once. The oldest stock is treated as
    sold first, so what is on the shelf is costed at the most recent receipts:
    buy 23 at 2,600, sell 13, then buy 15 at 3,400, and the 25 remaining are worth
    <em>10 × 2,600 + 15 × 3,400 = 77,000</em> — an average of 3,080 each, not the
    2,600 they started at. A cost marked <span class="badge badge--info">avg</span>
    came from more than one price; hover it to see which receipts.
</p>

<div class="panel">
    <h2 class="panel-title u-pad">By category</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th class="ta-right">Products</th>
                    <th class="ta-right">Quantity</th>
                    <th class="ta-right">At cost</th>
                    <th class="ta-right">At retail</th>
                    <th class="ta-right">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$byCat): ?>
                    <tr><td colspan="6" class="table-empty">No saleable products yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($byCat as $c): ?>
                    <tr>
                        <td><span class="cell-title"><?= e($c['category']) ?></span></td>
                        <td class="ta-right cell-muted"><?= e(num($c['products'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(num($c['quantity'], 2)) ?></td>
                        <td class="ta-right"><strong><?= e(money($c['value_at_cost'])) ?></strong></td>
                        <td class="ta-right cell-muted"><?= e(money($c['value_at_retail'])) ?></td>
                        <td class="ta-right tone-in"><?= e(money($c['margin'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($byCat): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="3">All categories</th>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['value_at_cost'], $byCat)))) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['value_at_retail'], $byCat)))) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['margin'], $byCat)))) ?></strong></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="panel">
    <h2 class="panel-title u-pad">By product <span class="cell-muted">most valuable first</span></h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th class="ta-right">Quantity</th>
                    <th class="ta-right">Cost</th>
                    <th class="ta-right">At cost</th>
                    <th class="ta-right">Selling</th>
                    <th class="ta-right">At retail</th>
                    <th class="ta-right">Margin %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="table-empty">
                        Nothing saleable is holding stock.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($pageRows as $r):
                    // The working behind the cost: which receipts the
                    // stock on hand came from. Only the rows on this
                    // page are asked for, so the query cost follows
                    // what is on screen rather than the whole list.
                    $layers = product_cost_layers((int) $r['product_id']);
                    $mixed  = count($layers) > 1;
                ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($r['name']) ?></span>
                            <span class="cell-sub"><?= e($r['sku'] ?: '—') ?></span>
                        </td>
                        <td class="cell-muted"><?= e($r['category'] ?: 'Uncategorised') ?></td>
                        <td class="ta-right">
                            <?= e(num($r['stock_quantity'], 2)) ?>
                            <?php if ($r['uom']): ?>
                                <span class="cell-muted"><?= e($r['uom']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right cell-muted"
                            <?php if ($layers): ?>title="<?= e(product_cost_layers_label($layers)) ?>"<?php endif; ?>>
                            <?= e(money($r['cost_price'])) ?>
                            <?php if ($mixed): ?>
                                <?php // Bought at more than one price. Saying so is
                                      // the difference between a figure somebody
                                      // trusts and one they query. ?>
                                <span class="badge badge--info" title="<?= e(product_cost_layers_label($layers)) ?>">avg</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><strong><?= e(money($r['value_at_cost'])) ?></strong></td>
                        <td class="ta-right cell-muted"><?= e(money($r['selling_price'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(money($r['value_at_retail'])) ?></td>
                        <td class="ta-right <?= (float) $r['margin'] < 0 ? 'tone-out' : 'cell-muted' ?>">
                            <?= $r['margin_pct'] === null ? '—' : e(num($r['margin_pct'], 1)) . '%' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="4">Total <span class="cell-muted">saleable stock, every page</span></th>
                        <td class="ta-right"><strong><?= e(money($totals['at_cost'])) ?></strong></td>
                        <td></td>
                        <td class="ta-right"><strong><?= e(money($totals['at_retail'])) ?></strong></td>
                        <td class="ta-right"><strong><?= $totals['at_retail'] > 0.005
                            ? e(num($totals['margin'] / $totals['at_retail'] * 100, 1)) . '%' : '—' ?></strong></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php pagination_nav($pg, $baseQuery, 'product'); ?>
    </div>
</div>

<?php if ($excludedHeld): ?>
    <?php // Stock is stock. Leaving it out of the valuation is right;
          // leaving it out of the page is how a figure gets queried. ?>
    <div class="panel">
        <div class="panel-head">
            <h2>Not counted <span class="cell-muted">holding stock, but not saleable</span></h2>
            <strong><?= e(money($excludedCost)) ?> at cost</strong>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th class="ta-right">Quantity</th>
                        <th class="ta-right">At cost</th>
                        <th>Why it is not counted</th>
                        <th class="ta-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($excludedHeld as $r): ?>
                        <tr>
                            <td>
                                <span class="cell-title"><?= e($r['name']) ?></span>
                                <span class="cell-sub"><?= e($r['sku'] ?: '—') ?></span>
                            </td>
                            <td class="ta-right"><?= e(num($r['stock_quantity'], 2)) ?></td>
                            <td class="ta-right cell-muted"><?= e(money($r['value_at_cost'])) ?></td>
                            <td class="cell-muted">
                                <?= !$r['is_active']
                                    ? 'Not active'
                                    : 'No selling price — set one and it counts' ?>
                            </td>
                            <td class="ta-right">
                                <a class="icon-btn" title="Open the product"
                                   href="<?= e(url('modules/inventory/view_products.php?q=' . urlencode((string) ($r['sku'] ?: $r['name'])))) ?>"><?= icon('box') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if ($unpricedActive > 0.005 || $inactiveHeld > 0.005): ?>
    <p class="form-hint u-pad">
        <strong>How this ties to the daily position.</strong> That page values
        every <em>active</em> product, priced or not, so:
        <br>
        <?= e(money($totals['at_cost'])) ?> saleable
        <?php if ($unpricedActive > 0.005): ?>
            + <?= e(money($unpricedActive)) ?> active but unpriced
        <?php endif; ?>
        = <?= e(money($totals['at_cost'] + $unpricedActive)) ?> inventory on the daily position.
        <?php if ($inactiveHeld > 0.005): ?>
            A further <strong><?= e(money($inactiveHeld)) ?></strong> sits against
            products marked <strong>not active</strong>, which appears in neither
            figure — stock on a shelf that no report is counting is worth a look.
        <?php endif; ?>
    </p>
<?php endif; ?>

<p class="form-hint u-pad">
    Valued at what was paid for the units actually on the shelf, first-in
    first-out, from the goods received notes. Until migration 035 this page used
    one cost price per product — whatever was typed when the product was created —
    so a price rise left the stock valued at the old figure and the balance sheet
    short by the difference. Stock entered by hand at go-live has no receipt behind
    it and is still valued at the cost typed on the product; hover a cost to see
    whether that applies. See <a href="<?= e(url('reports/index.php')) ?>">the
    reports index</a> and INVENTORY.md.
</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
