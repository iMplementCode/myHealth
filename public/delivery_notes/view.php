<?php

/**
 * ============================================================
 *  Delivery Notes — View
 * ------------------------------------------------------------
 *  The delivery document plus its place in the document graph:
 *  the quote or sales order it came from, the invoice it belongs
 *  to, and the stock movements it produced.
 *
 *  Posting is the action that releases stock; cancelling a
 *  posted note reverses it. Both are handled by post.php.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/company.php';
require_once __DIR__ . '/../../includes/workflow.php';
require_tables('delivery_notes', 'delivery_note_items');

$id = input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No delivery note specified.');
    redirect('delivery_notes/index.php');
}

$dn = db_one(
    "SELECT dn.*, so.order_number, q.quote_number, i.invoice_number, i.status AS invoice_status,
            w.name AS warehouse,
            " . customer_name_sql('c') . " AS customer,
            c.email AS customer_email, c.phone AS customer_phone,
            TRIM(CONCAT(cu.first_name,' ',cu.last_name)) AS created_by_name,
            TRIM(CONCAT(pu.first_name,' ',pu.last_name)) AS posted_by_name,
            TRIM(CONCAT(fu.first_name,' ',fu.last_name)) AS fulfilment_marked_by_name
     FROM delivery_notes dn
     LEFT JOIN customers c     ON c.customer_id = dn.customer_id
     LEFT JOIN sales_orders so ON so.sales_order_id = dn.sales_order_id
     LEFT JOIN quotes q        ON q.quote_id = dn.quote_id
     LEFT JOIN invoices i      ON i.invoice_id = dn.invoice_id
     LEFT JOIN warehouses w    ON w.warehouse_id = dn.warehouse_id
     LEFT JOIN users cu        ON cu.user_id = dn.created_by
     LEFT JOIN users pu        ON pu.user_id = dn.posted_by
     LEFT JOIN users fu        ON fu.user_id = dn.fulfilment_marked_by
     WHERE dn.dn_id = :id",
    [':id' => $id]
);
if (!$dn) {
    flash('error', 'That delivery note does not exist.');
    redirect('delivery_notes/index.php');
}

$items = db_all(
    "SELECT dni.*, p.name AS product_name, p.sku
     FROM delivery_note_items dni
     JOIN products p ON p.product_id = dni.product_id
     WHERE dni.dn_id = :id
     ORDER BY p.name",
    [':id' => $id]
);

$movements = db_all(
    "SELECT it.*, p.name AS product_name
     FROM inventory_transactions it
     JOIN products p ON p.product_id = it.product_id
     WHERE it.reference_table = 'delivery_notes' AND it.reference_id = :id
     ORDER BY it.txn_id",
    [':id' => $id]
);

$related  = related_documents('delivery_note', $id);
$canPost  = $dn['status'] === 'draft';

// Fulfilment: the computed value follows the delivered quantities; a
// manual mark overrides it when the real world disagrees.
$computedFulfilment = $dn['delivery_status'];
$markedFulfilment   = $dn['delivery_status_override'] ?? null;
$effectiveFulfilment = $markedFulfilment ?: $computedFulfilment;
$canMarkFulfilment  = is_admin() || user_has_role(ROLE_MANAGER);

// A posted note whose goods are already invoiced is final — the
// correction belongs on a credit note, not on the delivery.
$billedOn = $dn['status'] === 'posted' ? delivery_note_invoice($dn) : null;
$lockedByInvoice = $billedOn !== null;

$canCancel = !$lockedByInvoice
    && in_array($dn['status'], ['draft', 'posted'], true)
    && (is_admin() || user_has_role(ROLE_MANAGER) || $dn['status'] === 'draft');

$pageTitle    = $dn['dn_number'];
$pageSubtitle = 'Delivery note';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Delivery Notes', 'href' => 'delivery_notes/index.php'], ['label' => $dn['dn_number']]];

require __DIR__ . '/../../includes/header.php';
?>

<div class="toolbar no-print">
    <a href="<?= e(url('delivery_notes/index.php')) ?>" class="btn btn-ghost">&larr; All delivery notes</a>
    <div class="u-flex u-gap">
        <?php if ($canPost): ?>
            <form method="POST" action="<?= e(url('delivery_notes/post.php')) ?>" class="inline-form"
                  data-confirm="Post <?= e($dn['dn_number']) ?>? This releases stock and cannot be edited afterwards.">
                <?= csrf_field() ?>
                <input type="hidden" name="dn_id" value="<?= (int) $dn['dn_id'] ?>">
                <input type="hidden" name="action" value="post">
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> Post &amp; release stock</button>
            </form>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form method="POST" action="<?= e(url('delivery_notes/post.php')) ?>" class="inline-form"
                  data-confirm="Cancel <?= e($dn['dn_number']) ?>?<?= $dn['status'] === 'posted' ? ' Stock will be returned.' : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="dn_id" value="<?= (int) $dn['dn_id'] ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-ghost"><?= icon('x') ?> Cancel note</button>
            </form>
        <?php endif; ?>
        <?php if ($lockedByInvoice): ?>
            <span class="lock-note" title="Reversing invoiced goods is a credit-note matter">
                <?= icon('check') ?> Invoiced on <?= e($billedOn['invoice_number']) ?>
            </span>
        <?php endif; ?>
        <a href="<?= e(url('delivery_notes/pdf.php?id=' . (int) $dn['dn_id'])) ?>" target="_blank" rel="noopener"
           class="btn btn-primary"><?= icon('file') ?> Print</a>
        <a href="<?= e(url('delivery_notes/pdf.php?id=' . (int) $dn['dn_id'] . '&dl=1')) ?>"
           class="btn btn-ghost"><?= icon('download') ?> Download PDF</a>
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
        <h2 class="panel-title">Fulfilment</h2>
        <span class="panel-sub">
            <?php if ($markedFulfilment): ?>
                Marked by hand<?= $dn['fulfilment_marked_by_name'] ? ' by ' . e($dn['fulfilment_marked_by_name']) : '' ?>
                <?= $dn['fulfilment_marked_at'] ? ' on ' . e(fmt_date($dn['fulfilment_marked_at'])) : '' ?>
            <?php else: ?>
                Following the delivered quantities
            <?php endif; ?>
        </span>
    </div>

    <div class="form-grid">
        <div class="form-group">
            <span class="form-label">From delivered quantities</span>
            <div><span class="badge badge--<?= e(status_tone($computedFulfilment)) ?>"><?= e(status_label($computedFulfilment)) ?></span></div>
        </div>
        <div class="form-group">
            <span class="form-label">Showing</span>
            <div>
                <span class="badge badge--<?= e(status_tone($effectiveFulfilment)) ?>"><?= e(status_label($effectiveFulfilment)) ?></span>
                <?php if ($markedFulfilment && $markedFulfilment !== $computedFulfilment): ?>
                    <span class="field-note">Overrides the computed value</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($dn['fulfilment_note']): ?>
            <div class="form-group form-group--wide">
                <span class="form-label">Reason</span>
                <div class="cell-muted"><?= e($dn['fulfilment_note']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canMarkFulfilment): ?>
        <form method="POST" action="<?= e(url('delivery_notes/post.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="dn_id" value="<?= (int) $dn['dn_id'] ?>">
            <input type="hidden" name="action" value="fulfilment">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="fulfilment_mark">Mark as</label>
                    <select id="fulfilment_mark" name="delivery_status" class="form-control">
                        <option value="">Automatic — follow the delivered quantities</option>
                        <?php foreach (DELIVERY_STATES as $ds): ?>
                            <option value="<?= e($ds) ?>" <?= $markedFulfilment === $ds ? 'selected' : '' ?>>
                                <?= e(status_label($ds)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="fulfilment_note">Reason <span class="opt">(optional)</span></label>
                    <input type="text" id="fulfilment_note" name="fulfilment_note" class="form-control"
                           maxlength="255" value="<?= e($dn['fulfilment_note'] ?? '') ?>"
                           placeholder="e.g. customer accepted the short shipment as complete">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save fulfilment</button>
            </div>
        </form>
    <?php else: ?>
        <p class="form-hint" style="padding: 0 24px 16px;">
            Only a manager or administrator can mark fulfilment by hand.
        </p>
    <?php endif; ?>
</div>

<div class="panel doc-sheet">
    <div class="doc-head">
        <div>
            <h2 class="doc-company"><?= e(company_settings()['company_name'] ?? APP_NAME) ?></h2>
            <?php foreach (['location', 'email', 'mobile'] as $f): ?>
                <?php if (!empty(company_settings()[$f])): ?>
                    <div class="doc-line"><?= e(company_settings()[$f]) ?></div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="doc-meta">
            <h1 class="doc-title">DELIVERY NOTE</h1>
            <table class="doc-meta-table">
                <tr><td>Number</td><td><strong><?= e($dn['dn_number']) ?></strong></td></tr>
                <?php if ($dn['order_number']): ?><tr><td>Sales order</td><td><?= e($dn['order_number']) ?></td></tr><?php endif; ?>
                <?php if ($dn['quote_number']): ?><tr><td>Quote</td><td><?= e($dn['quote_number']) ?></td></tr><?php endif; ?>
                <?php if ($dn['invoice_number']): ?><tr><td>Invoice</td><td><?= e($dn['invoice_number']) ?></td></tr><?php endif; ?>
                <tr><td>Delivery date</td><td><?= e(fmt_date($dn['delivery_date'])) ?></td></tr>
                <tr><td>Status</td><td><span class="badge badge--<?= e(status_tone($dn['status'])) ?>"><?= e(status_label($dn['status'])) ?></span></td></tr>
                <tr><td>Fulfilment</td><td><span class="badge badge--<?= e(status_tone($effectiveFulfilment)) ?>"><?= e(status_label($effectiveFulfilment)) ?></span></td></tr>
            </table>
        </div>
    </div>

    <div class="doc-billto">
        <span class="doc-label">Delivered to</span>
        <div class="doc-customer"><?= e($dn['customer']) ?></div>
        <?php if ($dn['delivery_address']): ?><div class="doc-line"><?= e($dn['delivery_address']) ?></div><?php endif; ?>
        <?php if ($dn['customer_phone']): ?><div class="doc-line"><?= e($dn['customer_phone']) ?></div><?php endif; ?>
        <?php if ($dn['delivered_to']): ?><div class="doc-line">Received by: <?= e($dn['delivered_to']) ?></div><?php endif; ?>
        <?php if ($dn['vehicle_reg'] || $dn['driver_name']): ?>
            <div class="doc-line">
                <?= e(trim(($dn['driver_name'] ?: '') . ' ' . ($dn['vehicle_reg'] ? '(' . $dn['vehicle_reg'] . ')' : ''))) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table doc-items">
            <thead>
                <tr><th>#</th><th>Item</th><th>SKU</th><th class="ta-right">Quantity</th><th>Notes</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $n => $it): ?>
                    <tr>
                        <td class="cell-muted"><?= $n + 1 ?></td>
                        <td><?= e($it['product_name']) ?></td>
                        <td class="cell-muted"><?= e($it['sku'] ?: '—') ?></td>
                        <td class="ta-right"><strong><?= e(num($it['quantity'], 2)) ?></strong></td>
                        <td class="cell-muted"><?= e($it['notes'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($dn['notes']): ?>
        <div class="doc-notes">
            <div><span class="doc-label">Notes</span><p><?= nl2br(e($dn['notes'])) ?></p></div>
        </div>
    <?php endif; ?>

    <div class="doc-foot">
        Prepared by <?= e($dn['created_by_name'] ?: '—') ?>
        <?php if ($dn['posted_by_name']): ?>
            · Posted by <?= e($dn['posted_by_name']) ?> on <?= e(fmt_date($dn['posted_at'])) ?>
        <?php endif; ?>
        · <?= e(APP_NAME) ?>
    </div>
</div>

<?php if ($movements): ?>
    <div class="panel no-print">
        <div class="panel-head">
            <h2 class="panel-title">Inventory movements</h2>
            <span class="panel-sub">Every stock change this note produced</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Product</th><th>Type</th><th class="ta-right">Quantity</th>
                        <th class="ta-right">Balance after</th><th>When</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $m): ?>
                        <tr>
                            <td><?= e($m['product_name']) ?></td>
                            <td><span class="badge badge--<?= $m['movement_type'] === 'delivery' ? 'pending' : 'info' ?>">
                                <?= e(status_label($m['movement_type'])) ?></span></td>
                            <td class="ta-right <?= (float) $m['quantity'] < 0 ? 'cell-danger' : '' ?>">
                                <?= e(num($m['quantity'], 2)) ?></td>
                            <td class="ta-right"><?= e(num($m['balance_after'], 2)) ?></td>
                            <td class="cell-muted"><?= e(fmt_date($m['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
