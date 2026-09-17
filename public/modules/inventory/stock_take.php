<?php

/**
 * ============================================================
 *  Stock Take
 * ------------------------------------------------------------
 *  Counting the shelf, and telling the books what was found.
 *
 *  Every other movement of stock has a document behind it: goods
 *  arrive on a GRN, leave on a delivery note, come back on a
 *  return. This is the one that does not — the shelf simply
 *  disagreeing with the system — and until now there was no way
 *  to record it. The choice was to leave the figure wrong or to
 *  edit the product and leave no trace of who changed what, or
 *  why.
 *
 *  A count is raised, counted, and posted. Only posting moves
 *  stock, and a posted count cannot be reopened: it is the
 *  evidence for an adjustment that has already been made. To
 *  correct one, raise another.
 *
 *  Administrators and Managers only — this writes stock without
 *  a supplier or a customer on the other side of it.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/stocktake.php';
require_once __DIR__ . '/../../../includes/documents.php';
require_once __DIR__ . '/../../../includes/cashbook.php';
require_once __DIR__ . '/../../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('stock_counts', 'stock_count_items', 'inventory_transactions');

$countId = input_int($_GET, 'id');
$count   = stock_count_row($countId);

/* ── POST: raise a new count sheet ───────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'create') {
    csrf_check();

    $scope   = input($_POST, 'scope') ?: 'all';
    $catId   = input_int($_POST, 'category_id');
    $countOn = input($_POST, 'counted_on') ?: date('Y-m-d');
    $whId    = input_int($_POST, 'warehouse_id');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $countOn) || $countOn > date('Y-m-d')) {
        flash('error', 'Please give a valid date, not one in the future.');
        redirect('modules/inventory/stock_take.php');
    }

    $candidates = stock_count_candidates($scope, $catId);
    if (!$candidates) {
        flash('error', 'No active products match that selection, so there is nothing to count.');
        redirect('modules/inventory/stock_take.php');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $number = next_document_number('stock_counts', 'count_number', 'STK');
        $stmt = $pdo->prepare(
            "INSERT INTO stock_counts (count_number, warehouse_id, counted_on, notes, counted_by)
             VALUES (:num, :wh, :d, :notes, :by)
             RETURNING count_id"
        );
        $stmt->execute([
            ':num' => $number,
            ':wh'  => $whId ?? db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1"),
            ':d'   => $countOn,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by'  => current_user()['id'],
        ]);
        $newId = (int) $stmt->fetchColumn();

        // The books as they stand right now, and the cost each line
        // is valued at, are both captured here — not read again at
        // posting time. A price rise next week must not restate what
        // this week's shrinkage was worth.
        $line = $pdo->prepare(
            "INSERT INTO stock_count_items (count_id, product_id, system_quantity, unit_cost)
             VALUES (:c, :p, :sys, :cost)"
        );
        foreach ($candidates as $p) {
            $line->execute([
                ':c' => $newId, ':p' => $p['product_id'],
                ':sys' => $p['stock_quantity'], ':cost' => $p['cost_price'],
            ]);
        }

        $pdo->commit();
        audit_log('stock_count.create', 'stock_counts', $newId, [
            'number' => $number, 'lines' => count($candidates), 'scope' => $scope,
        ]);
        flash('success', $number . ' raised with ' . count($candidates) . ' '
            . (count($candidates) === 1 ? 'line' : plural('line')) . ' to count.');
        redirect('modules/inventory/stock_take.php?id=' . $newId);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[STK] create failed: ' . $ex->getMessage());
        flash('error', 'Could not raise the count sheet.');
        redirect('modules/inventory/stock_take.php');
    }
}

/* ── AJAX: save what was counted ─────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save_lines') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    if (!$count) {
        json_response(['success' => false, 'message' => 'That count no longer exists.']);
    }
    if ($count['status'] !== 'draft') {
        json_response(['success' => false, 'message' =>
            $count['count_number'] . ' has been ' . $count['status'] . ' and can no longer be edited.']);
    }

    $ids      = (array) ($_POST['count_item_id'] ?? []);
    $counted  = (array) ($_POST['counted_quantity'] ?? []);
    $reasons  = (array) ($_POST['reason'] ?? []);
    $notes    = (array) ($_POST['line_note'] ?? []);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $upd = $pdo->prepare(
            "UPDATE stock_count_items
                SET counted_quantity = :qty, reason = :reason, notes = :notes
              WHERE count_item_id = :id AND count_id = :c"
        );
        $saved = 0;
        foreach ($ids as $i => $rawId) {
            $raw = trim((string) ($counted[$i] ?? ''));
            // Blank means "not counted yet", which is not the same
            // as counting zero — and the difference matters when
            // deciding whether a sheet is finished.
            $qty = $raw === '' ? null : (float) $raw;
            if ($qty !== null && $qty < 0) {
                throw new RuntimeException('A counted quantity cannot be negative.');
            }
            $reason = $reasons[$i] ?? 'miscount';
            if (!isset(STOCK_COUNT_REASONS[$reason])) {
                $reason = 'miscount';
            }
            $upd->execute([
                ':qty' => $qty, ':reason' => $reason,
                ':notes' => trim((string) ($notes[$i] ?? '')) ?: null,
                ':id' => (int) $rawId, ':c' => $countId,
            ]);
            $saved++;
        }
        $pdo->commit();

        $items  = stock_count_items((int) $countId);
        $totals = stock_count_totals($items);
        json_response([
            'success' => true,
            'message' => $totals['counted'] . ' of ' . $totals['lines'] . ' '
                       . ($totals['lines'] === 1 ? 'line' : plural('line')) . ' counted. '
                       . ($totals['uncounted'] > 0
                            ? $totals['uncounted'] . ' still to do.'
                            : 'Ready to post.'),
        ]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[STK] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not save the count.')], 500);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[STK] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not save the count.'], 500);
    }
}

/* ── POST: post the count and move stock ─────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'post') {
    csrf_check();

    if (!$count) {
        flash('error', 'That count no longer exists.');
        redirect('modules/inventory/stock_take.php');
    }
    if ($count['status'] !== 'draft') {
        flash('error', $count['count_number'] . ' has already been ' . $count['status'] . '.');
        redirect('modules/inventory/stock_take.php?id=' . $countId);
    }

    $items = stock_count_items((int) $countId);
    $toPost = array_values(array_filter($items, fn($i) => $i['is_counted'] && abs($i['variance']) > 0.0005));

    if (!array_filter($items, fn($i) => $i['is_counted'])) {
        flash('error', 'Nothing has been counted yet, so there is nothing to post.');
        redirect('modules/inventory/stock_take.php?id=' . $countId);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // The difference the counter found is what is applied — not
        // the counted figure itself. Stock that moved legitimately
        // while the counting was going on stays moved.
        $stock = $pdo->prepare(
            "UPDATE products SET stock_quantity = stock_quantity + :delta, updated_at = NOW()
              WHERE product_id = :p RETURNING stock_quantity"
        );
        $ledger = $pdo->prepare(
            "INSERT INTO inventory_transactions
                (product_id, warehouse_id, quantity, movement_type,
                 reference_table, reference_id, reference_number, balance_after, notes, created_by)
             VALUES (:p, :wh, :qty, 'adjustment', 'stock_counts', :ref, :num, :bal, :notes, :by)"
        );

        foreach ($toPost as $i) {
            $stock->execute([':delta' => $i['variance'], ':p' => $i['product_id']]);
            $balance = (float) $stock->fetchColumn();

            $ledger->execute([
                ':p' => $i['product_id'], ':wh' => $count['warehouse_id'],
                ':qty' => $i['variance'], ':ref' => $countId,
                ':num' => $count['count_number'], ':bal' => $balance,
                ':notes' => stock_reason_label((string) $i['reason'])
                            . ($i['notes'] ? ' — ' . $i['notes'] : ''),
                ':by' => current_user()['id'],
            ]);
        }

        db_run(
            "UPDATE stock_counts
                SET status = 'posted', posted_by = :by, posted_at = NOW(), updated_at = NOW()
              WHERE count_id = :id",
            [':by' => current_user()['id'], ':id' => $countId]
        );

        $pdo->commit();

        $totals = stock_count_totals($items);
        audit_log('stock_count.post', 'stock_counts', (int) $countId, [
            'number' => $count['count_number'],
            'lines_adjusted' => count($toPost),
            'net_value' => $totals['net_value'],
        ]);

        flash('success', sprintf(
            '%s posted. %s %s adjusted, %s net %s.',
            $count['count_number'],
            count($toPost) ?: 'No',
            count($toPost) === 1 ? 'line' : plural('line'),
            money(abs($totals['net_value'])),
            $totals['net_value'] < 0 ? 'written off' : 'added back'
        ));
        redirect('modules/inventory/stock_take.php?id=' . $countId);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[STK] post failed: ' . $ex->getMessage());
        // The no-negative-stock trigger is the likely objection, and
        // it says exactly which product it is unhappy about.
        flash('error', db_rule_message($ex, 'Could not post the count.'));
        redirect('modules/inventory/stock_take.php?id=' . $countId);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[STK] post failed: ' . $ex->getMessage());
        flash('error', 'Could not post the count.');
        redirect('modules/inventory/stock_take.php?id=' . $countId);
    }
}

/* ── POST: abandon a draft ───────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'cancel') {
    csrf_check();
    if ($count && $count['status'] === 'draft') {
        db_run("UPDATE stock_counts SET status = 'cancelled', updated_at = NOW() WHERE count_id = :id",
            [':id' => $countId]);
        audit_log('stock_count.cancel', 'stock_counts', (int) $countId, ['number' => $count['count_number']]);
        flash('success', $count['count_number'] . ' cancelled. No stock was changed.');
    }
    redirect('modules/inventory/stock_take.php');
}

/* ═══════════════════════════════════════════════════════════════
 *  One count — the counting sheet
 * ══════════════════════════════════════════════════════════════ */
if ($count) {
    $items  = stock_count_items((int) $countId);
    $totals = stock_count_totals($items);

    if (wants_export()) {
        export_deliver(export_filename('stock-count-' . strtolower($count['count_number'])), [[
            'name'  => 'Stock count',
            'title' => 'Stock count ' . $count['count_number'],
            'meta'  => export_meta([
                'Counted on' => fmt_date($count['counted_on']),
                'Where'      => $count['warehouse_name'] ?? '—',
                'Status'     => ucfirst($count['status']),
                'Counted by' => $count['counted_by_name'] ?? '',
            ]),
            'columns' => [
                ['label' => 'SKU',       'key' => 'sku'],
                ['label' => 'Product',   'key' => 'product_name'],
                ['label' => 'Category',  'key' => 'category_name'],
                ['label' => 'System',    'key' => 'system_quantity',  'type' => 'number'],
                ['label' => 'Counted',   'key' => 'counted_quantity', 'type' => 'number'],
                ['label' => 'Variance',  'key' => 'variance',         'type' => 'number'],
                ['label' => 'Unit cost', 'key' => 'unit_cost',        'type' => 'money'],
                ['label' => 'Value',     'key' => 'variance_value',   'type' => 'money'],
                ['label' => 'Reason',    'key' => 'reason', 'value' => fn($r) => stock_reason_label($r['reason'])],
                ['label' => 'Note',      'key' => 'notes'],
            ],
            'rows'  => $items,
            'total' => true,
        ]]);
    }

    $pageTitle    = 'Stock Count ' . $count['count_number'];
    $pageSubtitle = fmt_date($count['counted_on']) . ' · ' . ($count['warehouse_name'] ?? 'Main store');
    $pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
    $pageScripts  = ['validation.js', 'stocktake.js'];
    $breadcrumbs  = [
        ['label' => 'Inventory'],
        ['label' => 'Stock Take', 'href' => 'modules/inventory/stock_take.php'],
        ['label' => $count['count_number']],
    ];
    require __DIR__ . '/../../../includes/header.php';
    $draft = $count['status'] === 'draft';
    ?>

    <section class="mini-grid">
        <div class="mini-card">
            <span class="mini-label">Counted</span>
            <span class="mini-value"><?= e(num($totals['counted'])) ?> of <?= e(num($totals['lines'])) ?></span>
        </div>
        <div class="mini-card">
            <span class="mini-label">Agreed with the books</span>
            <span class="mini-value tone-in"><?= e(num($totals['agreed'])) ?></span>
        </div>
        <div class="mini-card">
            <span class="mini-label">Short / over</span>
            <span class="mini-value tone-out"><?= e(num($totals['short'])) ?></span>
            <span class="mini-sub"><?= e(num($totals['over'])) ?> over</span>
        </div>
        <div class="mini-card">
            <span class="mini-label">Net value of the difference</span>
            <span class="mini-value <?= $totals['net_value'] < 0 ? 'tone-out' : 'tone-in' ?>">
                <?= e(money($totals['net_value'])) ?>
            </span>
            <span class="mini-sub">at cost</span>
        </div>
    </section>

    <?php if (!$draft): ?>
        <div class="position-warning">
            <?= icon('alert') ?>
            <div>
                <strong><?= e($count['count_number']) ?> was <?= e($count['status']) ?></strong>
                <?php if ($count['status'] === 'posted'): ?>
                    on <?= e(fmt_date($count['posted_at'])) ?>
                    <?= $count['posted_by_name'] ? 'by ' . e($count['posted_by_name']) : '' ?>.
                    It is the evidence for an adjustment that has already been made, so it cannot
                    be reopened. Raise another count to correct it.
                <?php else: ?>
                    . No stock was changed.
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form class="toolbar" method="GET" action="<?= e(url('modules/inventory/stock_take.php')) ?>">
        <input type="hidden" name="id" value="<?= (int) $countId ?>">
        <div class="toolbar-search">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" class="form-control" data-stk-filter autocomplete="off"
                   placeholder="Type a product name or SKU to jump to it…">
        </div>
        <div class="toolbar-actions">
            <?= export_button('modules/inventory/stock_take.php', ['id' => $countId]) ?>
            <button type="button" class="btn btn-ghost" data-print><?= icon('file') ?> Print sheet</button>
            <a class="btn btn-ghost" href="<?= e(url('modules/inventory/stock_take.php')) ?>">
                <?= icon('grid') ?> All counts
            </a>
        </div>
    </form>

    <form method="POST" action="<?= e(url('modules/inventory/stock_take.php?id=' . $countId)) ?>"
          data-ajax data-stk-sheet>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_lines">

        <div class="panel">
            <div class="table-wrap">
                <table class="data-table stk-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="ta-right">System</th>
                            <th class="ta-right">Counted</th>
                            <th class="ta-right">Variance</th>
                            <th class="ta-right">Value</th>
                            <th>Reason</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $lastCat = null; foreach ($items as $i): ?>
                            <?php if (($i['category_name'] ?? '') !== $lastCat): $lastCat = $i['category_name'] ?? ''; ?>
                                <tr class="cashbook-daybreak">
                                    <th scope="rowgroup" colspan="7"><?= e($lastCat ?: 'Uncategorised') ?></th>
                                </tr>
                            <?php endif; ?>
                            <tr data-stk-row
                                data-search="<?= e(strtolower($i['product_name'] . ' ' . $i['sku'])) ?>">
                                <td>
                                    <span class="cell-title"><?= e($i['product_name']) ?></span>
                                    <span class="cell-muted"><?= e($i['sku'] ?: '—') ?></span>
                                    <input type="hidden" name="count_item_id[]" value="<?= (int) $i['count_item_id'] ?>">
                                </td>
                                <td class="ta-right cell-muted" data-stk-system="<?= e(number_format((float) $i['system_quantity'], 3, '.', '')) ?>">
                                    <?= e(num($i['system_quantity'], 2)) ?>
                                    <?= e($i['uom_name'] ?: '') ?>
                                </td>
                                <td class="ta-right">
                                    <input type="number" name="counted_quantity[]" step="0.001" min="0"
                                           class="form-control stk-qty" data-stk-counted
                                           value="<?= $i['counted_quantity'] !== null ? e(number_format((float) $i['counted_quantity'], 3, '.', '')) : '' ?>"
                                           <?= $draft ? '' : 'disabled' ?>
                                           data-cost="<?= e(number_format((float) $i['unit_cost'], 2, '.', '')) ?>">
                                </td>
                                <td class="ta-right" data-stk-variance>
                                    <?= $i['is_counted'] ? e(num($i['variance'], 2)) : '—' ?>
                                </td>
                                <td class="ta-right" data-stk-value>
                                    <?= $i['is_counted'] ? e(money($i['variance_value'])) : '—' ?>
                                </td>
                                <td>
                                    <select name="reason[]" class="form-control stk-reason" <?= $draft ? '' : 'disabled' ?>>
                                        <?php foreach (STOCK_COUNT_REASONS as $key => $meta): ?>
                                            <option value="<?= e($key) ?>" <?= $i['reason'] === $key ? 'selected' : '' ?>>
                                                <?= e($meta['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="line_note[]" class="form-control stk-note"
                                           maxlength="200" value="<?= e($i['notes'] ?? '') ?>"
                                           <?= $draft ? '' : 'disabled' ?> placeholder="optional">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($draft): ?>
                <div class="form-actions panel-actions">
                    <span class="form-hint" data-stk-summary>
                        Enter what is on the shelf. A blank line has not been counted yet —
                        which is not the same as counting zero.
                    </span>
                    <button type="submit" class="btn btn-ghost"><?= icon('save') ?> Save progress</button>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($draft): ?>
        <div class="panel report-caveat">
            <h3 class="report-caveat-title"><?= icon('alert') ?> Posting is final</h3>
            <p>
                Posting applies the difference each line found — counted less what the books
                said when the sheet was raised — and writes it to the stock ledger, where it
                stays traceable. It does <strong>not</strong> set stock to the counted figure,
                so anything delivered or received while the counting was going on is left alone.
            </p>
            <p>
                A posted count cannot be reopened, because it is the evidence for an adjustment
                already made. If it turns out to be wrong, raise another count.
            </p>
            <div class="form-actions">
                <form method="POST" action="<?= e(url('modules/inventory/stock_take.php?id=' . $countId)) ?>"
                      class="inline-form" data-confirm="Cancel <?= e($count['count_number']) ?>? No stock will be changed.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="cancel">
                    <button type="submit" class="btn btn-ghost"><?= icon('x') ?> Cancel this count</button>
                </form>
                <form method="POST" action="<?= e(url('modules/inventory/stock_take.php?id=' . $countId)) ?>"
                      class="inline-form"
                      data-confirm="Post <?= e($count['count_number']) ?>? This moves stock and cannot be undone.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="post">
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?> Post the count</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php
    require __DIR__ . '/../../../includes/footer.php';
    return;
}

/* ═══════════════════════════════════════════════════════════════
 *  The list of counts
 * ══════════════════════════════════════════════════════════════ */

$statusFlt = input($_GET, 'status');
$dateFrom  = input($_GET, 'from');
$dateTo    = input($_GET, 'to');
$perPage   = per_page();

$where  = [];
$params = [];
if (in_array($statusFlt, ['draft', 'posted', 'cancelled'], true)) {
    $where[] = 'sc.status = :st';
    $params[':st'] = $statusFlt;
} else {
    $statusFlt = '';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'sc.counted_on >= :from';
    $params[':from'] = $dateFrom;
} else {
    $dateFrom = '';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'sc.counted_on <= :to';
    $params[':to'] = $dateTo;
} else {
    $dateTo = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM stock_counts sc $whereSql", $params);
$pg    = paginate($total, $perPage);

$counts = db_all(
    "SELECT sc.*, w.name AS warehouse_name,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS counted_by_name,
            (SELECT COUNT(*) FROM stock_count_items i WHERE i.count_id = sc.count_id) AS line_count,
            (SELECT COUNT(*) FROM stock_count_items i
              WHERE i.count_id = sc.count_id AND i.counted_quantity IS NOT NULL
                AND ABS(i.counted_quantity - i.system_quantity) > 0.0005) AS variance_lines,
            (SELECT COALESCE(SUM((i.counted_quantity - i.system_quantity) * i.unit_cost), 0)
               FROM stock_count_items i
              WHERE i.count_id = sc.count_id AND i.counted_quantity IS NOT NULL) AS net_value
       FROM stock_counts sc
       LEFT JOIN warehouses w ON w.warehouse_id = sc.warehouse_id
       LEFT JOIN users u ON u.user_id = sc.counted_by
     $whereSql
     ORDER BY sc.counted_on DESC, sc.count_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$categories  = db_all("SELECT category_id, name FROM categories ORDER BY name");
$warehouses  = db_all("SELECT warehouse_id, name, is_default FROM warehouses WHERE is_active ORDER BY is_default DESC, name");
$openDraft   = db_one("SELECT count_id, count_number FROM stock_counts WHERE status = 'draft' ORDER BY count_id DESC LIMIT 1");
$shrinkage   = stock_shrinkage_between(date('Y-01-01'), date('Y-m-d'));

$pageTitle    = 'Stock Take';
$pageSubtitle = 'Count the shelf, and tell the books what you found';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Stock Take']];

require __DIR__ . '/../../../includes/header.php';

$baseQuery = array_filter([
    'status' => $statusFlt, 'from' => $dateFrom, 'to' => $dateTo,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Counts recorded</span><span class="mini-value"><?= e(num($total)) ?></span></div>
    <div class="mini-card">
        <span class="mini-label">Written off this year</span>
        <span class="mini-value tone-out"><?= e(money(abs($shrinkage['value']))) ?></span>
        <span class="mini-sub">shrinkage, damage and expiry, at cost</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Sheet in progress</span>
        <span class="mini-value mini-value--text">
            <?= $openDraft ? e($openDraft['count_number']) : 'None' ?>
        </span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Stock at cost</span>
        <span class="mini-value"><?= e(money(finance_live_stock_value())) ?></span>
    </div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('modules/inventory/stock_take.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach (['draft' => 'Draft', 'posted' => 'Posted', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= $statusFlt === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control toolbar-select" title="From">
        <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control toolbar-select" title="To">
        <?= toolbar_apply('modules/inventory/stock_take.php') ?>
    </div>
    <div class="toolbar-actions">
        <button type="button" class="btn btn-primary" data-modal-open="tplNewCount"
                data-modal-title="Start a stock count" data-modal-auto>
            <?= icon('plus') ?> New count
        </button>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Counted on</th>
                    <th>Where</th>
                    <th class="ta-right">Lines</th>
                    <th class="ta-right">Differences</th>
                    <th class="ta-right">Net value</th>
                    <th>Status</th>
                    <th>Counted by</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$counts): ?>
                    <tr><td colspan="8" class="table-empty">
                        No stock counts yet. Start one with <strong>New count</strong> — it is the only
                        way to correct a figure that has drifted from the shelf.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($counts as $c): ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('modules/inventory/stock_take.php?id=' . (int) $c['count_id'])) ?>">
                                <?= e($c['count_number']) ?>
                            </a>
                        </td>
                        <td class="cell-muted"><?= e(fmt_date($c['counted_on'])) ?></td>
                        <td class="cell-muted"><?= e($c['warehouse_name'] ?: '—') ?></td>
                        <td class="ta-right"><?= e(num($c['line_count'])) ?></td>
                        <td class="ta-right <?= (int) $c['variance_lines'] > 0 ? 'tone-out' : 'cell-muted' ?>">
                            <?= e(num($c['variance_lines'])) ?>
                        </td>
                        <td class="ta-right <?= (float) $c['net_value'] < 0 ? 'tone-out' : '' ?>">
                            <?= e(money($c['net_value'])) ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= $c['status'] === 'posted' ? 'active' : ($c['status'] === 'draft' ? 'pending' : 'inactive') ?>">
                                <?= e(ucfirst($c['status'])) ?>
                            </span>
                        </td>
                        <td class="cell-muted"><?= e($c['counted_by_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php pagination_nav($pg, $baseQuery, 'count'); ?>
</div>

<template id="tplNewCount">
    <form method="POST" action="<?= e(url('modules/inventory/stock_take.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="create">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Counted on <span class="req">*</span></label>
                <input type="date" name="counted_on" class="form-control" required
                       max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Where</label>
                <select name="warehouse_id" class="form-control" data-searchable="Search warehouses">
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int) $w['warehouse_id'] ?>" <?= $w['is_default'] ? 'selected' : '' ?>>
                            <?= e($w['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">What to count <span class="req">*</span></label>
            <select name="scope" class="form-control" data-stk-scope>
                <option value="low">Only what the system thinks is low</option>
                <option value="moved">Only what has moved in the last 90 days</option>
                <option value="category">One category</option>
                <option value="all">Everything active</option>
            </select>
            <p class="form-hint">
                Counting everything at once is how a stock take never gets finished. A short
                sheet counted often beats a long one counted never — and the low and
                fast-moving lines are where being wrong actually costs a sale.
            </p>
        </div>

        <div class="form-group" data-stk-category hidden>
            <label class="form-label">Category</label>
            <select name="category_id" class="form-control" data-searchable="Search categories">
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['category_id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Note <span class="opt">(optional)</span></label>
            <input type="text" name="notes" class="form-control" maxlength="200"
                   placeholder="e.g. Month-end count, back store">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Create the sheet</button>
        </div>
    </form>
</template>

<script nonce="<?= csp_nonce() ?>">
// The category picker belongs to one choice of scope only.
(function () {
    document.addEventListener('change', function (e) {
        var sel = e.target.closest('[data-stk-scope]');
        if (!sel) return;
        var wrap = sel.form.querySelector('[data-stk-category]');
        if (wrap) wrap.hidden = sel.value !== 'category';
    });
})();
</script>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
