<?php

/**
 * ============================================================
 *  Quotes — List + create/edit in modals (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page. Quotes are created
 *  and edited in a modal document builder (no page hopping),
 *  preserving the legacy calculation rules:
 *      subtotal = Σ (qty × price − line discount)
 *      total    = (subtotal − global discount) × (1 + tax rate)
 *  Edit rewrites line items (delete + reinsert) and is blocked
 *  once a quote is converted — same as the legacy behaviour.
 *  Also ported: delete (non-converted), revert to draft,
 *  convert to proforma, print.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/documents.php';

require_once __DIR__ . '/../../../includes/workflow.php';

$Q_STATUSES = QUOTE_STATUSES;

/* ── AJAX: create / update a quote (modal document builder) ─ */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id         = input_int($_POST, 'quote_id');
    $customerId = input_int($_POST, 'customer_id');
    $expiry     = input($_POST, 'expiry_date');
    $gDiscount  = (float) ($_POST['discount_amount'] ?? 0);
    $taxRate    = (float) ($_POST['tax_rate'] ?? 0);
    $notes      = input($_POST, 'notes');
    $taxNote    = input($_POST, 'tax_note');
    $terms      = input($_POST, 'terms');

    if ($customerId === null || !db_value("SELECT 1 FROM customers WHERE customer_id = :id", [':id' => $customerId])) {
        json_response(['success' => false, 'message' => 'Please choose a valid customer.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
        json_response(['success' => false, 'message' => 'Please provide a valid expiry date.']);
    }
    if ($gDiscount < 0 || $taxRate < 0 || $taxRate > 1) {
        json_response(['success' => false, 'message' => 'Invalid discount or tax rate.']);
    }

    // Collect + validate line items (server-side authoritative).
    $prodIds   = $_POST['product_id'] ?? [];
    $qtys      = $_POST['quantity'] ?? [];
    $prices    = $_POST['unit_price'] ?? [];
    $discounts = $_POST['item_discount'] ?? [];
    // Read by the same index as every other line array. The builder
    // always posts one of these per row, including for rows left
    // blank — otherwise the notes arrive shorter than the lines and
    // each one after a gap lands on the wrong item.
    $itemNotes = $_POST['item_note'] ?? [];
    $items     = [];
    $subtotal  = 0.0;
    foreach ((array) $prodIds as $i => $pid) {
        $pid   = (int) $pid;
        $qty   = (float) ($qtys[$i] ?? 0);
        $price = (float) ($prices[$i] ?? 0);
        $disc  = (float) ($discounts[$i] ?? 0);
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
        // Trimmed, capped to the column, and stored as NULL when
        // blank — so "has a note" is one question with one answer,
        // rather than sometimes '' and sometimes NULL.
        $note = trim((string) ($itemNotes[$i] ?? ''));
        if ($note !== '' && mb_strlen($note) > 500) {
            $note = mb_substr($note, 0, 500);
        }
        $items[]   = ['pid' => $pid, 'qty' => $qty, 'price' => $price, 'disc' => $disc,
                      'note' => $note !== '' ? $note : null];
        $subtotal += max(0, $qty * $price - $disc);
    }
    if (!$items) {
        json_response(['success' => false, 'message' => 'Add at least one product line.']);
    }

    $afterDisc = max(0, $subtotal - $gDiscount);
    $tax       = $afterDisc * $taxRate;
    $total     = $afterDisc + $tax;
    $me        = current_user();
    $pdo       = db();

    try {
        $pdo->beginTransaction();

        if ($id === null) {
            $currencyId = default_currency_id();
            if ($currencyId === null) {
                throw new RuntimeException('No active currency configured (Settings → Currencies).');
            }
            $number = next_document_number('quotes', 'quote_number', 'Q');
            $stmt = $pdo->prepare(
                "INSERT INTO quotes (quote_number, customer_id, prepared_by, issue_date, expiry_date, currency_id,
                                     subtotal, discount_amount, tax_amount, tax_rate, total_amount, notes, terms, tax_note, status)
                 VALUES (:num, :cust, :user, CURRENT_DATE, :expiry, :cur, :sub, :disc, :tax, :rate, :total, :notes, :terms, :taxnote, 'draft')
                 RETURNING quote_id"
            );
            $stmt->execute([
                ':num' => $number, ':cust' => $customerId, ':user' => $me['id'], ':expiry' => $expiry,
                ':cur' => $currencyId, ':sub' => $subtotal, ':disc' => $gDiscount, ':tax' => $tax, ':rate' => $taxRate,
                ':total' => $total, ':notes' => $notes ?: null, ':terms' => $terms ?: null,
                ':taxnote' => $taxNote ?: null,
            ]);
            $id = (int) $stmt->fetchColumn();
            $message = "Quote {$number} created.";
            $auditAction = 'quote.create';
        } else {
            $existing = db_one("SELECT quote_number, status FROM quotes WHERE quote_id = :id FOR UPDATE", [':id' => $id]);
            if (!$existing) {
                throw new RuntimeException('That quote no longer exists.');
            }
            if ($existing['status'] === 'converted') {
                throw new RuntimeException('A converted quote can no longer be edited.');
            }
            $pdo->prepare(
                "UPDATE quotes SET customer_id = :cust, expiry_date = :expiry, subtotal = :sub,
                        discount_amount = :disc, tax_amount = :tax, tax_rate = :rate, total_amount = :total,
                        notes = :notes, terms = :terms, tax_note = :taxnote, updated_at = NOW()
                 WHERE quote_id = :id"
            )->execute([
                ':cust' => $customerId, ':expiry' => $expiry, ':sub' => $subtotal, ':disc' => $gDiscount,
                ':tax' => $tax, ':rate' => $taxRate, ':total' => $total, ':notes' => $notes ?: null, ':terms' => $terms ?: null,
                ':taxnote' => $taxNote ?: null, ':id' => $id,
            ]);
            // Legacy behaviour: rewrite line items.
            $pdo->prepare("DELETE FROM quote_items WHERE quote_id = :id")->execute([':id' => $id]);
            $message = "Quote {$existing['quote_number']} updated.";
            $auditAction = 'quote.update';
        }

        // The note column arrives in migration 056. A database that
        // has not run it still saves quotes; it just cannot keep the
        // line notes yet.
        $hasNote  = column_exists('quote_items', 'note');
        $itemStmt = $pdo->prepare($hasNote
            ? "INSERT INTO quote_items (quote_id, product_id, quantity, unit_price, discount, note)
               VALUES (:qid, :pid, :qty, :price, :disc, :note)"
            : "INSERT INTO quote_items (quote_id, product_id, quantity, unit_price, discount)
               VALUES (:qid, :pid, :qty, :price, :disc)");
        foreach ($items as $it) {
            $row = [':qid' => $id, ':pid' => $it['pid'], ':qty' => $it['qty'],
                    ':price' => $it['price'], ':disc' => $it['disc']];
            if ($hasNote) {
                $row[':note'] = $it['note'];
            }
            $itemStmt->execute($row);
        }

        $pdo->commit();
        audit_log($auditAction, 'quotes', $id, ['total' => $total, 'items' => count($items)]);
        json_response(['success' => true, 'message' => $message]);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[QUOTES] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not save the quote. Please try again.'], 500);
    }
}

/* ── POST: row actions (delete / revert / status) ─────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['delete', 'revert_draft', 'status'], true)) {
    csrf_check();
    $id    = input_int($_POST, 'quote_id');
    $quote = $id !== null ? db_one("SELECT quote_number, status FROM quotes WHERE quote_id = :id", [':id' => $id]) : null;
    $act   = $_POST['_action'];

    if (!$quote) {
        flash('error', 'That quote no longer exists.');
    } elseif ($act === 'delete') {
        if ($quote['status'] === 'converted') {
            flash('error', 'A converted quote cannot be deleted.');
        } else {
            db_run("DELETE FROM quotes WHERE quote_id = :id AND status != 'converted'", [':id' => $id]);
            audit_log('quote.delete', 'quotes', $id, ['number' => $quote['quote_number']]);
            flash('success', $quote['quote_number'] . ' deleted.');
        }
    } elseif ($act === 'revert_draft') {
        db_run("UPDATE quotes SET status = 'draft', updated_at = NOW() WHERE quote_id = :id", [':id' => $id]);
        audit_log('quote.revert_draft', 'quotes', $id, ['number' => $quote['quote_number']]);
        flash('success', $quote['quote_number'] . ' reverted to draft.');
    } else { // status
        $status = input($_POST, 'status');
        if (!in_array($status, ['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired'], true)) {
            flash('error', 'Invalid status.');
        } elseif ($quote['status'] === 'converted') {
            flash('error', 'A converted quote\'s status is managed by its proforma.');
        } elseif (!can_transition('quote', $quote['status'], $status)) {
            flash('error', sprintf('%s cannot go from %s to %s.',
                $quote['quote_number'], status_label($quote['status']), status_label($status)));
        } else {
            // Accepting is what unlocks delivery notes, so record who
            // accepted it and when.
            if ($status === 'accepted') {
                db_run(
                    "UPDATE quotes SET status = 'accepted', accepted_at = NOW(), accepted_by = :u,
                            updated_at = NOW()
                      WHERE quote_id = :id",
                    [':u' => current_user()['id'], ':id' => $id]
                );
            } else {
                db_run("UPDATE quotes SET status = :s, updated_at = NOW() WHERE quote_id = :id", [':s' => $status, ':id' => $id]);
            }
            audit_log('quote.status', 'quotes', $id, ['number' => $quote['quote_number'], 'to' => $status]);
            flash('success', $quote['quote_number'] . ' marked as ' . status_label($status) . '.');
        }
    }
    redirect('modules/sales/manage_quotes.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$from    = input($_GET, 'from');
$to      = input($_GET, 'to');
$perPage = per_page();

// Only well-formed dates reach the query, and a range entered back
// to front is swapped rather than silently returning nothing — the
// same rule the invoice list uses.
$isDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from   = $isDate($from) ? $from : '';
$to     = $isDate($to) ? $to : '';
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where  = [];
$params = [];
if ($from !== '') {
    $where[] = "q.issue_date >= :from";
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[] = "q.issue_date <= :to";
    $params[':to'] = $to;
}
if ($search !== '') {
    $where[] = "(LOWER(q.quote_number) LIKE :q
                 OR LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if (in_array($status, $Q_STATUSES, true)) {
    $where[] = "q.status = :status";
    $params[':status'] = $status;
} else {
    $status = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM quotes q LEFT JOIN customers c ON c.customer_id = q.customer_id $whereSql", $params);
$pg    = paginate($total, $perPage);

$quotes = db_all(
    "SELECT q.*, " . customer_name_sql('c') . " AS customer
     FROM quotes q
     LEFT JOIN customers c ON c.customer_id = q.customer_id
     $whereSql
     ORDER BY q.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Line items for the quotes on this page (powers modal edit prefill).
$itemsByQuote = [];
if ($quotes) {
    $ids = implode(',', array_map(fn($q) => (int) $q['quote_id'], $quotes));
    // The product NAME travels with the line. The picker holds a
    // page of products, not all of them, so without this an edit
    // could open with the line's own product missing from its own
    // <select>.
    // The note comes back with the line, so re-opening a quote shows
    // what was written on it. Guarded: the column arrives in
    // migration 056 and this page must still answer without it.
    $noteCol = column_exists('quote_items', 'note') ? 'qi.note' : 'NULL::text AS note';
    foreach (db_all(
        "SELECT qi.quote_id, qi.product_id, qi.quantity, qi.unit_price, qi.discount, $noteCol,
                p.name || COALESCE(' (' || NULLIF(p.sku, '') || ')', '') AS name
           FROM quote_items qi
           JOIN products p ON p.product_id = qi.product_id
          WHERE qi.quote_id IN ($ids) ORDER BY qi.quote_item_id") as $it) {
        $itemsByQuote[$it['quote_id']][] = [
            'product_id' => (int) $it['product_id'],
            'name'       => $it['name'],
            'quantity'   => (float) $it['quantity'],
            'unit_price' => (float) $it['unit_price'],
            'discount'   => (float) $it['discount'],
            'note'       => $it['note'] ?? null,
        ];
    }
}

// Builder data: customers + product catalogue.
// Phone, email and company come along so the picker can be searched
// by whatever the person has in front of them — a number off a text
// message finds the customer as readily as a name.

$pageTitle    = 'Quotes';
$pageSubtitle = num($total) . ' quote' . ($total === 1 ? '' : 's');
$pageStyles   = ['forms.css'];
$pageScripts  = ['docbuilder.js'];
$breadcrumbs  = [['label' => 'Sales'], ['label' => 'Quotes']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter(
    ['q' => $search, 'status' => $status, 'from' => $from, 'to' => $to],
    fn($v) => $v !== ''
);
?>

<!-- Product catalogue for the document builder -->
<?php lookup_seed_block('sale_products', 'db-products'); ?>

<form class="filter-bar" method="GET" action="<?= e(url('modules/sales/manage_quotes.php')) ?>">
    <div class="filter-row">
        <div class="toolbar-search filter-grow">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="Search number or customer…">
        </div>
        <button type="button" class="btn btn-primary" data-modal-open="tplQuoteForm"
                data-modal-title="New Quote" data-modal-xl data-modal-auto>
            <?= icon('plus') ?> New Quote
        </button>
    </div>

    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">Status</span>
            <select name="status" class="form-control">
                <option value="">Any status</option>
                <?php foreach ($Q_STATUSES as $st): ?>
                    <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?= toolbar_apply('modules/sales/manage_quotes.php') ?>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Customer</th>
                    <th>Issued</th>
                    <th>Expires</th>
                    <th>Status</th>
                    <th class="ta-right">Total</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$quotes): ?>
                    <tr><td colspan="7" class="table-empty">
                        <?php if ($baseQuery): ?>
                            <?php /* There are quotes; none match. Saying "create your
                                     first" here sends somebody looking for a bug that
                                     is really just a date range. */ ?>
                            No quotes match those filters.
                            <a href="<?= e(url('modules/sales/manage_quotes.php')) ?>">Clear them</a>
                        <?php else: ?>
                            No quotes yet — create your first with &ldquo;New Quote&rdquo;.
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($quotes as $q):
                    $prefill = json_encode([
                        'quote_id'        => $q['quote_id'],
                        'customer_id'     => $q['customer_id'],
                        'expiry_date'     => $q['expiry_date'],
                        'discount_amount' => $q['discount_amount'],
                        'notes'           => $q['notes'],
                        'tax_note'        => $q['tax_note'] ?? '',
                        'terms'           => $q['terms'],
                        'items_json'      => json_encode($itemsByQuote[$q['quote_id']] ?? []),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    // The customer picker holds a page of customers, so
                    // the name goes with the id: see App.ensureOption.
                    $prefillLabels = json_encode(
                        ['customer_id' => $q['customer']],
                        JSON_HEX_APOS | JSON_HEX_QUOT
                    );
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($q['quote_number']) ?></span></td>
                        <td><?= e($q['customer']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($q['issue_date'])) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($q['expiry_date'])) ?></td>
                        <td><span class="badge badge--<?= e(status_tone($q['status'])) ?>"><?= e(status_label($q['status'])) ?></span></td>
                        <td class="ta-right"><strong><?= e(money($q['total_amount'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($q['status'] !== 'converted'): ?>
                                    <form method="POST" action="<?= e(url('modules/sales/manage_quotes.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="status">
                                        <input type="hidden" name="quote_id" value="<?= (int) $q['quote_id'] ?>">
                                        <select name="status" class="form-control status-select" data-autosubmit
                                                aria-label="Change status of <?= e($q['quote_number']) ?>">
                                            <option value="<?= e($q['status']) ?>" selected><?= e(status_label($q['status'])) ?></option>
                                            <?php foreach (allowed_transitions('quote', $q['status']) as $st): ?>
                                                <?php if ($st === 'converted') continue; ?>
                                                <option value="<?= e($st) ?>"><?= e(status_label($st)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <button type="button" class="icon-btn" title="Edit quote"
                                            data-modal-open="tplQuoteForm" data-modal-title="Edit <?= e($q['quote_number']) ?>" data-modal-xl
                                            data-prefill="<?= e($prefill) ?>"
                                            data-prefill-labels="<?= e($prefillLabels) ?>"><?= icon('edit') ?></button>
                                    <form method="POST" action="<?= e(url('proformas/create_from_quote.php')) ?>" class="inline-form"
                                          data-confirm="Create a proforma invoice from <?= e($q['quote_number']) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="quote_id" value="<?= (int) $q['quote_id'] ?>">
                                        <button type="submit" class="icon-btn" title="Convert to proforma"><?= icon('check') ?></button>
                                    </form>
                                    <?php if (quote_delivery_block($q) === null && ($q['delivery_status'] ?? '') !== 'fully_delivered'): ?>
                                        <a href="<?= e(url('delivery_notes/create.php?source=quote&id=' . (int) $q['quote_id'])) ?>"
                                           class="icon-btn" title="Create delivery note"><?= icon('truck') ?></a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="POST" action="<?= e(url('modules/sales/manage_quotes.php')) ?>" class="inline-form"
                                          data-confirm="Revert <?= e($q['quote_number']) ?> back to draft?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="revert_draft">
                                        <input type="hidden" name="quote_id" value="<?= (int) $q['quote_id'] ?>">
                                        <button type="submit" class="icon-btn" title="Revert to draft"><?= icon('key') ?></button>
                                    </form>
                                <?php endif; ?>
                                <a href="<?= e(url('modules/sales/print_quote.php?quote_id=' . (int) $q['quote_id'])) ?>"
                                   target="_blank" class="icon-btn" title="Print PDF"><?= icon('file') ?></a>
                                <?php // Downloads under the document's own number, rather than
                                      // leaving the browser's viewer to name it after the URL. ?>
                                <a href="<?= e(url('modules/sales/print_quote.php?quote_id=' . (int) $q['quote_id'] . '&dl=1')) ?>"
                                   class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                                <?php if ($q['status'] !== 'converted'): ?>
                                    <form method="POST" action="<?= e(url('modules/sales/manage_quotes.php')) ?>" class="inline-form"
                                          data-confirm="Delete <?= e($q['quote_number']) ?>? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="quote_id" value="<?= (int) $q['quote_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'quote'); ?>
</div>

<!-- Quote builder modal (create + edit) -->
<template id="tplQuoteForm">
    <!-- data-db-notes turns on the per-line note field. It is opt-in
         so the sales-order, purchase-order and invoice builders,
         which share this code, are unchanged. -->
    <form method="POST" action="<?= e(url('modules/sales/manage_quotes.php')) ?>" data-ajax data-docbuilder data-db-notes class="modal-form--xl">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="quote_id" value="">
        <input type="hidden" name="items_json" value="">

        <div class="form-grid-2">
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
                <label class="form-label">Valid until <span class="req">*</span></label>
                <input type="date" name="expiry_date" class="form-control" required value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
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
            <button type="submit" class="btn btn-primary">Save quote</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
