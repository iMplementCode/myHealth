<?php

/**
 * ============================================================
 *  VAT Summary
 * ------------------------------------------------------------
 *  Output tax charged, less input tax paid, for a filing
 *  period. The figure at the bottom is what is owed to the
 *  revenue authority — or, when it is negative, what is
 *  reclaimable.
 *
 *  This is a working summary to file from, not a return. What
 *  it can and cannot see is stated on the page rather than
 *  left for someone to discover during an audit.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);

['from' => $from, 'to' => $to] = report_period($_GET);
$vat = report_vat($from, $to);

// The invoices behind the output figure, so a number can always
// be traced to the documents that made it.
$taxedInvoices = db_all(
    "SELECT i.invoice_id, i.invoice_number, i.issue_date, i.subtotal, i.tax_amount, i.total_amount,
            " . customer_name_sql('c') . " AS customer_name
       FROM invoices i
       LEFT JOIN customers c ON c.customer_id = i.customer_id
      WHERE " . REPORT_LIVE_INVOICES . " AND i.tax_amount > 0
        AND i.issue_date BETWEEN :f AND :t
      ORDER BY i.issue_date, i.invoice_id",
    [':f' => $from, ':t' => $to]
);

// And the receipts behind the input figure. Input tax is the half of
// a return that gets asked about, and a total with nothing behind it
// is a total nobody can defend.
$taxedReceipts = report_vat_input_receipts($from, $to);
$unclaimed     = report_vat_unclaimed($from, $to);
$unclaimedVat  = array_sum(array_map(fn($r) => (float) $r['po_would_claim'], $unclaimed));

[$pg, $pageInvoices] = paginate_rows($taxedInvoices, per_page());
$baseQuery = array_filter([
    'from' => $from, 'to' => $to,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

if (wants_export()) {
    export_deliver(export_filename('vat-summary'), [
        [
            'name'  => 'Summary',
            'title' => 'VAT summary — ' . report_period_label($from, $to),
            'meta'  => export_meta(['Period' => report_period_label($from, $to)]),
            'columns' => [
                ['label' => 'Item',   'key' => 'item'],
                ['label' => 'Amount', 'key' => 'amount', 'type' => 'money', 'total' => false],
            ],
            'rows' => [
                ['item' => 'Output tax on invoices',     'amount' => $vat['output']],
                ['item' => 'less VAT on credit notes',   'amount' => -$vat['output_credited']],
                ['item' => 'Output tax',                 'amount' => $vat['output_net']],
                ['item' => 'Input tax on purchases',     'amount' => -$vat['input']],
                ['item' => $vat['payable'] >= 0 ? 'VAT payable' : 'VAT reclaimable',
                 'amount' => $vat['payable']],
            ],
        ],
        [
            'name'  => 'Invoices with VAT',
            'columns' => [
                ['label' => 'Invoice',  'key' => 'invoice_number'],
                ['label' => 'Customer', 'key' => 'customer_name'],
                ['label' => 'Issued',   'key' => 'issue_date',   'type' => 'date'],
                ['label' => 'Net',      'key' => 'subtotal',     'type' => 'money'],
                ['label' => 'VAT',      'key' => 'tax_amount',   'type' => 'money'],
                ['label' => 'Gross',    'key' => 'total_amount', 'type' => 'money'],
            ],
            'rows'  => $taxedInvoices,
            'total' => true,
        ],
        [
            'name'  => 'Receipts with VAT',
            'title' => 'Purchases carrying VAT charged by the supplier',
            'meta'  => export_meta(['Claimable against' => 'the supplier tax invoice named on each line']),
            'columns' => [
                ['label' => 'Document',         'key' => 'document'],
                ['label' => 'Supplier',         'key' => 'supplier_name'],
                ['label' => 'Date',             'key' => 'doc_date', 'type' => 'date'],
                ['label' => 'Supplier invoice', 'key' => 'supplier_invoice_no'],
                ['label' => 'Order',            'key' => 'po_number'],
                ['label' => 'Net',              'key' => 'total_amount', 'type' => 'money'],
                ['label' => 'VAT',              'key' => 'tax_amount',   'type' => 'money'],
                ['label' => 'Gross',            'key' => 'gross',        'type' => 'money'],
            ],
            'rows'  => $taxedReceipts,
            'total' => true,
        ],
        [
            'name'  => 'No VAT recorded',
            'title' => 'Receipts whose order expected VAT but which recorded none',
            'meta'  => export_meta([
                'Why it matters' => 'Nothing here is claimed. Either the supplier '
                                    . 'charged no VAT, or their tax invoice was never recorded.',
            ]),
            'columns' => [
                ['label' => 'Document',         'key' => 'document'],
                ['label' => 'Supplier',         'key' => 'supplier_name'],
                ['label' => 'Date',             'key' => 'doc_date', 'type' => 'date'],
                ['label' => 'Supplier invoice', 'key' => 'supplier_invoice_no',
                 'value' => fn($r) => $r['supplier_invoice_no'] ?: 'none recorded'],
                ['label' => 'Order',            'key' => 'po_number'],
                ['label' => 'Net',              'key' => 'total_amount', 'type' => 'money'],
                ['label' => 'Order expected',   'key' => 'po_would_claim', 'type' => 'money'],
            ],
            'rows'  => $unclaimed,
            'total' => true,
        ],
    ]);
}

$pageTitle    = 'VAT Summary';
$pageSubtitle = report_period_label($from, $to);
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'VAT Summary']];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_period_bar.php';
?>

<div class="position-grid">
    <table class="position-table">
        <tbody>
            <tr class="position-head"><th colspan="2">Output tax <span class="cell-muted">charged on sales</span></th></tr>
            <tr>
                <th>VAT on invoices</th>
                <td class="ta-right"><?= e(money($vat['output'])) ?></td>
            </tr>
            <?php if ($vat['output_credited'] > 0.005): ?>
                <tr class="position-sub">
                    <th>less VAT on credit notes</th>
                    <td class="ta-right">(<?= e(money($vat['output_credited'])) ?>)</td>
                </tr>
            <?php endif; ?>
            <tr class="position-subtotal">
                <th>Output tax</th>
                <td class="ta-right"><?= e(money($vat['output_net'])) ?></td>
            </tr>

            <tr class="position-head"><th colspan="2">Input tax <span class="cell-muted">paid on purchases</span></th></tr>
            <tr>
                <th>VAT on purchases</th>
                <td class="ta-right"><?= e(money($vat['input'])) ?></td>
            </tr>
            <tr class="position-subtotal">
                <th>Input tax</th>
                <td class="ta-right">(<?= e(money($vat['input'])) ?>)</td>
            </tr>

            <tr class="position-valuation">
                <th><?= $vat['payable'] >= 0 ? 'VAT payable' : 'VAT reclaimable' ?></th>
                <td class="ta-right <?= $vat['payable'] >= 0 ? 'tone-out' : 'tone-in' ?>">
                    <?= e(money(abs($vat['payable']))) ?>
                </td>
            </tr>
        </tbody>
    </table>

    <table class="position-table">
        <tbody>
            <tr class="position-head"><th colspan="2">What this covers</th></tr>
            <tr>
                <td colspan="2" class="report-note">
                    <p>
                        <strong>Output tax</strong> is the VAT on every invoice issued in the
                        period, less the VAT on credit notes approved in it — a sale reversed
                        takes its VAT with it.
                    </p>
                    <p>
                        <strong>Input tax</strong> is the VAT the <em>supplier</em> charged,
                        recorded against their tax invoice — on the goods received note
                        for goods, or on the order when service work is accepted. It is not
                        taken from the purchase order: that is our own document, its VAT is what
                        we expected to be billed, and a receipt with no tax invoice behind it has
                        nothing to claim. A receipt showing no VAT contributes nothing here, and
                        is listed below so the omission is a decision rather than an accident.
                    </p>
                    <p>
                        <strong>Expenses are not included.</strong> The expenses table has no tax
                        field, so there is nothing to claim from. Where VAT has been paid on an
                        expense it must be added by hand when filing — or recorded through a
                        purchase order instead, which the system can then see.
                    </p>
                    <p class="cell-muted">
                        A working summary to file from, not a return.
                    </p>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="panel">
    <h2 class="panel-title u-pad">Invoices carrying VAT <span class="panel-sub"><?= e(num(count($taxedInvoices))) ?> in this period</span></h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Issued</th>
                    <th class="ta-right">Net</th>
                    <th class="ta-right">VAT</th>
                    <th class="ta-right">Gross</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$taxedInvoices): ?>
                    <tr><td colspan="6" class="table-empty">
                        No invoice in this period carried VAT.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($pageInvoices as $i): ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('invoices/view.php?id=' . (int) $i['invoice_id'])) ?>">
                                <?= e($i['invoice_number']) ?>
                            </a>
                        </td>
                        <td><?= e($i['customer_name']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($i['issue_date'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(money($i['subtotal'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($i['tax_amount'])) ?></strong></td>
                        <td class="ta-right"><?= e(money($i['total_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($taxedInvoices): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="4">Output tax on invoices <span class="cell-muted">every page</span></th>
                        <td class="ta-right"><strong><?= e(money($vat['output'])) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php pagination_nav($pg, $baseQuery, 'invoice'); ?>
    </div>
</div>

<div class="panel">
    <h2 class="panel-title u-pad">
        Purchases carrying VAT
        <span class="panel-sub"><?= e(num(count($taxedReceipts))) ?> in this period</span>
    </h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Supplier</th>
                    <th>Date</th>
                    <th>Their tax invoice</th>
                    <th class="ta-right">Net</th>
                    <th class="ta-right">VAT</th>
                    <th class="ta-right">Gross</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$taxedReceipts): ?>
                    <tr><td colspan="7" class="table-empty">
                        No supplier charged VAT on anything bought in this
                        period, so there is no input tax to claim.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($taxedReceipts as $g): ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($g['document']) ?></span>
                            <span class="cell-sub"><?= e($g['kind']) ?> &middot; <?= e($g['po_number'] ?: 'no order') ?></span>
                        </td>
                        <td><?= e($g['supplier_name']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($g['doc_date'])) ?></td>
                        <td class="cell-muted"><?= e($g['supplier_invoice_no'] ?: '—') ?></td>
                        <td class="ta-right cell-muted"><?= e(money($g['total_amount'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($g['tax_amount'])) ?></strong>
                            <span class="cell-muted"><?= e(vat_rate_label($g['tax_rate'])) ?></span></td>
                        <td class="ta-right"><?= e(money($g['gross'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($taxedReceipts): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="5">Input tax on goods received and services accepted</th>
                        <td class="ta-right"><strong><?= e(money($vat['input'])) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php if ($unclaimed): ?>
    <?php // Not claimed, and said out loud. Either the supplier charges no
          // VAT — in which case the order was left on the form's default and
          // the only thing wrong is the order — or their tax invoice never
          // reached the receipt, and a real claim is going begging. The data
          // cannot tell the two apart, so the person filing decides. ?>
    <div class="panel">
        <div class="panel-head">
            <h2>Nothing claimed on these
                <span class="cell-muted">the order expected VAT, no supplier invoice was recorded</span></h2>
            <strong><?= e(money($unclaimedVat)) ?> not claimed</strong>
        </div>
        <p class="form-hint u-pad">
            A purchase order's VAT is what we expected to be billed — the form opens
            on 16% — so it is no evidence that anybody billed us. Nothing below is on
            the return. If the supplier <strong>did</strong> issue a tax invoice, open
            the receipt (or the order, for service work), enter their invoice
            number and the VAT on it, and this figure
            moves into the claim.
        </p>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Document</th>
                        <th>Supplier</th>
                        <th>Date</th>
                        <th>Their tax invoice</th>
                        <th class="ta-right">Net</th>
                        <th class="ta-right">Order expected</th>
                        <th class="ta-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unclaimed as $g): ?>
                        <tr>
                            <td>
                                <span class="cell-title"><?= e($g['document']) ?></span>
                                <span class="cell-sub"><?= e($g['kind']) ?> &middot; <?= e($g['po_number']) ?>
                                    at <?= e(vat_rate_label($g['po_tax_rate'])) ?></span>
                            </td>
                            <td>
                                <?= e($g['supplier_name']) ?>
                                <?php // No PIN on file, no tax invoice possible —
                                      // which usually settles the question outright. ?>
                                <?php if (!trim((string) $g['supplier_pin'])): ?>
                                    <span class="cell-sub">no tax PIN on file</span>
                                <?php endif; ?>
                            </td>
                            <td class="cell-muted"><?= e(fmt_date($g['doc_date'])) ?></td>
                            <td class="cell-muted <?= $g['supplier_invoice_no'] ? '' : 'tone-out' ?>">
                                <?= e($g['supplier_invoice_no'] ?: 'none recorded') ?>
                            </td>
                            <td class="ta-right cell-muted"><?= e(money($g['total_amount'])) ?></td>
                            <td class="ta-right"><?= e(money($g['po_would_claim'])) ?></td>
                            <td class="ta-right">
                                <?php $where = $g['kind'] === 'Service'
                                        ? 'modules/purchasing/manage_pos.php?q=' . urlencode((string) $g['po_number'])
                                        : 'modules/purchasing/manage_grns.php?q=' . urlencode((string) $g['document']); ?>
                                <a class="icon-btn" title="<?= $g['kind'] === 'Service'
                                        ? 'Open the order and record the bill' : 'Open the receipt' ?>"
                                   href="<?= e(url($where)) ?>"><?= icon('edit') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="5">Not claimed</th>
                        <td class="ta-right"><strong><?= e(money($unclaimedVat)) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
