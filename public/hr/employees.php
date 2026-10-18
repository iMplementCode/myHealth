<?php

/**
 * ============================================================
 *  Employees
 * ------------------------------------------------------------
 *  The people who work here. Not the same list as Users, which
 *  is accounts — see the note at the top of includes/hr.php for
 *  why the two are deliberately separate, and why an employee
 *  can exist with no login and a login with no employee.
 *
 *  Pay and bank details are on this form only for an
 *  administrator. A department head needs to approve leave,
 *  which is not a reason to show them what their team earns, and
 *  this is the screen where that would leak by default if nobody
 *  decided otherwise. hr_employee_save() enforces the same thing
 *  on the way in, so a hand-made POST cannot set a salary either.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/icons.php';

require_role(ROLE_MANAGER);
require_tables('employees', 'departments');

$canSeePay = hr_can_see_pay();

/* ── AJAX: create or update ───────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    //  One form serves both modals; which one follows from whether
    //  the prefill carried an id, so no script has to keep a hidden
    //  action field in step with the button that opened it.
    $id = input_int($_POST, 'employee_id');
    if ($id !== null && !hr_employee_get($id)) {
        json_response(['success' => false, 'message' => 'That employee record no longer exists.']);
    }

    $result = hr_employee_save($_POST, $id);
    json_response([
        'success' => $result['ok'],
        'message' => $result['message'],
    ], $result['ok'] ? 200 : 422);
}

/* ── POST: attach or detach a login ───────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'login_link') {
    csrf_check();
    require_role(ROLE_ADMIN);

    $id     = input_int($_POST, 'employee_id');
    $userId = input_int($_POST, 'user_id');
    if ($id === null) {
        flash('error', 'No employee was named.');
    } else {
        $r = hr_employee_set_login($id, $userId);
        flash($r['ok'] ? 'success' : 'error', $r['message']);
    }
    redirect('hr/employees.php');
}

/* ── The list ─────────────────────────────────────────────── */
$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$deptId  = input_int($_GET, 'department');
$perPage = per_page();

$total = hr_employee_count($search, $status, $deptId);
$pg    = paginate($total, $perPage);
$people = hr_employee_list($search, $status, $deptId, (int) $pg['per_page'], (int) $pg['offset']);

$departments = hr_departments();
$expiring    = hr_registrations_expiring(60);

//  Logins not yet attached to anybody, for the attach control.
$freeLogins = db_all(
    "SELECT u.user_id, u.username, TRIM(u.first_name || ' ' || u.last_name) AS name, r.name AS role
       FROM users u
       LEFT JOIN roles r ON r.role_id = u.role_id
      WHERE u.is_active
        AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.user_id = u.user_id)
      ORDER BY u.first_name, u.last_name"
);

$headcount = (int) db_value("SELECT COUNT(*) FROM employees WHERE status <> 'exited'");
$dispensers = count(array_filter(hr_cover_staff(), static fn(array $e): bool =>
    $e['prof_expiry'] !== null && $e['prof_expiry'] >= date('Y-m-d')));

$pageTitle    = 'Employees';
$pageSubtitle = 'The people who work here';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'People'], ['label' => 'Employees']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter([
    'q' => $search, 'status' => $status, 'department' => $deptId,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], static fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">On the books</span>
        <span class="mini-value"><?= e(num($headcount)) ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Registered dispensers</span>
        <span class="mini-value"><?= e(num($dispensers)) ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Departments</span>
        <span class="mini-value"><?= e(num(count($departments))) ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Registrations expiring</span>
        <span class="mini-value"><?= e(num(count($expiring))) ?></span>
    </div>
</section>

<?php if ($expiring): ?>
    <?php /*  Said on this page rather than left to a report. An
              expired registration is not a reminder, it is a shop
              that should not be dispensing.                       */ ?>
    <div class="alert alert--warning" role="status">
        <span>
            <strong>Professional registration:</strong>
            <?php foreach ($expiring as $i => $x): ?>
                <?= $i ? '; ' : '' ?>
                <?= e(trim($x['first_name'] . ' ' . $x['last_name'])) ?>
                <?php if ((int) $x['days_left'] < 0): ?>
                    expired <?= e(fmt_date($x['prof_expiry'])) ?>
                <?php else: ?>
                    expires <?= e(fmt_date($x['prof_expiry'])) ?>
                    (<?= (int) $x['days_left'] ?> days)
                <?php endif; ?>
            <?php endforeach; ?>
        </span>
    </div>
<?php endif; ?>

<form class="toolbar" method="GET" action="<?= e(url('hr/employees.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search name, staff number or job title…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach (hr_statuses() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="department" class="form-control toolbar-select" data-autosubmit>
            <option value="">Every department</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['department_id'] ?>"
                    <?= $deptId === (int) $d['department_id'] ? 'selected' : '' ?>>
                    <?= e($d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?= toolbar_apply('hr/employees.php') ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('hr/departments.php')) ?>">
        <?= icon('building') ?> Departments
    </a>
    <?php /*  Wide, like the product form. The default modal is
              420px and a two-column row needs 416px of content
              inside 24px padding, so every grid in here collapsed
              to one column and the form became a very long
              ribbon.                                             */ ?>
    <button type="button" class="btn btn-primary" data-modal-open="tplEmployeeForm"
            data-modal-title="Add Employee" data-modal-wide data-modal-auto>
        <?= icon('plus') ?> Add Employee
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Role here</th>
                    <th>Department</th>
                    <th>Registration</th>
                    <th>Since</th>
                    <th>Login</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$people): ?>
                    <tr><td colspan="8" class="table-empty">
                        Nobody matches. Add somebody with <strong>Add Employee</strong>.
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($people as $p):
                    //  $p is the whole row, so the prefill is built
                    //  from it rather than fetching each record again.
                    $full = $p;
                    $prefill = [
                        'employee_id'     => $p['employee_id'],
                        'staff_number'    => $full['staff_number'],
                        'first_name'      => $full['first_name'],
                        'last_name'       => $full['last_name'],
                        'other_names'     => $full['other_names'],
                        'national_id'     => $full['national_id'],
                        'date_of_birth'   => $full['date_of_birth'],
                        'sex'             => $full['sex'],
                        'phone'           => $full['phone'],
                        'personal_email'  => $full['personal_email'],
                        'physical_address'=> $full['physical_address'],
                        'department_id'   => $full['department_id'],
                        'job_title'       => $full['job_title'],
                        'cadre'           => $full['cadre'],
                        'employment_type' => $full['employment_type'],
                        'hire_date'       => $full['hire_date'],
                        'end_date'        => $full['end_date'],
                        'exit_reason'     => $full['exit_reason'],
                        'status'          => $full['status'],
                        'prof_body'       => $full['prof_body'],
                        'prof_registration_no' => $full['prof_registration_no'],
                        'prof_expiry'     => $full['prof_expiry'],
                        'kra_pin'         => $full['kra_pin'],
                        'nssf_number'     => $full['nssf_number'],
                        'shif_number'     => $full['shif_number'],
                        'nok_name'        => $full['nok_name'],
                        'nok_relationship'=> $full['nok_relationship'],
                        'nok_phone'       => $full['nok_phone'],
                        'notes'           => $full['notes'],
                    ];
                    //  Only an administrator's form carries these, so
                    //  only an administrator's prefill does.
                    if ($canSeePay) {
                        $prefill += [
                            'basic_salary'    => $full['basic_salary'],
                            'bank_name'       => $full['bank_name'],
                            'bank_branch'     => $full['bank_branch'],
                            'bank_account_no' => $full['bank_account_no'],
                        ];
                    }
                    $regLapsed = $p['prof_expiry'] !== null && $p['prof_expiry'] < date('Y-m-d');
                ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e(hr_full_name($p)) ?></span>
                            <span class="cell-sub"><?= e($p['staff_number']) ?><?= $p['phone'] ? ' · ' . e($p['phone']) : '' ?></span>
                        </td>
                        <td>
                            <span class="cell-title"><?= e($p['job_title']) ?></span>
                            <span class="cell-sub"><?= e(hr_cadres()[$p['cadre']] ?? $p['cadre']) ?>
                                · <?= e(hr_employment_types()[$p['employment_type']] ?? $p['employment_type']) ?></span>
                        </td>
                        <td class="cell-muted"><?= e($p['department_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($p['prof_expiry']): ?>
                                <span class="badge badge--<?= $regLapsed ? 'expired' : 'active' ?>">
                                    <?= $regLapsed ? 'Expired' : 'To' ?> <?= e(fmt_date($p['prof_expiry'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="cell-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= e(fmt_date($p['hire_date'])) ?></td>
                        <td>
                            <?php if ($p['user_id']): ?>
                                <span class="badge badge--active">Yes</span>
                            <?php else: ?>
                                <span class="cell-muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= e(hr_status_tone($p['status'])) ?>">
                                <?= e(hr_statuses()[$p['status']] ?? $p['status']) ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a class="icon-btn" title="Leave history"
                                   href="<?= e(url('hr/leave.php?employee=' . (int) $p['employee_id'])) ?>">
                                    <?= icon('calendar') ?>
                                </a>
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplEmployeeForm" data-modal-title="Edit Employee"
                                        data-modal-wide
                                        data-prefill="<?= e(json_encode($prefill, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                                    <?= icon('edit') ?>
                                </button>
                                <?php if (is_admin()): ?>
                                    <?php if ($p['user_id']): ?>
                                        <form method="POST" action="<?= e(url('hr/employees.php')) ?>" class="inline-form"
                                              data-confirm="Detach the login from <?= e(hr_full_name($p)) ?>? The employment record stays.">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_action" value="login_link">
                                            <input type="hidden" name="employee_id" value="<?= (int) $p['employee_id'] ?>">
                                            <button type="submit" class="icon-btn" title="Detach login"><?= icon('key') ?></button>
                                        </form>
                                    <?php elseif ($freeLogins): ?>
                                        <button type="button" class="icon-btn" title="Attach a login"
                                                data-modal-open="tplAttachLogin" data-modal-title="Attach a login"
                                                data-prefill="<?= e(json_encode([
                                                    'employee_id' => $p['employee_id'],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                                            <?= icon('user-plus') ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'employee'); ?>
</div>

<template id="tplEmployeeForm">
    <form method="POST" action="<?= e(url('hr/employees.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="employee_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">First name <span class="req">*</span></label>
                <input type="text" name="first_name" class="form-control" required maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label">Last name <span class="req">*</span></label>
                <input type="text" name="last_name" class="form-control" required maxlength="100">
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Other names</label>
                <input type="text" name="other_names" class="form-control" maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label">Staff number <span class="opt">(leave blank to generate)</span></label>
                <input type="text" name="staff_number" class="form-control" maxlength="30" placeholder="EMP-…">
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Job title <span class="req">*</span></label>
                <input type="text" name="job_title" class="form-control" required maxlength="120"
                       placeholder="e.g. Pharmaceutical Technologist">
            </div>
            <div class="form-group">
                <label class="form-label">Department</label>
                <select name="department_id" class="form-control">
                    <option value="">— none —</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['department_id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">Leave approval goes to this department's head first.</p>
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Professional cadre</label>
                <select name="cadre" class="form-control">
                    <?php foreach (hr_cadres() as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">
                    A pharmacist or pharmaceutical technologist counts towards dispensary
                    cover, so leave is checked against who else is on duty.
                </p>
            </div>
            <div class="form-group">
                <label class="form-label">Employment type</label>
                <select name="employment_type" class="form-control">
                    <?php foreach (hr_employment_types() as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Hire date <span class="req">*</span></label>
                <input type="date" name="hire_date" class="form-control" required>
                <p class="form-hint">Annual and sick leave open after a qualifying period measured from here.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <?php foreach (hr_statuses() as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Leaving date</label>
                <input type="date" name="end_date" class="form-control">
                <p class="form-hint">Only for somebody marked as having left.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Reason for leaving</label>
            <input type="text" name="exit_reason" class="form-control">
        </div>

        <fieldset class="form-fieldset">
        <legend class="form-legend">Professional registration</legend>
        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Body</label>
                <input type="text" name="prof_body" class="form-control" maxlength="40" placeholder="PPB">
            </div>
            <div class="form-group">
                <label class="form-label">Registration number</label>
                <input type="text" name="prof_registration_no" class="form-control" maxlength="60">
            </div>
            <div class="form-group">
                <label class="form-label">Expires</label>
                <input type="date" name="prof_expiry" class="form-control">
                <p class="form-hint">Required if a number is given, or it cannot be checked.</p>
            </div>
        </div>

        </fieldset>

        <fieldset class="form-fieldset">
        <legend class="form-legend">Personal</legend>
        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">National ID</label>
                <input type="text" name="national_id" class="form-control" maxlength="30">
            </div>
            <div class="form-group">
                <label class="form-label">Date of birth</label>
                <input type="date" name="date_of_birth" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Sex</label>
                <select name="sex" class="form-control">
                    <option value="">— not stated —</option>
                    <option value="female">Female</option>
                    <option value="male">Male</option>
                    <option value="other">Other</option>
                </select>
                <p class="form-hint">Only read to decide maternity and paternity eligibility.</p>
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" maxlength="30">
            </div>
            <div class="form-group">
                <label class="form-label">Personal email</label>
                <input type="email" name="personal_email" class="form-control" maxlength="255">
                <p class="form-hint">Where leave decisions are sent when there is no login.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Address</label>
            <input type="text" name="physical_address" class="form-control">
        </div>

        </fieldset>

        <fieldset class="form-fieldset">
        <legend class="form-legend">Next of kin</legend>
        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Name</label>
                <input type="text" name="nok_name" class="form-control" maxlength="160">
            </div>
            <div class="form-group">
                <label class="form-label">Relationship</label>
                <input type="text" name="nok_relationship" class="form-control" maxlength="60">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="nok_phone" class="form-control" maxlength="30">
            </div>
        </div>

        </fieldset>

        <fieldset class="form-fieldset">
        <legend class="form-legend">Statutory</legend>
        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">KRA PIN</label>
                <input type="text" name="kra_pin" class="form-control" maxlength="20">
            </div>
            <div class="form-group">
                <label class="form-label">NSSF number</label>
                <input type="text" name="nssf_number" class="form-control" maxlength="30">
            </div>
            <div class="form-group">
                <label class="form-label">SHIF number</label>
                <input type="text" name="shif_number" class="form-control" maxlength="30">
            </div>
        </div>

        <?php if ($canSeePay): ?>
            <?php /*  Administrators only, on the form and on the way
                      in — hr_employee_save() drops these fields for
                      anybody else, so this is not the only guard. */ ?>
            </fieldset>

            <fieldset class="form-fieldset">
            <legend class="form-legend">Pay and bank</legend>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Basic monthly pay</label>
                    <input type="number" name="basic_salary" class="form-control" step="0.01" min="0">
                    <p class="form-hint">Read by payroll. Visible to administrators only.</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Bank account</label>
                    <input type="text" name="bank_account_no" class="form-control" maxlength="40">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label">Bank</label>
                    <input type="text" name="bank_name" class="form-control" maxlength="120">
                </div>
                <div class="form-group">
                    <label class="form-label">Branch</label>
                    <input type="text" name="bank_branch" class="form-control" maxlength="120">
                </div>
            </div>
            </fieldset>
        <?php else: ?>
            </fieldset>
        <?php endif; ?>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save employee</button>
        </div>
    </form>
</template>

<?php if (is_admin() && $freeLogins): ?>
<template id="tplAttachLogin">
    <form method="POST" action="<?= e(url('hr/employees.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="login_link">
        <input type="hidden" name="employee_id" value="">

        <div class="form-group">
            <label class="form-label">Login to attach</label>
            <select name="user_id" class="form-control" required>
                <option value="">— choose an account —</option>
                <?php foreach ($freeLogins as $u): ?>
                    <option value="<?= (int) $u['user_id'] ?>">
                        <?= e($u['name'] ?: $u['username']) ?> (<?= e($u['username']) ?><?= $u['role'] ? ' · ' . e($u['role']) : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">
                Only accounts not already attached to an employee appear here. Give somebody
                who only needs to apply for leave the <strong>Staff</strong> role — it reaches
                their own leave and their own profile, and nothing else.
            </p>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Attach login</button>
        </div>
    </form>
</template>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
