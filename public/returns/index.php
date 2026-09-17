<?php

/**
 * ============================================================
 *  Goods Return Notes
 * ------------------------------------------------------------
 *  The goods actually coming back, counted at the door.
 *
 *  Receiving offers two ways, because two things really happen:
 *
 *    everything as per the credit note — the common case, one
 *        click, every expected quantity received in full
 *    as returned by the customer      — count each line, because
 *        what came back is not always what was sent
 *
 *  Only what is received goes back into stock, and only if it is
 *  in good condition: damaged goods are still credited to the
 *  customer but are not sellable, so adding them to inventory
 *  would overstate it.
 *
 *  A shortfall — goods the customer kept — is not written off. It
 *  is billed: the page raises an invoice and a delivery note for
 *  exactly what did not come back.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/returns.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/workflow.php';
require_role(ROLE_MANAGER);
require_tables('goods_return_notes', 'goods_return_items', 'credit_notes');

/* ── AJAX: receive the goods ────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'receive') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $return = return_note_row(input_int($_POST, 'grn_return_id'));
    if (!$return) {
        json_response(['success' => false, 'message' => 'That return note no longer exists.']);
    }
    if ($return['status'] !== 'awaiting') {
        json_response(['success' => false, 'message' =>
            "{$return['return_number']} has already been {$return['status']}."]);
    }

    $date = input($_POST, 'return_date') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'Please provide a valid date, not in the future.']);
    }

    $mode  = ($_POST['receive_mode'] ?? 'all') === 'counted' ? 'counted' : 'all';
    $lines = return_note_items((int) $return['grn_return_id']);
    if (!$lines) {
        json_response(['success' => false, 'message' => 'This return note has no lines.']);
    }

    // What was actually received, per line.
    $counted = [];
    if ($mode === 'all') {
        foreach ($lines as $l) {
            $counted[(int) $l['return_item_id']] = [
                'qty'       => (float) $l['quantity_expected'],
                'condition' => 'good',
            ];
        }
    } else {
        $byId = [];
        foreach ($lines as $l) {
            $byId[(int) $l['return_item_id']] = $l;
        }
        foreach ((array) ($_POST['return_item_id'] ?? []) as $i => $rawId) {
            $itemId = (int) $rawId;
            if (!isset($byId[$itemId])) {
                continue;
            }
            $qty = (float) ($_POST['quantity_received'][$i] ?? 0);
            $cond = ($_POST['condition'][$i] ?? 'good') === 'damaged' ? 'damaged' : 'good';
            if ($qty < 0) {
                json_response(['success' => false, 'message' => 'A received quantity cannot be negative.']);
            }
            if ($qty > (float) $byId[$itemId]['quantity_expected'] + 0.0005) {
                json_response(['success' => false, 'message' => sprintf(
                    '%s: %s was sent out, so %s cannot come back.',
                    $byId[$itemId]['product_name'],
                    num($byId[$itemId]['quantity_expected'], 2), num($qty, 2)
                )]);
            }
            $counted[$itemId] = ['qty' => $qty, 'condition' => $cond];
        }
        if (!$counted) {
            json_response(['success' => false, 'message' => 'Enter what was received.']);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $upd = $pdo->prepare(
            "UPDATE goods_return_items
                SET quantity_received = :qty, condition = :cond
              WHERE return_item_id = :id AND grn_return_id = :r"
        );
        foreach ($counted as $itemId => $c) {
            $upd->execute([
                ':qty' => $c['qty'], ':cond' => $c['condition'],
                ':id' => $itemId, ':r' => $return['grn_return_id'],
            ]);
        }

        // Re-read so stock is posted from what the database holds,
        // not from what the form claimed.
        $stored    = return_note_items((int) $return['grn_return_id']);
        $shortfall = 0.0;
        foreach ($stored as $l) {
            $shortfall += max(0.0, (float) $l['quantity_expected'] - (float) $l['quantity_received']);
        }

        return_note_post_stock($return, $stored);

        db_run(
            "UPDATE goods_return_notes
                SET status = 'received', return_date = :d, has_shortfall = :short,
                    received_by = :by, received_at = NOW(), notes = COALESCE(:notes, notes),
                    updated_at = NOW()
              WHERE grn_return_id = :id",
            [
                ':d' => $date,
                ':short' => $shortfall > 0.0005 ? 't' : 'f',
                ':by' => current_user()['id'],
                ':notes' => input($_POST, 'notes') ?: null,
                ':id' => $return['grn_return_id'],
            ]
        );

        $pdo->commit();

        $backIn = 0.0;
        $damaged = 0.0;
        foreach ($stored as $l) {
            if ($l['condition'] === 'good') {
                $backIn += (float) $l['quantity_received'];
            } else {
                $damaged += (float) $l['quantity_received'];
            }
        }

        audit_log('goods_return.receive', 'goods_return_notes', (int) $return['grn_return_id'], [
            'number' => $return['return_number'], 'shortfall' => $shortfall,
        ]);

        $parts = [num($backIn, 2) . ' unit(s) returned to stock against ' . $return['return_number'] . '.'];
        if ($damaged > 0.0005) {
            $parts[] = num($damaged, 2) . ' damaged unit(s) were credited but not restocked.';
        }
        if ($shortfall > 0.0005) {
            $parts[] = num($shortfall, 2) . ' unit(s) did not come back — bill them from this page.';
        }
        json_response(['success' => true, 'message' => implode(' ', $parts)]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[RET] receive failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the return.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[RET] receive failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the return.'], 500);
    }
}

/* ── AJAX: bill what the customer kept ──────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'bill_shortfall') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $return = return_note_row(input_int($_POST, 'grn_return_id'));
    if (!$return) {
        json_response(['success' => false, 'message' => 'That return note no longer exists.']);
    }
    if ($return['status'] !== 'received') {
        json_response(['success' => false, 'message' => 'Receive the goods first, so the shortfall is known.']);
    }
    if ($return['shortfall_invoice_id']) {
        json_response(['success' => false, 'message' =>
            'The shortfall on ' . $return['return_number'] . ' has already been billed as '
            . $return['shortfall_invoice_number'] . '.']);
    }

    $lines = return_note_shortfall((int) $return['grn_return_id']);
    if (!$lines) {
        json_response(['success' => false, 'message' => 'Everything came back — there is nothing to bill.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $currencyId = default_currency_id();
        $subtotal   = return_shortfall_value($lines);

        // Tax at the rate the original invoice charged, so the
        // customer is billed on the same basis as before.
        $orig = db_one(
            "SELECT subtotal, tax_amount, tax_rate FROM invoices WHERE invoice_id = :id",
            [':id' => $return['invoice_id']]
        );
        // The invoice carries its own rate (migration 026); deriving
        // it from the money is the fallback for older rows.
        $rate = $orig && (float) ($orig['tax_rate'] ?? 0) > 0
            ? (float) $orig['tax_rate']
            : ($orig && (float) $orig['subtotal'] > 0
                ? round((float) $orig['tax_amount'] / (float) $orig['subtotal'], 4)
                : 0.0);
        $tax   = round($subtotal * $rate, 2);
        $total = $subtotal + $tax;

        $number = next_document_number('invoices', 'invoice_number', 'INV');
        // Same terms as any other invoice. Without a due date the
        // ageing report falls back to the issue date and calls this
        // overdue tomorrow.
        $stmt = $pdo->prepare(
            "INSERT INTO invoices
                (invoice_number, customer_id, issued_by, issue_date, due_date, currency_id,
                 subtotal, tax_amount, tax_rate, total_amount, status, notes, from_return_id)
             VALUES (:num, :cust, :by, CURRENT_DATE, :due, :cur, :sub, :tax, :rate, :total, 'issued', :notes, :ret)
             RETURNING invoice_id"
        );
        $stmt->execute([
            ':num' => $number, ':cust' => $return['customer_id'], ':by' => current_user()['id'],
            ':due' => invoice_due_date(),
            ':cur' => $currencyId, ':sub' => $subtotal, ':tax' => $tax, ':rate' => $rate, ':total' => $total,
            ':notes' => 'Goods not returned against ' . $return['return_number']
                        . ' (' . $return['cn_number'] . '). Billed as retained by the customer.',
            ':ret' => $return['grn_return_id'],
        ]);
        $invoiceId = (int) $stmt->fetchColumn();

        $insItem = $pdo->prepare(
            "INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, discount)
             VALUES (:inv, :p, :qty, :price, 0)"
        );
        foreach ($lines as $l) {
            $insItem->execute([
                ':inv' => $invoiceId, ':p' => $l['product_id'],
                ':qty' => $l['quantity'], ':price' => $l['unit_price'],
            ]);
        }

        db_run(
            "UPDATE goods_return_notes SET shortfall_invoice_id = :inv, updated_at = NOW()
              WHERE grn_return_id = :id",
            [':inv' => $invoiceId, ':id' => $return['grn_return_id']]
        );

        link_documents('goods_return', (int) $return['grn_return_id'], 'invoice', $invoiceId);

        $pdo->commit();
        audit_log('goods_return.bill_shortfall', 'invoices', $invoiceId, [
            'return' => $return['return_number'], 'invoice' => $number, 'total' => $total,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s raised for %s of goods the customer kept. Create a delivery note from it '
            . 'to record that the goods are already with them.',
            $number, money($total)
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[RET] bill failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not raise the invoice.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[RET] bill failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not raise the invoice.'], 500);
    }
}

/* ── Data ───────────────────────────────────────────────────── */
$statusFilter = input($_GET, 'status');
$search       = input($_GET, 'q');
$perPage      = per_page();

$where  = [];
$params = [];
if (in_array($statusFilter, ['awaiting', 'received', 'cancelled'], true)) {
    $where[] = "r.status = :st";
    $params[':st'] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(LOWER(r.return_number) LIKE :q OR LOWER(cn.cn_number) LIKE :q
                 OR LOWER(COALESCE(i.invoice_number,'')) LIKE :q
                 OR LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value(
    "SELECT COUNT(*) FROM goods_return_notes r
       JOIN credit_notes cn ON cn.credit_note_id = r.credit_note_id
       LEFT JOIN invoices i ON i.invoice_id = r.invoice_id
       LEFT JOIN customers c ON c.customer_id = r.customer_id
     $whereSql",
    $params
);
$pg = paginate($total, $perPage);

$returns = db_all(
    "SELECT r.*, cn.cn_number, cn.total_amount AS credit_total,
            i.invoice_number, si.invoice_number AS shortfall_invoice_number,
            " . customer_name_sql('c') . " AS customer_name,
            (SELECT COALESCE(SUM(quantity_expected), 0) FROM goods_return_items WHERE grn_return_id = r.grn_return_id) AS qty_expected,
            (SELECT COALESCE(SUM(quantity_received), 0) FROM goods_return_items WHERE grn_return_id = r.grn_return_id) AS qty_received
       FROM goods_return_notes r
       JOIN credit_notes cn ON cn.credit_note_id = r.credit_note_id
       LEFT JOIN invoices i ON i.invoice_id = r.invoice_id
       LEFT JOIN invoices si ON si.invoice_id = r.shortfall_invoice_id
       LEFT JOIN customers c ON c.customer_id = r.customer_id
     $whereSql
     ORDER BY r.status = 'awaiting' DESC, r.return_date DESC, r.grn_return_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Lines for the receive modal, so counting does not need a round trip.
$linesByReturn = [];
foreach ($returns as $r) {
    if ($r['status'] === 'awaiting') {
        $linesByReturn[(int) $r['grn_return_id']] = array_map(fn($l) => [
            'return_item_id' => (int) $l['return_item_id'],
            'name'           => $l['product_name'],
            'sku'            => $l['sku'],
            'expected'       => (float) $l['quantity_expected'],
        ], return_note_items((int) $r['grn_return_id']));
    }
}

$awaiting   = (int) db_value("SELECT COUNT(*) FROM goods_return_notes WHERE status = 'awaiting'");
$shortfalls = (int) db_value(
    "SELECT COUNT(*) FROM goods_return_notes WHERE has_shortfall AND shortfall_invoice_id IS NULL"
);
$restocked  = (float) db_value(
    "SELECT COALESCE(SUM(ri.quantity_received * ri.unit_cost), 0)
       FROM goods_return_items ri
       JOIN goods_return_notes r ON r.grn_return_id = ri.grn_return_id
      WHERE r.status = 'received' AND ri.condition = 'good'"
);

$pageTitle    = 'Goods Returns';
$pageSubtitle = 'What comes back after a credit note, and what does not';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js', 'goods-return.js'];
$breadcrumbs  = [['label' => 'Sales'], ['label' => 'Goods Returns']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter([
    'status' => $statusFilter, 'q' => $search,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Awaiting goods</span><span class="mini-value <?= $awaiting ? 'tone-out' : '' ?>"><?= e(num($awaiting)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Shortfalls to bill</span><span class="mini-value <?= $shortfalls ? 'tone-out' : '' ?>"><?= e(num($shortfalls)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Value returned to stock</span><span class="mini-value tone-in"><?= e(money($restocked)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Return notes</span><span class="mini-value"><?= e(num($total)) ?></span></div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('returns/index.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search return, credit note, invoice or customer…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <option value="awaiting"  <?= $statusFilter === 'awaiting' ? 'selected' : '' ?>>Awaiting goods</option>
            <option value="received"  <?= $statusFilter === 'received' ? 'selected' : '' ?>>Received</option>
            <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <?= toolbar_apply('returns/index.php') ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('credit_notes/index.php')) ?>"><?= icon('file') ?> Credit Notes</a>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Return note</th>
                    <th>Credit note</th>
                    <th>Customer</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="ta-right">Expected</th>
                    <th class="ta-right">Received</th>
                    <th>Shortfall</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$returns): ?>
                    <tr><td colspan="9" class="table-empty">
                        No goods returns. One opens automatically when a credit note
                        expecting goods back is approved.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($returns as $r):
                    $short = (float) $r['qty_expected'] - (float) $r['qty_received'];
                    $receiveFill = json_encode([
                        'grn_return_id' => $r['grn_return_id'],
                        'return_date'   => date('Y-m-d'),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    $billFill = json_encode([
                        'grn_return_id' => $r['grn_return_id'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($r['return_number']) ?></span></td>
                        <td>
                            <a href="<?= e(url('credit_notes/view.php?id=' . (int) $r['credit_note_id'])) ?>">
                                <?= e($r['cn_number']) ?>
                            </a>
                            <?php if ($r['invoice_number']): ?>
                                <br><small class="cell-muted"><?= e($r['invoice_number']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['customer_name']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($r['return_date'])) ?></td>
                        <td><span class="badge badge--<?= e(returns_status_tone($r['status'])) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                        <td class="ta-right"><?= e(num($r['qty_expected'], 2)) ?></td>
                        <td class="ta-right <?= $r['status'] === 'received' ? 'tone-in' : 'cell-muted' ?>"><?= e(num($r['qty_received'], 2)) ?></td>
                        <td>
                            <?php if ($r['status'] !== 'received'): ?>
                                <span class="cell-muted">—</span>
                            <?php elseif ($short > 0.0005): ?>
                                <span class="badge badge--inactive"><?= e(num($short, 2)) ?> kept</span>
                                <?php if ($r['shortfall_invoice_number']): ?>
                                    <br><small class="cell-muted">billed as <?= e($r['shortfall_invoice_number']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge--active">All returned</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php // Printable before the goods arrive as well as after:
                                      // awaiting, it is the sheet to count against. ?>
                                <a class="icon-btn" title="Print the goods return note" target="_blank" rel="noopener"
                                   href="<?= e(url('returns/pdf.php?id=' . (int) $r['grn_return_id'])) ?>"><?= icon('report') ?></a>
                                <a class="icon-btn" title="Download the goods return note"
                                   href="<?= e(url('returns/pdf.php?id=' . (int) $r['grn_return_id'] . '&dl=1')) ?>"><?= icon('download') ?></a>
                                <?php if ($r['status'] === 'awaiting'): ?>
                                    <button type="button" class="btn btn-primary btn--sm"
                                            data-modal-open="tplReceiveReturn" data-modal-wide
                                            data-modal-title="Receive <?= e($r['return_number']) ?>"
                                            data-prefill="<?= e($receiveFill) ?>">Receive goods</button>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'received' && $short > 0.0005 && !$r['shortfall_invoice_id']): ?>
                                    <button type="button" class="btn btn-ghost btn--sm"
                                            data-modal-open="tplBillShortfall"
                                            data-modal-title="Bill goods kept · <?= e($r['return_number']) ?>"
                                            data-prefill="<?= e($billFill) ?>">Bill what was kept</button>
                                <?php endif; ?>
                                <?php if ($r['shortfall_invoice_id']): ?>
                                    <a class="icon-btn" title="Open the shortfall invoice"
                                       href="<?= e(url('invoices/index.php?q=' . urlencode((string) $r['shortfall_invoice_number']))) ?>"><?= icon('file') ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'goods return'); ?>
</div>

<template id="tplReceiveReturn">
    <form method="POST" action="<?= e(url('returns/index.php')) ?>" data-ajax data-goods-return>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="receive">
        <input type="hidden" name="grn_return_id" value="">

        <div class="form-group">
            <label class="form-label">How much came back <span class="req">*</span></label>
            <select name="receive_mode" class="form-control" data-return-mode>
                <option value="all">Everything, as per the credit note</option>
                <option value="counted">Count each line — some is missing or damaged</option>
            </select>
            <p class="form-hint" data-return-mode-help>
                Every expected quantity is received in full and goes back into stock.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Date received <span class="req">*</span></label>
            <input type="date" name="return_date" class="form-control" required max="<?= date('Y-m-d') ?>">
        </div>

        <div data-return-lines hidden>
            <div class="db-head db-head--return">
                <span>Product</span><span>Expected</span><span>Received</span><span>Condition</span>
            </div>
            <div data-return-lines-body class="cn-lines"></div>
            <p class="form-hint">
                Anything short of the expected quantity is treated as kept by the
                customer, and can be billed from the list afterwards. Damaged goods
                are credited but not put back on the shelf.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Condition, who delivered it, anything worth recording">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Receive goods</button>
        </div>
    </form>
</template>

<template id="tplBillShortfall">
    <form method="POST" action="<?= e(url('returns/index.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="bill_shortfall">
        <input type="hidden" name="grn_return_id" value="">

        <p class="form-hint">
            Goods that did not come back were not returned, so crediting them would
            be a gift. This raises an invoice for exactly what the customer kept, at
            the prices they were originally charged and with the same tax treatment.
        </p>
        <p class="form-hint">
            The goods are already with the customer, so create a delivery note from
            the new invoice to record that — it will show as delivered rather than
            outstanding.
        </p>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Raise the invoice</button>
        </div>
    </form>
</template>

<script type="application/json" id="return-lines"><?= json_encode(
    $linesByReturn, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
