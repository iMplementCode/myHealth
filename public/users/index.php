<?php

/**
 * ============================================================
 *  User Management — List
 * ------------------------------------------------------------
 *  Searchable, paginated list of users with inline actions:
 *  edit, reset password, activate/deactivate and delete.
 *  Administrators only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(ROLE_ADMIN);

$search = input($_GET, 'q');
$roleId = input_int($_GET, 'role');

// Build the filtered query with bound parameters.
$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(u.first_name) LIKE :q OR LOWER(u.last_name) LIKE :q
                 OR LOWER(u.email) LIKE :q OR LOWER(u.username) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($roleId !== null) {
    $where[] = "u.role_id = :role";
    $params[':role'] = $roleId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM users u $whereSql", $params);
$pg    = paginate($total, per_page());

$users = db_all(
    "SELECT u.user_id, u.username, u.first_name, u.last_name, u.email, u.mobile,
            u.is_active, u.last_login, u.created_at, r.name AS role_name
     FROM users u
     LEFT JOIN roles r ON r.role_id = u.role_id
     $whereSql
     ORDER BY u.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$roles = db_all("SELECT role_id, name FROM roles ORDER BY name");

$pageTitle    = 'Users';
$pageSubtitle = 'Manage accounts, roles and access';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Users']];
$me           = current_user();

// Carried onto every page link so paging keeps the filters.
$baseQuery = array_filter([
    'q' => $search, 'role' => $roleId,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

require __DIR__ . '/../../includes/header.php';
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('users/index.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email or username…"
               class="form-control" data-table-search="usersTable">
        <select name="role" class="form-control toolbar-select" data-autosubmit>
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= (int) $r['role_id'] ?>" <?= $roleId === (int) $r['role_id'] ? 'selected' : '' ?>>
                    <?= e($r['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    
        <?= toolbar_apply('users/index.php') ?>
    </form>
    <button type="button" class="btn btn-primary" data-modal-open="tplUserForm" data-modal-title="Add User" data-modal-wide data-modal-auto>
        <?= icon('plus') ?> Add User
    </button>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table" id="usersTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last login</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$users): ?>
                    <tr><td colspan="6" class="table-empty">No users found.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u):
                    $name = trim($u['first_name'] . ' ' . $u['last_name']);
                    $isSelf = $me['id'] === (int) $u['user_id'];
                ?>
                    <tr>
                        <td>
                            <div class="cell-user">
                                <span class="avatar avatar--sm"><?= e(initials($name ?: $u['email'])) ?></span>
                                <div>
                                    <span class="cell-title"><?= e($name ?: '—') ?>
                                        <?php if ($isSelf): ?><span class="tag-inline">you</span><?php endif; ?>
                                    </span>
                                    <span class="cell-sub"><?= e($u['email']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($u['username'] ?? '—') ?></td>
                        <td><span class="role-pill"><?= e($u['role_name'] ?? 'No role') ?></span></td>
                        <td>
                            <span class="badge <?= $u['is_active'] ? 'badge--active' : 'badge--inactive' ?>">
                                <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="cell-muted"><?= e($u['last_login'] ? fmt_date($u['last_login'], 'd M Y H:i') : 'Never') ?></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a href="<?= e(url('users/edit.php?id=' . (int) $u['user_id'])) ?>"
                                   class="icon-btn" title="Edit"><?= icon('edit') ?></a>

                                <form method="POST" action="<?= e(url('users/actions.php')) ?>" class="inline-form"
                                      data-confirm="Reset this user's password to a temporary one?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?= (int) $u['user_id'] ?>">
                                    <button type="submit" class="icon-btn" title="Reset password"><?= icon('key') ?></button>
                                </form>

                                <?php if (!$isSelf): ?>
                                    <form method="POST" action="<?= e(url('users/actions.php')) ?>" class="inline-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= (int) $u['user_id'] ?>">
                                        <button type="submit" class="icon-btn" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                            <?= icon($u['is_active'] ? 'x' : 'check') ?>
                                        </button>
                                    </form>

                                    <form method="POST" action="<?= e(url('users/actions.php')) ?>" class="inline-form"
                                          data-confirm="Delete <?= e($name ?: $u['email']) ?>? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $u['user_id'] ?>">
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

    <?php pagination_nav($pg, $baseQuery, 'user'); ?>
</div>

<!-- Add User modal (submits to create.php via AJAX) -->
<template id="tplUserForm">
    <form method="POST" action="<?= e(url('users/create.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">First name <span class="req">*</span></label>
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Last name <span class="req">*</span></label>
                <input type="text" name="last_name" class="form-control" required>
            </div>
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Email <span class="req">*</span></label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Username <span class="opt">(optional)</span></label>
                <input type="text" name="username" class="form-control" autocomplete="off">
            </div>
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Mobile <span class="opt">(optional)</span></label>
                <input type="text" name="mobile" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Role <span class="req">*</span></label>
                <select name="role_id" class="form-control" required>
                    <option value="">— Select role —</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int) $r['role_id'] ?>"><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Password <span class="req">*</span></label>
                <input type="password" name="password" class="form-control" required minlength="8">
            </div>
            <div class="form-group">
                <label class="form-label">Confirm password <span class="req">*</span></label>
                <input type="password" name="confirm_password" class="form-control" required minlength="8">
            </div>
        </div>
        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="is_active" checked>
                <span>Active account</span>
            </label>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Create user</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
