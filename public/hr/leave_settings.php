<?php

/**
 * ============================================================
 *  Leave settings
 * ------------------------------------------------------------
 *  The leave types and the public holidays, on one page because
 *  they are the two things that decide how many days a request
 *  costs and neither is big enough to deserve a page of its own.
 *
 *  The seeded types are the Employment Act 2007 minimums. They
 *  are editable on purpose — a minimum is a floor, not a figure,
 *  and a shop that gives twenty-four days should not need a
 *  developer. The screen says which are statutory so that
 *  whoever edits 21 down to 14 knows what they are doing.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/icons.php';

require_role(ROLE_ADMIN);
require_tables('leave_types', 'public_holidays');

/* ── AJAX: save a leave type ──────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save_type') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $id   = input_int($_POST, 'leave_type_id');
    $name = trim(input($_POST, 'name'));
    if ($id === null || $name === '') {
        json_response(['success' => false, 'message' => 'Choose a type and give it a name.'], 422);
    }

    $perYear = trim(input($_POST, 'days_per_year'));
    $perYear = $perYear === '' ? null : max(0, (int) $perYear);
    $full    = max(0, (int) input_int($_POST, 'full_pay_days'));
    $half    = max(0, (int) input_int($_POST, 'half_pay_days'));

    //  Said here as well as in the CHECK constraint, because a
    //  constraint violation arrives as SQLSTATE and this arrives
    //  as a sentence.
    if ($perYear !== null && $full + $half > $perYear) {
        json_response(['success' => false, 'message' =>
            'The paid days (' . ($full + $half) . ') cannot exceed the allowance of ' . $perYear . '.'], 422);
    }

    try {
        db_run(
            'UPDATE leave_types
                SET name = :n, description = :d, days_per_year = :y,
                    full_pay_days = :f, half_pay_days = :h,
                    counts_calendar_days = :cal, min_service_months = :m,
                    requires_document = :doc, carry_over_max_days = :carry,
                    is_active = :a, updated_at = CURRENT_TIMESTAMP
              WHERE leave_type_id = :id',
            [
                ':n' => $name, ':d' => input($_POST, 'description') ?: null,
                ':y' => $perYear, ':f' => $full, ':h' => $half,
                ':cal'   => isset($_POST['counts_calendar_days']) ? 't' : 'f',
                ':m'     => max(0, (int) input_int($_POST, 'min_service_months')),
                ':doc'   => isset($_POST['requires_document']) ? 't' : 'f',
                ':carry' => max(0, (int) input_int($_POST, 'carry_over_max_days')),
                ':a'     => isset($_POST['is_active']) ? 't' : 'f',
                ':id'    => $id,
            ]
        );
        audit_log('leave.type_updated', 'leave_types', $id, ['name' => $name]);
        json_response(['success' => true, 'message' => $name . ' has been saved.']);
    } catch (PDOException $ex) {
        error_log('[LEAVE] type save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'That could not be saved.'], 500);
    }
}

/* ── POST: holidays ───────────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'add_holiday') {
    csrf_check();
    $date = trim(input($_POST, 'holiday_date'));
    $name = trim(input($_POST, 'holiday_name'));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $name === '') {
        flash('error', 'Give the holiday a date and a name.');
    } else {
        db_run('INSERT INTO public_holidays (holiday_date, name) VALUES (:d, :n)
                ON CONFLICT (holiday_date) DO UPDATE SET name = EXCLUDED.name',
               [':d' => $date, ':n' => $name]);
        audit_log('leave.holiday_added', 'public_holidays', null, ['date' => $date, 'name' => $name]);
        flash('success', $name . ' has been added.');
    }
    redirect('hr/leave_settings.php');
}

if (is_post() && ($_POST['_action'] ?? '') === 'delete_holiday') {
    csrf_check();
    $date = trim(input($_POST, 'holiday_date'));
    db_run('DELETE FROM public_holidays WHERE holiday_date = :d', [':d' => $date]);
    audit_log('leave.holiday_removed', 'public_holidays', null, ['date' => $date]);
    flash('success', 'The holiday has been removed.');
    redirect('hr/leave_settings.php');
}

$types = leave_types_all(false);

//  This year and next. History is not interesting and the list
//  would grow without end.
$holidays = db_all(
    "SELECT holiday_date, name FROM public_holidays
      WHERE holiday_date >= date_trunc('year', CURRENT_DATE)
      ORDER BY holiday_date"
);

$pageTitle    = 'Leave settings';
$pageSubtitle = 'Entitlements and the public holidays they are counted against';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'People'], ['label' => 'Leave'], ['label' => 'Settings']];

require __DIR__ . '/../../includes/header.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Leave types</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('hr/leave.php')) ?>">Back to leave</a>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type</th><th class="ta-right">Days a year</th><th>Paid</th>
                    <th>Counted in</th><th>Opens after</th><th>Document</th>
                    <th>Status</th><th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($types as $t):
                    $prefill = json_encode([
                        'leave_type_id'      => $t['leave_type_id'],
                        'name'               => $t['name'],
                        'description'        => $t['description'],
                        'days_per_year'      => $t['days_per_year'],
                        'full_pay_days'      => $t['full_pay_days'],
                        'half_pay_days'      => $t['half_pay_days'],
                        'min_service_months' => $t['min_service_months'],
                        'carry_over_max_days'=> $t['carry_over_max_days'],
                        'counts_calendar_days' => $t['counts_calendar_days'] ? 1 : '',
                        'requires_document'  => $t['requires_document'] ? 1 : '',
                        'is_active'          => $t['is_active'] ? 1 : '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($t['name']) ?></span>
                            <span class="cell-sub">
                                <?= e($t['code']) ?>
                                <?php if ($t['is_statutory']): ?>
                                    · <span class="badge badge--active">Statutory minimum</span>
                                <?php else: ?>
                                    · house policy
                                <?php endif; ?>
                                <?php if ($t['sex_restriction']): ?>
                                    · <?= e($t['sex_restriction']) ?> employees only
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <?= $t['days_per_year'] === null ? '<span class="cell-muted">uncapped</span>' : (int) $t['days_per_year'] ?>
                        </td>
                        <td class="cell-muted">
                            <?= (int) $t['full_pay_days'] ?> full<?= (int) $t['half_pay_days'] ? ', ' . (int) $t['half_pay_days'] . ' half' : '' ?>
                        </td>
                        <td class="cell-muted"><?= $t['counts_calendar_days'] ? 'calendar days' : 'working days' ?></td>
                        <td class="cell-muted">
                            <?= (int) $t['min_service_months'] ? (int) $t['min_service_months'] . ' months' : 'day one' ?>
                        </td>
                        <td><?= $t['requires_document'] ? '<span class="badge badge--pending">Required</span>' : '<span class="cell-muted">—</span>' ?></td>
                        <td>
                            <span class="badge badge--<?= $t['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $t['is_active'] ? 'Active' : 'Off' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <button type="button" class="icon-btn" title="Edit"
                                    data-modal-open="tplTypeForm" data-modal-title="Edit <?= e($t['name']) ?>"
                                    data-modal-wide data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Public holidays</h2>
    </div>
    <div class="u-pad">
        <p class="form-hint">
            Days here are not counted against annual or sick leave. Kenya's fixed-date
            holidays are seeded for three years; Good Friday, Easter Monday and the two
            Idds move each year and have to be added, because they cannot be worked out
            from a calendar.
        </p>
        <form method="POST" action="<?= e(url('hr/leave_settings.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="add_holiday">
            <div class="form-grid-3">
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="holiday_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text" name="holiday_name" class="form-control" required maxlength="120"
                           placeholder="e.g. Good Friday">
                </div>
                <div class="form-group">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Add holiday</button>
                </div>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Day</th><th>Holiday</th><th class="ta-right">Actions</th></tr></thead>
            <tbody>
                <?php if (!$holidays): ?>
                    <tr><td colspan="4" class="table-empty">None recorded from this year onwards.</td></tr>
                <?php endif; ?>
                <?php foreach ($holidays as $h): ?>
                    <tr>
                        <td><?= e(fmt_date($h['holiday_date'])) ?></td>
                        <td class="cell-muted"><?= e(date('l', strtotime((string) $h['holiday_date']))) ?></td>
                        <td><?= e($h['name']) ?></td>
                        <td class="ta-right">
                            <form method="POST" action="<?= e(url('hr/leave_settings.php')) ?>" class="inline-form"
                                  data-confirm="Remove <?= e($h['name']) ?>? Leave over that day will then be counted as a working day.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="delete_holiday">
                                <input type="hidden" name="holiday_date" value="<?= e($h['holiday_date']) ?>">
                                <button type="submit" class="icon-btn icon-btn--danger" title="Remove"><?= icon('trash') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<template id="tplTypeForm">
    <form method="POST" action="<?= e(url('hr/leave_settings.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_type">
        <input type="hidden" name="leave_type_id" value="">

        <div class="form-group">
            <label class="form-label">Name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="100">
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <input type="text" name="description" class="form-control">
            <p class="form-hint">Shown to whoever is applying.</p>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Days a year</label>
                <input type="number" name="days_per_year" class="form-control" min="0" step="1">
                <p class="form-hint">Leave blank for uncapped, like leave without pay.</p>
            </div>
            <div class="form-group">
                <label class="form-label">At full pay</label>
                <input type="number" name="full_pay_days" class="form-control" min="0" step="1" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">At half pay</label>
                <input type="number" name="half_pay_days" class="form-control" min="0" step="1" value="0">
                <p class="form-hint">Sick leave is 7 and 7 under the Act.</p>
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Opens after (months of service)</label>
                <input type="number" name="min_service_months" class="form-control" min="0" step="1" value="0">
            </div>
            <div class="form-group">
                <label class="form-label">Carry over at most</label>
                <input type="number" name="carry_over_max_days" class="form-control" min="0" step="1" value="0">
            </div>
        </div>

        <div class="form-group form-check">
            <label><input type="checkbox" name="counts_calendar_days" value="1">
                Counted in calendar days <span class="opt">(rather than working days)</span></label>
        </div>
        <div class="form-group form-check">
            <label><input type="checkbox" name="requires_document" value="1">
                Needs a supporting document before it can be approved</label>
        </div>
        <div class="form-group form-check">
            <label><input type="checkbox" name="is_active" value="1"> Available to apply for</label>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
