<?php

/**
 * ============================================================
 *  Audit Trail
 * ------------------------------------------------------------
 *  Forty-nine files write to `audit_logs`. Until now nothing
 *  read it back, which meant the answer to "who deleted that
 *  supplier?" was sitting in the database with no way to ask.
 *
 *  A trail nobody can look at is not a trail. It is storage.
 *
 *  ── What it is for ──────────────────────────────────────────
 *  Three questions, and the filters exist to answer them:
 *
 *    what happened to THIS record   → entity + entity id
 *    what has THIS PERSON done      → user
 *    what happened THAT DAY         → date range
 *
 *  ── Read-only, on purpose ───────────────────────────────────
 *  There is no edit and no delete. An audit trail somebody can
 *  tidy up is worth nothing in the argument it exists to settle,
 *  so the page offers no way to change what it shows — and this
 *  file writes nothing to the table it reads.
 *
 *  Administrators only: it records everybody's actions, including
 *  which invoices were looked at and what a manager changed.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/icons.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_ADMIN);
require_tables('audit_logs');

/* ── Filters ──────────────────────────────────────────────── */
$search  = input($_GET, 'q');
$userId  = input_int($_GET, 'user_id');
$action  = input($_GET, 'action');
$entity  = input($_GET, 'entity');
$from    = input($_GET, 'from');
$to      = input($_GET, 'to');
$perPage = per_page();

$where  = [];
$params = [];

if ($search !== '') {
    // One box over the things a person actually remembers: the
    // record's number, the action, who did it.
    $where[] = "(LOWER(a.action) LIKE :q
                 OR LOWER(COALESCE(a.details, '')) LIKE :q
                 OR LOWER(COALESCE(a.entity, '')) LIKE :q
                 OR a.entity_id = :qexact
                 OR LOWER(TRIM(CONCAT(u.first_name, ' ', u.last_name))) LIKE :q
                 OR LOWER(COALESCE(u.email, '')) LIKE :q)";
    $params[':q']      = '%' . strtolower($search) . '%';
    $params[':qexact'] = $search;
}
if ($userId !== null) {
    $where[] = "a.user_id = :uid";
    $params[':uid'] = $userId;
}
if ($action !== '') {
    $where[] = "a.action = :action";
    $params[':action'] = $action;
}
if ($entity !== '') {
    $where[] = "a.entity = :entity";
    $params[':entity'] = $entity;
}
// Dates are inclusive of both ends: somebody asking for "the 3rd"
// means the whole of the 3rd, not up to midnight at the start of it.
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where[] = "a.created_at >= :from::date";
    $params[':from'] = $from;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where[] = "a.created_at < (:to::date + INTERVAL '1 day')";
    $params[':to'] = $to;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$joinSql  = "FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id";

/* ── Filter options, from what is actually in the table ──── */
// Built from the data rather than a hard-coded list, so an action
// added by a new module appears in the filter without anyone
// remembering to come back here.
$actionOptions = db_all("SELECT DISTINCT action FROM audit_logs WHERE action <> '' ORDER BY action");
$entityOptions = db_all("SELECT DISTINCT entity FROM audit_logs WHERE entity IS NOT NULL AND entity <> '' ORDER BY entity");
$userOptions   = db_all(
    "SELECT u.user_id, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email
       FROM users u
      WHERE EXISTS (SELECT 1 FROM audit_logs a WHERE a.user_id = u.user_id)
      ORDER BY name"
);

$selectSql = "SELECT a.log_id, a.user_id, a.action, a.entity, a.entity_id,
                     a.details, a.ip_address, a.created_at,
                     COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''),
                              u.email, 'Deleted user') AS user_name,
                     u.email AS user_email
              $joinSql
              $whereSql";

/* ── Export ───────────────────────────────────────────────── */
if (wants_export()) {
    export_deliver(export_filename('audit-trail'), [[
        'name'  => 'Audit trail',
        'title' => 'Audit trail',
        'meta'  => export_meta([
            'Search' => $search, 'Action' => $action, 'Record type' => $entity,
            'From' => $from, 'To' => $to,
        ]),
        'columns' => [
            ['label' => 'When',   'key' => 'created_at', 'value' => fn($r) => fmt_date($r['created_at'], 'd M Y H:i')],
            ['label' => 'Who',    'key' => 'user_name'],
            ['label' => 'Action', 'key' => 'action', 'value' => fn($r) => audit_action_label($r['action'])],
            ['label' => 'Record', 'key' => 'entity'],
            ['label' => 'Record id', 'key' => 'entity_id'],
            ['label' => 'Details', 'key' => 'details', 'value' => fn($r) => audit_detail_text($r['details'])],
            ['label' => 'IP',     'key' => 'ip_address'],
        ],
        // The export is what you are looking at, minus the page
        // limit — an export of page 3 alone is not what anybody
        // means by "export" — but capped, because the trail grows
        // without bound and a spreadsheet of a million rows helps
        // nobody.
        'rows' => db_all("$selectSql ORDER BY a.created_at DESC, a.log_id DESC LIMIT 5000", $params),
    ]]);
}

$total = (int) db_value("SELECT COUNT(*) $joinSql $whereSql", $params);
$pg    = paginate($total, $perPage);

$entries = db_all(
    "$selectSql
     ORDER BY a.created_at DESC, a.log_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$pageTitle    = 'Audit Trail';
$pageSubtitle = num($total) . ' recorded action' . ($total === 1 ? '' : 's');
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Users', 'href' => 'users/index.php'], ['label' => 'Audit Trail']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter([
    'q' => $search, 'user_id' => $userId, 'action' => $action,
    'entity' => $entity, 'from' => $from, 'to' => $to,
], fn($v) => $v !== null && $v !== '');
?>

<form class="filter-bar" method="GET" action="<?= e(url('users/audit.php')) ?>">
    <div class="filter-row">
        <div class="toolbar-search filter-grow">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="Record number, action, person…">
        </div>
        <?= export_button('users/audit.php', $baseQuery) ?>
    </div>

    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">Who</span>
            <select name="user_id" class="form-control" data-searchable="Search people">
                <option value="">Anyone</option>
                <?php foreach ($userOptions as $u): ?>
                    <option value="<?= (int) $u['user_id'] ?>" <?= $userId === (int) $u['user_id'] ? 'selected' : '' ?>>
                        <?= e($u['name'] ?: $u['email']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">Action</span>
            <select name="action" class="form-control">
                <option value="">Any action</option>
                <?php foreach ($actionOptions as $a): ?>
                    <option value="<?= e($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>>
                        <?= e(audit_action_label($a['action'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">Record type</span>
            <select name="entity" class="form-control">
                <option value="">Anything</option>
                <?php foreach ($entityOptions as $en): ?>
                    <option value="<?= e($en['entity']) ?>" <?= $entity === $en['entity'] ? 'selected' : '' ?>>
                        <?= e(audit_entity_label($en['entity'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
        </label>
        <?= toolbar_apply('users/audit.php') ?>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>Record</th>
                    <th>Details</th>
                    <th>IP address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$entries): ?>
                    <tr><td colspan="6" class="table-empty">
                        <?= $baseQuery
                            ? 'No recorded action matches these filters.'
                            : 'Nothing has been recorded yet.' ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($entries as $en): ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e(fmt_date($en['created_at'])) ?></span>
                            <span class="cell-sub"><?= e(date('H:i', strtotime($en['created_at']))) ?></span>
                        </td>
                        <td>
                            <span class="cell-title"><?= e($en['user_name']) ?></span>
                            <?php if ($en['user_email']): ?>
                                <span class="cell-sub"><?= e($en['user_email']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= e(audit_action_tone($en['action'])) ?>">
                                <?= e(audit_action_label($en['action'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($en['entity']): ?>
                                <span class="cell-title"><?= e(audit_entity_label($en['entity'])) ?></span>
                                <?php if ($en['entity_id'] !== null && $en['entity_id'] !== ''): ?>
                                    <?php $link = audit_entity_url($en['entity'], $en['entity_id']); ?>
                                    <span class="cell-sub">
                                        <?php if ($link): ?>
                                            <a href="<?= e(url($link)) ?>">#<?= e($en['entity_id']) ?></a>
                                        <?php else: ?>
                                            #<?= e($en['entity_id']) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= e(audit_detail_text($en['details'])) ?></td>
                        <td class="cell-muted"><?= e($en['ip_address'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'action'); ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
