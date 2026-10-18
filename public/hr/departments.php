<?php

/**
 * ============================================================
 *  Departments
 * ------------------------------------------------------------
 *  Small list, one job: say who heads each department, because
 *  that is who the first leave approval goes to.
 *
 *  The head is an employee, not a user — see includes/hr.php.
 *  Approving needs an account, so this page says so plainly when
 *  the named head has no login, rather than leaving requests to
 *  sit in a queue nobody can reach.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/icons.php';

require_role(ROLE_MANAGER);
require_tables('departments', 'employees');

/* ── AJAX: create or update ───────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $id   = input_int($_POST, 'department_id');
    $name = trim(input($_POST, 'name'));
    $head = input_int($_POST, 'head_employee_id');

    if ($name === '') {
        json_response(['success' => false, 'message' => 'A department needs a name.'], 422);
    }
    $clash = db_one(
        'SELECT name FROM departments WHERE LOWER(name) = LOWER(:n) AND department_id IS DISTINCT FROM :id',
        [':n' => $name, ':id' => $id]
    );
    if ($clash) {
        json_response(['success' => false, 'message' => $clash['name'] . ' is already on the list.'], 422);
    }
    if ($head !== null && !hr_employee_get($head)) {
        json_response(['success' => false, 'message' => 'That employee no longer exists.'], 422);
    }

    try {
        if ($id === null) {
            $id = (int) db_value(
                'INSERT INTO departments (name, description, head_employee_id, is_active)
                 VALUES (:n, :d, :h, :a) RETURNING department_id',
                [':n' => $name, ':d' => input($_POST, 'description') ?: null,
                 ':h' => $head, ':a' => isset($_POST['is_active']) ? 't' : 'f']
            );
            audit_log('hr.department_added', 'departments', $id, ['name' => $name]);
        } else {
            db_run(
                'UPDATE departments SET name = :n, description = :d, head_employee_id = :h,
                        is_active = :a, updated_at = CURRENT_TIMESTAMP
                  WHERE department_id = :id',
                [':n' => $name, ':d' => input($_POST, 'description') ?: null,
                 ':h' => $head, ':a' => isset($_POST['is_active']) ? 't' : 'f', ':id' => $id]
            );
            audit_log('hr.department_updated', 'departments', $id, ['name' => $name]);
        }
        json_response(['success' => true, 'message' => $name . ' has been saved.']);
    } catch (PDOException $ex) {
        error_log('[HR] department save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'The department could not be saved.'], 500);
    }
}

/* ── POST: delete ─────────────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    require_role(ROLE_ADMIN);

    $id   = input_int($_POST, 'id');
    $dept = $id !== null ? db_one('SELECT name FROM departments WHERE department_id = :id', [':id' => $id]) : null;

    if (!$dept) {
        flash('error', 'That department no longer exists.');
    } else {
        $staff = (int) db_value('SELECT COUNT(*) FROM employees WHERE department_id = :id', [':id' => $id]);
        if ($staff > 0) {
            //  Said rather than cascaded. ON DELETE SET NULL on the
            //  employee side would quietly orphan the people in it,
            //  and their leave would then have nobody to approve it.
            flash('error', $dept['name'] . ' still has ' . $staff . ' '
                . ($staff === 1 ? 'person' : 'people') . ' in it. Move them first.');
        } else {
            db_run('DELETE FROM departments WHERE department_id = :id', [':id' => $id]);
            audit_log('hr.department_deleted', 'departments', $id, ['name' => $dept['name']]);
            flash('success', $dept['name'] . ' has been removed.');
        }
    }
    redirect('hr/departments.php');
}

$departments = hr_departments();

//  Anybody who could be named head. Somebody who has left cannot.
$candidates = db_all(
    "SELECT employee_id, staff_number, first_name, last_name, job_title, user_id
       FROM employees WHERE status <> 'exited' ORDER BY first_name, last_name"
);

$pageTitle    = 'Departments';
$pageSubtitle = 'And who approves leave for each';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'People'], ['label' => 'Departments']];

require __DIR__ . '/../../includes/header.php';
?>

<form class="toolbar" method="GET" action="<?= e(url('hr/departments.php')) ?>">
    <div class="toolbar-search"></div>
    <a class="btn btn-ghost" href="<?= e(url('hr/employees.php')) ?>"><?= icon('users') ?> Employees</a>
    <button type="button" class="btn btn-primary" data-modal-open="tplDeptForm"
            data-modal-title="Add Department" data-modal-auto>
        <?= icon('plus') ?> Add Department
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Head</th>
                    <th>Can they approve?</th>
                    <th class="ta-right">People</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$departments): ?>
                    <tr><td colspan="6" class="table-empty">
                        No departments yet. Leave from somebody with no department goes
                        straight to an administrator.
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($departments as $d):
                    $prefill = json_encode([
                        'department_id'    => $d['department_id'],
                        'name'             => $d['name'],
                        'description'      => $d['description'],
                        'head_employee_id' => $d['head_employee_id'],
                        'is_active'        => $d['is_active'] ? 1 : '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    $canApprove = $d['head_employee_id'] && $d['head_user_id'];
                ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($d['name']) ?></span>
                            <?php if ($d['description']): ?>
                                <span class="cell-sub"><?= e($d['description']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= e($d['head_name'] ?: '— none named —') ?></td>
                        <td>
                            <?php if ($canApprove): ?>
                                <span class="badge badge--active">Yes</span>
                            <?php elseif ($d['head_employee_id']): ?>
                                <span class="badge badge--warn">No login</span>
                                <span class="cell-sub">Leave goes to an administrator instead.</span>
                            <?php else: ?>
                                <span class="badge badge--inactive">No head</span>
                                <span class="cell-sub">Leave goes to an administrator instead.</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><?= e(num((int) $d['headcount'])) ?></td>
                        <td>
                            <span class="badge badge--<?= $d['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $d['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplDeptForm" data-modal-title="Edit Department"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <?php if (is_admin()): ?>
                                    <form method="POST" action="<?= e(url('hr/departments.php')) ?>" class="inline-form"
                                          data-confirm="Remove <?= e($d['name']) ?>?<?= (int) $d['headcount'] > 0 ? ' It still has ' . (int) $d['headcount'] . ' people and will be refused.' : '' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $d['department_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Remove"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<template id="tplDeptForm">
    <form method="POST" action="<?= e(url('hr/departments.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="department_id" value="">

        <div class="form-group">
            <label class="form-label">Name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="100"
                   placeholder="e.g. Dispensary">
        </div>

        <div class="form-group">
            <label class="form-label">Head of department</label>
            <select name="head_employee_id" class="form-control">
                <option value="">— none named —</option>
                <?php foreach ($candidates as $c): ?>
                    <option value="<?= (int) $c['employee_id'] ?>">
                        <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?>
                        — <?= e($c['job_title']) ?><?= $c['user_id'] ? '' : ' (no login)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">
                Leave raised in this department goes to them first. They need a login to
                act on it; without one it falls through to an administrator.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control">
        </div>

        <div class="form-group form-check">
            <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save department</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
