<?php

/**
 * ============================================================
 *  Supplier Statement
 * ------------------------------------------------------------
 *  Every movement on one supplier's account in date order, with
 *  a running balance. The mirror of the customer statement, and
 *  the document you sit down with when the supplier sends theirs
 *  and the two figures do not agree.
 *
 *  Three things move the account: goods arriving put it up,
 *  goods going back on a debit note bring it down, money paid
 *  brings it down. It opens with whatever was owed before the
 *  period started, so consecutive statements join up.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_once __DIR__ . '/../../includes/company.php';
require_role(ROLE_MANAGER);
require_tables('suppliers', 'grns');

$supplierId = input_int($_GET, 'supplier');
$supplier   = $supplierId !== null ? db_one(
    "SELECT * FROM suppliers WHERE supplier_id = :id",
    [':id' => $supplierId]
) : null;

// A year back by default: long enough to show the history that
// matters, short enough to stay readable.
['from' => $from, 'to' => $to] = report_period($_GET + [
    'from' => date('Y-m-d', strtotime('-1 year')),
    'to'   => date('Y-m-d'),
]);

/* ── No supplier chosen: ask, rather than bouncing away ──────
   A statement needs a party, but "choose one" is a page, not an
   error. Landing here from the reports hub with nothing selected
   is the normal way in. */
if (!$supplier) {
    $pageTitle    = 'Supplier Statement';
    $pageSubtitle = 'Choose a supplier';
    $pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
    $breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Supplier Statement']];

    require __DIR__ . '/../../includes/header.php';
    ?>
    <div class="panel">
        <h2 class="panel-title u-pad">Which supplier?</h2>
        <form class="filter-bar" method="GET" action="<?= e(url('reports/supplier_statement.php')) ?>">
            <div class="filter-row filter-row--fields">
                <label class="filter-field filter-field--wide">
                    <span class="filter-label">Supplier</span>
                    <?php lookup_select('suppliers', 'supplier', null, [
                        'prompt'   => '— Select supplier —',
                        'label'    => 'Search suppliers',
                        'required' => true,
                        'empty'    => 'No supplier matches that.',
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
                    <a class="btn btn-ghost" href="<?= e(url('reports/payables.php')) ?>"><?= icon('clock') ?> Aged payables</a>
                    <a class="btn btn-ghost" href="<?= e(url('reports/index.php')) ?>"><?= icon('grid') ?> All reports</a>
                </div>
            </div>
        </form>
        <p class="form-hint u-pad">
            Suppliers are listed most recently delivered first, because that is
            usually the account being queried.
        </p>
    </div>
    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

$statement = report_supplier_statement($supplierId, $from, $to);
$company   = company_settings();

// What the payables report says about this supplier, which is not
// always the same number — see the note at the foot of the page.
$owedPerOrder = array_sum(array_map(
    fn($r) => (float) $r['balance'],
    array_filter(report_payables($to), fn($r) => (int) $r['supplier_id'] === $supplierId)
));

$KIND_LABELS = [
    'receipt'    => 'Goods received',
    'debit_note' => 'Goods returned',
    'payment'    => 'Payment made',
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
    export_deliver(export_filename('supplier-statement-' . slugify($supplier['name'])), [[
        'name'  => 'Statement',
        'title' => 'Supplier statement — ' . $supplier['name'],
        'meta'  => export_meta([
            'Supplier' => $supplier['name'],
            'Contact'  => $supplier['phone'] ?: $supplier['email'],
            'Period'   => report_period_label($from, $to),
            'Balance'  => money($statement['closing']),
            'Basis'    => 'Net of VAT — input tax is claimed on the VAT return',
        ]),
        'columns' => [
            ['label' => 'Date',              'key' => 'entry_date', 'type' => 'date'],
            ['label' => 'Type',              'key' => 'kind'],
            ['label' => 'Reference',         'key' => 'reference'],
            ['label' => 'Charged',           'key' => 'debit',   'type' => 'money'],
            ['label' => 'Paid / returned',   'key' => 'credit',  'type' => 'money'],
            ['label' => 'Balance',           'key' => 'balance', 'type' => 'money', 'total' => false],
        ],
        'rows' => $stRows,
    ]]);
}

$baseQuery = ['supplier' => $supplierId, 'from' => $from, 'to' => $to];

$pageTitle    = 'Statement — ' . $supplier['name'];
$pageSubtitle = report_period_label($from, $to);
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [
    ['label' => 'Reports', 'href' => 'reports/index.php'],
    ['label' => 'Aged Payables', 'href' => 'reports/payables.php'],
    ['label' => 'Supplier Statement'],
];

require __DIR__ . '/../../includes/header.php';
?>

<form class="filter-bar" method="GET" action="<?= e(url('reports/supplier_statement.php')) ?>">
    <input type="hidden" name="supplier" value="<?= (int) $supplierId ?>">
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
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Apply</button>
            <?= export_button('reports/supplier_statement.php', $baseQuery) ?>
            <button type="button" class="btn btn-ghost" data-print><?= icon('file') ?> Print</button>
            <a class="btn btn-ghost" href="<?= e(url('reports/supplier_statement.php')) ?>"><?= icon('users') ?> Another supplier</a>
            <a class="btn btn-ghost" href="<?= e(url('modules/purchasing/payments.php?q=' . urlencode((string) $supplier['name']))) ?>"><?= icon('money') ?> Pay this supplier</a>
        </div>
    </div>
</form>

<div class="panel statement-sheet">
    <header class="statement-head">
        <div>
            <h2 class="statement-title">Supplier statement</h2>
            <p class="statement-sub"><?= e(report_period_label($from, $to)) ?></p>
        </div>
        <div class="statement-party">
            <strong><?= e($supplier['name']) ?></strong>
            <?php foreach (['contact_name', 'email', 'phone', 'tax_pin'] as $f): ?>
                <?php if (!empty($supplier[$f])): ?><span><?= e($supplier[$f]) ?></span><?php endif; ?>
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
                    <th class="ta-right">Paid / returned</th>
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
                        <?= $statement['closing'] >= 0 ? 'Balance now owed to this supplier' : 'In credit — the supplier owes us' ?>
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
            <strong>Balance = goods received − goods returned − paid</strong>, net of
            VAT. Input tax is claimed on the
            <a href="<?= e(url('reports/vat.php')) ?>">VAT return</a>, not settled
            through this account.
        </p>
        <?php if (abs($owedPerOrder - $statement['closing']) > 0.005): ?>
            <?php // Not a discrepancy to hide — the two figures answer
                  // different questions, and somebody comparing them
                  // deserves the reason rather than a support call. ?>
            <p class="form-hint">
                <strong>Aged payables shows <?= e(money($owedPerOrder)) ?> for this supplier,
                not <?= e(money($statement['closing'])) ?>.</strong>
                Both are right. This statement is a ledger, so it nets the whole
                account. Aged payables works order by order and never lets one
                order's credit balance cancel another order's debt — money paid
                ahead of delivery is a
                <a href="<?= e(url('reports/payables.php?view=prepayments&as_of=' . $to)) ?>">prepayment</a>,
                an asset held by the supplier, and it is reported as one rather
                than quietly reducing what is owed.
            </p>
        <?php endif; ?>
        <p class="cell-muted">
            Produced <?= e(fmt_date(date('Y-m-d'), 'd F Y')) ?><?php if (!empty($company['name'])): ?>
            by <?= e($company['name']) ?><?php endif; ?>. Movements recorded after
            that date are not shown.
        </p>
    </footer>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
