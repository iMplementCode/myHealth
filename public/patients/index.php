<?php

/**
 * ============================================================
 *  Patients
 * ------------------------------------------------------------
 *  Who the medicine is for. Not `customers`, which is who pays —
 *  see migration 064 for why those are two tables.
 *
 *  Today the pharmacy uses this to attach a name and an allergy
 *  list to a sale. The columns it does not use yet are the ones
 *  a hospital will, and adding them now costs a migration where
 *  adding them later costs a data migration on live records.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/icons.php';
require_once __DIR__ . '/../../includes/patients.php';

require_role(ROLE_SALES);

/* ── Search, as JSON, for the counter's picker ────────────── */
if (($_GET['find'] ?? '') !== '') {
    json_response([
        'success' => true,
        'results' => array_map(static fn(array $p): array => [
            'id'        => (int) $p['patient_id'],
            'number'    => $p['patient_number'],
            'name'      => patient_name($p),
            'phone'     => $p['phone'],
            'age'       => patient_age($p['date_of_birth']),
            'allergies' => $p['allergies'],
        ], patient_search((string) $_GET['find'])),
    ]);
}

/* ── Create or update ─────────────────────────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $result = patient_save(
        $_POST['_action'] === 'create' ? null : input_int($_POST, 'patient_id'),
        $_POST
    );
    json_response(
        ['success' => $result['ok'], 'message' => $result['message']],
        $result['ok'] ? 200 : 422
    );
}

/* ── The list ─────────────────────────────────────────────── */
$search = trim((string) ($_GET['q'] ?? ''));
$where  = ['p.is_active'];
$params = [];
if ($search !== '') {
    $where[] = '(LOWER(p.first_name) LIKE :q OR LOWER(p.last_name) LIKE :q
              OR LOWER(p.patient_number) LIKE :q OR p.phone LIKE :q
              OR LOWER(COALESCE(p.sha_number, \'\')) LIKE :q)';
    $params[':q'] = '%' . mb_strtolower($search) . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_value("SELECT COUNT(*) FROM patients p $whereSql", $params);
$pg    = paginate($total, 25);

$patients = db_all(
    "SELECT p.*,
            (SELECT COUNT(*) FROM visits v WHERE v.patient_id = p.patient_id) AS visit_count
       FROM patients p
       $whereSql
      ORDER BY p.created_at DESC
      LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$pageTitle    = 'Patients';
$pageSubtitle = 'Who the medicine is for';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Patients']];

require __DIR__ . '/../../includes/header.php';
$baseQuery = array_filter(['q' => $search], fn($v) => $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('patients/index.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Name, number, phone or SHA&hellip;">
        <?= toolbar_apply('patients/index.php') ?>
    </form>
    <button type="button" class="btn btn-primary"
            data-modal-open="tplPatientForm" data-modal-title="Register Patient" data-modal-auto
            data-prefill='<?= e(json_encode(['_action' => 'create', 'patient_id' => ''], JSON_UNESCAPED_SLASHES)) ?>'>
        <?= icon('plus') ?> Register Patient
    </button>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Name</th>
                    <th>Age</th>
                    <th>Phone</th>
                    <th>Allergies</th>
                    <th class="ta-right">Visits</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$patients): ?>
                    <tr><td colspan="7" class="table-empty">
                        <?= $search !== '' ? 'Nobody matches that.' : 'No patients registered yet.' ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($patients as $p): ?>
                    <?php $prefill = json_encode([
                        '_action'           => 'update',
                        'patient_id'        => $p['patient_id'],
                        'first_name'        => $p['first_name'],
                        'last_name'         => $p['last_name'] ?? '',
                        'date_of_birth'     => $p['date_of_birth'] ?? '',
                        'sex'               => $p['sex'] ?? '',
                        'phone'             => $p['phone'] ?? '',
                        'email'             => $p['email'] ?? '',
                        'national_id'       => $p['national_id'] ?? '',
                        'sha_number'        => $p['sha_number'] ?? '',
                        'blood_group'       => $p['blood_group'] ?? '',
                        'allergies'         => $p['allergies'] ?? '',
                        'chronic_conditions' => $p['chronic_conditions'] ?? '',
                        'next_of_kin_name'  => $p['next_of_kin_name'] ?? '',
                        'next_of_kin_phone' => $p['next_of_kin_phone'] ?? '',
                        'notes'             => $p['notes'] ?? '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                    <tr>
                        <td class="cell-muted"><?= e($p['patient_number']) ?></td>
                        <td><span class="cell-title"><?= e(patient_name($p)) ?></span>
                            <?php if (!empty($p['sha_number'])): ?>
                                <span class="cell-sub">SHA <?= e($p['sha_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= e(patient_age($p['date_of_birth']) ?? '—') ?></td>
                        <td class="cell-muted"><?= e($p['phone'] ?: '—') ?></td>
                        <td>
                            <?php /*  The one column on this page that can
                                      matter clinically, so it is shown rather
                                      than hidden behind an edit button. */ ?>
                            <?php if (!empty($p['allergies'])): ?>
                                <span class="badge badge--danger" title="<?= e($p['allergies']) ?>">
                                    <?= e(mb_strimwidth($p['allergies'], 0, 40, '…')) ?>
                                </span>
                            <?php else: ?>
                                <span class="cell-muted">none recorded</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><?= e(num($p['visit_count'], 0)) ?></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplPatientForm" data-modal-title="Edit Patient"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'patient'); ?>
</div>

<template id="tplPatientForm">
    <form method="POST" action="<?= e(url('patients/index.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="patient_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">First name <span class="req">*</span></label>
                <input type="text" name="first_name" class="form-control" required maxlength="80">
            </div>
            <div class="form-group">
                <label class="form-label">Last name</label>
                <input type="text" name="last_name" class="form-control" maxlength="80">
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Date of birth</label>
                <input type="date" name="date_of_birth" class="form-control">
                <span class="form-hint">Needed to weigh a child's dose.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Sex</label>
                <select name="sex" class="form-control">
                    <option value="">&mdash; Not said &mdash;</option>
                    <?php foreach (PATIENT_SEXES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="tel" name="phone" class="form-control" maxlength="30">
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">National ID</label>
                <input type="text" name="national_id" class="form-control" maxlength="30">
            </div>
            <div class="form-group">
                <label class="form-label">SHA number</label>
                <input type="text" name="sha_number" class="form-control" maxlength="40">
            </div>
            <div class="form-group">
                <label class="form-label">Blood group</label>
                <input type="text" name="blood_group" class="form-control" maxlength="5" placeholder="O+">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Allergies</label>
            <textarea name="allergies" class="form-control" rows="2"
                      placeholder="Penicillin, sulfa&hellip;"></textarea>
            <span class="form-hint">Shown to whoever dispenses. Written as words, and
                <strong>never</strong> checked automatically &mdash; a system that claimed to
                do that would be trusted, and it would be wrong.</span>
        </div>

        <div class="form-group">
            <label class="form-label">Long-term conditions</label>
            <textarea name="chronic_conditions" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Next of kin</label>
                <input type="text" name="next_of_kin_name" class="form-control" maxlength="160">
            </div>
            <div class="form-group">
                <label class="form-label">Their phone</label>
                <input type="tel" name="next_of_kin_phone" class="form-control" maxlength="30">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save Patient</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
