<?php

/**
 * ============================================================
 *  Customer Statement
 * ------------------------------------------------------------
 *  Every movement on one customer's account in date order,
 *  with a running balance. This is what gets sent to a customer
 *  who asks "what do I actually owe you?", and what gets taken
 *  along when the debt is being chased.
 *
 *  It opens with whatever was owed before the period started,
 *  so consecutive statements join up without a gap.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_once __DIR__ . '/../../includes/company.php';
require_role(ROLE_MANAGER);
require_tables('invoices', 'invoice_payments');

$customerId = input_int($_GET, 'customer');
$customer   = $customerId !== null ? db_one(
    "SELECT *, " . customer_name_sql('') . " AS display_name
       FROM customers WHERE customer_id = :id",
    [':id' => $customerId]
) : null;

// A year back by default: long enough to show the history that
// matters, short enough to stay readable.
['from' => $from, 'to' => $to] = report_period($_GET + [
    'from' => date('Y-m-d', strtotime('-1 year')),
    'to'   => date('Y-m-d'),
]);

/* ── No customer chosen: ask, rather than bouncing away ──────
   A statement needs a party, but "choose one" is a page, not an
   error. This used to redirect to the receivables report, which
   is why the statement could not be listed on the reports hub
   or in the menu at all — every link to it landed somewhere
   else. Arriving here with nothing selected is now the normal
   way in. */
if (!$customer) {
    $pageTitle    = 'Customer Statement';
    $pageSubtitle = 'Choose a customer';
    $pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
    $breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Customer Statement']];

    require __DIR__ . '/../../includes/header.php';
    ?>
    <div class="panel">
        <h2 class="panel-title u-pad">Which customer?</h2>
        <form class="filter-bar" method="GET" action="<?= e(url('reports/statement.php')) ?>">
            <div class="filter-row filter-row--fields">
                <label class="filter-field filter-field--wide">
                    <span class="filter-label">Customer</span>
                    <?php lookup_select('invoiced_customers', 'customer', null, [
                        'prompt'   => '— Select customer —',
                        'label'    => 'Search customers',
                        'required' => true,
                        'empty'    => 'No customer matches that.',
                        'wrap'     => 'span',
                    ]); ?>
                </label>
                <label class="filter-field">
                    <span class="filter-label">From</span>
                    <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
                </label>
                <label class="filter-field">
                    <span class="filter-label">To</span>
                    <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
                </label>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><?= icon('file') ?> Produce statement</button>
                    <a class="btn btn-ghost" href="<?= e(url('reports/receivables.php')) ?>"><?= icon('clock') ?> Aged receivables</a>
                    <a class="btn btn-ghost" href="<?= e(url('reports/index.php')) ?>"><?= icon('grid') ?> All reports</a>
                </div>
            </div>
        </form>
        <p class="form-hint u-pad">
            Customers are listed most recently invoiced first, because that is
            usually the account being chased.
        </p>
    </div>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

$statement = report_customer_statement($customerId, $from, $to);
$company   = company_settings();

$KIND_LABELS = [
    'invoice'     => 'Invoice',
    'payment'     => 'Payment received',
    'credit_note' => 'Credit note',
    'refund'      => 'Refund paid out',
];

if (wants_export()) {
    $stRows = [['entry_date' => $from, 'kind' => 'Balance brought forward',
                'reference' => '', 'debit' => null, 'credit' => null,
                'balance' => $statement['opening']]];
    foreach ($statement['entries'] as $r) {
        $stRows[] = [
            'entry_date' => $r['entry_date'],
            'kind'       => $KIND_LABELS[$r['kind']] ?? $r['kind'],
            'reference'  => $r['reference'],
            'debit'      => (float) $r['debit']  > 0.005 ? $r['debit']  : null,
            'credit'     => (float) $r['credit'] > 0.005 ? $r['credit'] : null,
            'balance'    => $r['balance'],
        ];
    }
    export_deliver(export_filename('statement-' . slugify($customer['display_name'])), [[
        'name'  => 'Statement',
        'title' => 'Statement of account — ' . $customer['display_name'],
        'meta'  => export_meta([
            'Customer' => $customer['display_name'],
            'Contact'  => $customer['phone'] ?: $customer['email'],
            'Period'   => report_period_label($from, $to),
            'Balance'  => money($statement['closing']),
        ]),
        'columns' => [
            ['label' => 'Date',            'key' => 'entry_date', 'type' => 'date'],
            ['label' => 'Type',            'key' => 'kind'],
            ['label' => 'Reference',       'key' => 'reference'],
            ['label' => 'Charged',         'key' => 'debit',   'type' => 'money'],
            ['label' => 'Paid / credited', 'key' => 'credit',  'type' => 'money'],
            ['label' => 'Balance',         'key' => 'balance', 'type' => 'money', 'total' => false],
        ],
        'rows' => $stRows,
    ]]);
}

$pageTitle    = 'Statement — ' . $customer['display_name'];
$pageSubtitle = report_period_label($from, $to);
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [
    ['label' => 'Reports', 'href' => 'reports/index.php'],
    ['label' => 'Aged Receivables', 'href' => 'reports/receivables.php'],
    ['label' => 'Statement'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_period_bar.php';
?>

<p class="u-pad">
    <a class="btn btn-ghost" href="<?= e(url('reports/statement.php')) ?>"><?= icon('users') ?> Another customer</a>
    <a class="btn btn-ghost" href="<?= e(url('invoices/payments.php?q=' . urlencode((string) $customer['display_name']))) ?>"><?= icon('money') ?> Record a receipt</a>
</p>

<div class="panel statement-sheet">
    <header class="statement-head">
        <div>
            <h2 class="statement-title">Statement of account</h2>
            <p class="statement-sub"><?= e(report_period_label($from, $to)) ?></p>
        </div>
        <div class="statement-party">
            <strong><?= e($customer['display_name']) ?></strong>
            <?php if (!empty($customer['company_name']) && $customer['company_name'] !== $customer['display_name']): ?>
                <span><?= e($customer['company_name']) ?></span>
            <?php endif; ?>
            <?php foreach (['email', 'phone', 'tax_pin'] as $f): ?>
                <?php if (!empty($customer[$f])): ?><span><?= e($customer[$f]) ?></span><?php endif; ?>
            <?php endforeach; ?>
        </div>
    </header>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Reference</th>
                    <th class="ta-right">Charged</th>
                    <th class="ta-right">Paid / credited</th>
                    <th class="ta-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr class="statement-opening">
                    <td class="cell-muted"><?= e(fmt_date($from)) ?></td>
                    <td colspan="4"><strong>Balance brought forward</strong></td>
                    <td class="ta-right"><strong><?= e(money($statement['opening'])) ?></strong></td>
                </tr>
                <?php if (!$statement['entries']): ?>
                    <tr><td colspan="6" class="table-empty">Nothing moved on this account in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($statement['entries'] as $r): ?>
                    <tr>
                        <td class="cell-muted"><?= e(fmt_date($r['entry_date'])) ?></td>
                        <td><span class="role-pill"><?= e($KIND_LABELS[$r['kind']] ?? $r['kind']) ?></span></td>
                        <td><?= e($r['reference'] ?: '—') ?></td>
                        <td class="ta-right <?= (float) $r['debit'] > 0.005 ? '' : 'cell-muted' ?>">
                            <?= (float) $r['debit'] > 0.005 ? e(money($r['debit'])) : '—' ?>
                        </td>
                        <td class="ta-right <?= (float) $r['credit'] > 0.005 ? 'tone-in' : 'cell-muted' ?>">
                            <?= (float) $r['credit'] > 0.005 ? e(money($r['credit'])) : '—' ?>
                        </td>
                        <td class="ta-right"><?= e(money($r['balance'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-total">
                    <th colspan="5">
                        <?= $statement['closing'] >= 0 ? 'Balance now due' : 'In credit — owed back to the customer' ?>
                    </th>
                    <td class="ta-right">
                        <strong class="<?= $statement['closing'] > 0.005 ? 'tone-out' : 'tone-in' ?>">
                            <?= e(money(abs($statement['closing']))) ?>
                        </strong>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <footer class="statement-foot">
        <p>
            Please quote the invoice number when paying.
            <?php if (!empty($company['name'])): ?>
                Queries to <?= e($company['name']) ?><?php if (!empty($company['phone'])): ?>,
                <?= e($company['phone']) ?><?php endif; ?>.
            <?php endif; ?>
        </p>
        <p class="cell-muted">
            Produced <?= e(fmt_date(date('Y-m-d'), 'd F Y')) ?>. Payments received after
            that date are not shown.
        </p>
    </footer>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
