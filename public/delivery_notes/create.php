<?php

/**
 * ============================================================
 *  Delivery Notes — Create
 * ------------------------------------------------------------
 *  Two steps:
 *    1. Pick a deliverable source — an accepted quote or a
 *       confirmed sales order that still has undelivered lines.
 *    2. Enter the quantity to deliver per line. Quantities are
 *       pre-filled with the outstanding balance and capped at
 *       it, so partial deliveries are the natural default.
 *
 *  Validation runs server-side against the outstanding balance
 *  and available stock; the database triggers enforce the same
 *  rules again when the note is posted, so neither layer is
 *  trusted alone (brief §13).
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/workflow.php';
require_tables('delivery_notes', 'delivery_note_items');

$source = input($_GET, 'source');          // 'quote' | 'sales_order'
$srcId  = input_int($_GET, 'id');

// ─── Handle submission ──────────────────────────────────────
if (is_post()) {
    csrf_check();
    $source = input($_POST, 'source');
    $srcId  = input_int($_POST, 'source_id');
    $qtys   = $_POST['qty'] ?? [];

    if (!in_array($source, ['quote', 'sales_order', 'proforma', 'invoice'], true) || $srcId === null) {
        flash('error', 'Choose a document to deliver against.');
        redirect('delivery_notes/create.php');
    }
    // An invoice or proforma that sits on top of an order or quote
    // delivers against that origin instead, so nothing is counted twice.
    ['source' => $source, 'id' => $srcId] = resolve_delivery_source($source, $srcId);

    $pdo = db();
    $me  = current_user();

    try {
        $pdo->beginTransaction();

        // Re-read the source under lock and re-check it is deliverable.
        if ($source === 'sales_order') {
            $doc = db_one("SELECT * FROM sales_orders WHERE sales_order_id = :id FOR UPDATE", [':id' => $srcId]);
            $block = $doc ? sales_order_delivery_block($doc) : 'That sales order no longer exists.';
        } elseif ($source === 'proforma') {
            $doc = db_one("SELECT * FROM proforma_invoices WHERE pi_id = :id FOR UPDATE", [':id' => $srcId]);
            $block = $doc ? billing_doc_delivery_block($doc, 'proforma') : 'That proforma no longer exists.';
        } elseif ($source === 'invoice') {
            $doc = db_one("SELECT * FROM invoices WHERE invoice_id = :id FOR UPDATE", [':id' => $srcId]);
            $block = $doc ? billing_doc_delivery_block($doc, 'invoice') : 'That invoice no longer exists.';
        } else {
            $doc = db_one("SELECT * FROM quotes WHERE quote_id = :id FOR UPDATE", [':id' => $srcId]);
            $block = $doc ? quote_delivery_block($doc) : 'That quote no longer exists.';
        }
        if ($block !== null) {
            $pdo->rollBack();
            flash('error', $block);
            redirect('delivery_notes/create.php');
        }

        // Validate the quantities against what is still outstanding
        // and against stock on hand.
        $lines  = outstanding_lines($source, $srcId);
        $byProd = [];
        foreach ($lines as $l) {
            $byProd[(int) $l['product_id']] = $l;
        }

        $toDeliver = [];
        $errors    = [];
        foreach ($qtys as $pid => $raw) {
            $pid = (int) $pid;
            $qty = (float) $raw;
            if ($qty <= 0) {
                continue;                      // line simply not delivered now
            }
            if (!isset($byProd[$pid])) {
                $errors[] = 'A product was submitted that is not on the source document.';
                continue;
            }
            $line = $byProd[$pid];
            if ($qty > (float) $line['remaining'] + 1e-9) {
                $errors[] = sprintf(
                    '%s: only %s outstanding, %s entered.',
                    $line['name'], num($line['remaining'], 2), num($qty, 2)
                );
                continue;
            }
            if ($qty > (float) $line['stock_quantity'] + 1e-9) {
                $errors[] = sprintf(
                    '%s: only %s in stock, %s entered.',
                    $line['name'], num($line['stock_quantity'], 2), num($qty, 2)
                );
                continue;
            }
            $toDeliver[$pid] = $qty;
        }

        if (!$toDeliver) {
            $errors[] = 'Enter a quantity for at least one line.';
        }
        if ($errors) {
            $pdo->rollBack();
            flash('error', implode(' ', $errors));
            redirect('delivery_notes/create.php?source=' . $source . '&id=' . $srcId);
        }

        $dnNumber = next_document_number('delivery_notes', 'dn_number', 'DN');
        $wh = db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1");

        $stmt = $pdo->prepare(
            "INSERT INTO delivery_notes
                (dn_number, customer_id, quote_id, sales_order_id, pi_id, invoice_id, warehouse_id,
                 delivery_date, delivered_to, delivery_address, vehicle_reg, driver_name,
                 notes, status, created_by)
             VALUES
                (:num, :cust, :quote, :so, :pi, :inv, :wh,
                 :ddate, :dto, :addr, :veh, :driver,
                 :notes, 'draft', :by)
             RETURNING dn_id"
        );
        $stmt->execute([
            ':num'   => $dnNumber,
            ':cust'  => $doc['customer_id'],
            ':quote' => $source === 'quote' ? $srcId : null,
            ':so'    => $source === 'sales_order' ? $srcId : null,
            ':pi'    => $source === 'proforma' ? $srcId : null,
            ':inv'   => $source === 'invoice' ? $srcId : null,
            ':wh'    => $wh,
            ':ddate' => input($_POST, 'delivery_date') ?: date('Y-m-d'),
            ':dto'   => input($_POST, 'delivered_to') ?: null,
            ':addr'  => input($_POST, 'delivery_address') ?: null,
            ':veh'   => input($_POST, 'vehicle_reg') ?: null,
            ':driver' => input($_POST, 'driver_name') ?: null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by'    => $me['id'],
        ]);
        $dnId = (int) $stmt->fetchColumn();

        $ins = $pdo->prepare(
            "INSERT INTO delivery_note_items (dn_id, product_id, quantity) VALUES (:dn, :p, :q)"
        );
        foreach ($toDeliver as $pid => $qty) {
            $ins->execute([':dn' => $dnId, ':p' => $pid, ':q' => $qty]);
        }

        // Post immediately when asked — this is the action that moves
        // stock, and the triggers do the final enforcement.
        $postNow = !empty($_POST['post_now']);
        if ($postNow) {
            $pdo->prepare(
                "UPDATE delivery_notes SET status='posted', posted_at=NOW(), posted_by=:by WHERE dn_id=:id"
            )->execute([':by' => $me['id'], ':id' => $dnId]);
        }

        $pdo->commit();

        link_documents($source, $srcId, 'delivery_note', $dnId, 'delivered_by');
        audit_log('delivery_note.create', 'delivery_notes', $dnId, [
            'number' => $dnNumber, 'source' => $source, 'source_id' => $srcId,
            'lines'  => count($toDeliver), 'posted' => $postNow,
        ]);
        flash('success', $postNow
            ? "$dnNumber posted — stock has been released."
            : "$dnNumber saved as a draft. Post it to release stock.");
        redirect('delivery_notes/view.php?id=' . $dnId);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[DN] create failed: ' . $ex->getMessage());
        // Trigger messages are written for humans — surface them.
        $msg = $ex instanceof PDOException && str_contains($ex->getMessage(), 'ERROR:')
            ? trim(preg_replace('/^.*ERROR:\s*/s', '', explode("\n", $ex->getMessage())[0]))
            : 'Could not create the delivery note. Please try again.';
        flash('error', $msg);
        redirect('delivery_notes/create.php?source=' . $source . '&id=' . $srcId);
    }
}

// ─── Step 1: choose a source ────────────────────────────────
$doc = null;
$lines = [];
if (in_array($source, ['quote', 'sales_order', 'proforma', 'invoice'], true) && $srcId !== null) {
    ['source' => $source, 'id' => $srcId] = resolve_delivery_source($source, $srcId);

    if ($source === 'proforma') {
        $doc = db_one(
            "SELECT pi.*, pi.pi_number AS number,
                    " . customer_name_sql('c') . " AS customer
             FROM proforma_invoices pi
             LEFT JOIN customers c ON c.customer_id = pi.customer_id
             WHERE pi.pi_id = :id",
            [':id' => $srcId]
        );
        $block = $doc ? billing_doc_delivery_block($doc, 'proforma') : 'That proforma does not exist.';
    } elseif ($source === 'invoice') {
        $doc = db_one(
            "SELECT i.*, i.invoice_number AS number,
                    " . customer_name_sql('c') . " AS customer
             FROM invoices i
             LEFT JOIN customers c ON c.customer_id = i.customer_id
             WHERE i.invoice_id = :id",
            [':id' => $srcId]
        );
        $block = $doc ? billing_doc_delivery_block($doc, 'invoice') : 'That invoice does not exist.';
    } elseif ($source === 'sales_order') {
        $doc = db_one(
            "SELECT so.*, so.order_number AS number,
                    " . customer_name_sql('c') . " AS customer
             FROM sales_orders so
             LEFT JOIN customers c ON c.customer_id = so.customer_id
             WHERE so.sales_order_id = :id",
            [':id' => $srcId]
        );
        $block = $doc ? sales_order_delivery_block($doc) : 'That sales order does not exist.';
    } else {
        $doc = db_one(
            "SELECT q.*, q.quote_number AS number,
                    " . customer_name_sql('c') . " AS customer
             FROM quotes q
             LEFT JOIN customers c ON c.customer_id = q.customer_id
             WHERE q.quote_id = :id",
            [':id' => $srcId]
        );
        $block = $doc ? quote_delivery_block($doc) : 'That quote does not exist.';
    }
    if ($block !== null) {
        flash('error', $block);
        redirect('delivery_notes/create.php');
    }
    $lines = outstanding_lines($source, $srcId);
}

// Deliverable sources, newest first, only those still owing goods.
$openOrders = db_all(
    "SELECT so.sales_order_id AS id, so.order_number AS number, so.status, so.delivery_status,
            " . customer_name_sql('c') . " AS customer
     FROM sales_orders so
     LEFT JOIN customers c ON c.customer_id = so.customer_id
     WHERE so.status IN ('confirmed','partially_delivered','invoiced')
       AND so.delivery_status <> 'fully_delivered'
     ORDER BY so.sales_order_id DESC LIMIT 100"
);
$openProformas = db_all(
    "SELECT pi.pi_id AS id, pi.pi_number AS number, pi.status, pi.delivery_status,
            " . customer_name_sql('c') . " AS customer
     FROM proforma_invoices pi
     LEFT JOIN customers c ON c.customer_id = pi.customer_id
     WHERE pi.status <> 'cancelled'
       AND pi.quote_id IS NULL
       AND pi.delivery_status <> 'fully_delivered'
     ORDER BY pi.pi_id DESC LIMIT 100"
);
// Invoices with nothing upstream — these are the ones with no other
// route to a delivery note.
$openInvoices = db_all(
    "SELECT i.invoice_id AS id, i.invoice_number AS number, i.status, i.delivery_status,
            " . customer_name_sql('c') . " AS customer
     FROM invoices i
     LEFT JOIN customers c ON c.customer_id = i.customer_id
     WHERE i.status NOT IN ('cancelled','draft')
       AND i.sales_order_id IS NULL AND i.quote_id IS NULL AND i.pi_id IS NULL
       AND i.delivery_status <> 'fully_delivered'
     ORDER BY i.invoice_id DESC LIMIT 100"
);
$openQuotes = db_all(
    "SELECT q.quote_id AS id, q.quote_number AS number, q.status, q.delivery_status,
            " . customer_name_sql('c') . " AS customer
     FROM quotes q
     LEFT JOIN customers c ON c.customer_id = q.customer_id
     WHERE q.status IN ('accepted','converted')
       AND q.delivery_status <> 'fully_delivered'
     ORDER BY q.quote_id DESC LIMIT 100"
);

$pageTitle    = 'New Delivery Note';
$pageSubtitle = $doc ? 'Delivering against ' . $doc['number'] : 'Choose what to deliver';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Delivery Notes', 'href' => 'delivery_notes/index.php'], ['label' => 'New']];

require __DIR__ . '/../../includes/header.php';
?>

<?php if (!$doc): ?>
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Deliver against a sales order</h2>
            <span class="panel-sub">Confirmed orders with goods still outstanding</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Delivery</th><th class="ta-right"></th></tr></thead>
                <tbody>
                    <?php if (!$openOrders): ?>
                        <tr><td colspan="5" class="table-empty">No confirmed sales orders awaiting delivery.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($openOrders as $o): ?>
                        <tr>
                            <td><span class="cell-title"><?= e($o['number']) ?></span></td>
                            <td><?= e($o['customer']) ?></td>
                            <td><span class="badge badge--<?= e(status_tone($o['status'])) ?>"><?= e(status_label($o['status'])) ?></span></td>
                            <td><span class="badge badge--<?= e(status_tone($o['delivery_status'])) ?>"><?= e(status_label($o['delivery_status'])) ?></span></td>
                            <td class="ta-right">
                                <a class="btn btn-ghost btn--sm"
                                   href="?source=sales_order&id=<?= (int) $o['id'] ?>">Deliver &rarr;</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Deliver against an accepted quote</h2>
            <span class="panel-sub">Only accepted quotes may be delivered</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Quote</th><th>Customer</th><th>Status</th><th>Delivery</th><th class="ta-right"></th></tr></thead>
                <tbody>
                    <?php if (!$openQuotes): ?>
                        <tr><td colspan="5" class="table-empty">No accepted quotes awaiting delivery.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($openQuotes as $o): ?>
                        <tr>
                            <td><span class="cell-title"><?= e($o['number']) ?></span></td>
                            <td><?= e($o['customer']) ?></td>
                            <td><span class="badge badge--<?= e(status_tone($o['status'])) ?>"><?= e(status_label($o['status'])) ?></span></td>
                            <td><span class="badge badge--<?= e(status_tone($o['delivery_status'])) ?>"><?= e(status_label($o['delivery_status'])) ?></span></td>
                            <td class="ta-right">
                                <a class="btn btn-ghost btn--sm"
                                   href="?source=quote&id=<?= (int) $o['id'] ?>">Deliver &rarr;</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    // Proformas and invoices raised without an order or quote behind
    // them — anything with an upstream document is delivered from
    // there instead, so it is not repeated here.
    foreach ([
        ['Deliver against a proforma invoice', 'Proformas raised without a quote behind them', 'proforma', $openProformas],
        ['Deliver against an invoice', 'Invoices with no order, quote or proforma behind them', 'invoice', $openInvoices],
    ] as [$title, $sub, $srcKey, $rows]): ?>
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title"><?= e($title) ?></h2>
                <span class="panel-sub"><?= e($sub) ?></span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Document</th><th>Customer</th><th>Status</th><th>Delivery</th><th class="ta-right"></th></tr></thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr><td colspan="5" class="table-empty">Nothing awaiting delivery here.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $o): ?>
                            <tr>
                                <td><span class="cell-title"><?= e($o['number']) ?></span></td>
                                <td><?= e($o['customer']) ?></td>
                                <td><span class="badge badge--<?= e(status_tone($o['status'])) ?>"><?= e(status_label($o['status'])) ?></span></td>
                                <td><span class="badge badge--<?= e(status_tone($o['delivery_status'])) ?>"><?= e(status_label($o['delivery_status'])) ?></span></td>
                                <td class="ta-right">
                                    <a class="btn btn-ghost btn--sm"
                                       href="?source=<?= e($srcKey) ?>&id=<?= (int) $o['id'] ?>">Deliver &rarr;</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <div class="toolbar">
        <a href="<?= e(url('delivery_notes/create.php')) ?>" class="btn btn-ghost">&larr; Choose another</a>
    </div>

    <form method="POST" action="<?= e(url('delivery_notes/create.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="source" value="<?= e($source) ?>">
        <input type="hidden" name="source_id" value="<?= (int) $srcId ?>">

        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Delivering against <?= e($doc['number']) ?></h2>
                <span class="panel-sub"><?= e($doc['customer']) ?></span>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label" for="delivery_date">Delivery date</label>
                    <input type="date" id="delivery_date" name="delivery_date" class="form-control"
                           value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="delivered_to">Received by</label>
                    <input type="text" id="delivered_to" name="delivered_to" class="form-control"
                           placeholder="Name of person receiving">
                </div>
                <div class="form-group">
                    <label class="form-label" for="vehicle_reg">Vehicle reg.</label>
                    <input type="text" id="vehicle_reg" name="vehicle_reg" class="form-control" placeholder="KDA 123A">
                </div>
                <div class="form-group">
                    <label class="form-label" for="driver_name">Driver</label>
                    <input type="text" id="driver_name" name="driver_name" class="form-control">
                </div>
                <div class="form-group form-group--wide">
                    <label class="form-label" for="delivery_address">Delivery address</label>
                    <input type="text" id="delivery_address" name="delivery_address" class="form-control">
                </div>
                <div class="form-group form-group--wide">
                    <label class="form-label" for="notes">Notes</label>
                    <textarea id="notes" name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Quantities to deliver</h2>
                <span class="panel-sub">Pre-filled with the outstanding balance — reduce for a partial delivery</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item</th><th>SKU</th>
                            <th class="ta-right">Ordered</th>
                            <th class="ta-right">Delivered</th>
                            <th class="ta-right">Credited</th>
                            <th class="ta-right">Outstanding</th>
                            <th class="ta-right">In stock</th>
                            <th class="ta-right">Deliver now</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $anyOutstanding = false; ?>
                        <?php foreach ($lines as $l): ?>
                            <?php
                            $remaining = (float) $l['remaining'];
                            $stock     = (float) $l['stock_quantity'];
                            $credited  = (float) ($l['credited'] ?? 0);
                            $max       = min($remaining, max($stock, 0));
                            if ($remaining > 0) {
                                $anyOutstanding = true;
                            }
                            ?>
                            <tr>
                                <td><?= e($l['name']) ?></td>
                                <td class="cell-muted"><?= e($l['sku'] ?: '—') ?></td>
                                <td class="ta-right"><?= e(num($l['ordered'], 2)) ?></td>
                                <td class="ta-right"><?= e(num($l['delivered'], 2)) ?></td>
                                <td class="ta-right <?= $credited > 0 ? 'tone-out' : 'cell-muted' ?>"><?= e(num($credited, 2)) ?></td>
                                <td class="ta-right"><strong><?= e(num($remaining, 2)) ?></strong></td>
                                <td class="ta-right <?= $stock < $remaining ? 'cell-danger' : '' ?>"><?= e(num($stock, 2)) ?></td>
                                <td class="ta-right">
                                    <?php if ($remaining <= 0): ?>
                                        <?php // Say which it is. "Complete" against a line
                                              // nobody ever delivered reads as a mistake. ?>
                                        <?php if ($credited > 0 && (float) $l['delivered'] <= 0): ?>
                                            <span class="badge badge--warn">Credited</span>
                                        <?php else: ?>
                                            <span class="badge badge--active">Complete</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <input type="number" class="form-control ta-right"
                                               name="qty[<?= (int) $l['product_id'] ?>]"
                                               value="<?= e(number_format($max, 2, '.', '')) ?>"
                                               min="0" max="<?= e(number_format($max, 3, '.', '')) ?>" step="0.001"
                                               style="max-width:120px; margin-left:auto;">
                                        <?php if ($stock < $remaining): ?>
                                            <div class="field-note">Capped by stock</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$anyOutstanding): ?>
                <p class="feed-empty">Everything on this document has already been delivered.</p>
            <?php else: ?>
                <div class="form-actions">
                    <label class="check-inline">
                        <input type="checkbox" name="post_now" value="1" checked>
                        <span>Post immediately (releases stock)</span>
                    </label>
                    <button type="submit" class="btn btn-primary"><?= icon('truck') ?> Create Delivery Note</button>
                </div>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
