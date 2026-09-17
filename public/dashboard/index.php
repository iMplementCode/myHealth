<?php

/**
 * ============================================================
 *  Dashboard
 * ------------------------------------------------------------
 *  Summary statistics, quick-action shortcuts, charts and a
 *  recent-activity feed. Protected: any authenticated user.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/reporting.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/reports.php';

$s             = dashboard_summary();
$salesChart    = sales_last_12_months();
$expensesChart = expenses_last_12_months();
$topChart      = top_selling_products(5);
$latestQuotes    = recent_quotes(5);
$latestSales     = recent_sales(5);
$newestCustomers = recent_customers(5);
$latestExpenses  = user_has_role(ROLE_ADMIN, ROLE_MANAGER) ? recent_expenses(5) : [];
$newestProducts  = recent_products(5);
$latestDeliveries = recent_deliveries(5);
$user = current_user();

// Date ranges behind the sales cards, reused to deep-link each card
// into the invoice list filtered to exactly the same period.
$today     = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');

// Net profit = gross profit on sales minus recorded expenses.
$netProfit = $s['total_profit'] - $s['total_expenses'];

// Summary cards: icon, label, value, tone, target URL. Every card
// is a link so the dashboard doubles as a navigation hub.
$cards = [
    ['tag',    'Total Sales',    num($s['total_sales']),              'primary', 'invoices/index.php'],
    ['money',  'Total Revenue',  money($s['total_revenue']),          'green',   'invoices/index.php'],
    ['trend',  'Gross Profit',   money($s['total_profit']),           'violet',  'invoices/index.php'],
    ['trend',  'Net Profit',     money($netProfit),                   $netProfit >= 0 ? 'green' : 'gold', 'expenses/index.php'],
    ['file',   'Pending Quotes', num($s['pending_quotes']),           'gold',    'modules/sales/manage_quotes.php'],
    ['check',  'Converted Quotes', num($s['converted_quotes']),       'primary', 'proformas/index.php'],
    ['users',  'Customers',      num($s['total_customers']),          'primary', 'modules/sales/list_customers.php'],
    ['truck',  'Suppliers',      num($s['total_suppliers']),          'gold',    'modules/purchasing/view_suppliers.php'],
    ['box',    'Products',       num($s['total_products']),           'green',   'modules/inventory/view_products.php'],
    ['layers', 'Stock Value',    money($s['stock_value']),            'violet',  'modules/inventory/view_products.php'],
    ['box',    'Inventory Qty',  num($s['inventory_qty']) . ' units', 'gold',    'modules/inventory/view_products.php'],
    ['money',  'Total Expenses', money($s['total_expenses']),         'violet',  'expenses/index.php'],
    ['cart',   'Online Orders',  num($s['online_orders']) . ($s['pending_online'] > 0 ? ' (' . num($s['pending_online']) . ' pending)' : ''), 'primary', 'modules/sales/list_sales_orders.php?channel=ecommerce'],
];

// Quick actions (label, icon, href, roles). Filtered by role below.
$shortcuts = [
    ['Add Product',    'plus',   'modules/inventory/view_products.php?new=1', []],
    ['Add Customer',   'user',   'modules/sales/list_customers.php',      []],
    ['Create Quote',   'file',   'modules/sales/manage_quotes.php',       []],
    ['Create Sale',    'cart',   'modules/sales/list_sales_orders.php',   []],
    ['Proformas',      'file',   'proformas/index.php',                   []],
    ['Invoices',       'report', 'invoices/index.php',                    []],
    ['Delivery Notes', 'truck',  'delivery_notes/index.php',              []],
    ['Expenses',       'money',  'expenses/index.php',                    [ROLE_ADMIN, ROLE_MANAGER]],
    ['Manage Users',   'users',  'users/index.php',                       [ROLE_ADMIN]],
    ['Inventory',      'box',    'modules/inventory/view_products.php',   []],
    ['Suppliers',      'truck',  'modules/purchasing/view_suppliers.php', [ROLE_ADMIN, ROLE_MANAGER]],
    ['Settings',       'cog',    'modules/settings/company_detail.php',   [ROLE_ADMIN, ROLE_MANAGER]],
];
$shortcuts = array_filter($shortcuts, fn($sc) => empty($sc[3]) || is_admin() || user_has_role(...$sc[3]));

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Welcome back, ' . ($user['name'] ?: $user['email']);
$pageStyles   = ['dashboard.css'];
$pageScripts  = ['dashboard.js'];
$inlineData   = [
    'salesChart'    => $salesChart,
    'expensesChart' => $expensesChart,
    'topChart'      => $topChart,
];

require __DIR__ . '/../../includes/header.php';
?>

<!-- Summary cards (each links to its detail view) -->
<section class="stat-grid">
    <?php foreach ($cards as [$ic, $label, $value, $tone, $href]): ?>
        <a class="stat-card stat-card--<?= e($tone) ?>" href="<?= e(url($href)) ?>">
            <div class="stat-ic"><?= icon($ic) ?></div>
            <div class="stat-body">
                <span class="stat-label"><?= e($label) ?></span>
                <span class="stat-value"><?= e($value) ?></span>
            </div>
        </a>
    <?php endforeach; ?>
</section>

<?php
/* ── What needs attention ────────────────────────────────────
 *  Above the metrics on purpose. The numbers below say how the
 *  business is doing; this says what nobody has dealt with, and
 *  that is the thing you open the dashboard to find out.        */
if (function_exists('notifications_ready') && notifications_ready()):
    $dashNotes   = notifications_list(6, false);
    $dashSummary = notifications_summary();
?>
<section class="panel">
    <div class="panel-head">
        <h2 class="panel-title">
            <?= icon('bell') ?> Needs attention
            <?php if ($dashSummary['unread']): ?>
                <span class="badge badge--pending"><?= (int) $dashSummary['unread'] ?> unread</span>
            <?php endif; ?>
        </h2>
        <div class="panel-actions">
            <a class="panel-link" href="<?= e(url('notifications/index.php')) ?>">See all</a>
        </div>
    </div>

    <?php if (!$dashNotes): ?>
        <p class="notif-dash-empty">
            <?= icon('check') ?> Nothing outstanding — no overdue invoices, no empty shelves,
            nothing about to expire.
        </p>
    <?php else: ?>
        <ul class="notif-list">
            <?php foreach ($dashNotes as $n): ?>
                <li class="notif-row notif-row--<?= e(notification_tone($n['severity'])) ?><?= $n['is_read'] ? ' is-read' : '' ?>">
                    <span class="notif-row-dot" aria-hidden="true"></span>
                    <div class="notif-row-main">
                        <a class="notif-row-title"
                           href="<?= e(url('notifications/index.php?go=' . (int) $n['notification_id'])) ?>">
                            <?= e($n['title']) ?>
                        </a>
                        <?php if ($n['body']): ?>
                            <p class="notif-row-body"><?= e($n['body']) ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="notif-row-meta"><?= e(notification_when($n['created_at'])) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Secondary metrics (clickable, filter-aware) -->
<section class="mini-grid">
    <a class="mini-card" href="<?= e(url('invoices/index.php?from=' . $today . '&to=' . $today)) ?>">
        <span class="mini-label">Today's Sales</span>
        <span class="mini-value"><?= e(money($s['today_sales'])) ?></span>
    </a>
    <a class="mini-card" href="<?= e(url('invoices/index.php?from=' . $weekStart . '&to=' . $today)) ?>">
        <span class="mini-label">This Week</span>
        <span class="mini-value"><?= e(money($s['week_sales'])) ?></span>
    </a>
    <a class="mini-card" href="<?= e(url('invoices/index.php?from=' . $monthStart . '&to=' . $today)) ?>">
        <span class="mini-label">This Month</span>
        <span class="mini-value"><?= e(money($s['month_sales'])) ?></span>
    </a>
    <a class="mini-card <?= $s['low_stock'] > 0 ? 'mini-card--warn' : '' ?>"
       href="<?= e(url('modules/inventory/view_products.php?filter=low')) ?>">
        <span class="mini-label">Low Stock Items</span>
        <span class="mini-value"><?= e(num($s['low_stock'])) ?></span>
    </a>
    <a class="mini-card <?= $s['out_of_stock'] > 0 ? 'mini-card--danger' : '' ?>"
       href="<?= e(url('modules/inventory/view_products.php?filter=out')) ?>">
        <span class="mini-label">Out of Stock</span>
        <span class="mini-value"><?= e(num($s['out_of_stock'])) ?></span>
    </a>
    <?php if (user_has_role(ROLE_ADMIN, ROLE_MANAGER)): ?>
        <a class="mini-card" href="<?= e(url('expenses/index.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d'))) ?>">
            <span class="mini-label">Today's Expenses</span>
            <span class="mini-value"><?= e(money($s['today_expenses'])) ?></span>
        </a>
        <a class="mini-card" href="<?= e(url('expenses/index.php?from=' . date('Y-m-01'))) ?>">
            <span class="mini-label">Monthly Expenses</span>
            <span class="mini-value"><?= e(money($s['month_expenses'])) ?></span>
        </a>
    <?php endif; ?>
</section>

<!-- Outstanding deliveries (operational visibility) -->
<section class="mini-grid">
    <a class="mini-card <?= $s['outstanding_deliveries'] > 0 ? 'mini-card--warn' : '' ?>"
       href="<?= e(url('modules/sales/list_sales_orders.php?delivery=outstanding')) ?>">
        <span class="mini-label">Outstanding Deliveries</span>
        <span class="mini-value"><?= e(num($s['outstanding_deliveries'])) ?></span>
    </a>
    <a class="mini-card" href="<?= e(url('invoices/index.php?delivery=not_delivered')) ?>">
        <span class="mini-label">Invoices Not Delivered</span>
        <span class="mini-value"><?= e(num($s['inv_undelivered'])) ?></span>
    </a>
    <a class="mini-card" href="<?= e(url('invoices/index.php?delivery=partially_delivered')) ?>">
        <span class="mini-label">Partially Delivered</span>
        <span class="mini-value"><?= e(num($s['inv_partial'])) ?></span>
    </a>
    <a class="mini-card" href="<?= e(url('invoices/index.php?delivery=fully_delivered')) ?>">
        <span class="mini-label">Fully Delivered</span>
        <span class="mini-value"><?= e(num($s['inv_delivered'])) ?></span>
    </a>
    <a class="mini-card" href="<?= e(url('delivery_notes/index.php?status=posted&from=' . date('Y-m-d') . '&to=' . date('Y-m-d'))) ?>">
        <span class="mini-label">Delivered Today</span>
        <span class="mini-value"><?= e(num($s['dn_today'])) ?></span>
    </a>
    <a class="mini-card <?= $s['dn_draft'] > 0 ? 'mini-card--warn' : '' ?>"
       href="<?= e(url('delivery_notes/index.php?status=draft')) ?>">
        <span class="mini-label">Unposted Delivery Notes</span>
        <span class="mini-value"><?= e(num($s['dn_draft'])) ?></span>
    </a>
</section>

<?php
/* Reversals: what a cancelled sale still owes.
   Cancelling an invoice whose goods have gone out does not undo
   anything by itself — the stock is with the customer and, if they
   paid, so is the money. These four cards are the queue of things
   still to do about it. */
// These tables arrive with migration 017; on a database that has
// not run it yet the section simply does not appear.
$rev = ['awaiting_credit' => 0, 'draft_credits' => 0, 'returns_due' => 0,
        'shortfalls' => 0, 'refunds_due' => 0.0];
if (table_exists('credit_notes') && table_exists('goods_return_notes')) {
    $rev = [
        'awaiting_credit' => (int) db_value("SELECT COUNT(*) FROM invoices WHERE credit_status = 'awaiting_credit'"),
        'draft_credits'   => (int) db_value("SELECT COUNT(*) FROM credit_notes WHERE status = 'draft'"),
        'returns_due'     => (int) db_value("SELECT COUNT(*) FROM goods_return_notes WHERE status = 'awaiting'"),
        'shortfalls'      => (int) db_value("SELECT COUNT(*) FROM goods_return_notes WHERE has_shortfall AND shortfall_invoice_id IS NULL"),
        'refunds_due'     => finance_customer_refunds_due(date('Y-m-d')),
    ];
}
$hasReversals = $rev['awaiting_credit'] || $rev['draft_credits'] || $rev['returns_due']
    || $rev['shortfalls'] || $rev['refunds_due'] > 0.005;
?>
<?php if ($hasReversals): ?>
<!-- Cancelled sales and returns -->
<section class="mini-grid">
    <a class="mini-card <?= $rev['awaiting_credit'] > 0 ? 'mini-card--danger' : '' ?>"
       href="<?= e(url('credit_notes/index.php')) ?>">
        <span class="mini-label">Cancelled, Awaiting Credit Note</span>
        <span class="mini-value"><?= e(num($rev['awaiting_credit'])) ?></span>
    </a>
    <a class="mini-card <?= $rev['draft_credits'] > 0 ? 'mini-card--warn' : '' ?>"
       href="<?= e(url('credit_notes/index.php?status=draft')) ?>">
        <span class="mini-label">Credit Notes to Approve</span>
        <span class="mini-value"><?= e(num($rev['draft_credits'])) ?></span>
    </a>
    <a class="mini-card <?= $rev['returns_due'] > 0 ? 'mini-card--warn' : '' ?>"
       href="<?= e(url('returns/index.php?status=awaiting')) ?>">
        <span class="mini-label">Goods Awaiting Return</span>
        <span class="mini-value"><?= e(num($rev['returns_due'])) ?></span>
    </a>
    <a class="mini-card <?= $rev['shortfalls'] > 0 ? 'mini-card--warn' : '' ?>"
       href="<?= e(url('returns/index.php?status=received')) ?>">
        <span class="mini-label">Goods Kept, Not Billed</span>
        <span class="mini-value"><?= e(num($rev['shortfalls'])) ?></span>
    </a>
    <a class="mini-card <?= $rev['refunds_due'] > 0.005 ? 'mini-card--danger' : '' ?>"
       href="<?= e(url('credit_notes/index.php')) ?>">
        <span class="mini-label">Refunds Owed to Customers</span>
        <span class="mini-value"><?= e(money($rev['refunds_due'])) ?></span>
    </a>
</section>
<?php endif; ?>

<?php
/* Money out to suppliers that has nothing to show for it yet.
   Prepayments are an asset — the supplier is holding our money —
   but they are the asset most likely to be a mistake: an order paid
   for whose goods arrived and were never booked in reads exactly
   the same as one still genuinely in transit. Both want chasing,
   for different reasons, so the card leads to the list rather than
   just stating a figure. */
$buy = ['prepaid' => 0.0, 'prepaid_orders' => 0, 'nothing_in' => 0, 'payables' => 0.0];
// The cards lead to the payables report, which is Manager and up. A
// card a salesperson cannot follow is worse than no card, so the
// section is not drawn for them at all — and the figures are not
// queried either.
// Same test require_role() makes: an administrator has every role.
if ((is_admin() || user_has_role(ROLE_MANAGER))
    && table_exists('purchase_payments') && table_exists('grns')) {
    $today   = date('Y-m-d');
    $prepaid = report_supplier_prepayments($today);
    $buy = [
        'prepaid'        => array_sum(array_map(fn($r) => (float) $r['prepaid'], $prepaid)),
        'prepaid_orders' => count($prepaid),
        // The ones worth looking at first: paid for, and not one
        // goods received note against them.
        'nothing_in'     => count(array_filter($prepaid, fn($r) => (int) $r['grn_count'] === 0)),
        'payables'       => finance_trade_payables($today),
    ];
}
?>
<?php if ($buy['prepaid'] > 0.005 || $buy['payables'] > 0.005): ?>
<!-- Money owed to and held by suppliers -->
<section class="mini-grid">
    <a class="mini-card <?= $buy['prepaid'] > 0.005 ? 'mini-card--warn' : '' ?>"
       href="<?= e(url('reports/payables.php?view=prepayments')) ?>">
        <span class="mini-label">Paid For, Goods Not Yet In</span>
        <span class="mini-value"><?= e(money($buy['prepaid'])) ?></span>
    </a>
    <a class="mini-card" href="<?= e(url('reports/payables.php?view=prepayments')) ?>">
        <span class="mini-label">Orders Waiting on Goods</span>
        <span class="mini-value"><?= e(num($buy['prepaid_orders'])) ?></span>
    </a>
    <?php if ($buy['nothing_in'] > 0): ?>
        <a class="mini-card mini-card--danger" href="<?= e(url('reports/payables.php?view=prepayments')) ?>">
            <span class="mini-label">Paid, Nothing Received At All</span>
            <span class="mini-value"><?= e(num($buy['nothing_in'])) ?></span>
        </a>
    <?php endif; ?>
    <a class="mini-card" href="<?= e(url('reports/payables.php')) ?>">
        <span class="mini-label">Owed to Suppliers</span>
        <span class="mini-value"><?= e(money($buy['payables'])) ?></span>
    </a>
</section>
<?php endif; ?>

<!-- Quick actions -->
<section class="panel">
    <div class="panel-head"><h2 class="panel-title">Quick Actions</h2></div>
    <div class="shortcut-grid">
        <?php foreach ($shortcuts as [$label, $ic, $href]): ?>
            <a href="<?= e(url($href)) ?>" class="shortcut">
                <span class="shortcut-ic"><?= icon($ic) ?></span>
                <span class="shortcut-label"><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- Charts -->
<section class="chart-row">
    <div class="panel chart-panel">
        <div class="panel-head">
            <h2 class="panel-title">Sales vs Expenses</h2>
            <span class="panel-sub">Last 12 months</span>
        </div>
        <div class="chart-wrap">
            <canvas id="chartMonthlySales" height="120"></canvas>
            <p class="chart-empty" data-chart-empty hidden>No sales data yet.</p>
        </div>
    </div>
    <div class="panel chart-panel">
        <div class="panel-head">
            <h2 class="panel-title">Top Selling Products</h2>
            <span class="panel-sub">By quantity</span>
        </div>
        <div class="chart-wrap">
            <canvas id="chartTopProducts" height="120"></canvas>
            <p class="chart-empty" data-chart-empty hidden>No sales data yet.</p>
        </div>
    </div>
</section>

<!-- Recent activity -->
<section class="activity-row">

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Latest Quotes</h2>
            <a class="panel-link" href="<?= e(url('modules/sales/manage_quotes.php')) ?>">View all</a></div>
        <?php if ($latestQuotes): ?>
            <ul class="feed">
                <?php foreach ($latestQuotes as $q): ?>
                    <li class="feed-item">
                        <div class="feed-main">
                            <span class="feed-title"><?= e($q['quote_number']) ?></span>
                            <span class="feed-sub"><?= e($q['customer']) ?></span>
                        </div>
                        <div class="feed-meta">
                            <span class="feed-amount"><?= e(money($q['total_amount'])) ?></span>
                            <span class="badge badge--<?= e($q['status']) ?>"><?= e($q['status']) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="feed-empty">No quotes yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Latest Sales</h2></div>
        <?php if ($latestSales): ?>
            <ul class="feed">
                <?php foreach ($latestSales as $so): ?>
                    <li class="feed-item">
                        <div class="feed-main">
                            <span class="feed-title"><?= e($so['order_number']) ?></span>
                            <span class="feed-sub"><?= e($so['customer']) ?></span>
                        </div>
                        <div class="feed-meta">
                            <span class="feed-amount"><?= e(money($so['total_amount'])) ?></span>
                            <span class="badge badge--<?= e($so['status']) ?>"><?= e($so['status']) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="feed-empty">No sales yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">Recent Deliveries</h2>
            <a class="panel-link" href="<?= e(url('delivery_notes/index.php')) ?>">View all</a></div>
        <?php if ($latestDeliveries): ?>
            <ul class="feed">
                <?php foreach ($latestDeliveries as $d): ?>
                    <li class="feed-item">
                        <div class="feed-main">
                            <a class="feed-title" href="<?= e(url('delivery_notes/view.php?id=' . (int) $d['dn_id'])) ?>"><?= e($d['dn_number']) ?></a>
                            <span class="feed-sub"><?= e($d['customer']) ?></span>
                        </div>
                        <div class="feed-meta">
                            <span class="feed-amount"><?= e(num($d['units'], 0)) ?> units</span>
                            <span class="feed-date"><?= e(fmt_date($d['delivery_date'])) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="feed-empty">No deliveries posted yet.</p>
        <?php endif; ?>
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">New Customers</h2></div>
        <?php if ($newestCustomers): ?>
            <ul class="feed">
                <?php foreach ($newestCustomers as $c): ?>
                    <li class="feed-item">
                        <div class="feed-main">
                            <span class="feed-title"><?= e($c['name']) ?></span>
                            <span class="feed-sub"><?= e($c['email'] ?? '—') ?></span>
                        </div>
                        <span class="feed-date"><?= e(fmt_date($c['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="feed-empty">No customers yet.</p>
        <?php endif; ?>
    </div>

    <?php if ($latestExpenses): ?>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">Recent Expenses</h2>
                <a class="panel-link" href="<?= e(url('expenses/index.php')) ?>">View all</a></div>
            <ul class="feed">
                <?php foreach ($latestExpenses as $x): ?>
                    <li class="feed-item">
                        <div class="feed-main">
                            <span class="feed-title"><?= e($x['category']) ?></span>
                            <span class="feed-sub"><?= e($x['description'] ?: fmt_date($x['expense_date'])) ?></span>
                        </div>
                        <span class="feed-amount"><?= e(money($x['amount'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="panel">
            <div class="panel-head"><h2 class="panel-title">New Products</h2></div>
            <?php if ($newestProducts): ?>
                <ul class="feed">
                    <?php foreach ($newestProducts as $p): ?>
                        <li class="feed-item">
                            <div class="feed-main">
                                <span class="feed-title"><?= e($p['name']) ?></span>
                                <span class="feed-sub"><?= e(num($p['stock_quantity'], 0)) ?> in stock</span>
                            </div>
                            <span class="feed-amount"><?= e(money($p['selling_price'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="feed-empty">No products yet.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</section>

<?php
// Chart.js powers the dashboard charts, and the dashboard degrades
// to empty-state text without it.
//
// A local copy is preferred and a CDN is the fallback, because a
// third-party script runs with the same authority as the page that
// loads it: whoever controls that host controls this dashboard.
// Running deploy/vendor-assets.sh puts the file in assets/vendor/,
// after which the content security policy stops naming any
// external host at all.
//
// While the CDN is still in use, set CHART_JS_SRI in .env to the
// hash that script prints. The browser then refuses any file that
// does not match it, so a compromised CDN serves nothing rather
// than serving something. It is left unset by default because a
// wrong hash is worse than none: the charts would simply stop
// appearing, with no error anyone would think to look for.
$chartLocal = PUBLIC_PATH . '/assets/vendor/chart.umd.min.js';
$chartSri   = trim((string) env('CHART_JS_SRI', ''));
?>
<?php if (is_file($chartLocal)): ?>
    <script src="<?= e(asset('assets/vendor/chart.umd.min.js')) ?>" defer></script>
<?php else: ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
            <?= $chartSri !== '' ? 'integrity="' . e($chartSri) . '" crossorigin="anonymous"' : '' ?>
            referrerpolicy="no-referrer" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
