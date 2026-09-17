<?php

/**
 * ============================================================
 *  Debit Notes — List (uniform UI)
 * ------------------------------------------------------------
 *  Paginated, searchable list of supplier debit notes with
 *  status transitions, print links and a create button. The
 *  sidebar "Debit Notes" entry lands here.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/documents.php';

// Purchasing is a Manager's module: the menu has always hidden it
// from everyone else, but hiding a link is not access control —
// the page was reachable by anyone who knew the URL.
require_role(ROLE_MANAGER);

$DN_STATUSES = ['draft', 'sent', 'processed', 'cancelled'];

/* ── AJAX: raise a debit note against a GRN ───────────────── */
// Returning goods to a supplier takes them back out of stock, so the
// note, its lines and the stock movement commit together.
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $grnId     = input_int($_POST, 'grn_id');
    $issueDate = input($_POST, 'issue_date') ?: date('Y-m-d');

    if ($grnId === null) {
        json_response(['success' => false, 'message' => 'Choose the goods receipt being returned against.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
        json_response(['success' => false, 'message' => 'Please provide a valid issue date.']);
    }

    $grn = db_one("SELECT grn_id, grn_number, supplier_id FROM grns WHERE grn_id = :id", [':id' => $grnId]);
    if (!$grn) {
        json_response(['success' => false, 'message' => 'That goods receipt no longer exists.']);
    }

    // What each receipt line still has available to return.
    $available = [];
    foreach (db_all(
        "SELECT gi.grn_item_id, gi.product_id, gi.unit_cost, p.name,
                (gi.quantity_received - COALESCE((
                     SELECT SUM(dni.quantity_returned) FROM debit_note_items dni
                      JOIN debit_notes dn ON dn.debit_note_id = dni.debit_note_id
                     WHERE dni.grn_item_id = gi.grn_item_id
                       AND dn.status <> 'cancelled'
                 ), 0)) AS returnable
           FROM grn_items gi
           JOIN products p ON p.product_id = gi.product_id
          WHERE gi.grn_id = :id",
        [':id' => $grnId]
    ) as $row) {
        $available[(int) $row['grn_item_id']] = $row;
    }

    $validReasons = ['damaged', 'expired', 'short_expiry', 'wrong_item', 'defective'];
    $lines = [];
    $total = 0.0;
    foreach ((array) ($_POST['grn_item_id'] ?? []) as $i => $rawId) {
        $itemId = (int) $rawId;
        $qty    = (float) ($_POST['quantity'][$i] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if (!isset($available[$itemId])) {
            json_response(['success' => false, 'message' => 'A line was submitted that is not on that receipt.']);
        }
        $line = $available[$itemId];
        if ($qty > (float) $line['returnable'] + 1e-9) {
            json_response(['success' => false, 'message' => sprintf(
                '%s: only %s available to return.', $line['name'], num($line['returnable'], 2)
            )]);
        }
        $reason = (string) ($_POST['reason_code'][$i] ?? '');
        if (!in_array($reason, $validReasons, true)) {
            json_response(['success' => false, 'message' => 'Choose a return reason for every line.']);
        }
        $cost = (float) $line['unit_cost'];
        $lines[] = [
            'grn_item_id' => $itemId,
            'product_id'  => (int) $line['product_id'],
            'qty'         => $qty,
            'reason'      => $reason,
            'note'        => trim((string) ($_POST['reason_description'][$i] ?? '')),
            'cost'        => $cost,
        ];
        $total += $qty * $cost;
    }

    if (!$lines) {
        json_response(['success' => false, 'message' => 'Enter a quantity for at least one line.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $number = next_document_number('debit_notes', 'debit_note_number', 'DN-P');

        $stmt = $pdo->prepare(
            "INSERT INTO debit_notes
                (debit_note_number, grn_id, supplier_id, issued_by, issue_date, total_refund_amount, status)
             VALUES (:num, :grn, :sup, :by, :date, :total, 'draft')
             RETURNING debit_note_id"
        );
        $stmt->execute([
            ':num' => $number, ':grn' => $grnId, ':sup' => $grn['supplier_id'],
            ':by' => current_user()['id'], ':date' => $issueDate, ':total' => $total,
        ]);
        $noteId = (int) $stmt->fetchColumn();

        $itemStmt = $pdo->prepare(
            "INSERT INTO debit_note_items
                (debit_note_id, product_id, grn_item_id, quantity_returned,
                 reason_code, reason_description, unit_cost)
             VALUES (:note, :pid, :gitem, :qty, :reason, :desc, :cost)"
        );
        $stockStmt = $pdo->prepare(
            "UPDATE products SET stock_quantity = stock_quantity - :qty, updated_at = NOW()
              WHERE product_id = :pid RETURNING stock_quantity"
        );
        $wh = db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1");

        foreach ($lines as $l) {
            $itemStmt->execute([
                ':note' => $noteId, ':pid' => $l['product_id'], ':gitem' => $l['grn_item_id'],
                ':qty' => $l['qty'], ':reason' => $l['reason'],
                ':desc' => $l['note'] !== '' ? $l['note'] : null, ':cost' => $l['cost'],
            ]);

            // Goods going back to the supplier leave stock.
            $stockStmt->execute([':qty' => $l['qty'], ':pid' => $l['product_id']]);
            $balance = (float) $stockStmt->fetchColumn();
            try {
                db_run(
                    "INSERT INTO inventory_transactions
                        (product_id, warehouse_id, quantity, movement_type,
                         reference_table, reference_id, reference_number, balance_after, created_by)
                     VALUES (:pid, :wh, :qty, 'return', 'debit_notes', :ref, :num, :bal, :by)",
                    [
                        ':pid' => $l['product_id'], ':wh' => $wh, ':qty' => -$l['qty'],
                        ':ref' => $noteId, ':num' => $number, ':bal' => $balance,
                        ':by' => current_user()['id'],
                    ]
                );
            } catch (PDOException $e) {
                error_log('[DEBIT NOTE] ledger write skipped: ' . $e->getMessage());
            }
        }

        $pdo->commit();
        audit_log('debit_note.create', 'debit_notes', $noteId, [
            'number' => $number, 'grn' => $grn['grn_number'], 'total' => $total,
        ]);
        json_response(['success' => true, 'message' => "$number raised — stock adjusted."]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[DEBIT NOTE] create failed: ' . $ex->getMessage());
        // The negative-stock trigger writes a human message.
        $raw = $ex->getMessage();
        $msg = str_contains($raw, 'ERROR:')
            ? trim(preg_replace('/^.*ERROR:\s*/s', '', explode("\n", $raw)[0]))
            : 'Could not raise the debit note. Please try again.';
        json_response(['success' => false, 'message' => $msg], 500);
    }
}

/* ── POST: change status ──────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'status') {
    csrf_check();
    $id     = input_int($_POST, 'debit_note_id');
    $status = input($_POST, 'status');
    $dn     = $id !== null ? db_one("SELECT debit_note_id, debit_note_number, status FROM debit_notes WHERE debit_note_id = :id", [':id' => $id]) : null;

    if (!$dn || !in_array($status, $DN_STATUSES, true)) {
        flash('error', 'Invalid status request.');
    } elseif ($status === $dn['status']) {
        flash('info', $dn['debit_note_number'] . ' is already ' . $status . '.');
    } elseif ($status === 'cancelled' && !is_admin() && !user_has_role(ROLE_MANAGER)) {
        flash('error', 'Only managers or administrators can cancel a debit note.');
    } elseif ($dn['status'] === 'cancelled') {
        // Cancelling put the goods back on the shelf. Reviving the
        // note would have to take them off again, and the quantities
        // may well have moved on since. A cancelled note is finished:
        // raise a fresh one against the receipt.
        flash('error', $dn['debit_note_number'] . ' was cancelled and the stock was put back. '
            . 'Raise a new debit note against the receipt instead of reviving this one.');
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            db_run("UPDATE debit_notes SET status = :s, updated_at = NOW() WHERE debit_note_id = :id",
                [':s' => $status, ':id' => $id]);

            // Cancelling says the goods never went back — so they are
            // on the shelf again, and the supplier is owed for them
            // again. Payables read the notes directly, so the debt
            // returns on its own; the stock has to be put back by hand,
            // and if it were not, cancelling would restore the debt
            // while the goods stayed gone.
            if ($status === 'cancelled') {
                $restore = $pdo->prepare(
                    "UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
                      WHERE product_id = :pid RETURNING stock_quantity"
                );
                $wh = db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1");

                foreach (db_all(
                    "SELECT product_id, quantity_returned FROM debit_note_items WHERE debit_note_id = :id",
                    [':id' => $id]
                ) as $line) {
                    $restore->execute([':qty' => $line['quantity_returned'], ':pid' => $line['product_id']]);
                    $balance = (float) $restore->fetchColumn();
                    try {
                        db_run(
                            "INSERT INTO inventory_transactions
                                (product_id, warehouse_id, quantity, movement_type,
                                 reference_table, reference_id, reference_number, balance_after, created_by)
                             VALUES (:pid, :wh, :qty, 'return_reversal', 'debit_notes', :ref, :num, :bal, :by)",
                            [
                                ':pid' => $line['product_id'], ':wh' => $wh,
                                ':qty' => $line['quantity_returned'], ':ref' => $id,
                                ':num' => $dn['debit_note_number'], ':bal' => $balance,
                                ':by'  => current_user()['id'],
                            ]
                        );
                    } catch (PDOException $e) {
                        error_log('[DN] cancel ledger write skipped: ' . $e->getMessage());
                    }
                }
            }

            $pdo->commit();
            audit_log('debit_note.status', 'debit_notes', $id, [
                'number' => $dn['debit_note_number'], 'from' => $dn['status'], 'to' => $status,
            ]);
            flash('success', $dn['debit_note_number'] . ' marked as ' . $status . '.'
                . ($status === 'cancelled' ? ' The goods are back in stock and owed for again.' : ''));
        } catch (PDOException $ex) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('[DN] status change failed: ' . $ex->getMessage());
            flash('error', db_rule_message($ex, 'Could not update the debit note status.'));
        }
    }
    redirect('modules/purchasing/list_debit_notes.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(dn.debit_note_number) LIKE :q OR LOWER(s.name) LIKE :q OR LOWER(g.grn_number) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if (in_array($status, $DN_STATUSES, true)) {
    $where[] = "dn.status = :status";
    $params[':status'] = $status;
} else {
    $status = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$joinSql = "FROM debit_notes dn
            LEFT JOIN suppliers s ON s.supplier_id = dn.supplier_id
            LEFT JOIN grns g ON g.grn_id = dn.grn_id
            $whereSql";
$total = (int) db_value("SELECT COUNT(*) $joinSql", $params);
$pg    = paginate($total, $perPage);

$notes = db_all(
    "SELECT dn.*, s.name AS supplier_name, g.grn_number,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
     FROM debit_notes dn
     LEFT JOIN suppliers s ON s.supplier_id = dn.supplier_id
     LEFT JOIN grns g ON g.grn_id = dn.grn_id
     LEFT JOIN users u ON u.user_id = dn.issued_by
     $whereSql
     ORDER BY dn.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$canCancel = is_admin() || user_has_role(ROLE_MANAGER);

$pageTitle    = 'Debit Notes';
$pageSubtitle = 'Supplier returns and refunds';
$pageStyles   = ['forms.css'];

// Goods receipts that still have quantity available to return.
$openGrns = db_all(
    "SELECT g.grn_id, g.grn_number, g.receipt_date, s.name AS supplier
       FROM grns g
       JOIN suppliers s ON s.supplier_id = g.supplier_id
      WHERE g.status <> 'cancelled'
      ORDER BY g.grn_id DESC
      LIMIT 200"
);
$grnLines = [];
foreach (db_all(
    "SELECT gi.grn_id, gi.grn_item_id, p.name, p.sku, gi.unit_cost,
            (gi.quantity_received - COALESCE((
                 SELECT SUM(dni.quantity_returned) FROM debit_note_items dni
                  JOIN debit_notes dn ON dn.debit_note_id = dni.debit_note_id
                 WHERE dni.grn_item_id = gi.grn_item_id AND dn.status <> 'cancelled'
             ), 0)) AS returnable
       FROM grn_items gi
       JOIN grns g ON g.grn_id = gi.grn_id
       JOIN products p ON p.product_id = gi.product_id
      WHERE g.status <> 'cancelled'
      ORDER BY p.name"
) as $l) {
    if ((float) $l['returnable'] <= 0) {
        continue;
    }
    $grnLines[(int) $l['grn_id']][] = [
        'item_id'    => (int) $l['grn_item_id'],
        'name'       => $l['name'],
        'sku'        => $l['sku'],
        'returnable' => (float) $l['returnable'],
        'cost'       => (float) $l['unit_cost'],
    ];
}
$breadcrumbs  = [['label' => 'Purchasing'], ['label' => 'Debit Notes']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter(['q' => $search, 'status' => $status], fn($v) => $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/purchasing/list_debit_notes.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search number, supplier or GRN…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach ($DN_STATUSES as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
    
        <?= toolbar_apply('modules/purchasing/list_debit_notes.php') ?>
    </form>
    <button type="button" class="btn btn-primary"
            data-modal-open="tplDebitForm" data-modal-title="New Debit Note" data-modal-xl data-modal-auto>
        <?= icon('plus') ?> New Debit Note
    </button>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Supplier</th>
                    <th>GRN</th>
                    <th>Issued</th>
                    <th>By</th>
                    <th>Status</th>
                    <th class="ta-right">Refund</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$notes): ?>
                    <tr><td colspan="8" class="table-empty">No debit notes yet. Create one for a supplier return.</td></tr>
                <?php endif; ?>
                <?php foreach ($notes as $dn): ?>
                    <tr>
                        <td><span class="cell-title"><?= e($dn['debit_note_number']) ?></span></td>
                        <td><?= e($dn['supplier_name'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e($dn['grn_number'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($dn['issue_date'])) ?></td>
                        <td class="cell-muted"><?= e($dn['issued_by_name'] ?: '—') ?></td>
                        <td><span class="badge badge--<?= e($dn['status']) ?>"><?= e($dn['status']) ?></span></td>
                        <td class="ta-right"><strong><?= e(money($dn['total_refund_amount'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <form method="POST" action="<?= e(url('modules/purchasing/list_debit_notes.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="status">
                                    <input type="hidden" name="debit_note_id" value="<?= (int) $dn['debit_note_id'] ?>">
                                    <select name="status" class="form-control status-select" data-autosubmit
                                            aria-label="Change status of <?= e($dn['debit_note_number']) ?>">
                                        <?php foreach ($DN_STATUSES as $st): ?>
                                            <?php if ($st === 'cancelled' && !$canCancel && $dn['status'] !== 'cancelled') continue; ?>
                                            <option value="<?= $st ?>" <?= $dn['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <a href="<?= e(url('modules/purchasing/print_debit_note.php?dn_id=' . (int) $dn['debit_note_id'])) ?>"
                                   target="_blank" class="icon-btn" title="Print PDF"><?= icon('file') ?></a>
                                <?php // Downloads under the document's own number, rather than
                                      // leaving the browser's viewer to name it after the URL. ?>
                                <a href="<?= e(url('modules/purchasing/print_debit_note.php?dn_id=' . (int) $dn['debit_note_id'] . '&dl=1')) ?>"
                                   class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'debit note'); ?>
</div>

<script type="application/json" id="dn-grn-lines"><?= json_encode($grnLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<template id="tplDebitForm">
    <form method="POST" action="<?= e(url('modules/purchasing/list_debit_notes.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Goods receipt <span class="req">*</span></label>
                <select name="grn_id" class="form-control" required data-dn-grn>
                    <option value="">— Select goods receipt —</option>
                    <?php foreach ($openGrns as $g): ?>
                        <option value="<?= (int) $g['grn_id'] ?>">
                            <?= e($g['grn_number']) ?> — <?= e($g['supplier']) ?> (<?= e(fmt_date($g['receipt_date'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Issue date <span class="req">*</span></label>
                <input type="date" name="issue_date" class="form-control" required value="<?= e(date('Y-m-d')) ?>">
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table" data-dn-table hidden>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="ta-right">Returnable</th>
                        <th class="ta-right">Return qty</th>
                        <th>Reason</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody data-dn-rows></tbody>
            </table>
        </div>
        <p class="form-hint" data-dn-empty>Choose a goods receipt to list what can be returned.</p>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Raise debit note</button>
        </div>
    </form>
</template>

<script nonce="<?= csp_nonce() ?>">
    // Render the chosen receipt's returnable lines. Quantities start
    // at zero — returning is the exception, not the default.
    (function () {
        let lines = {};
        try { lines = JSON.parse(document.getElementById('dn-grn-lines').textContent) || {}; }
        catch (e) { lines = {}; }

        const reasons = [
            ['damaged', 'Damaged'], ['expired', 'Expired'],
            ['short_expiry', 'Short expiry'], ['wrong_item', 'Wrong item'],
            ['defective', 'Defective'],
        ];
        const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        document.addEventListener('change', (e) => {
            const sel = e.target.closest('[data-dn-grn]');
            if (!sel) return;
            const form  = sel.closest('form');
            const body  = form.querySelector('[data-dn-rows]');
            const table = form.querySelector('[data-dn-table]');
            const note  = form.querySelector('[data-dn-empty]');
            const rows  = lines[sel.value] || [];

            body.innerHTML = rows.map((l) =>
                '<tr>' +
                '<td>' + esc(l.name) +
                    '<span class="cell-sub">' + esc(l.sku || '') + '</span>' +
                    '<input type="hidden" name="grn_item_id[]" value="' + l.item_id + '"></td>' +
                '<td class="ta-right"><strong>' + l.returnable + '</strong></td>' +
                '<td class="ta-right"><input type="number" class="form-control ta-right" name="quantity[]" ' +
                    'value="0" min="0" max="' + l.returnable + '" step="0.001" style="max-width:110px"></td>' +
                '<td><select class="form-control" name="reason_code[]" style="max-width:150px">' +
                    reasons.map((r) => '<option value="' + r[0] + '">' + r[1] + '</option>').join('') +
                    '</select></td>' +
                '<td><input type="text" class="form-control" name="reason_description[]" maxlength="255"></td>' +
                '</tr>').join('');

            const has = rows.length > 0;
            table.hidden = !has;
            note.hidden = has;
            if (!has && sel.value) {
                note.hidden = false;
                note.textContent = 'That receipt has nothing left to return.';
            }
        });
    })();
</script>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
