<?php

/**
 * ============================================================
 *  Permissions — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page into the shared
 *  layout. Permissions are grouped by module and each row
 *  shows which roles hold it.
 *
 *  Add, edit and role assignment all use the shared modal +
 *  AJAX pattern, so nothing leaves this page.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_role(ROLE_ADMIN);

/* ── AJAX: create or update a permission ──────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $isCreate = $_POST['_action'] === 'create';
    $id       = input_int($_POST, 'permission_id');
    $name     = strtolower(input($_POST, 'name'));
    $module   = input($_POST, 'module');
    $desc     = input($_POST, 'description');

    $errors = [];
    if ($name === '')   $errors[] = 'Permission name is required.';
    if ($module === '') $errors[] = 'Module is required.';
    if (!$isCreate && ($id === null || !db_value("SELECT 1 FROM permissions WHERE permission_id = :id", [':id' => $id]))) {
        $errors[] = 'That permission no longer exists.';
    }
    if ($errors) {
        json_response(['success' => false, 'message' => implode(' ', $errors)]);
    }

    try {
        if ($isCreate) {
            $id = (int) db_value(
                "INSERT INTO permissions (name, module, description)
                 VALUES (:name, :module, :desc) RETURNING permission_id",
                [':name' => $name, ':module' => $module, ':desc' => $desc ?: null]
            );
        } else {
            db_run(
                "UPDATE permissions SET name = :name, module = :module,
                        description = :desc, updated_at = NOW()
                  WHERE permission_id = :id",
                [':name' => $name, ':module' => $module, ':desc' => $desc ?: null, ':id' => $id]
            );
        }
        audit_log($isCreate ? 'permission.create' : 'permission.update', 'permissions', $id, ['name' => $name]);
        json_response(['success' => true, 'message' => $isCreate ? 'Permission added.' : 'Permission updated.']);
    } catch (PDOException $ex) {
        error_log('[PERMISSIONS] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getCode() === '23505'
            ? 'A permission with that name already exists.'
            : db_rule_message($ex, 'Could not save the permission.')], 500);
    }
}

/* ── AJAX: assign a permission to roles ───────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'assign') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id = input_int($_POST, 'permission_id');
    if ($id === null || !db_value("SELECT 1 FROM permissions WHERE permission_id = :id", [':id' => $id])) {
        json_response(['success' => false, 'message' => 'That permission no longer exists.']);
    }
    $roleIds = array_map('intval', (array) ($_POST['role_ids'] ?? []));

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM role_permissions WHERE permission_id = :id")->execute([':id' => $id]);
        if ($roleIds) {
            $ins = $pdo->prepare(
                "INSERT INTO role_permissions (role_id, permission_id) VALUES (:r, :p)
                 ON CONFLICT DO NOTHING"
            );
            foreach (array_unique($roleIds) as $rid) {
                $ins->execute([':r' => $rid, ':p' => $id]);
            }
        }
        $pdo->commit();
        audit_log('permission.assign', 'permissions', $id, ['roles' => $roleIds]);
        json_response(['success' => true, 'message' => 'Role assignment saved.']);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PERMISSIONS] assign failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not save the assignment.'], 500);
    }
}

/* ── POST: delete a permission ────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    $id   = input_int($_POST, 'permission_id');
    $perm = $id !== null ? db_one("SELECT name FROM permissions WHERE permission_id = :id", [':id' => $id]) : null;
    if (!$perm) {
        flash('error', 'That permission no longer exists.');
    } else {
        try {
            // role_permissions cascades, so the grant rows go with it.
            db_run("DELETE FROM permissions WHERE permission_id = :id", [':id' => $id]);
            audit_log('permission.delete', 'permissions', $id, ['name' => $perm['name']]);
            flash('success', 'Permission "' . $perm['name'] . '" deleted.');
        } catch (PDOException $ex) {
            error_log('[PERMISSIONS] delete failed: ' . $ex->getMessage());
            flash('error', 'Could not delete that permission.');
        }
    }
    redirect('modules/users/view_permissions.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search = input($_GET, 'q');
$module = input($_GET, 'module');

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(p.name) LIKE :q OR LOWER(COALESCE(p.description,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($module !== '') {
    $where[] = "p.module = :module";
    $params[':module'] = $module;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$permissions = db_all(
    "SELECT p.*,
            COALESCE(STRING_AGG(r.name, ', ' ORDER BY r.name), '') AS role_names,
            COALESCE(ARRAY_AGG(r.role_id) FILTER (WHERE r.role_id IS NOT NULL), '{}') AS role_ids
     FROM permissions p
     LEFT JOIN role_permissions rp ON rp.permission_id = p.permission_id
     LEFT JOIN roles r ON r.role_id = rp.role_id
     $whereSql
     GROUP BY p.permission_id
     ORDER BY p.module, p.name",
    $params
);

$modules = db_all("SELECT DISTINCT module FROM permissions ORDER BY module");
$roles   = db_all("SELECT role_id, name FROM roles ORDER BY name");

$pageTitle    = 'Permissions';
$pageSubtitle = 'What each role is allowed to do';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Users'], ['label' => 'Permissions']];

require __DIR__ . '/../../../includes/header.php';

// Group for display so each module gets its own block.
$byModule = [];
foreach ($permissions as $p) {
    $byModule[$p['module']][] = $p;
}
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/users/view_permissions.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search permissions…">
        <select name="module" class="form-control toolbar-select" data-autosubmit>
            <option value="">All modules</option>
            <?php foreach ($modules as $m): ?>
                <option value="<?= e($m['module']) ?>" <?= $module === $m['module'] ? 'selected' : '' ?>>
                    <?= e(ucfirst($m['module'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    
        <?= toolbar_apply('modules/users/view_permissions.php') ?>
    </form>
    <button type="button" class="btn btn-primary"
            data-modal-open="tplPermissionForm" data-modal-title="Add Permission" data-modal-auto
            data-prefill='<?= e(json_encode(['_action' => 'create', 'permission_id' => ''], JSON_UNESCAPED_SLASHES)) ?>'>
        <?= icon('plus') ?> Add Permission
    </button>
</div>

<?php if (!$permissions): ?>
    <div class="panel"><p class="feed-empty">No permissions match these filters.</p></div>
<?php endif; ?>

<?php foreach ($byModule as $mod => $perms): ?>
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title"><?= e(ucfirst($mod)) ?></h2>
            <span class="panel-sub"><?= count($perms) ?> permission<?= count($perms) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Permission</th><th>Description</th><th>Roles</th><th class="ta-right">Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($perms as $p): ?>
                        <?php
                        // Postgres returns the aggregated ids as a literal
                        // "{1,2}" array — normalise for the assign modal.
                        $ids = array_values(array_filter(
                            explode(',', trim((string) $p['role_ids'], '{}')),
                            fn($v) => $v !== ''
                        ));
                        $editPrefill = json_encode([
                            'permission_id' => $p['permission_id'],
                            'name'          => $p['name'],
                            'module'        => $p['module'],
                            'description'   => $p['description'] ?? '',
                        ], JSON_HEX_APOS | JSON_HEX_QUOT);
                        $assignPrefill = json_encode([
                            'permission_id' => $p['permission_id'],
                        ], JSON_HEX_APOS | JSON_HEX_QUOT);
                        ?>
                        <tr>
                            <td><span class="cell-title"><?= e($p['name']) ?></span></td>
                            <td class="cell-muted"><?= e($p['description'] ?: '—') ?></td>
                            <td>
                                <?php if ($p['role_names'] !== ''): ?>
                                    <?php foreach (explode(', ', $p['role_names']) as $rn): ?>
                                        <span class="role-pill"><?= e($rn) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="cell-muted">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="ta-right">
                                <div class="row-actions">
                                    <button type="button" class="icon-btn" title="Assign roles"
                                            data-modal-open="tplAssignForm"
                                            data-modal-title="Roles for <?= e($p['name']) ?>"
                                            data-roles="<?= e(implode(',', $ids)) ?>"
                                            data-prefill="<?= e($assignPrefill) ?>"><?= icon('users') ?></button>
                                    <button type="button" class="icon-btn" title="Edit"
                                            data-modal-open="tplPermissionForm" data-modal-title="Edit Permission"
                                            data-prefill="<?= e($editPrefill) ?>"><?= icon('edit') ?></button>
                                    <form method="POST" action="<?= e(url('modules/users/view_permissions.php')) ?>" class="inline-form"
                                          data-confirm="Delete <?= e($p['name']) ?>? Roles holding it will lose it.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="permission_id" value="<?= (int) $p['permission_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<template id="tplPermissionForm">
    <form method="POST" action="<?= e(url('modules/users/view_permissions.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="permission_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Permission name <span class="req">*</span></label>
                <input type="text" name="name" class="form-control" required maxlength="100"
                       placeholder="products.create">
            </div>
            <div class="form-group">
                <label class="form-label">Module <span class="req">*</span></label>
                <input type="text" name="module" class="form-control" required maxlength="50"
                       list="permModules" placeholder="inventory">
                <datalist id="permModules">
                    <?php foreach ($modules as $m): ?>
                        <option value="<?= e($m['module']) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save permission</button>
        </div>
    </form>
</template>

<template id="tplAssignForm">
    <form method="POST" action="<?= e(url('modules/users/view_permissions.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="assign">
        <input type="hidden" name="permission_id" value="">

        <p class="form-hint">Tick every role that should hold this permission.</p>
        <div class="form-group">
            <?php foreach ($roles as $r): ?>
                <label class="checkbox">
                    <input type="checkbox" name="role_ids[]" value="<?= (int) $r['role_id'] ?>">
                    <span><?= e($r['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save assignment</button>
        </div>
    </form>
</template>

<script nonce="<?= csp_nonce() ?>">
    // The role checkboxes are a multi-value field, which data-prefill
    // (single value per name) cannot express — tick them from the
    // trigger's data-roles list once the modal has been cloned in.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-modal-open="tplAssignForm"]');
        if (!btn) return;
        const ids = (btn.getAttribute('data-roles') || '').split(',').filter(Boolean);
        setTimeout(() => {
            document.querySelectorAll('.modal-overlay input[name="role_ids[]"]')
                .forEach((cb) => { cb.checked = ids.includes(cb.value); });
        }, 0);
    });
</script>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
