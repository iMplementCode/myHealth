<?php

/**
 * ============================================================
 *  Invoices — View / Print
 * ------------------------------------------------------------
 *  A clean invoice document: company header, customer block,
 *  line items, totals, notes and terms. Browser-printable via
 *  the print stylesheet in style.css.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/company.php';
require_once __DIR__ . '/../../includes/workflow.php';
require_tables('invoices', 'invoice_items');

$id = input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No invoice specified.');
    redirect('invoices/index.php');
}

$inv = db_one(
    "SELECT i.*, pi.pi_number, so.order_number, cur.code AS currency_code, cur.symbol AS currency_symbol,
            c.company_name, c.first_name, c.last_name, c.location AS customer_location,
            c.email AS customer_email, c.phone AS customer_phone, c.tax_pin,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
     FROM invoices i
     LEFT JOIN customers c ON c.customer_id = i.customer_id
     LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
     LEFT JOIN sales_orders so ON so.sales_order_id = i.sales_order_id
     LEFT JOIN currencies cur ON cur.currency_id = i.currency_id
     LEFT JOIN users u ON u.user_id = i.issued_by
     WHERE i.invoice_id = :id",
    [':id' => $id]
);
if (!$inv) {
    flash('error', 'That invoice does not exist.');
    redirect('invoices/index.php');
}

$items = db_all(
    "SELECT ii.*, p.name AS product_name, p.sku
     FROM invoice_items ii
     JOIN products p ON p.product_id = ii.product_id
     WHERE ii.invoice_id = :id
     ORDER BY ii.invoice_item_id",
    [':id' => $id]
);

$company = db_one("SELECT * FROM company_settings WHERE id = 1") ?? [];
$symbol  = $inv['currency_symbol'] ?: 'KES';
// Company first, contact underneath. See invoices/pdf.php.
$customerName    = customer_display_name($inv);
$customerContact = customer_contact_name($inv);

$pageTitle   = $inv['invoice_number'];
$pageStyles  = ['forms.css'];
$breadcrumbs = [['label' => 'Invoices', 'href' => 'invoices/index.php'], ['label' => $inv['invoice_number']]];
$canCancel   = is_admin() || user_has_role(ROLE_MANAGER);

// Delivery notes covering this invoice, and the wider document trail.
$deliveryNotes = db_all(
    "SELECT dn_id, dn_number, delivery_date, status
     FROM delivery_notes
     WHERE invoice_id = :id
     ORDER BY delivery_date, dn_id",
    [':id' => $id]
);
$related = related_documents('invoice', $id);

require __DIR__ . '/../../includes/header.php';
?>

<div class="toolbar no-print">
    <a href="<?= e(url('invoices/index.php')) ?>" class="btn btn-ghost">&larr; All invoices</a>
    <div class="u-flex u-gap">
        <form method="POST" action="<?= e(url('invoices/status.php')) ?>" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="invoice_id" value="<?= (int) $inv['invoice_id'] ?>">
            <input type="hidden" name="back" value="view">
            <select name="status" class="form-control status-select" data-autosubmit aria-label="Change invoice status">
                <option value="<?= e($inv['status']) ?>" selected><?= e(status_label($inv['status'])) ?></option>
                <?php foreach (allowed_transitions('invoice', $inv['status']) as $st): ?>
                    <?php if ($st === 'cancelled' && !$canCancel) continue; ?>
                    <option value="<?= e($st) ?>"><?= e(status_label($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($inv['status'] !== 'paid'): ?>
            <form method="POST" action="<?= e(url('invoices/status.php')) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="invoice_id" value="<?= (int) $inv['invoice_id'] ?>">
                <input type="hidden" name="status" value="paid">
                <input type="hidden" name="back" value="view">
                <button type="submit" class="btn btn-ghost"><?= icon('check') ?> Mark as Paid</button>
            </form>
        <?php endif; ?>
        <a href="<?= e(url('invoices/pdf.php?id=' . (int) $inv['invoice_id'])) ?>" target="_blank" rel="noopener" class="btn btn-primary"><?= icon('file') ?> Print</a>
        <?php /*  The till slip, for the customer who wants what a
                  supermarket hands them rather than a sheet of A4.
                  Same invoice, same figures — see invoices/receipt.php.
                  80mm by default; RECEIPT_WIDTH_MM sets the shop's
                  own roll and ?w=58 prints one off narrow. */ ?>
        <a href="<?= e(url('invoices/receipt.php?id=' . (int) $inv['invoice_id'])) ?>" target="_blank" rel="noopener" class="btn btn-ghost"><?= icon('invoice') ?> Receipt</a>
        <a href="<?= e(url('invoices/pdf.php?id=' . (int) $inv['invoice_id'] . '&dl=1')) ?>" class="btn btn-ghost"><?= icon('download') ?> Download PDF</a>
    </div>
</div>

<?php if ($related): ?>
    <div class="panel no-print">
        <div class="panel-head"><h2 class="panel-title">Related documents</h2></div>
        <div class="doc-trail">
            <?php foreach ($related as $r): ?>
                <a class="trail-item" href="<?= e(url($r['url'])) ?>">
                    <span class="trail-label"><?= e($r['label']) ?></span>
                    <span class="trail-number"><?= e($r['number']) ?></span>
                    <span class="badge badge--<?= e(status_tone($r['status'])) ?>"><?= e(status_label($r['status'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="panel no-print">
    <div class="panel-head">
        <h2 class="panel-title">Deliveries</h2>
        <span class="panel-sub"><?= e(status_label($inv['delivery_status'])) ?></span>
    </div>
    <?php // Offered on what is still owed — invoiced, less delivered,
          // less credited — not on the badge. A line credited away is
          // never going out, and inviting somebody to send it is how a
          // customer receives goods they have already been refunded for.
          $outstanding = array_sum(array_map(
              fn($it) => max(0.0, (float) $it['quantity']
                                  - (float) $it['delivered_quantity']
                                  - (float) ($it['credited_quantity'] ?? 0)),
              $items)); ?>
    <?php if (!in_array($inv['status'], ['cancelled', 'draft'], true) && $outstanding > 0.0005): ?>
        <div class="form-actions" style="justify-content: flex-start;">
            <a class="btn btn-primary"
               href="<?= e(url('delivery_notes/create.php?source=invoice&id=' . (int) $inv['invoice_id'])) ?>">
                <?= icon('truck') ?> Create delivery note
            </a>
        </div>
    <?php endif; ?>

    <?php if ($deliveryNotes): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Delivery note</th><th>Date</th><th>Status</th><th class="ta-right"></th></tr></thead>
                <tbody>
                    <?php foreach ($deliveryNotes as $d): ?>
                        <tr>
                            <td><span class="cell-title"><?= e($d['dn_number']) ?></span></td>
                            <td class="cell-muted"><?= e(fmt_date($d['delivery_date'])) ?></td>
                            <td><span class="badge badge--<?= e(status_tone($d['status'])) ?>"><?= e(status_label($d['status'])) ?></span></td>
                            <td class="ta-right">
                                <a class="btn btn-ghost btn--sm" href="<?= e(url('delivery_notes/view.php?id=' . (int) $d['dn_id'])) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="feed-empty">No delivery notes yet.</p>
    <?php endif; ?>
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
            <h1 class="doc-title">INVOICE</h1>
            <table class="doc-meta-table">
                <tr><td>Number</td><td><strong><?= e($inv['invoice_number']) ?></strong></td></tr>
                <?php if ($inv['pi_number']): ?><tr><td>Proforma</td><td><?= e($inv['pi_number']) ?></td></tr><?php endif; ?>
                <?php if ($inv['order_number']): ?><tr><td>Sales order</td><td><?= e($inv['order_number']) ?></td></tr><?php endif; ?>
                <tr><td>Issue date</td><td><?= e(fmt_date($inv['issue_date'])) ?></td></tr>
                <tr><td>Due date</td><td><?= e(fmt_date($inv['due_date'])) ?></td></tr>
                <tr><td>Status</td><td><span class="badge badge--<?= e(status_tone($inv['status'])) ?>"><?= e(status_label($inv['status'])) ?></span></td></tr>
                <tr><td>Delivery</td><td><span class="badge badge--<?= e(status_tone($inv['delivery_status'])) ?>"><?= e(status_label($inv['delivery_status'])) ?></span></td></tr>
            </table>
        </div>
    </div>

    <div class="doc-billto">
        <span class="doc-label">Billed to</span>
        <div class="doc-customer"><?= e($customerName) ?></div>
        <?php if ($customerContact !== ''): ?>
            <div class="doc-line">Attn: <?= e($customerContact) ?></div>
        <?php endif; ?>
        <?php if (!empty($inv['customer_location'])): ?><div class="doc-line"><?= e($inv['customer_location']) ?></div><?php endif; ?>
        <?php if (!empty($inv['customer_email'])): ?><div class="doc-line"><?= e($inv['customer_email']) ?></div><?php endif; ?>
        <?php if (!empty($inv['customer_phone'])): ?><div class="doc-line"><?= e($inv['customer_phone']) ?></div><?php endif; ?>
        <?php if (!empty($inv['tax_pin'])): ?><div class="doc-line">PIN: <?= e($inv['tax_pin']) ?></div><?php endif; ?>
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
            <tr><td>Subtotal</td><td><?= e(money($inv['subtotal'], $symbol)) ?></td></tr>
            <?php if ((float) $inv['discount_amount'] > 0.005): ?>
                <tr><td>Discount</td><td>- <?= e(money($inv['discount_amount'], $symbol)) ?></td></tr>
            <?php endif; ?>
            <?php // A "Tax 0.00" line invites the question of whether tax
                  // was forgotten, so an untaxed document carries none. ?>
            <?php if ((float) $inv['tax_amount'] > 0.005): ?>
                <tr><td>Tax</td><td><?= e(money($inv['tax_amount'], $symbol)) ?></td></tr>
            <?php endif; ?>
            <tr class="doc-grand"><td>Total</td><td><?= e(money($inv['total_amount'], $symbol)) ?></td></tr>
            <?php if ((float) $inv['amount_paid'] > 0): ?>
                <tr><td>Amount paid</td><td><?= e(money($inv['amount_paid'], $symbol)) ?></td></tr>
                <tr><td>Balance due</td><td><?= e(money((float) $inv['balance_due'], $symbol)) ?></td></tr>
            <?php endif; ?>
        </table>
        <?php if (!empty($inv['tax_note'])): ?>
            <p class="doc-tax-note"><?= nl2br(e($inv['tax_note'])) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($inv['notes'] || $inv['terms']): ?>
        <div class="doc-notes">
            <?php if ($inv['notes']): ?>
                <div><span class="doc-label">Notes</span><p><?= nl2br(e($inv['notes'])) ?></p></div>
            <?php endif; ?>
            <?php if ($inv['terms']): ?>
                <div><span class="doc-label">Payment terms</span><p><?= nl2br(e($inv['terms'])) ?></p></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="doc-foot">Issued by <?= e($inv['issued_by_name'] ?: '—') ?> · <?= e(APP_NAME) ?></div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
