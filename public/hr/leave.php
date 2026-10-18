<?php

/**
 * ============================================================
 *  Leave — approving it
 * ------------------------------------------------------------
 *  The queue, and one request in detail.
 *
 *  The part worth reading is the cover panel. Before anybody
 *  approves, this page asks who would actually be on duty in the
 *  dispensary on each working day covered by the request, and
 *  says so. Approving anyway is allowed — a locum gets arranged
 *  on the telephone, and a system that flatly refused would be
 *  worked around by not recording the leave at all — but the
 *  warning is written onto the request, so the shop cannot later
 *  be unsure whether it knew.
 *
 *  Who may press which button is decided by leave.php, not here,
 *  and the page asks the same functions the actions do. A button
 *  that appears and then refuses is worse than no button.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/icons.php';
require_once __DIR__ . '/../../includes/uploads.php';

require_role(ROLE_MANAGER);
require_tables('employees', 'leave_requests');

/* ── POST: a decision ─────────────────────────────────────── */
if (is_post()) {
    csrf_check();

    $id     = input_int($_POST, 'request_id') ?? 0;
    $action = (string) ($_POST['_action'] ?? '');

    if (!in_array($action, ['dept_approve', 'final_approve', 'reject', 'cancel'], true)) {
        flash('error', 'That is not something that can be done to a leave request.');
        redirect('hr/leave.php');
    }

    //  leave_decide() re-checks the permission and recomputes the
    //  cover warning itself; nothing here is trusted on its own.
    $r = leave_decide($id, $action, input($_POST, 'note'));
    flash($r['ok'] ? 'success' : 'error', $r['message']);

    //  An approval that went ahead over a cover warning says so
    //  once more on the way out, so it is not something that only
    //  shows up in a database column.
    if ($r['ok'] && !empty($r['cover_warning'])) {
        flash('warning', $r['cover_warning']);
    }

    redirect('hr/leave.php' . ($id ? '?id=' . $id : ''));
}

$viewId = input_int($_GET, 'id');
$one    = $viewId ? leave_request_get($viewId) : null;
if ($viewId && !$one) {
    flash('error', 'That request no longer exists.');
    redirect('hr/leave.php');
}

/* ── The queue ────────────────────────────────────────────── */
$status  = input($_GET, 'status');
$deptId  = input_int($_GET, 'department');
$empId   = input_int($_GET, 'employee');
$openly  = $status === '' && $empId === null;   // default view: what needs deciding
$perPage = per_page();

$filter = array_filter([
    'status'        => $status,
    'department_id' => $deptId,
    'employee_id'   => $empId,
    'open'          => $openly ? 1 : null,
], static fn($v) => $v !== null && $v !== '');

$total    = leave_request_count($filter);
$pg       = paginate($total, $perPage);
$requests = leave_requests_list($filter, (int) $pg['per_page'], (int) $pg['offset']);

$awaiting   = leave_request_count(['open' => 1]);
$awayToday  = leave_away_on(date('Y-m-d'));
$departments = hr_departments();

//  The cover picture for the request being looked at.
$coverDays = $one ? leave_cover_by_day((string) $one['start_date'], (string) $one['end_date']) : [];
$coverNow  = $one && in_array($one['status'], ['submitted', 'dept_approved'], true)
    ? leave_cover_warning((string) $one['start_date'], (string) $one['end_date'])
    : null;

$pageTitle    = 'Leave';
$pageSubtitle = 'Requests, approvals and who is away';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'People'], ['label' => 'Leave']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter([
    'status' => $status, 'department' => $deptId, 'employee' => $empId,
], static fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card<?= $awaiting ? ' mini-card--danger' : '' ?>">
        <span class="mini-label">Awaiting a decision</span>
        <span class="mini-value"><?= e(num($awaiting)) ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Away today</span>
        <span class="mini-value"><?= e(num(count($awayToday))) ?></span>
        <?php if ($awayToday): ?>
            <span class="mini-sub">
                <?= e(implode(', ', array_map(
                    static fn(array $a): string => trim($a['first_name'] . ' ' . $a['last_name']),
                    array_slice($awayToday, 0, 3)
                ))) ?><?= count($awayToday) > 3 ? ' and ' . (count($awayToday) - 3) . ' more' : '' ?>
            </span>
        <?php endif; ?>
    </div>
    <?php
        //  Cover today, which is the number somebody actually wants
        //  when they walk in and find a queue at the counter.
        $today = leave_cover_by_day(date('Y-m-d'), date('Y-m-d'));
        $onDuty = $today ? (int) $today[0]['registered_on_duty'] : null;
    ?>
    <div class="mini-card<?= $onDuty === 0 ? ' mini-card--danger' : '' ?>">
        <span class="mini-label">Registered dispensers on duty</span>
        <span class="mini-value"><?= $onDuty === null ? '—' : e(num($onDuty)) ?></span>
        <span class="mini-sub"><?= $onDuty === null ? 'Not a working day' : e($today[0]['who'] ?: 'nobody') ?></span>
    </div>
</section>

<?php if ($one): ?>
    <?php
        $canDept  = leave_may_dept_decide($one);
        $canFinal = leave_may_final_decide($one);
        $canCncl  = leave_may_cancel($one);
        $days     = rtrim(rtrim(number_format((float) $one['days_requested'], 1), '0'), '.');
    ?>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">
                <?= e(trim($one['first_name'] . ' ' . $one['last_name'])) ?> — <?= e($one['type_name']) ?>
            </h2>
            <span class="badge badge--<?= e(leave_status_tone($one['status'])) ?>">
                <?= e(leave_statuses()[$one['status']] ?? $one['status']) ?>
            </span>
        </div>

        <div class="u-pad">
            <div class="form-grid-3">
                <div><span class="cell-sub">Reference</span><br><strong><?= e($one['request_number']) ?></strong></div>
                <div><span class="cell-sub">Dates</span><br>
                    <strong><?= e(fmt_date($one['start_date'])) ?> – <?= e(fmt_date($one['end_date'])) ?></strong></div>
                <div><span class="cell-sub">Days</span><br>
                    <strong><?= e($days) ?></strong>
                    <?= $one['counts_calendar_days'] ? ' calendar' : ' working' ?></div>
                <div><span class="cell-sub">Staff number</span><br><strong><?= e($one['staff_number']) ?></strong></div>
                <div><span class="cell-sub">Department</span><br><strong><?= e($one['department_name'] ?: '—') ?></strong></div>
                <div><span class="cell-sub">Applied</span><br><strong><?= e(fmt_date($one['submitted_at'])) ?></strong></div>
            </div>

            <?php if ($one['reason']): ?>
                <p class="u-mt"><span class="cell-sub">Reason</span><br><?= e($one['reason']) ?></p>
            <?php endif; ?>
            <?php if ($one['contact_while_away']): ?>
                <p class="u-mt"><span class="cell-sub">Contact while away</span><br><?= e($one['contact_while_away']) ?></p>
            <?php endif; ?>

            <p class="u-mt">
                <span class="cell-sub">Supporting document</span><br>
                <?php if ($one['document_url']): ?>
                    <a href="<?= e(private_document_url($one['document_url'])) ?>" target="_blank" rel="noopener">
                        <?= icon('file') ?> Open
                    </a>
                <?php elseif (!empty($one['requires_document'])): ?>
                    <span class="badge badge--warn">Required, not attached</span>
                    — <?= e($one['type_name']) ?> cannot be approved until there is one.
                <?php else: ?>
                    <span class="cell-muted">None</span>
                <?php endif; ?>
            </p>

            <?php if ($one['dept_decision_at']): ?>
                <p class="cell-sub u-mt">
                    Department: <?= e($one['dept_decider'] ?: 'somebody') ?>
                    on <?= e(fmt_date($one['dept_decision_at'])) ?>
                    <?= $one['dept_note'] ? '— ' . e($one['dept_note']) : '' ?>
                </p>
            <?php endif; ?>
            <?php if ($one['final_decision_at']): ?>
                <p class="cell-sub">
                    Final: <?= e($one['final_decider'] ?: 'somebody') ?>
                    on <?= e(fmt_date($one['final_decision_at'])) ?>
                    <?= $one['final_note'] ? '— ' . e($one['final_note']) : '' ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php /*  ── Cover ──────────────────────────────────────────
              The reason this module exists. Shown before the
              buttons, not after, and shown on a decided request
              too — as the record of what was known at the time. */ ?>
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Dispensary cover for these dates</h2>
        </div>

        <?php if ($coverNow): ?>
            <div class="u-pad">
                <div class="alert alert--warning" role="status"><span><?= e($coverNow) ?></span></div>
            </div>
        <?php elseif ($one['cover_warning']): ?>
            <div class="u-pad">
                <div class="alert alert--warning" role="status">
                    <span>
                        <strong>Approved over this warning:</strong> <?= e($one['cover_warning']) ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Working day</th><th class="ta-right">On duty</th>
                        <th class="ta-right">With current registration</th><th>Who</th></tr>
                </thead>
                <tbody>
                    <?php if (!$coverDays): ?>
                        <tr><td colspan="4" class="table-empty">
                            No working days in this range — it is all weekend and public holidays.
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($coverDays as $d): ?>
                        <tr>
                            <td><?= e(fmt_date($d['day'])) ?></td>
                            <td class="ta-right"><?= (int) $d['on_duty'] ?></td>
                            <td class="ta-right">
                                <span class="badge badge--<?= (int) $d['registered_on_duty'] === 0 ? 'expired' : 'active' ?>">
                                    <?= (int) $d['registered_on_duty'] ?>
                                </span>
                            </td>
                            <td class="cell-muted"><?= e($d['who'] ?: 'nobody') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canDept || $canFinal || $canCncl): ?>
            <div class="u-pad">
                <?php if ($canDept && $canFinal): ?>
                    <?php /*  An administrator can do either step, so both
                              buttons appear. Unlabelled, that is a choice
                              between two things that look identical.     */ ?>
                    <p class="form-hint">
                        <strong>Approve for the department</strong> passes it on for a second,
                        separate decision — which is the point of two steps.
                        <strong>Approve</strong> grants it outright, recording that there was no
                        departmental step. Use that where the shop is too small to have two
                        approvers.
                    </p>
                <?php endif; ?>
                <div class="form-actions" style="justify-content:flex-start">
                    <?php if ($canDept): ?>
                        <form method="POST" action="<?= e(url('hr/leave.php')) ?>" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="dept_approve">
                            <input type="hidden" name="request_id" value="<?= (int) $one['request_id'] ?>">
                            <button type="submit" class="btn btn-primary">
                                <?= icon('check') ?> Approve for the department
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canFinal): ?>
                        <form method="POST" action="<?= e(url('hr/leave.php')) ?>" class="inline-form"
                              <?= $coverNow ? 'data-confirm="There is no dispensary cover on some of these days. Approve anyway? The warning is kept on the request."' : '' ?>>
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="final_approve">
                            <input type="hidden" name="request_id" value="<?= (int) $one['request_id'] ?>">
                            <button type="submit" class="btn btn-primary">
                                <?= icon('check') ?> Approve
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canDept || $canFinal): ?>
                        <button type="button" class="btn btn-ghost"
                                data-modal-open="tplReject" data-modal-title="Refuse this request"
                                data-prefill="<?= e(json_encode(['request_id' => $one['request_id']], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>">
                            Refuse
                        </button>
                    <?php endif; ?>

                    <?php if ($canCncl): ?>
                        <form method="POST" action="<?= e(url('hr/leave.php')) ?>" class="inline-form"
                              data-confirm="Cancel <?= e($one['request_number']) ?>? The days go back on the balance.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="cancel">
                            <input type="hidden" name="request_id" value="<?= (int) $one['request_id'] ?>">
                            <button type="submit" class="btn btn-ghost">Cancel the leave</button>
                        </form>
                    <?php endif; ?>

                    <a class="btn btn-ghost" href="<?= e(url('hr/leave.php')) ?>">Back to the queue</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <template id="tplReject">
        <form method="POST" action="<?= e(url('hr/leave.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="reject">
            <input type="hidden" name="request_id" value="">
            <div class="form-group">
                <label class="form-label">Why <span class="req">*</span></label>
                <input type="text" name="note" class="form-control" required maxlength="300"
                       placeholder="The employee is told this, so make it the real reason">
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">Refuse the request</button>
            </div>
        </form>
    </template>

<?php endif; ?>

<form class="toolbar" method="GET" action="<?= e(url('hr/leave.php')) ?>">
    <div class="toolbar-search">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Awaiting a decision</option>
            <?php foreach (leave_statuses() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="department" class="form-control toolbar-select" data-autosubmit>
            <option value="">Every department</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['department_id'] ?>"
                    <?= $deptId === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?= toolbar_apply('hr/leave.php') ?>
    </div>
    <a class="btn btn-ghost" href="<?= e(url('hr/employees.php')) ?>"><?= icon('users') ?> Employees</a>
    <a class="btn btn-ghost" href="<?= e(url('hr/leave_settings.php')) ?>"><?= icon('cog') ?> Leave settings</a>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Employee</th><th>Leave</th><th>When</th>
                    <th class="ta-right">Days</th><th>Status</th><th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="6" class="table-empty">
                        <?= $openly ? 'Nothing is waiting for a decision.' : 'Nothing matches.' ?>
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></span>
                            <span class="cell-sub"><?= e($r['job_title']) ?><?= $r['department_name'] ? ' · ' . e($r['department_name']) : '' ?></span>
                        </td>
                        <td>
                            <span class="cell-title"><?= e($r['type_name']) ?></span>
                            <span class="cell-sub"><?= e($r['request_number']) ?></span>
                        </td>
                        <td class="cell-muted">
                            <?= e(fmt_date($r['start_date'])) ?> – <?= e(fmt_date($r['end_date'])) ?>
                        </td>
                        <td class="ta-right"><?= e(rtrim(rtrim(number_format((float) $r['days_requested'], 1), '0'), '.')) ?></td>
                        <td>
                            <span class="badge badge--<?= e(leave_status_tone($r['status'])) ?>">
                                <?= e(leave_statuses()[$r['status']] ?? $r['status']) ?>
                            </span>
                            <?php if ($r['cover_warning']): ?>
                                <span class="cell-sub">Approved without cover</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <a class="btn btn-ghost btn-sm" href="<?= e(url('hr/leave.php?id=' . (int) $r['request_id'])) ?>">
                                Open
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'request'); ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
