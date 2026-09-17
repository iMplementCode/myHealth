<?php

/**
 * ============================================================
 *  Proforma Invoices — View / Print
 * ------------------------------------------------------------
 *  A printable proforma document mirroring the invoice layout,
 *  in the proforma's own currency. Browser-printable via the
 *  print stylesheet in style.css.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/workflow.php';
require_once __DIR__ . '/../../includes/company.php';
require_tables('proforma_invoices', 'proforma_invoice_items');

$id = input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No proforma specified.');
    redirect('proformas/index.php');
}

$pi = db_one(
    "SELECT pi.*, q.quote_number, inv.invoice_number,
            cur.code AS currency_code, cur.symbol AS currency_symbol,
            c.company_name, c.first_name, c.last_name, c.location AS customer_location,
            c.email AS customer_email, c.phone AS customer_phone, c.tax_pin,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS prepared_by_name
     FROM proforma_invoices pi
     LEFT JOIN customers c ON c.customer_id = pi.customer_id
     LEFT JOIN quotes q ON q.quote_id = pi.quote_id
     LEFT JOIN invoices inv ON inv.invoice_id = pi.converted_invoice_id
     LEFT JOIN currencies cur ON cur.currency_id = pi.currency_id
     LEFT JOIN users u ON u.user_id = pi.prepared_by
     WHERE pi.pi_id = :id",
    [':id' => $id]
);
if (!$pi) {
    flash('error', 'That proforma does not exist.');
    redirect('proformas/index.php');
}

$items = db_all(
    "SELECT pii.*, p.name AS product_name, p.sku
     FROM proforma_invoice_items pii
     JOIN products p ON p.product_id = pii.product_id
     WHERE pii.pi_id = :id
     ORDER BY pii.pi_item_id",
    [':id' => $id]
);

$company = db_one("SELECT * FROM company_settings WHERE id = 1") ?? [];
$symbol  = $pi['currency_symbol'] ?: 'KES';
// Company first, contact underneath. See invoices/pdf.php.
$customerName    = customer_display_name($pi);
$customerContact = customer_contact_name($pi);

$pageTitle   = $pi['pi_number'];
$pageStyles  = ['forms.css'];
$breadcrumbs = [['label' => 'Proforma Invoices', 'href' => 'proformas/index.php'], ['label' => $pi['pi_number']]];

require __DIR__ . '/../../includes/header.php';
?>

<div class="toolbar no-print">
    <a href="<?= e(url('proformas/index.php')) ?>" class="btn btn-ghost">&larr; All proformas</a>
    <?php if ($pi['status'] !== 'cancelled' && ($pi['delivery_status'] ?? '') !== 'fully_delivered'): ?>
        <a href="<?= e(url('delivery_notes/create.php?source=proforma&id=' . (int) $pi['pi_id'])) ?>"
           class="btn btn-ghost"><?= icon('truck') ?> Create delivery note</a>
    <?php endif; ?>
    <a href="<?= e(url('proformas/pdf.php?id=' . (int) $pi['pi_id'])) ?>" target="_blank" rel="noopener" class="btn btn-primary"><?= icon('file') ?> Print</a>
    <a href="<?= e(url('proformas/pdf.php?id=' . (int) $pi['pi_id'] . '&dl=1')) ?>" class="btn btn-ghost"><?= icon('download') ?> Download PDF</a>
</div>

<div class="panel doc-sheet">
    <div class="doc-head">
        <div>
            <h2 class="doc-company"><?= e($company['company_name'] ?? APP_NAME) ?></h2>
            <?php if (!empty($company['location'])): ?><div class="doc-line"><?= e($company['location']) ?></div><?php endif; ?>
            <?php if (!empty($company['email'])): ?><div class="doc-line"><?= e($company['email']) ?></div><?php endif; ?>
            <?php if (!empty($company['mobile'])): ?><div class="doc-line"><?= e($company['mobile']) ?></div><?php endif; ?>
        </div>
        <div class="doc-meta">
            <h1 class="doc-title">PROFORMA INVOICE</h1>
            <table class="doc-meta-table">
                <tr><td>Number</td><td><strong><?= e($pi['pi_number']) ?></strong></td></tr>
                <?php if ($pi['quote_number']): ?><tr><td>Quote</td><td><?= e($pi['quote_number']) ?></td></tr><?php endif; ?>
                <?php if ($pi['invoice_number']): ?><tr><td>Invoice</td><td><?= e($pi['invoice_number']) ?></td></tr><?php endif; ?>
                <tr><td>Issue date</td><td><?= e(fmt_date($pi['issue_date'])) ?></td></tr>
                <tr><td>Valid until</td><td><?= e(fmt_date($pi['expiry_date'])) ?></td></tr>
                <tr><td>Status</td><td><span class="badge badge--<?= e(status_tone($pi['status'])) ?>"><?= e(status_label($pi['status'])) ?></span></td></tr>
                <tr><td>Delivery</td><td><span class="badge badge--<?= e(status_tone($pi['delivery_status'] ?? 'not_delivered')) ?>"><?= e(status_label($pi['delivery_status'] ?? 'not_delivered')) ?></span></td></tr>
            </table>
        </div>
    </div>

    <div class="doc-billto">
        <span class="doc-label">Prepared for</span>
        <div class="doc-customer"><?= e($customerName) ?></div>
        <?php if ($customerContact !== ''): ?>
            <div class="doc-line">Attn: <?= e($customerContact) ?></div>
        <?php endif; ?>
        <?php if (!empty($pi['customer_location'])): ?><div class="doc-line"><?= e($pi['customer_location']) ?></div><?php endif; ?>
        <?php if (!empty($pi['customer_email'])): ?><div class="doc-line"><?= e($pi['customer_email']) ?></div><?php endif; ?>
        <?php if (!empty($pi['customer_phone'])): ?><div class="doc-line"><?= e($pi['customer_phone']) ?></div><?php endif; ?>
        <?php if (!empty($pi['tax_pin'])): ?><div class="doc-line">PIN: <?= e($pi['tax_pin']) ?></div><?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table doc-items">
            <thead>
                <tr>
                    <th>#</th><th>Item</th><th>SKU</th>
                    <th class="ta-right">Qty</th>
                    <th class="ta-right">Unit Price</th>
                    <th class="ta-right">Discount</th>
                    <th class="ta-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $n => $it): ?>
                    <tr>
                        <td class="cell-muted"><?= $n + 1 ?></td>
                        <td><?= e($it['product_name']) ?></td>
                        <td class="cell-muted"><?= e($it['sku'] ?: '—') ?></td>
                        <td class="ta-right"><?= e(num($it['quantity'], 2)) ?></td>
                        <td class="ta-right"><?= e(money($it['unit_price'], $symbol)) ?></td>
                        <td class="ta-right"><?= e(money($it['discount'], $symbol)) ?></td>
                        <td class="ta-right"><?= e(money($it['subtotal'], $symbol)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="doc-totals">
        <table>
            <tr><td>Subtotal</td><td><?= e(money($pi['subtotal'], $symbol)) ?></td></tr>
            <tr><td>Discount</td><td>- <?= e(money($pi['discount_amount'], $symbol)) ?></td></tr>
            <tr><td>Tax</td><td><?= e(money($pi['tax_amount'], $symbol)) ?></td></tr>
            <tr class="doc-grand"><td>Total</td><td><?= e(money($pi['total_amount'], $symbol)) ?></td></tr>
        </table>
    </div>

    <?php if ($pi['notes'] || $pi['terms']): ?>
        <div class="doc-notes">
            <?php if ($pi['notes']): ?>
                <div><span class="doc-label">Notes</span><p><?= nl2br(e($pi['notes'])) ?></p></div>
            <?php endif; ?>
            <?php if ($pi['terms']): ?>
                <div><span class="doc-label">Terms</span><p><?= nl2br(e($pi['terms'])) ?></p></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="doc-foot">Prepared by <?= e($pi['prepared_by_name'] ?: '—') ?> · <?= e(APP_NAME) ?></div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
