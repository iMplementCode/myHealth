<?php

/**
 * ============================================================
 *  Aged Receivables
 * ------------------------------------------------------------
 *  Who owes money, and for how long. One line per customer with
 *  a column per age band, worst debt first — which is the order
 *  anybody actually works down a list like this.
 *
 *  The balance is the same formula used everywhere else in the
 *  system: total less paid less credited plus refunded. It
 *  cannot disagree with the daily position's trade receivables,
 *  because it is the same arithmetic over the same rows.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('invoices', 'invoice_payments');

$asOf = input($_GET, 'as_of');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = date('Y-m-d');
}
$view = input($_GET, 'view') === 'invoice' ? 'invoice' : 'customer';

/*  The bucket totals and the summary cards have to cover the whole
 *  ledger, and the table on screen holds ten lines of it. Those are
 *  two different questions, and this page used to answer both by
 *  reading every unpaid invoice into PHP and adding them up there —
 *  80,241 rows fetched to display ten, 315ms of it.
 *
 *  The totals are now taken in SQL and the rows are paged in SQL.
 *  Same figures, same definition — report_receivables_sql() is the
 *  single source both are built from. */
$totals    = report_receivables_summary($asOf);
$overdue   = $totals['total'] - $totals['current'];

$pg     = paginate($totals['rows'], per_page());
$pgCust = paginate($totals['customers'], per_page(), (int) ($_GET['c_page'] ?? 1));

$pageRows  = $view === 'invoice'
    ? report_receivables($asOf, $pg['per_page'], $pg['offset'])
    : [];
$pageCusts = $view === 'invoice'
    ? []
    : report_receivables_customers($asOf, $pgCust['per_page'], $pgCust['offset']);
$baseQuery = array_filter([
    'as_of' => $asOf, 'view' => $view,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

if (wants_export()) {
    /*  An export is the one caller that genuinely wants every row —
     *  it is a spreadsheet of the whole ledger, which is the point
     *  of it. So the full fetch happens here and nowhere else,
     *  rather than on every page load for the sake of a download
     *  nobody asked for. */
    $customers = report_receivables_customers($asOf);
    $rows      = report_receivables($asOf);

    export_deliver(export_filename('aged-receivables'), [
        [
            'name'  => 'By customer',
            'title' => 'Aged receivables as at ' . fmt_date($asOf, 'd F Y'),
            'meta'  => export_meta(['As at' => $asOf, 'Total owed' => money($totals['total'])]),
            'columns' => array_merge(
                [['label' => 'Customer', 'key' => 'name'],
                 ['label' => 'Contact',  'key' => 'phone', 'value' => fn($r) => $r['phone'] ?: $r['email']],
                 ['label' => 'Invoices', 'key' => 'invoices', 'type' => 'integer'],
                 ['label' => 'Oldest (days)', 'key' => 'oldest', 'type' => 'integer', 'total' => false]],
                array_map(fn($k, $b) => ['label' => $b['label'], 'key' => $k, 'type' => 'money'],
                          array_keys(AGEING_BUCKETS), AGEING_BUCKETS),
                [['label' => 'Total owed', 'key' => 'total', 'type' => 'money']]
            ),
            'rows'  => $customers,
            'total' => true,
        ],
        [
            'name'  => 'By invoice',
            'columns' => [
                ['label' => 'Invoice',   'key' => 'invoice_number'],
                ['label' => 'Customer',  'key' => 'customer_name'],
                ['label' => 'Issued',    'key' => 'issue_date', 'type' => 'date'],
                ['label' => 'Due',       'key' => 'due_date',   'type' => 'date'],
                ['label' => 'Days overdue', 'key' => 'days_overdue', 'type' => 'integer', 'total' => false],
                ['label' => 'Age band',  'key' => 'days_overdue',
                 'value' => fn($r) => AGEING_BUCKETS[ageing_bucket((int) $r['days_overdue'])]['label']],
                ['label' => 'Invoiced',  'key' => 'total_amount', 'type' => 'money'],
                ['label' => 'Still owed','key' => 'balance',      'type' => 'money'],
            ],
            'rows'  => $rows,
            'total' => true,
        ],
    ]);
}

$pageTitle    = 'Aged Receivables';
$pageSubtitle = 'As at ' . fmt_date($asOf, 'd F Y');
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Aged Receivables']];

require __DIR__ . '/../../includes/header.php';
?>

<form class="filter-bar" method="GET" action="<?= e(url('reports/receivables.php')) ?>">
    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">As at</span>
            <input type="date" name="as_of" value="<?= e($asOf) ?>" class="form-control" max="<?= e(date('Y-m-d')) ?>">
        </label>
        <label class="filter-field">
            <span class="filter-label">Show</span>
            <select name="view" class="form-control">
                <option value="customer" <?= $view === 'customer' ? 'selected' : '' ?>>One line per customer</option>
                <option value="invoice"  <?= $view === 'invoice'  ? 'selected' : '' ?>>Every unpaid invoice</option>
            </select>
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Apply</button>
            <?= export_button('reports/receivables.php', $baseQuery) ?>
            <button type="button" class="btn btn-ghost" data-print><?= icon('file') ?> Print</button>
            <a class="btn btn-ghost" href="<?= e(url('reports/index.php')) ?>"><?= icon('grid') ?> All reports</a>
        </div>
    </div>
</form>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">Owed in total</span>
        <span class="mini-value"><?= e(money($totals['total'])) ?></span>
        <span class="mini-note"><?= e(num($totals['rows'])) ?> invoice<?= $totals['rows'] === 1 ? '' : 's' ?>, <?= e(num($totals['customers'])) ?> customer<?= $totals['customers'] === 1 ? '' : 's' ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Overdue</span>
        <span class="mini-value <?= $overdue > 0.005 ? 'tone-out' : '' ?>"><?= e(money($overdue)) ?></span>
        <span class="mini-note">past the due date</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Over 90 days</span>
        <span class="mini-value <?= $totals['older'] > 0.005 ? 'tone-out' : '' ?>"><?= e(money($totals['older'])) ?></span>
        <span class="mini-note">the debt worth worrying about</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Not yet due</span>
        <span class="mini-value"><?= e(money($totals['current'])) ?></span>
        <span class="mini-note">still within terms</span>
    </div>
</section>

<div class="panel">
    <div class="table-wrap">
        <?php if ($view === 'customer'): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Contact</th>
                        <?php foreach (AGEING_BUCKETS as $b): ?>
                            <th class="ta-right"><?= e($b['label']) ?></th>
                        <?php endforeach; ?>
                        <th class="ta-right">Total owed</th>
                        <th class="ta-right">Statement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$totals['customers']): ?>
                        <tr><td colspan="9" class="table-empty">Nothing outstanding. Every invoice is settled.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pageCusts as $c): ?>
                        <tr>
                            <td>
                                <span class="cell-title"><?= e($c['name']) ?></span>
                                <span class="cell-sub">
                                    <?= e(num($c['invoices'])) ?> invoice<?= (int) $c['invoices'] === 1 ? '' : 's' ?>
                                    <?php if ($c['oldest'] > 0): ?> · oldest <?= e(num($c['oldest'])) ?> days<?php endif; ?>
                                </span>
                            </td>
                            <td class="cell-muted"><?= e($c['phone'] ?: $c['email'] ?: '—') ?></td>
                            <?php foreach (array_keys(AGEING_BUCKETS) as $key): ?>
                                <td class="ta-right <?= $c[$key] > 0.005 ? ($key === 'older' ? 'tone-out' : '') : 'cell-muted' ?>">
                                    <?= $c[$key] > 0.005 ? e(money($c[$key])) : '—' ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="ta-right"><strong><?= e(money($c['total'])) ?></strong></td>
                            <td class="ta-right">
                                <a class="icon-btn" title="Customer statement"
                                   href="<?= e(url('reports/statement.php?customer=' . (int) $c['customer_id'])) ?>"><?= icon('file') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($totals['customers']): ?>
                    <tfoot>
                        <tr class="table-total">
                            <th colspan="2">All customers <span class="cell-muted">every page</span></th>
                            <?php foreach (array_keys(AGEING_BUCKETS) as $key): ?>
                                <td class="ta-right"><strong><?= e(money($totals[$key])) ?></strong></td>
                            <?php endforeach; ?>
                            <td class="ta-right"><strong><?= e(money($totals['total'])) ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
            <?php pagination_nav($pgCust, $baseQuery, 'customer'); ?>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th>Age</th>
                        <th class="ta-right">Invoiced</th>
                        <th class="ta-right">Still owed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$totals['rows']): ?>
                        <tr><td colspan="7" class="table-empty">Nothing outstanding.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pageRows as $r):
                        $days   = (int) $r['days_overdue'];
                        $bucket = ageing_bucket($days);
                    ?>
                        <tr>
                            <td>
                                <a class="cell-title" href="<?= e(url('invoices/view.php?id=' . (int) $r['invoice_id'])) ?>">
                                    <?= e($r['invoice_number']) ?>
                                </a>
                            </td>
                            <td><?= e($r['customer_name']) ?></td>
                            <td class="cell-muted"><?= e(fmt_date($r['issue_date'])) ?></td>
                            <td class="cell-muted"><?= e(fmt_date($r['due_date'])) ?></td>
                            <td>
                                <span class="badge badge--<?= $bucket === 'current' ? 'active' : ($bucket === 'older' ? 'inactive' : 'pending') ?>">
                                    <?= $days > 0 ? e(num($days)) . ' days' : 'Within terms' ?>
                                </span>
                            </td>
                            <td class="ta-right cell-muted"><?= e(money($r['total_amount'])) ?></td>
                            <td class="ta-right"><strong><?= e(money($r['balance'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($totals['rows']): ?>
                    <tfoot>
                        <tr class="table-total">
                            <th colspan="6">Total outstanding <span class="cell-muted">every page</span></th>
                            <td class="ta-right"><strong><?= e(money($totals['total'])) ?></strong></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
            <?php pagination_nav($pg, $baseQuery, 'invoice'); ?>
        <?php endif; ?>
    </div>
</div>

<p class="form-hint u-pad">
    Age is counted from the due date, so an invoice on 30-day terms is not
    overdue until day 31. An invoice with no due date is aged from the day it
    was issued.
</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
