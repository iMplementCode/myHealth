<?php

/**
 * ============================================================
 *  Suppliers — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page. Preserves every
 *  legacy action — edit, activate/deactivate, delete — and
 *  adds server-side pagination and search, with modal AJAX
 *  editing and an Add Supplier button.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/export.php';
require_once __DIR__ . '/../../../includes/icons.php';

// Purchasing is a Manager's module: the menu has always hidden it
// from everyone else, but hiding a link is not access control —
// the page was reachable by anyone who knew the URL.
require_role(ROLE_MANAGER);

/* ── AJAX: update supplier (edit modal) ───────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $isCreate = $_POST['_action'] === 'create';
    $id      = input_int($_POST, 'supplier_id');
    $name    = input($_POST, 'name');
    $phone   = input($_POST, 'phone');
    $contact = input($_POST, 'contact_name');
    $email   = strtolower(input($_POST, 'email'));
    $taxPin  = input($_POST, 'tax_pin');
    $location = input($_POST, 'location');

    if (!$isCreate && ($id === null || !db_value("SELECT 1 FROM suppliers WHERE supplier_id = :id", [':id' => $id]))) {
        json_response(['success' => false, 'message' => 'That supplier no longer exists.']);
    }
    if ($name === '' || $phone === '') {
        json_response(['success' => false, 'message' => 'Supplier name and phone are required.']);
    }
    if ($email !== '' && !valid_email($email)) {
        json_response(['success' => false, 'message' => 'Please provide a valid email address.']);
    }

    // Uniqueness checks against other suppliers (matches legacy behaviour).
    $unique = ['name' => $name, 'phone' => $phone];
    if ($email !== '')  $unique['email']   = $email;
    if ($taxPin !== '') $unique['tax_pin'] = $taxPin;
    foreach ($unique as $col => $val) {
        if (db_value(
            "SELECT 1 FROM suppliers WHERE $col = :v AND (:id::int IS NULL OR supplier_id <> :id)",
            [':v' => $val, ':id' => $isCreate ? null : $id]
        )) {
            json_response(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $col)) . ' "' . $val . '" is already used by another supplier.']);
        }
    }

    $fields = [
        ':name' => $name, ':phone' => $phone,
        ':contact' => $contact !== '' ? $contact : null,
        ':email' => $email !== '' ? $email : null,
        ':pin' => $taxPin !== '' ? $taxPin : null,
        ':location' => $location !== '' ? $location : null,
        ':active' => isset($_POST['is_active']) ? 't' : 'f',
    ];

    // `location` was `address` until migration 036 renamed it. A
    // database that has not had that applied still has the old name,
    // and writing to the new one failed the whole save with
    // "Could not save changes" — which says nothing about why.
    // Reads go through supplier_location_sql(); writes need the same
    // care.
    $locCol = column_exists('suppliers', 'location') ? 'location'
            : (column_exists('suppliers', 'address') ? 'address' : null);
    if ($locCol === null) {
        unset($fields[':location']);
    }

    try {
        if ($isCreate) {
            $cols = 'name, contact_name, phone, email, tax_pin, is_active'
                  . ($locCol ? ", $locCol" : '');
            $vals = ':name, :contact, :phone, :email, :pin, :active'
                  . ($locCol ? ', :location' : '');
            $id = (int) db_value(
                "INSERT INTO suppliers ($cols) VALUES ($vals) RETURNING supplier_id",
                $fields
            );
        } else {
            $setLoc = $locCol ? "$locCol = :location," : '';
            db_run(
                "UPDATE suppliers
                 SET name = :name, contact_name = :contact, phone = :phone, email = :email,
                     tax_pin = :pin, $setLoc is_active = :active, updated_at = NOW()
                 WHERE supplier_id = :id",
                $fields + [':id' => $id]
            );
        }
        audit_log($isCreate ? 'supplier.create' : 'supplier.update', 'suppliers', $id, ['name' => $name]);
        json_response(['success' => true, 'message' => $isCreate ? 'Supplier added.' : 'Supplier updated.']);
    } catch (PDOException $ex) {
        error_log('[SUPPLIERS] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getCode() === '23505'
            ? 'Another supplier already uses that name, phone, email or PIN.'
            : db_rule_message($ex, 'Could not save changes. Please try again.')], 500);
    }
}

/* ── POST: toggle active / delete ─────────────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['toggle', 'delete'], true)) {
    csrf_check();
    $id  = input_int($_POST, 'supplier_id');
    $sup = $id !== null ? db_one("SELECT name, is_active FROM suppliers WHERE supplier_id = :id", [':id' => $id]) : null;
    if (!$sup) {
        flash('error', 'That supplier no longer exists.');
    } elseif (($_POST['_action'] ?? '') === 'toggle') {
        db_run("UPDATE suppliers SET is_active = :a, updated_at = NOW() WHERE supplier_id = :id",
            [':a' => $sup['is_active'] ? 'f' : 't', ':id' => $id]);
        audit_log($sup['is_active'] ? 'supplier.deactivate' : 'supplier.activate', 'suppliers', $id, ['name' => $sup['name']]);
        flash('success', $sup['name'] . ($sup['is_active'] ? ' deactivated.' : ' activated.'));
    } else {
        try {
            db_run("DELETE FROM suppliers WHERE supplier_id = :id", [':id' => $id]);
            audit_log('supplier.delete', 'suppliers', $id, ['name' => $sup['name']]);
            flash('success', 'Supplier "' . $sup['name'] . '" deleted.');
        } catch (PDOException $ex) {
            flash('error', $ex->getCode() === '23503'
                ? 'This supplier is referenced by products or purchases and cannot be deleted. Deactivate it instead.'
                : 'Could not delete the supplier.');
        }
    }
    redirect('modules/purchasing/view_suppliers.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search  = input($_GET, 'q');
$filter  = input($_GET, 'filter'); // active | inactive
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(s.name) LIKE :q OR LOWER(COALESCE(s.contact_name,'')) LIKE :q
                 OR LOWER(COALESCE(s.email,'')) LIKE :q OR s.phone LIKE :q
                 OR LOWER(COALESCE(s.location,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($filter === 'active')   $where[] = "s.is_active = TRUE";
elseif ($filter === 'inactive') $where[] = "s.is_active = FALSE";
else $filter = '';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if (wants_export()) {
    export_deliver(export_filename('suppliers'), [[
        'name'  => 'Suppliers',
        'title' => 'Suppliers',
        'meta'  => export_meta(['Search' => $search]),
        'columns' => [
            ['label' => 'Supplier', 'key' => 'name'],
            ['label' => 'Email',    'key' => 'email'],
            ['label' => 'Phone',    'key' => 'phone'],
            ['label' => 'Location', 'key' => 'location'],
            ['label' => 'Status',   'key' => 'is_active', 'value' => fn($r) => $r['is_active'] ? 'Active' : 'Inactive'],
        ],
        'rows' => db_all("SELECT s.* FROM suppliers s $whereSql ORDER BY s.name", $params),
    ]]);
}

$total = (int) db_value("SELECT COUNT(*) FROM suppliers s $whereSql", $params);
$pg    = paginate($total, $perPage);

$suppliers = db_all(
    "SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.supplier_id) AS product_count
     FROM suppliers s $whereSql
     ORDER BY s.name ASC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$pageTitle    = 'Suppliers';
$pageSubtitle = num($total) . ' supplier' . ($total === 1 ? '' : 's');
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Purchasing'], ['label' => 'Suppliers']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter(['q' => $search, 'filter' => $filter], fn($v) => $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/purchasing/view_suppliers.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search name, contact, email, phone or location…">
        <select name="filter" class="form-control toolbar-select" data-autosubmit>
            <option value="">All suppliers</option>
            <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    
        <?= toolbar_apply('modules/purchasing/view_suppliers.php') ?>
    </form>
    <div class="toolbar-actions">
        <?= export_button('modules/purchasing/view_suppliers.php', $baseQuery) ?>
    <button type="button" class="btn btn-primary"
            data-modal-open="tplSupplierForm" data-modal-title="Add Supplier" data-modal-auto
            data-prefill='<?= e(json_encode(['_action' => 'create', 'supplier_id' => '', 'is_active' => 1], JSON_UNESCAPED_SLASHES)) ?>'>
        <?= icon('plus') ?> Add Supplier
    </button>
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th>Phone</th>
                    <th>Tax PIN</th>
                    <th class="ta-right">Products</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$suppliers): ?>
                    <tr><td colspan="8" class="table-empty">No suppliers match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($suppliers as $s):
                    $prefill = json_encode([
                        'supplier_id'  => $s['supplier_id'],
                        'name'         => $s['name'],
                        'contact_name' => $s['contact_name'],
                        'phone'        => $s['phone'],
                        'email'        => $s['email'],
                        'tax_pin'      => $s['tax_pin'],
                        'location'     => $s['location'],
                        'is_active'    => $s['is_active'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <div class="cell-user">
                                <span class="avatar avatar--sm"><?= e(initials($s['name'])) ?></span>
                                <div>
                                    <span class="cell-title"><?= e($s['name']) ?></span>
                                    <span class="cell-sub"><?= e($s['email'] ?? '—') ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="cell-muted"><?= e($s['contact_name'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e($s['location'] ?? '') !== '' ? e($s['location']) : '—' ?></td>
                        <td class="cell-muted"><?= e($s['phone']) ?></td>
                        <td class="cell-muted"><?= e($s['tax_pin'] ?? '—') ?></td>
                        <td class="ta-right"><?= e(num($s['product_count'])) ?></td>
                        <td>
                            <span class="badge <?= $s['is_active'] ? 'badge--active' : 'badge--inactive' ?>">
                                <?= $s['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a class="icon-btn" title="Statement of account"
                                   href="<?= e(url('reports/supplier_statement.php?supplier=' . (int) $s['supplier_id'])) ?>"><?= icon('file') ?></a>
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplSupplierForm" data-modal-title="Edit Supplier"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <form method="POST" action="<?= e(url('modules/purchasing/view_suppliers.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="toggle">
                                    <input type="hidden" name="supplier_id" value="<?= (int) $s['supplier_id'] ?>">
                                    <button type="submit" class="icon-btn" title="<?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <?= icon($s['is_active'] ? 'x' : 'check') ?>
                                    </button>
                                </form>
                                <form method="POST" action="<?= e(url('modules/purchasing/view_suppliers.php')) ?>" class="inline-form"
                                      data-confirm="Delete <?= e($s['name']) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="supplier_id" value="<?= (int) $s['supplier_id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'supplier'); ?>
</div>

<!-- Edit supplier modal -->
<template id="tplSupplierForm">
    <form method="POST" action="<?= e(url('modules/purchasing/view_suppliers.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="supplier_id" value="">

        <div class="form-group">
            <label class="form-label">Supplier name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="255">
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Contact person</label>
                <input type="text" name="contact_name" class="form-control" maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label">Phone <span class="req">*</span></label>
                <input type="text" name="phone" class="form-control" required maxlength="50">
            </div>
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label">Tax PIN</label>
                <input type="text" name="tax_pin" class="form-control" maxlength="50">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Location</label>
            <textarea name="location" class="form-control" rows="2"
                      placeholder="Road, building, floor — where they are"></textarea>
        </div>
        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="is_active">
                <span>Active supplier</span>
            </label>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
