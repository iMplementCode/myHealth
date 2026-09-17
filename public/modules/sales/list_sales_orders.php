<?php

/**
 * ============================================================
 *  Sales Orders — List (uniform UI)
 * ------------------------------------------------------------
 *  Paginated, searchable list of sales orders with channel
 *  (POS / e-commerce / B2B) and status filters, in-row status
 *  transitions, print links and a create button. The sidebar
 *  "Sales Orders" entry lands here — the create form is one
 *  click away.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/documents.php';
require_once __DIR__ . '/../../../includes/workflow.php';

$SO_STATUSES = SO_STATUSES;

/* ── AJAX: create a sales order (modal document builder) ──── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $customerId = input_int($_POST, 'customer_id');
    $channel    = input($_POST, 'sales_channel');
    $lpo        = input($_POST, 'customer_lpo_number');
    $gDiscount  = (float) ($_POST['discount_amount'] ?? 0);
    $taxRate    = (float) ($_POST['tax_rate'] ?? 0);

    if ($customerId === null || !db_value("SELECT 1 FROM customers WHERE customer_id = :id", [':id' => $customerId])) {
        json_response(['success' => false, 'message' => 'Please choose a valid customer.']);
    }
    if (!in_array($channel, ['pos', 'ecommerce', 'b2b'], true)) {
        json_response(['success' => false, 'message' => 'Please choose a sales channel.']);
    }
    if ($gDiscount < 0 || $taxRate < 0 || $taxRate > 1) {
        json_response(['success' => false, 'message' => 'Invalid discount or tax rate.']);
    }

    $prodIds   = $_POST['product_id'] ?? [];
    $qtys      = $_POST['quantity'] ?? [];
    $prices    = $_POST['unit_price'] ?? [];
    $discounts = $_POST['item_discount'] ?? [];
    $items     = [];
    $subtotal  = 0.0;
    foreach ((array) $prodIds as $i => $pid) {
        $pid = (int) $pid; $qty = (float) ($qtys[$i] ?? 0);
        $price = (float) ($prices[$i] ?? 0); $disc = (float) ($discounts[$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        if ($price < 0 || $disc < 0) {
            json_response(['success' => false, 'message' => 'Line prices and discounts must be 0 or more.']);
        }
        // The discount is an amount, not a percentage. Typing 20 into
        // it against a 15.00 line is somebody meaning 20% — and the
        // line's subtotal is generated as (qty × price − discount), so
        // accepting it stores a negative line under a header that
        // floors itself at zero, and the document contradicts itself.
        // Equal is allowed: that is a line given away.
        if ($disc > $qty * $price + 0.005) {
            json_response(['success' => false, 'message' => sprintf(
                'A discount of %s is more than the line it is on (%s). '
                . 'The discount is an amount, not a percentage.',
                num($disc, 2), num($qty * $price, 2)
            )]);
        }
        if (!db_value("SELECT 1 FROM products WHERE product_id = :id", [':id' => $pid])) {
            json_response(['success' => false, 'message' => 'A selected product no longer exists.']);
        }
        $items[] = ['pid' => $pid, 'qty' => $qty, 'price' => $price, 'disc' => $disc];
        $subtotal += max(0, $qty * $price - $disc);
    }
    if (!$items) {
        json_response(['success' => false, 'message' => 'Add at least one product line.']);
    }

    $afterDisc = max(0, $subtotal - $gDiscount);
    $tax = $afterDisc * $taxRate;
    $totalAmt = $afterDisc + $tax;
    $me = current_user();
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $currencyId = default_currency_id();
        if ($currencyId === null) {
            throw new RuntimeException('No active currency configured (Settings → Currencies).');
        }
        $number = next_document_number('sales_orders', 'order_number', 'SO');
        $stmt = $pdo->prepare(
            "INSERT INTO sales_orders (order_number, customer_id, customer_lpo_number, sales_channel, sold_by,
                                       currency_id, subtotal, discount_amount, tax_amount, tax_rate, total_amount, status)
             VALUES (:num, :cust, :lpo, :chan, :user, :cur, :sub, :disc, :tax, :rate, :total, 'confirmed')
             RETURNING sales_order_id"
        );
        $stmt->execute([
            ':num' => $number, ':cust' => $customerId, ':lpo' => $lpo ?: null, ':chan' => $channel,
            ':user' => $me['id'], ':cur' => $currencyId, ':sub' => $subtotal, ':disc' => $gDiscount,
            ':tax' => $tax, ':rate' => $taxRate, ':total' => $totalAmt,
        ]);
        $orderId = (int) $stmt->fetchColumn();
        $itemStmt = $pdo->prepare(
            "INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price, discount)
             VALUES (:oid, :pid, :qty, :price, :disc)"
        );
        foreach ($items as $it) {
            $itemStmt->execute([':oid' => $orderId, ':pid' => $it['pid'], ':qty' => $it['qty'], ':price' => $it['price'], ':disc' => $it['disc']]);
        }
        $pdo->commit();
        audit_log('sales_order.create', 'sales_orders', $orderId, ['number' => $number, 'total' => $totalAmt]);
        json_response(['success' => true, 'message' => "Sales order {$number} created."]);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[SO] create failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not create the sales order.'], 500);
    }
}

/* ── POST: change order status ────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'status') {
    csrf_check();
    $id     = input_int($_POST, 'sales_order_id');
    $status = input($_POST, 'status');
    $order  = $id !== null ? db_one("SELECT sales_order_id, order_number, status FROM sales_orders WHERE sales_order_id = :id", [':id' => $id]) : null;

    if (!$order || !in_array($status, $SO_STATUSES, true)) {
        flash('error', 'Invalid status request.');
    } elseif ($status === $order['status']) {
        flash('info', $order['order_number'] . ' is already ' . status_label($status) . '.');
    } elseif (!can_transition('sales_order', $order['status'], $status)) {
        // Delivery statuses are owned by the delivery notes, and
        // invoicing happens through convert_to_invoice.php.
        flash('error', sprintf('%s cannot go from %s to %s.',
            $order['order_number'], status_label($order['status']), status_label($status)));
    } elseif (in_array($status, ['partially_delivered', 'fully_delivered'], true)) {
        flash('error', 'Delivery status is set by posting delivery notes, not by hand.');
    } elseif ($status === 'invoiced') {
        flash('error', 'Use “Convert to invoice” so the invoice is actually created.');
    } elseif ($status === 'cancelled' && !is_admin() && !user_has_role(ROLE_MANAGER)) {
        flash('error', 'Only managers or administrators can cancel a sales order.');
    } else {
        try {
            db_run("UPDATE sales_orders SET status = :s, updated_at = NOW() WHERE sales_order_id = :id",
                [':s' => $status, ':id' => $id]);
            audit_log('sales_order.status', 'sales_orders', $id, [
                'number' => $order['order_number'], 'from' => $order['status'], 'to' => $status,
            ]);
            flash('success', $order['order_number'] . ' marked as ' . status_label($status) . '.');
        } catch (PDOException $ex) {
            error_log('[SO] status change failed: ' . $ex->getMessage());
            flash('error', 'Could not update the order status.');
        }
    }
    redirect('modules/sales/list_sales_orders.php');
}

/* ── Filters ──────────────────────────────────────────────── */
$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$channel = input($_GET, 'channel');
$delivery = input($_GET, 'delivery');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(so.order_number) LIKE :q
                 OR LOWER(COALESCE(so.customer_lpo_number,'')) LIKE :q
                 OR LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if (in_array($status, $SO_STATUSES, true)) {
    $where[] = "so.status = :status";
    $params[':status'] = $status;
} else {
    $status = '';
}
if (in_array($channel, ['pos', 'ecommerce', 'b2b'], true)) {
    $where[] = "so.sales_channel = :channel";
    $params[':channel'] = $channel;
} else {
    $channel = '';
}
// 'outstanding' is the dashboard's view of orders still owing goods;
// the three delivery states filter on the stored value directly.
if ($delivery === 'outstanding') {
    $where[] = "so.status IN ('confirmed','partially_delivered','invoiced')
                AND so.delivery_status <> 'fully_delivered'";
} elseif (in_array($delivery, DELIVERY_STATES, true)) {
    $where[] = "so.delivery_status = :dstat";
    $params[':dstat'] = $delivery;
} else {
    $delivery = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$joinSql = "FROM sales_orders so LEFT JOIN customers c ON c.customer_id = so.customer_id $whereSql";
$total   = (int) db_value("SELECT COUNT(*) $joinSql", $params);
$pg      = paginate($total, $perPage);

$orders = db_all(
    "SELECT so.*, q.quote_number,
            " . customer_name_sql('c') . " AS customer
     FROM sales_orders so
     LEFT JOIN customers c ON c.customer_id = so.customer_id
     LEFT JOIN quotes q ON q.quote_id = so.quote_id
     $whereSql
     ORDER BY so.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$canCancel = is_admin() || user_has_role(ROLE_MANAGER);

// Builder data for the "New Sales Order" modal.

$pageTitle    = 'Sales Orders';
$pageSubtitle = num($total) . ' order' . ($total === 1 ? '' : 's') . ($channel ? ' · ' . $channel . ' channel' : '');
$pageStyles   = ['forms.css'];
$pageScripts  = ['docbuilder.js'];
$breadcrumbs  = [['label' => 'Sales'], ['label' => 'Sales Orders']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter(['q' => $search, 'status' => $status, 'channel' => $channel, 'delivery' => $delivery], fn($v) => $v !== '');
?>

<?php lookup_seed_block('sale_products', 'db-products'); ?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/sales/list_sales_orders.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search order, LPO or customer…">
        <select name="channel" class="form-control toolbar-select" data-autosubmit>
            <option value="">All channels</option>
            <option value="pos" <?= $channel === 'pos' ? 'selected' : '' ?>>POS</option>
            <option value="ecommerce" <?= $channel === 'ecommerce' ? 'selected' : '' ?>>Online (e-commerce)</option>
            <option value="b2b" <?= $channel === 'b2b' ? 'selected' : '' ?>>B2B</option>
        </select>
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach ($SO_STATUSES as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
    
        <?= toolbar_apply('modules/sales/list_sales_orders.php') ?>
    </form>
    <button type="button" class="btn btn-primary" data-modal-open="tplOrderForm" data-modal-title="New Sales Order" data-modal-xl data-modal-auto>
        <?= icon('plus') ?> New Sales Order
    </button>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Channel</th>
                    <th>Quote</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th class="ta-right">Total</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$orders): ?>
                    <tr><td colspan="9" class="table-empty">No sales orders match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($o['order_number']) ?></span>
                            <?php if ($o['customer_lpo_number']): ?>
                                <span class="cell-sub">LPO: <?= e($o['customer_lpo_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($o['customer']) ?></td>
                        <td><span class="role-pill"><?= e($o['sales_channel'] === 'ecommerce' ? 'Online' : strtoupper($o['sales_channel'])) ?></span></td>
                        <td class="cell-muted"><?= e($o['quote_number'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($o['created_at'])) ?></td>
                        <td><span class="badge badge--<?= e(status_tone($o['status'])) ?>"><?= e(status_label($o['status'])) ?></span></td>
                        <td><span class="badge badge--<?= e(status_tone($o['delivery_status'])) ?>"><?= e(status_label($o['delivery_status'])) ?></span></td>
                        <td class="ta-right"><strong><?= e(money($o['total_amount'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <form method="POST" action="<?= e(url('modules/sales/list_sales_orders.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="status">
                                    <input type="hidden" name="sales_order_id" value="<?= (int) $o['sales_order_id'] ?>">
                                    <select name="status" class="form-control status-select" data-autosubmit
                                            aria-label="Change status of <?= e($o['order_number']) ?>">
                                        <option value="<?= e($o['status']) ?>" selected><?= e(status_label($o['status'])) ?></option>
                                        <?php foreach (allowed_transitions('sales_order', $o['status']) as $st): ?>
                                            <?php if (in_array($st, ['partially_delivered','fully_delivered','invoiced'], true)) continue; ?>
                                            <?php if ($st === 'cancelled' && !$canCancel) continue; ?>
                                            <option value="<?= e($st) ?>"><?= e(status_label($st)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <a href="<?= e(url('modules/sales/print_sales_order.php?so_id=' . (int) $o['sales_order_id'])) ?>"
                                   target="_blank" class="icon-btn" title="Print PDF"><?= icon('file') ?></a>
                                <?php // Downloads under the document's own number, rather than
                                      // leaving the browser's viewer to name it after the URL. ?>
                                <a href="<?= e(url('modules/sales/print_sales_order.php?so_id=' . (int) $o['sales_order_id'] . '&dl=1')) ?>"
                                   class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                                <?php if (sales_order_delivery_block($o) === null && $o['delivery_status'] !== 'fully_delivered'): ?>
                                    <a href="<?= e(url('delivery_notes/create.php?source=sales_order&id=' . (int) $o['sales_order_id'])) ?>"
                                       class="icon-btn" title="Create delivery note"><?= icon('truck') ?></a>
                                <?php endif; ?>
                                <?php if (empty($o['converted_invoice_id']) && !in_array($o['status'], ['draft','cancelled'], true)): ?>
                                    <form method="POST" action="<?= e(url('modules/sales/convert_to_invoice.php')) ?>" class="inline-form"
                                          data-confirm="Invoice <?= e($o['order_number']) ?>? Items, pricing, taxes, discounts, terms and notes carry over.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="sales_order_id" value="<?= (int) $o['sales_order_id'] ?>">
                                        <button type="submit" class="icon-btn" title="Convert to invoice"><?= icon('money') ?></button>
                                    </form>
                                <?php elseif (!empty($o['converted_invoice_id'])): ?>
                                    <a href="<?= e(url('invoices/view.php?id=' . (int) $o['converted_invoice_id'])) ?>"
                                       class="icon-btn" title="View invoice"><?= icon('money') ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'sales order'); ?>
</div>

<!-- New sales order modal (document builder) -->
<template id="tplOrderForm">
    <form method="POST" action="<?= e(url('modules/sales/list_sales_orders.php')) ?>" data-ajax data-docbuilder>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Customer <span class="req">*</span></label>
                <?php lookup_select('active_customers', 'customer_id', null, [
                    'prompt'   => '— Select customer —',
                    'label'    => 'Search customers',
                    'required' => true,
                    'empty'    => 'No customer matches that.',
                ]); ?>
            </div>
            <div class="form-group">
                <label class="form-label">Channel <span class="req">*</span></label>
                <select name="sales_channel" class="form-control" required>
                    <option value="pos">POS</option>
                    <option value="b2b">B2B</option>
                    <option value="ecommerce">Online</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Customer LPO <span class="opt">(optional)</span></label>
                <input type="text" name="customer_lpo_number" class="form-control" maxlength="100">
            </div>
        </div>

        <div class="db-head">
            <span>Product</span><span>Qty</span><span>Unit price</span><span>Discount</span><span>Line total</span><span></span>
        </div>
        <div class="db-rows" data-db-rows></div>
        <button type="button" class="btn btn-ghost btn--sm" data-db-add><?= icon('plus') ?> Add line</button>

        <div class="form-grid-2 u-mt">
            <div class="form-group">
                <label class="form-label">Global discount (KES)</label>
                <input type="number" name="discount_amount" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Tax</label>
                <select name="tax_rate" class="form-control">
                    <?= vat_rate_options($_POST['tax_rate'] ?? VAT_RATE_DEFAULT) ?>
                </select>
            </div>
        </div>

        <div class="db-totals">
            <div class="db-t-row"><span class="db-t-label">Subtotal</span><span data-db-subtotal>0.00</span></div>
            <div class="db-t-row"><span class="db-t-label">Tax</span><span data-db-tax>0.00</span></div>
            <div class="db-t-row db-t-grand"><span class="db-t-label">Total</span><span data-db-total>0.00</span></div>
            <div class="db-t-row db-t-profit" data-db-profit-row>
                <span class="db-t-label">Profit</span>
                <span><span data-db-profit>0.00</span>
                      <span class="db-profit-pct" data-db-profit-percent>0.00%</span></span>
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Create order</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
