<?php

/**
 * ============================================================
 *  Reports — the hub
 * ------------------------------------------------------------
 *  The daily position says what is true today. These say how
 *  the business has done, which is the other half of the
 *  question and the half a spreadsheet was never able to
 *  answer without a morning of adding up.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_role(ROLE_MANAGER);

['from' => $from, 'to' => $to] = report_period($_GET);
$today = date('Y-m-d');

// Headline figures, so the hub is worth opening on its own.
$pl        = report_profit_and_loss($from, $to);
// Aggregated in SQL. This hub shows two numbers from the
// receivables ledger, and it used to read all 80,241 unpaid
// invoices into memory to work them out — 315ms for a headline.
$recTotals = report_receivables_summary($today);
$pay       = report_payables($today);
$payTotals = report_ageing_totals($pay);
$stock     = report_stock('all');
$lowCount  = count(array_filter($stock, fn($r) => $r['stock_state'] !== 'ok'));
$stockCost = array_sum(array_map(fn($r) => (float) $r['value_at_cost'], $stock));
//  How many drugs need a register at all. Zero is a legitimate
//  answer for a shop that holds none, and the card says so
//  rather than hiding.
$controlledCount = (int) db_value(
    "SELECT COUNT(*) FROM products
      WHERE controlled_schedule IS NOT NULL AND controlled_schedule <> ''"
);
// Saleable only: the valuation answers "what would this fetch",
// which a product with no selling price cannot be part of.
// Held-only, so the card equals what the report shows on landing.
$stockValue = report_stock_value_totals(report_stock_value('saleable', true));
$overdue   = $recTotals['total'] - $recTotals['current'];

// How many accounts each statement report has something to say
// about — the only figure a "pick a party" report can lead with.
$recCustomers = $recTotals['customers'];
$paySuppliers = count(array_unique(array_map(fn($r) => (int) $r['supplier_id'], $pay)));

$q = ['from' => $from, 'to' => $to];

$REPORTS = [
    [
        'title' => 'Profit and Loss',
        'href'  => 'reports/profit_loss.php',
        'blurb' => 'Revenue, cost of sales and expenses for a period, ending in what was actually made.',
        'stat'  => money($pl['net_profit']),
        'label' => 'net profit, ' . report_period_label($from, $to),
        'tone'  => $pl['net_profit'] >= 0 ? 'tone-in' : 'tone-out',
    ],
    [
        'title' => 'Aged Receivables',
        'href'  => 'reports/receivables.php',
        'blurb' => 'Who owes money and for how long, by customer, so the oldest debt is the first one chased.',
        // The headline is what customers owe, which is what the card
        // is about and what the payables card beside it shows. It
        // used to be the overdue figure instead, so on a day when
        // nothing had fallen due yet the card read "KES 0.00" over a
        // business owed thousands, and looked like a fault.
        'stat'  => money($recTotals['total']),
        'label' => $recTotals['total'] < 0.005
            ? 'nothing outstanding'
            : ($overdue > 0.005
                ? 'owed · ' . money($overdue) . ' of it overdue'
                : 'owed · none of it overdue yet'),
        'tone'  => $overdue > 0.005 ? 'tone-out' : '',
    ],
    [
        'title' => 'Customer Statement',
        'href'  => 'reports/statement.php',
        'blurb' => 'Every movement on one customer\'s account with a running balance — what to send when they ask what they owe.',
        'stat'  => num($recCustomers),
        'label' => 'customer' . ($recCustomers === 1 ? '' : 's') . ' with a balance',
        'tone'  => '',
    ],
    [
        'title' => 'Aged Payables',
        'href'  => 'reports/payables.php',
        'blurb' => 'What is owed to suppliers for goods already received, aged from the day they arrived.',
        'stat'  => money($payTotals['total']),
        'label' => count($pay) . ' order' . (count($pay) === 1 ? '' : 's') . ' outstanding',
        'tone'  => '',
    ],
    [
        'title' => 'Supplier Statement',
        'href'  => 'reports/supplier_statement.php',
        'blurb' => 'One supplier\'s account movement by movement — goods in, goods back, money paid. What to reconcile theirs against.',
        'stat'  => num($paySuppliers),
        'label' => 'supplier' . ($paySuppliers === 1 ? '' : 's') . ' with a balance',
        'tone'  => '',
    ],
    [
        'title' => 'Controlled Drugs Register',
        'href'  => 'reports/controlled_register.php',
        'blurb' => 'Every narcotic and psychotropic received and issued, in date order with a running balance. What an inspector asks to see.',
        'stat'  => num($controlledCount),
        'label' => 'controlled drug' . ($controlledCount === 1 ? '' : 's') . ' on the catalogue',
        'tone'  => '',
    ],
    [
        'title' => 'Stock and Reorder',
        'href'  => 'reports/stock.php',
        'blurb' => 'What is on the shelf, what it is worth, and what has fallen to its reorder level.',
        'stat'  => num($lowCount),
        'label' => 'to reorder · ' . money($stockCost) . ' at cost',
        'tone'  => $lowCount > 0 ? 'tone-out' : '',
    ],
    [
        'title' => 'Stock Value',
        'href'  => 'reports/stock_value.php',
        'blurb' => 'What the saleable stock is worth, at cost and at retail, by category and by product.',
        'stat'  => money($stockValue['at_cost']),
        'label' => 'at cost · ' . money($stockValue['at_retail']) . ' at retail',
        'tone'  => '',
    ],
    [
        'title' => 'Sales Analysis',
        'href'  => 'reports/sales.php',
        'blurb' => 'Which products and which customers the revenue actually came from.',
        'stat'  => money($pl['revenue']['net']),
        'label' => 'invoiced, ' . report_period_label($from, $to),
        'tone'  => 'tone-in',
    ],
    [
        'title' => 'VAT Summary',
        'href'  => 'reports/vat.php',
        'blurb' => 'Output tax charged less input tax paid, for a filing period.',
        'stat'  => money(report_vat($from, $to)['payable']),
        'label' => 'payable, ' . report_period_label($from, $to),
        'tone'  => '',
    ],
];

$pageTitle    = 'Reports';
$pageSubtitle = 'How the business has done, not just where it stands';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports']];

require __DIR__ . '/../../includes/header.php';
?>

<form class="filter-bar" method="GET" action="<?= e(url('reports/index.php')) ?>">
    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Apply period</button>
            <a class="btn btn-ghost" href="<?= e(url('reports/index.php?from=' . date('Y-m-01', strtotime('-1 month'))
                . '&to=' . date('Y-m-t', strtotime('-1 month')))) ?>">Last month</a>
            <a class="btn btn-ghost" href="<?= e(url('reports/index.php?from=' . date('Y-01-01') . '&to=' . $today)) ?>">Year to date</a>
        </div>
    </div>
</form>

<div class="report-grid">
    <?php foreach ($REPORTS as $r): ?>
        <a class="report-card" href="<?= e(url($r['href'] . '?' . http_build_query($q))) ?>">
            <span class="report-card-stat <?= e($r['tone']) ?>"><?= e($r['stat']) ?></span>
            <span class="report-card-label"><?= e($r['label']) ?></span>
            <h2 class="report-card-title"><?= e($r['title']) ?></h2>
            <p class="report-card-blurb"><?= e($r['blurb']) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<p class="form-hint u-pad">
    Period reports read the documents themselves every time they are opened,
    so a correction made to last month's invoice shows here the next time the
    month is looked at. Nothing is stored, and nothing can drift.
</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
