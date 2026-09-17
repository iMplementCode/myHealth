<?php

/**
 * ============================================================
 *  Units of Measurement — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page into the shared
 *  layout. Add and edit both use the shared modal + AJAX
 *  pattern, so creating a unit never leaves this page.
 *
 *  `description` is written only when the column exists — some
 *  live databases predate it (see migration 003).
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';

/** True when units_of_measurement carries a description column. */
function uom_has_description(): bool
{
    static $has = null;
    if ($has === null) {
        $has = (bool) db_value(
            "SELECT 1 FROM information_schema.columns
              WHERE table_schema = 'public'
                AND table_name = 'units_of_measurement'
                AND column_name = 'description'"
        );
    }
    return $has;
}

/* ── AJAX: create or update a unit ────────────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    // Inventory is the Manager's to change. Reading the list stays
    // open to anyone signed in — a salesperson has to be able to
    // look a product up — but adding, editing and deleting do not.
    require_role(ROLE_MANAGER);

    $isCreate = $_POST['_action'] === 'create';
    $id       = input_int($_POST, 'uom_id');
    $name     = input($_POST, 'name');
    $abbr     = input($_POST, 'abbreviation');
    $decimals = isset($_POST['allow_decimals']) ? 't' : 'f';
    $desc     = input($_POST, 'description');

    $errors = [];
    if ($name === '') {
        $errors[] = 'Unit name is required.';
    }
    if ($abbr === '') {
        $errors[] = 'Abbreviation is required.';
    }
    if (!$isCreate && ($id === null || !db_value("SELECT 1 FROM units_of_measurement WHERE uom_id = :id", [':id' => $id]))) {
        $errors[] = 'That unit no longer exists.';
    }
    if ($errors) {
        json_response(['success' => false, 'message' => implode(' ', $errors)]);
    }

    $withDesc = uom_has_description();

    try {
        if ($isCreate) {
            $sql = $withDesc
                ? "INSERT INTO units_of_measurement (name, abbreviation, allow_decimals, description)
                   VALUES (:name, :abbr, :dec, :desc) RETURNING uom_id"
                : "INSERT INTO units_of_measurement (name, abbreviation, allow_decimals)
                   VALUES (:name, :abbr, :dec) RETURNING uom_id";
            $params = [':name' => $name, ':abbr' => $abbr, ':dec' => $decimals];
            if ($withDesc) {
                $params[':desc'] = $desc ?: null;
            }
            $id = (int) db_value($sql, $params);
        } else {
            $sql = $withDesc
                ? "UPDATE units_of_measurement
                      SET name = :name, abbreviation = :abbr, allow_decimals = :dec,
                          description = :desc, updated_at = NOW()
                    WHERE uom_id = :id"
                : "UPDATE units_of_measurement
                      SET name = :name, abbreviation = :abbr, allow_decimals = :dec,
                          updated_at = NOW()
                    WHERE uom_id = :id";
            $params = [':name' => $name, ':abbr' => $abbr, ':dec' => $decimals, ':id' => $id];
            if ($withDesc) {
                $params[':desc'] = $desc ?: null;
            }
            db_run($sql, $params);
        }
        audit_log($isCreate ? 'uom.create' : 'uom.update', 'units_of_measurement', $id, ['name' => $name]);
        json_response(['success' => true, 'message' => $isCreate ? 'Unit added.' : 'Unit updated.']);
    } catch (PDOException $ex) {
        error_log('[UOM] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getCode() === '23505'
            ? 'A unit with that name or abbreviation already exists.'
            : db_rule_message($ex, 'Could not save the unit. Please try again.')], 500);
    }
}

/* ── POST: delete a unit ──────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    require_role(ROLE_MANAGER);
    $id  = input_int($_POST, 'uom_id');
    $uom = $id !== null ? db_one("SELECT name FROM units_of_measurement WHERE uom_id = :id", [':id' => $id]) : null;
    if (!$uom) {
        flash('error', 'That unit no longer exists.');
    } else {
        $inUse = (int) db_value("SELECT COUNT(*) FROM products WHERE uom_id = :id", [':id' => $id]);
        if ($inUse > 0) {
            flash('error', sprintf(
                '"%s" is used by %d product%s and cannot be deleted.',
                $uom['name'], $inUse, $inUse === 1 ? '' : 's'
            ));
        } else {
            try {
                db_run("DELETE FROM units_of_measurement WHERE uom_id = :id", [':id' => $id]);
                audit_log('uom.delete', 'units_of_measurement', $id, ['name' => $uom['name']]);
                flash('success', 'Unit "' . $uom['name'] . '" deleted.');
            } catch (PDOException $ex) {
                error_log('[UOM] delete failed: ' . $ex->getMessage());
                flash('error', 'Could not delete that unit.');
            }
        }
    }
    redirect('modules/inventory/view_uom.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search  = input($_GET, 'q');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(u.name) LIKE :q OR LOWER(u.abbreviation) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM units_of_measurement u $whereSql", $params);
$pg    = paginate($total, $perPage);

$descCol = uom_has_description() ? 'u.description' : 'NULL AS description';
$units = db_all(
    "SELECT u.uom_id, u.name, u.abbreviation, u.allow_decimals, $descCol,
            (SELECT COUNT(*) FROM products WHERE uom_id = u.uom_id) AS product_count
     FROM units_of_measurement u
     $whereSql
     ORDER BY u.name
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Who may change the catalogue. The list stays open to anyone signed
// in; the buttons that write are the Manager's, and showing a button
// that answers 403 is its own kind of bug.
$canManage = is_admin() || user_has_role(ROLE_MANAGER);

$pageTitle    = 'Units of Measurement';
$pageSubtitle = 'How stock quantities are counted';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Units (UoM)']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter(['q' => $search], fn($v) => $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/inventory/view_uom.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search units…">
    
        <?= toolbar_apply('modules/inventory/view_uom.php') ?>
    </form>
    <?php if ($canManage): ?>
    <button type="button" class="btn btn-primary"
            data-modal-open="tplUomForm" data-modal-title="Add Unit" data-modal-auto
            data-prefill='<?= e(json_encode(['_action' => 'create', 'uom_id' => ''], JSON_UNESCAPED_SLASHES)) ?>'>
        <?= icon('plus') ?> Add Unit
    </button>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Abbreviation</th>
                    <th>Decimals</th>
                    <th class="ta-right">Products</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$units): ?>
                    <tr><td colspan="5" class="table-empty">No units yet. Add one to start measuring stock.</td></tr>
                <?php endif; ?>
                <?php foreach ($units as $u): ?>
                    <?php
                    $prefill = json_encode([
                        'uom_id'         => $u['uom_id'],
                        'name'           => $u['name'],
                        'abbreviation'   => $u['abbreviation'],
                        'allow_decimals' => $u['allow_decimals'],
                        'description'    => $u['description'] ?? '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    ?>
                    <tr>
                        <td><span class="cell-title"><?= e($u['name']) ?></span></td>
                        <td class="cell-muted"><?= e($u['abbreviation']) ?></td>
                        <td>
                            <span class="badge badge--<?= $u['allow_decimals'] ? 'info' : 'pending' ?>">
                                <?= $u['allow_decimals'] ? 'Fractional' : 'Whole units' ?>
                            </span>
                        </td>
                        <td class="ta-right"><?= e(num($u['product_count'], 0)) ?></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($canManage): ?>
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplUomForm" data-modal-title="Edit Unit"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <form method="POST" action="<?= e(url('modules/inventory/view_uom.php')) ?>" class="inline-form"
                                      data-confirm="Delete <?= e($u['name']) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="uom_id" value="<?= (int) $u['uom_id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                </form>
                                <?php else: ?>
                                    <span class="cell-muted">&mdash;</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'unit'); ?>
</div>

<template id="tplUomForm">
    <form method="POST" action="<?= e(url('modules/inventory/view_uom.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="uom_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Unit name <span class="req">*</span></label>
                <input type="text" name="name" class="form-control" required maxlength="100" placeholder="Pieces">
            </div>
            <div class="form-group">
                <label class="form-label">Abbreviation <span class="req">*</span></label>
                <input type="text" name="abbreviation" class="form-control" required maxlength="15" placeholder="pcs">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="allow_decimals">
                <span>Allow fractional quantities (e.g. metres, kilograms)</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save unit</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
