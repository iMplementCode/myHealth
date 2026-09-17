<?php

/**
 * ============================================================
 *  Proforma Invoices — List + workflow + edit/delete/print
 * ------------------------------------------------------------
 *  Paginated list with:
 *    • create a proforma from an existing quote
 *    • edit a proforma (modal document builder) — only while it
 *      has NOT been converted to an invoice (or cancelled)
 *    • delete a proforma — only while NOT converted
 *    • print a proforma (proformas/print.php)
 *    • convert to invoice (proformas/convert.php)
 *
 *  The invoice created on conversion inherits the proforma's
 *  amounts and currency exactly (see convert.php).
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/workflow.php';
require_once __DIR__ . '/../../includes/documents.php';
require_tables('proforma_invoices', 'proforma_invoice_items', 'invoices');

/* ── AJAX: edit a proforma (document builder) ─────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id         = input_int($_POST, 'pi_id');
    $customerId = input_int($_POST, 'customer_id');
    $currencyId = input_int($_POST, 'currency_id');
    $expiry     = input($_POST, 'expiry_date');
    $gDiscount  = (float) ($_POST['discount_amount'] ?? 0);
    $taxRate    = (float) ($_POST['tax_rate'] ?? 0);
    $notes      = input($_POST, 'notes');
    $terms      = input($_POST, 'terms');
    $taxNote    = input($_POST, 'tax_note');

    $existing = $id !== null ? db_one("SELECT pi_number, status FROM proforma_invoices WHERE pi_id = :id", [':id' => $id]) : null;
    if (!$existing) {
        json_response(['success' => false, 'message' => 'That proforma no longer exists.'], 404);
    }
    if (in_array($existing['status'], ['converted', 'cancelled'], true)) {
        json_response(['success' => false, 'message' => 'A ' . $existing['status'] . ' proforma can no longer be edited.']);
    }
    if ($customerId === null || !db_value("SELECT 1 FROM customers WHERE customer_id = :id", [':id' => $customerId])) {
        json_response(['success' => false, 'message' => 'Please choose a valid customer.']);
    }
    if ($currencyId === null || !db_value("SELECT 1 FROM currencies WHERE currency_id = :id", [':id' => $currencyId])) {
        json_response(['success' => false, 'message' => 'Please choose a valid currency.']);
    }
    if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
        json_response(['success' => false, 'message' => 'Please provide a valid expiry date.']);
    }
    if ($gDiscount < 0 || $taxRate < 0 || $taxRate > 1) {
        json_response(['success' => false, 'message' => 'Invalid discount or tax rate.']);
    }

    // Line items (server-authoritative totals).
    $prodIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $discounts = $_POST['item_discount'] ?? [];
    $items = [];
    $subtotal = 0.0;
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
    $tax   = $afterDisc * $taxRate;
    $total = $afterDisc + $tax;
    $pdo   = db();
    try {
        $pdo->beginTransaction();
        // Re-check status under a row lock (no race with a concurrent convert).
        $locked = db_one("SELECT status FROM proforma_invoices WHERE pi_id = :id FOR UPDATE", [':id' => $id]);
        if (!$locked || in_array($locked['status'], ['converted', 'cancelled'], true)) {
            throw new RuntimeException('This proforma can no longer be edited.');
        }
        $pdo->prepare(
            "UPDATE proforma_invoices
             SET customer_id = :cust, currency_id = :cur, expiry_date = :expiry,
                 subtotal = :sub, discount_amount = :disc, tax_amount = :tax, tax_rate = :rate, total_amount = :total,
                 notes = :notes, terms = :terms, tax_note = :taxnote, updated_at = NOW()
             WHERE pi_id = :id"
        )->execute([
            ':cust' => $customerId, ':cur' => $currencyId, ':expiry' => $expiry ?: null,
            ':sub' => $subtotal, ':disc' => $gDiscount, ':tax' => $tax, ':rate' => $taxRate, ':total' => $total,
            ':notes' => $notes ?: null, ':terms' => $terms ?: null,
            ':taxnote' => $taxNote ?: null, ':id' => $id,
        ]);
        $pdo->prepare("DELETE FROM proforma_invoice_items WHERE pi_id = :id")->execute([':id' => $id]);
        $itemStmt = $pdo->prepare(
            "INSERT INTO proforma_invoice_items (pi_id, product_id, quantity, unit_price, discount)
             VALUES (:pi, :pid, :qty, :price, :disc)"
        );
        foreach ($items as $it) {
            $itemStmt->execute([':pi' => $id, ':pid' => $it['pid'], ':qty' => $it['qty'], ':price' => $it['price'], ':disc' => $it['disc']]);
        }
        $pdo->commit();
        audit_log('proforma.update', 'proforma_invoices', $id, ['number' => $existing['pi_number'], 'total' => $total]);
        json_response(['success' => true, 'message' => "Proforma {$existing['pi_number']} updated."]);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PROFORMA] edit failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not save the proforma.'], 500);
    }
}

/* ── POST: delete a proforma (only if not converted) ──────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    $id = input_int($_POST, 'pi_id');
    $pi = $id !== null ? db_one("SELECT pi_number, status, converted_invoice_id FROM proforma_invoices WHERE pi_id = :id", [':id' => $id]) : null;
    if (!$pi) {
        flash('error', 'That proforma no longer exists.');
    } elseif ($pi['status'] === 'converted' || $pi['converted_invoice_id']) {
        flash('error', $pi['pi_number'] . ' has been converted to an invoice and cannot be deleted.');
    } else {
        try {
            // Free the originating quote so it can be re-used.
            $pdo = db();
            $pdo->beginTransaction();
            $quoteId = db_value("SELECT quote_id FROM proforma_invoices WHERE pi_id = :id", [':id' => $id]);
            $pdo->prepare("DELETE FROM proforma_invoices WHERE pi_id = :id")->execute([':id' => $id]);
            if ($quoteId) {
                $pdo->prepare("UPDATE quotes SET status = 'sent', updated_at = NOW() WHERE quote_id = :q AND status = 'converted'")
                    ->execute([':q' => $quoteId]);
            }
            $pdo->commit();
            audit_log('proforma.delete', 'proforma_invoices', $id, ['number' => $pi['pi_number']]);
            flash('success', $pi['pi_number'] . ' deleted.');
        } catch (PDOException $ex) {
            error_log('[PROFORMA] delete failed: ' . $ex->getMessage());
            flash('error', 'Could not delete the proforma.');
        }
    }
    redirect('proformas/index.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(pi.pi_number) LIKE :q
                 OR LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if (in_array($status, PI_STATUSES, true)) {
    $where[] = "pi.status = :status";
    $params[':status'] = $status;
} else {
    $status = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value(
    "SELECT COUNT(*) FROM proforma_invoices pi
     LEFT JOIN customers c ON c.customer_id = pi.customer_id $whereSql",
    $params
);
$pg = paginate($total, $perPage);

$proformas = db_all(
    "SELECT pi.*, i.invoice_number,
            " . customer_name_sql('c') . " AS customer,
            TRIM(CONCAT(cu.first_name, ' ', cu.last_name)) AS converted_by_name
     FROM proforma_invoices pi
     LEFT JOIN customers c ON c.customer_id = pi.customer_id
     LEFT JOIN invoices i ON i.invoice_id = pi.converted_invoice_id
     LEFT JOIN users cu ON cu.user_id = pi.converted_by
     $whereSql
     ORDER BY pi.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Line items for the proformas on this page (for edit prefill).
$itemsByPi = [];
if ($proformas) {
    $ids = implode(',', array_map(fn($p) => (int) $p['pi_id'], $proformas));
    // The product NAME travels with the line: see manage_quotes.php.
    foreach (db_all(
        "SELECT pii.pi_id, pii.product_id, pii.quantity, pii.unit_price, pii.discount,
                p.name || COALESCE(' (' || NULLIF(p.sku, '') || ')', '') AS name
           FROM proforma_invoice_items pii
           JOIN products p ON p.product_id = pii.product_id
          WHERE pii.pi_id IN ($ids) ORDER BY pii.pi_item_id") as $it) {
        $itemsByPi[$it['pi_id']][] = [
            'product_id' => (int) $it['product_id'],
            'name'       => $it['name'],
            'quantity'   => (float) $it['quantity'],
            'unit_price' => (float) $it['unit_price'],
            'discount'   => (float) $it['discount'],
        ];
    }
}

// Quotes eligible to become proformas (not already linked to one).
$eligibleQuotes = db_all(
    "SELECT q.quote_id, q.quote_number, q.total_amount,
            " . customer_name_sql('c') . " AS customer
     FROM quotes q
     LEFT JOIN customers c ON c.customer_id = q.customer_id
     WHERE q.status NOT IN ('rejected', 'expired')
       AND NOT EXISTS (SELECT 1 FROM proforma_invoices p WHERE p.quote_id = q.quote_id)
     ORDER BY q.created_at DESC
     LIMIT 50"
);

// Builder data.
$currencyOptions  = db_all("SELECT currency_id, code, symbol FROM currencies WHERE is_active = TRUE ORDER BY (LOWER(code)='kes') DESC, code");

// Read once rather than per row — the row actions ask for every
// proforma on the page.

$pageTitle    = 'Proforma Invoices';
$pageSubtitle = 'Create from quotes, edit, print and convert to invoices';
$pageStyles   = ['forms.css'];
$pageScripts  = ['docbuilder.js'];
$breadcrumbs  = [['label' => 'Proforma Invoices']];

require __DIR__ . '/../../includes/header.php';
$baseQuery = array_filter(['q' => $search, 'status' => $status], fn($v) => $v !== '');
?>

<?php lookup_seed_block('sale_products', 'db-products'); ?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('proformas/index.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search number or customer…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach (PI_STATUSES as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
    
        <?= toolbar_apply('proformas/index.php') ?>
    </form>
    <?php if ($eligibleQuotes): ?>
        <button type="button" class="btn btn-primary" data-modal-open="tplFromQuote" data-modal-title="New Proforma from Quote" data-modal-auto>
            <?= icon('plus') ?> From Quote
        </button>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Customer</th>
                    <th>Issued</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th>Invoice</th>
                    <th class="ta-right">Total</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$proformas): ?>
                    <tr><td colspan="8" class="table-empty">No proforma invoices yet. Create one from a quote above.</td></tr>
                <?php endif; ?>
                <?php foreach ($proformas as $p):
                    $editable = !in_array($p['status'], ['converted', 'cancelled'], true);
                    $base   = max(0, (float) $p['subtotal'] - (float) $p['discount_amount']);
                    $piRate = $base > 0 ? round((float) $p['tax_amount'] / $base, 4) : 0;
                    $prefill = json_encode([
                        'pi_id'           => $p['pi_id'],
                        'customer_id'     => $p['customer_id'],
                        'currency_id'     => $p['currency_id'],
                        'expiry_date'     => $p['expiry_date'],
                        'discount_amount' => $p['discount_amount'],
                        'tax_rate'        => $piRate,
                        'tax_note'        => $p['tax_note'] ?? '',
                        'notes'           => $p['notes'],
                        'terms'           => $p['terms'],
                        'items_json'      => json_encode($itemsByPi[$p['pi_id']] ?? []),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    // The customer picker holds a page of customers, so
                    // the name goes with the id: see App.ensureOption.
                    $prefillLabels = json_encode(
                        ['customer_id' => $p['customer']],
                        JSON_HEX_APOS | JSON_HEX_QUOT
                    );
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($p['pi_number']) ?></span></td>
                        <td><?= e($p['customer']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($p['issue_date'])) ?></td>
                        <td><span class="badge badge--<?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
                        <td>
                            <?php if ($p['invoice_number']): ?>
                                <a class="panel-link" href="<?= e(url('invoices/view.php?id=' . (int) $p['converted_invoice_id'])) ?>"><?= e($p['invoice_number']) ?></a>
                                <span class="cell-sub">by <?= e($p['converted_by_name'] ?: '—') ?> · <?= e(fmt_date($p['converted_at'])) ?></span>
                            <?php else: ?>
                                <span class="cell-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><strong><?= e(money($p['total_amount'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a href="<?= e(url('proformas/print.php?id=' . (int) $p['pi_id'])) ?>"
                                   class="icon-btn" title="View"><?= icon('file') ?></a>
                                <a href="<?= e(url('proformas/pdf.php?id=' . (int) $p['pi_id'] . '&dl=1')) ?>"
                                   class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                                <?php if ($p['status'] !== 'cancelled' && ($p['delivery_status'] ?? '') !== 'fully_delivered'): ?>
                                    <a href="<?= e(url('delivery_notes/create.php?source=proforma&id=' . (int) $p['pi_id'])) ?>"
                                       class="icon-btn" title="Create delivery note"><?= icon('truck') ?></a>
                                <?php endif; ?>
                                <?php if ($editable): ?>
                                    <button type="button" class="icon-btn" title="Edit"
                                            data-modal-open="tplProformaForm" data-modal-title="Edit <?= e($p['pi_number']) ?>" data-modal-xl
                                            data-prefill="<?= e($prefill) ?>"
                                            data-prefill-labels="<?= e($prefillLabels) ?>"><?= icon('edit') ?></button>
                                    <form method="POST" action="<?= e(url('proformas/convert.php')) ?>" class="inline-form"
                                          data-confirm="Convert <?= e($p['pi_number']) ?> into an invoice? Items, pricing, discounts and terms carry over. After this the proforma can no longer be edited or deleted.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="pi_id" value="<?= (int) $p['pi_id'] ?>">
                                        <button type="submit" class="icon-btn" title="Convert to invoice"><?= icon('check') ?></button>
                                    </form>
                                    <form method="POST" action="<?= e(url('proformas/index.php')) ?>" class="inline-form"
                                          data-confirm="Delete <?= e($p['pi_number']) ?>? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="pi_id" value="<?= (int) $p['pi_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                    </form>
                                <?php else: ?>
                                    <span class="cell-muted"><?= $p['status'] === 'converted' ? 'Converted' : 'Cancelled' ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'proforma'); ?>
</div>

<!-- Create-from-quote modal -->
<template id="tplFromQuote">
    <form method="POST" action="<?= e(url('proformas/create_from_quote.php')) ?>">
        <?= csrf_field() ?>
        <div class="form-group">
            <label class="form-label">Quote <span class="req">*</span></label>
            <select name="quote_id" class="form-control" required data-searchable="Search quotes">
                <option value="">— Select a quote —</option>
                <?php foreach ($eligibleQuotes as $q): ?>
                    <option value="<?= (int) $q['quote_id'] ?>">
                        <?= e($q['quote_number']) ?> · <?= e($q['customer']) ?> · <?= e(money($q['total_amount'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="hint">Customer, items, quantities, pricing, discounts, taxes, notes and terms are carried over.</span>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Create Proforma</button>
        </div>
    </form>
</template>

<!-- Edit proforma modal (document builder) -->
<template id="tplProformaForm">
    <form method="POST" action="<?= e(url('proformas/index.php')) ?>" data-ajax data-docbuilder>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="pi_id" value="">
        <input type="hidden" name="items_json" value="">

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
                <label class="form-label">Currency <span class="req">*</span></label>
                <select name="currency_id" class="form-control" required>
                    <?php foreach ($currencyOptions as $cu): ?>
                        <option value="<?= (int) $cu['currency_id'] ?>"><?= e($cu['code']) ?> (<?= e($cu['symbol']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Valid until</label>
                <input type="date" name="expiry_date" class="form-control">
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

        <div class="form-group">
            <label class="form-label">Tax note <span class="opt">(printed under the totals)</span></label>
            <input type="text" name="tax_note" class="form-control" maxlength="255"
                   list="taxNoteSuggestions"
                   placeholder="e.g. Prices quoted are exclusive of VAT">
            <datalist id="taxNoteSuggestions">
                <option value="Prices quoted are exclusive of VAT.">
                <option value="Prices quoted are inclusive of VAT.">
                <option value="VAT will be charged at the prevailing rate on invoicing.">
                <option value="Zero-rated for VAT.">
            </datalist>
            <p class="form-hint">
                Says in words what the figures do about tax. Leave it blank and
                nothing is printed; when no tax is charged the tax row is left
                off the document entirely.
            </p>
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

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Terms</label>
                <textarea name="terms" class="form-control" rows="2"></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save proforma</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
