<?php

/**
 * ============================================================
 *  Leave
 * ------------------------------------------------------------
 *  Applying, counting, approving — and checking that approving
 *  it does not leave the dispensary without anybody registered
 *  to be in charge of it.
 *
 *  That last part is the whole reason this is not a generic
 *  leave module. A pharmacy's premises licence turns on
 *  supervision, so the moment somebody clicks Approve on the
 *  superintendent pharmacist's Thursday off, the shop has a day
 *  it should not open. The warning is produced here, shown to
 *  the approver, and then STORED on the request, so that months
 *  later it is answerable whether the shop knew.
 *
 *  Two deliberate decisions, both the kind that is annoying to
 *  discover later:
 *
 *  1. days_requested is counted once, at submission, and then
 *     frozen. Adding a public holiday in March must not change
 *     what somebody was charged in January.
 *
 *  2. A request is charged to the leave year of its START date,
 *     including when it runs over the new year. Splitting it
 *     would be more precise and would also mean refusing a
 *     three-month maternity leave for crossing a boundary, which
 *     is worse. The screens say which year a request lands in.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/hr.php';

/** Statuses a request can hold, and what they are called on screen. */
function leave_statuses(): array
{
    return [
        'submitted'     => 'Awaiting department',
        'dept_approved' => 'Awaiting HR',
        'approved'      => 'Approved',
        'rejected'      => 'Rejected',
        'cancelled'     => 'Cancelled',
        'withdrawn'     => 'Withdrawn',
    ];
}

/** Badge modifier, matching the rest of the application's CSS. */
function leave_status_tone(string $status): string
{
    return match ($status) {
        'approved'                 => 'active',
        'submitted', 'dept_approved' => 'pending',
        'rejected'                 => 'expired',
        'cancelled', 'withdrawn'   => 'inactive',
        default                    => 'inactive',
    };
}

/** The statuses that occupy a person's calendar. */
function leave_live_statuses(): array
{
    return ['submitted', 'dept_approved', 'approved'];
}

function leave_ready(): bool
{
    return hr_ready() && table_exists('leave_types') && table_exists('leave_requests');
}

/** Which leave year a date falls in. */
function leave_year_of(string $date): int
{
    return (int) date('Y', strtotime($date));
}

/* ─────────────────────────────────────────────────────────────
 *  Counting days
 * ──────────────────────────────────────────────────────────── */

/**
 * How many days a range costs.
 *
 * Working days for annual and sick leave — weekends and public
 * holidays are not leave, and counting them means an employee
 * spends four of their twenty-one days on Christmas. Calendar
 * days for maternity and paternity, which the statute states in
 * months and weeks.
 *
 * Counted in SQL so the holiday list is consulted in one place
 * and one round trip.
 */
function leave_count_days(string $from, string $to, bool $calendarDays): float
{
    if ($from === '' || $to === '' || strtotime($to) < strtotime($from)) {
        return 0.0;
    }

    if ($calendarDays) {
        return (float) (int) db_value(
            "SELECT (CAST(:t AS date) - CAST(:f AS date)) + 1",
            [':f' => $from, ':t' => $to]
        );
    }

    return (float) (int) db_value(
        "SELECT COUNT(*)
           FROM generate_series(CAST(:f AS date), CAST(:t AS date), INTERVAL '1 day') AS d
          WHERE EXTRACT(ISODOW FROM d) < 6
            AND NOT EXISTS (
                SELECT 1 FROM public_holidays h WHERE h.holiday_date = d::date
            )",
        [':f' => $from, ':t' => $to]
    );
}

/** Holidays inside a range, so a form can explain the arithmetic. */
function leave_holidays_between(string $from, string $to): array
{
    if (!table_exists('public_holidays')) {
        return [];
    }
    return db_all(
        "SELECT holiday_date, name FROM public_holidays
          WHERE holiday_date BETWEEN CAST(:f AS date) AND CAST(:t AS date)
          ORDER BY holiday_date",
        [':f' => $from, ':t' => $to]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  Types and balances
 * ──────────────────────────────────────────────────────────── */

function leave_types_all(bool $activeOnly = true): array
{
    if (!leave_ready()) {
        return [];
    }
    return db_all(
        'SELECT * FROM leave_types '
        . ($activeOnly ? 'WHERE is_active ' : '')
        . 'ORDER BY sort_order, name'
    );
}

function leave_type_get(int $id): ?array
{
    return db_one('SELECT * FROM leave_types WHERE leave_type_id = :id', [':id' => $id]) ?: null;
}

/**
 * What one employee has left of one leave type this year.
 *
 * entitled is NULL for an uncapped type (leave without pay),
 * and then available is NULL too — there is no allowance to run
 * out of, so a screen shows a dash rather than a number.
 */
function leave_balance(int $employeeId, int $leaveTypeId, ?int $year = null): array
{
    $year ??= (int) date('Y');

    $type = leave_type_get($leaveTypeId);
    if (!$type) {
        return ['entitled' => 0.0, 'carried' => 0.0, 'adjustment' => 0.0,
                'taken' => 0.0, 'pending' => 0.0, 'available' => 0.0, 'uncapped' => false];
    }

    $ent = db_one(
        'SELECT days_entitled, days_carried_over, adjustment
           FROM leave_entitlements
          WHERE employee_id = :e AND leave_type_id = :t AND leave_year = :y',
        [':e' => $employeeId, ':t' => $leaveTypeId, ':y' => $year]
    );

    //  An override wins; otherwise the type's own allowance.
    $entitled = $ent && $ent['days_entitled'] !== null
        ? (float) $ent['days_entitled']
        : ($type['days_per_year'] === null ? null : (float) $type['days_per_year']);

    $carried    = $ent ? (float) $ent['days_carried_over'] : 0.0;
    $adjustment = $ent ? (float) $ent['adjustment'] : 0.0;

    $used = db_one(
        "SELECT
            COALESCE(SUM(days_requested) FILTER (WHERE status = 'approved'), 0) AS taken,
            COALESCE(SUM(days_requested) FILTER (WHERE status IN ('submitted','dept_approved')), 0) AS pending
           FROM leave_requests
          WHERE employee_id = :e AND leave_type_id = :t
            AND EXTRACT(YEAR FROM start_date) = :y",
        [':e' => $employeeId, ':t' => $leaveTypeId, ':y' => $year]
    ) ?: ['taken' => 0, 'pending' => 0];

    $taken   = (float) $used['taken'];
    $pending = (float) $used['pending'];

    return [
        'entitled'   => $entitled,
        'carried'    => $carried,
        'adjustment' => $adjustment,
        'taken'      => $taken,
        'pending'    => $pending,
        //  Pending counts against the balance. Two requests that
        //  each fit the allowance but together exceed it must not
        //  both be approvable.
        'available'  => $entitled === null
            ? null
            : round($entitled + $carried + $adjustment - $taken - $pending, 1),
        'uncapped'   => $entitled === null,
    ];
}

/** Every type with this employee's balance against it. */
function leave_balances_for(int $employeeId, ?int $year = null): array
{
    $out = [];
    foreach (leave_types_all() as $type) {
        $out[] = $type + ['balance' => leave_balance($employeeId, (int) $type['leave_type_id'], $year)];
    }
    return $out;
}

/**
 * Why this employee may not take this type of leave, or null.
 *
 * Returns a sentence, because every caller needs to say it to
 * somebody rather than decide what it means.
 */
function leave_eligibility(array $employee, array $type): ?string
{
    if (!empty($type['sex_restriction'])) {
        if (($employee['sex'] ?? null) === null) {
            return $type['name'] . ' is recorded for ' . $type['sex_restriction']
                 . ' employees, and this record does not say. An administrator can set it.';
        }
        if ($employee['sex'] !== $type['sex_restriction']) {
            return $type['name'] . ' is only available to ' . $type['sex_restriction'] . ' employees.';
        }
    }

    $months = (int) ($type['min_service_months'] ?? 0);
    if ($months > 0) {
        //  age() and not a subtraction. Taking one date from
        //  another in PostgreSQL yields an integer number of days,
        //  not an interval, so EXTRACT had nothing to extract from
        //  and the whole eligibility check raised an SQL error
        //  instead of answering. age() gives a real interval, and
        //  years*12 + months is whole calendar months of service —
        //  which is what the statute counts, rather than a
        //  thirty-day approximation of it.
        $served = (int) db_value(
            "SELECT EXTRACT(YEAR FROM age(CURRENT_DATE, CAST(:h AS date))) * 12
                  + EXTRACT(MONTH FROM age(CURRENT_DATE, CAST(:h AS date)))",
            [':h' => $employee['hire_date']]
        );
        if ($served < $months) {
            return $type['name'] . ' opens after ' . $months . ' months of service. '
                 . 'This record shows ' . max(0, $served) . '.';
        }
    }

    if (($employee['status'] ?? '') === 'exited') {
        return 'This employee has left.';
    }

    return null;
}

/* ─────────────────────────────────────────────────────────────
 *  Dispensary cover
 * ──────────────────────────────────────────────────────────── */

/**
 * Who is on duty, per working day, across a range.
 *
 * One query. For each working day it counts the cover-cadre
 * employees who were employed on that day and are not themselves
 * on leave, and separately counts how many of those hold a
 * registration that had not expired on that day.
 *
 * Both numbers matter and they are not the same question. "No
 * pharmacist at all on Thursday" and "the only pharmacist on
 * Thursday has a registration that lapsed in October" are
 * different problems with different fixes, and a module that
 * collapsed them into one count would say the wrong thing about
 * at least one of them.
 *
 * $alsoAway forces an employee to be treated as absent for the
 * whole range, which is how a form previews the effect of a
 * request that has not been saved yet. A request already stored
 * as submitted or approved needs no such help — it is already in
 * the leave the query subtracts.
 */
function leave_cover_by_day(string $from, string $to, ?int $alsoAway = null): array
{
    if (!leave_ready()) {
        return [];
    }

    $cadres = hr_cover_cadres();
    $place  = [];
    $args   = [':f' => $from, ':t' => $to, ':away' => $alsoAway];
    foreach ($cadres as $i => $c) {
        $place[]      = ":c$i";
        $args[":c$i"] = $c;
    }
    $live = "'" . implode("','", leave_live_statuses()) . "'";

    return db_all(
        "WITH days AS (
             SELECT d::date AS day
               FROM generate_series(CAST(:f AS date), CAST(:t AS date), INTERVAL '1 day') AS d
              WHERE EXTRACT(ISODOW FROM d) < 6
                AND NOT EXISTS (
                    SELECT 1 FROM public_holidays h WHERE h.holiday_date = d::date
                )
         ),
         cover AS (
             SELECT employee_id, first_name, last_name, prof_expiry, hire_date, end_date
               FROM employees
              WHERE status IN ('active', 'probation')
                AND cadre IN (" . implode(',', $place) . ")
         )
         SELECT d.day,
                COUNT(c.employee_id) AS on_duty,
                COUNT(c.employee_id) FILTER (
                    WHERE c.prof_expiry IS NOT NULL AND c.prof_expiry >= d.day
                ) AS registered_on_duty,
                COALESCE(STRING_AGG(
                    TRIM(c.first_name || ' ' || c.last_name)
                    || CASE WHEN c.prof_expiry IS NULL THEN ' (no registration recorded)'
                            WHEN c.prof_expiry < d.day THEN ' (registration expired)'
                            ELSE '' END,
                    ', ' ORDER BY c.first_name
                ), '') AS who
           FROM days d
           LEFT JOIN cover c
                  ON c.hire_date <= d.day
                 AND (c.end_date IS NULL OR c.end_date >= d.day)
                 --  Cast on both sides. PostgreSQL cannot infer a
                 --  parameter type from a bare IS NULL test and
                 --  refuses the whole statement as ambiguous.
                 AND (CAST(:away AS integer) IS NULL
                      OR c.employee_id <> CAST(:away AS integer))
                 AND NOT EXISTS (
                     SELECT 1 FROM leave_requests lr
                      WHERE lr.employee_id = c.employee_id
                        AND lr.status IN ($live)
                        AND lr.start_date <= d.day
                        AND lr.end_date   >= d.day
                 )
          GROUP BY d.day
          ORDER BY d.day",
        $args
    );
}

/**
 * The cover problem with a range, in one sentence, or null.
 *
 * Deliberately a string and not a boolean. It goes on screen to
 * the person deciding, and it goes into leave_requests.cover_warning
 * if they approve anyway — and "true" is not something anybody
 * can read six months later.
 */
function leave_cover_warning(string $from, string $to, ?int $alsoAway = null): ?string
{
    $days = leave_cover_by_day($from, $to, $alsoAway);
    if (!$days) {
        return null;
    }

    $none       = [];
    $unlicensed = [];
    foreach ($days as $d) {
        if ((int) $d['on_duty'] === 0) {
            $none[] = $d['day'];
        } elseif ((int) $d['registered_on_duty'] === 0) {
            $unlicensed[] = $d['day'];
        }
    }

    if (!$none && !$unlicensed) {
        return null;
    }

    $say = static function (array $dates): string {
        $shown = array_slice($dates, 0, 4);
        $out   = implode(', ', array_map(static fn($d) => date('j M', strtotime($d)), $shown));
        $extra = count($dates) - count($shown);
        return $extra > 0 ? $out . ' and ' . $extra . ' more' : $out;
    };

    $parts = [];
    if ($none) {
        $parts[] = 'no pharmacist or pharmaceutical technologist on duty at all on '
                 . $say($none) . ' (' . count($none) . ' working '
                 . (count($none) === 1 ? 'day' : 'days') . ')';
    }
    if ($unlicensed) {
        $parts[] = 'nobody with a current registration on duty on ' . $say($unlicensed);
    }

    return 'Dispensary cover: ' . implode('; and ', $parts)
         . '. The premises should not dispense without supervision — arrange a locum, '
         . 'or approve this knowing the shop is uncovered. This note is kept on the request.';
}

/* ─────────────────────────────────────────────────────────────
 *  Reading requests
 * ──────────────────────────────────────────────────────────── */

function leave_request_get(int $id): ?array
{
    if (!leave_ready()) {
        return null;
    }
    return db_one(
        "SELECT r.*, t.name AS type_name, t.code AS type_code,
                t.requires_document, t.counts_calendar_days,
                e.first_name, e.last_name, e.staff_number, e.job_title, e.cadre,
                e.department_id, e.user_id AS employee_user_id, e.personal_email,
                u.email AS login_email,
                d.name AS department_name,
                TRIM(COALESCE(du.first_name,'') || ' ' || COALESCE(du.last_name,'')) AS dept_decider,
                TRIM(COALESCE(fu.first_name,'') || ' ' || COALESCE(fu.last_name,'')) AS final_decider
           FROM leave_requests r
           JOIN leave_types t ON t.leave_type_id = r.leave_type_id
           JOIN employees  e ON e.employee_id = r.employee_id
           LEFT JOIN users u  ON u.user_id = e.user_id
           LEFT JOIN departments d ON d.department_id = e.department_id
           LEFT JOIN users du ON du.user_id = r.dept_decision_by
           LEFT JOIN users fu ON fu.user_id = r.final_decision_by
          WHERE r.request_id = :id",
        [':id' => $id]
    ) ?: null;
}

/** Shared filter for the request list and its count. */
function leave_request_filter(array $f): array
{
    $where  = [];
    $params = [];

    if (!empty($f['employee_id'])) {
        $where[] = 'r.employee_id = :emp';
        $params[':emp'] = (int) $f['employee_id'];
    }
    if (!empty($f['department_id'])) {
        $where[] = 'e.department_id = :dept';
        $params[':dept'] = (int) $f['department_id'];
    }
    if (!empty($f['status']) && array_key_exists($f['status'], leave_statuses())) {
        $where[] = 'r.status = :status';
        $params[':status'] = $f['status'];
    }
    if (!empty($f['open'])) {
        $where[] = "r.status IN ('submitted','dept_approved')";
    }
    if (!empty($f['year'])) {
        $where[] = 'EXTRACT(YEAR FROM r.start_date) = :yr';
        $params[':yr'] = (int) $f['year'];
    }
    if (!empty($f['from']) && !empty($f['to'])) {
        $where[] = 'r.start_date <= CAST(:rto AS date) AND r.end_date >= CAST(:rfrom AS date)';
        $params[':rfrom'] = $f['from'];
        $params[':rto']   = $f['to'];
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function leave_request_count(array $f = []): int
{
    if (!leave_ready()) {
        return 0;
    }
    [$sql, $params] = leave_request_filter($f);
    return (int) db_value(
        "SELECT COUNT(*) FROM leave_requests r JOIN employees e ON e.employee_id = r.employee_id $sql",
        $params
    );
}

function leave_requests_list(array $f = [], int $limit = 25, int $offset = 0): array
{
    if (!leave_ready()) {
        return [];
    }
    [$sql, $params] = leave_request_filter($f);
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);

    return db_all(
        "SELECT r.request_id, r.request_number, r.start_date, r.end_date,
                r.days_requested, r.status, r.reason, r.submitted_at,
                r.cover_warning, r.document_url,
                t.name AS type_name, t.requires_document,
                e.employee_id, e.first_name, e.last_name, e.staff_number, e.job_title,
                d.name AS department_name
           FROM leave_requests r
           JOIN leave_types t ON t.leave_type_id = r.leave_type_id
           JOIN employees  e ON e.employee_id = r.employee_id
           LEFT JOIN departments d ON d.department_id = e.department_id
           $sql
          ORDER BY r.submitted_at DESC
          LIMIT $limit OFFSET $offset",
        $params
    );
}

/** Who is away on a given day — the rota question. */
function leave_away_on(string $date): array
{
    if (!leave_ready()) {
        return [];
    }
    return db_all(
        "SELECT e.employee_id, e.first_name, e.last_name, e.job_title, e.cadre,
                t.name AS type_name, r.status, r.start_date, r.end_date
           FROM leave_requests r
           JOIN employees  e ON e.employee_id = r.employee_id
           JOIN leave_types t ON t.leave_type_id = r.leave_type_id
          WHERE r.status IN ('submitted','dept_approved','approved')
            AND CAST(:d AS date) BETWEEN r.start_date AND r.end_date
          ORDER BY e.first_name",
        [':d' => $date]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  Who may do what
 * ────────────────────────────────────────────────────────────
 *  Separate from the actions themselves, because the screens
 *  need the same answers to decide which buttons to draw. A
 *  button that appears and then refuses is worse than no button.
 * ──────────────────────────────────────────────────────────── */

/** May the signed-in person raise a request for this employee? */
function leave_may_submit_for(int $employeeId): bool
{
    $mine = hr_current_employee();
    if ($mine && (int) $mine['employee_id'] === $employeeId) {
        return true;
    }
    return is_admin() || user_has_role(ROLE_MANAGER);
}

/** May they give the departmental decision on this request? */
function leave_may_dept_decide(array $req): bool
{
    if ($req['status'] !== 'submitted') {
        return false;
    }
    $me = current_user();
    if (!$me) {
        return false;
    }
    //  The applicant never approves their own leave, whatever
    //  else they are. A manager who is also the department head
    //  is still not an exception to that.
    if ((int) ($req['employee_user_id'] ?? 0) === (int) $me['id']) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    if (hr_user_heads_department((int) $me['id'], $req['department_id'] ? (int) $req['department_id'] : null)) {
        return true;
    }
    //  A manager can stand in where no head has been named, which
    //  is the common case in a shop with one department.
    return user_has_role(ROLE_MANAGER)
        && hr_department_head_user($req['department_id'] ? (int) $req['department_id'] : null) === null;
}

/**
 * May they give the final decision?
 *
 * Two people, not two clicks. The person who gave the
 * departmental approval cannot also give the final one — that is
 * the entire content of "two-step". An administrator may approve
 * a request that has had no departmental step at all, which is
 * how a shop too small to have two approvers still works, and it
 * is recorded as having skipped the step rather than pretending
 * one happened.
 */
function leave_may_final_decide(array $req): bool
{
    $me = current_user();
    if (!$me) {
        return false;
    }
    if ((int) ($req['employee_user_id'] ?? 0) === (int) $me['id']) {
        return false;
    }

    if ($req['status'] === 'dept_approved') {
        if ((int) ($req['dept_decision_by'] ?? 0) === (int) $me['id']) {
            return false;
        }
        return is_admin() || user_has_role(ROLE_MANAGER);
    }

    //  Straight through, administrators only.
    return $req['status'] === 'submitted' && is_admin();
}

/** May they withdraw it? Only the person whose leave it is. */
function leave_may_withdraw(array $req): bool
{
    $mine = hr_current_employee();
    return $mine
        && (int) $mine['employee_id'] === (int) $req['employee_id']
        && in_array($req['status'], ['submitted', 'dept_approved'], true);
}

/** May they cancel an approved one? */
function leave_may_cancel(array $req): bool
{
    return in_array($req['status'], ['submitted', 'dept_approved', 'approved'], true)
        && (is_admin() || user_has_role(ROLE_MANAGER));
}

/* ─────────────────────────────────────────────────────────────
 *  Applying
 * ──────────────────────────────────────────────────────────── */

/**
 * Raise a leave request.
 *
 * @param array{employee_id:int, leave_type_id:int, start_date:string,
 *              end_date:string, reason?:string, contact?:string,
 *              document_url?:?string} $in
 * @return array{ok:bool, message:string, request_id:?int}
 */
function leave_submit(array $in): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'message' => $m, 'request_id' => null];

    if (!leave_ready()) {
        return $fail('The leave tables are not in this database yet.');
    }

    $employeeId = (int) ($in['employee_id'] ?? 0);
    $typeId     = (int) ($in['leave_type_id'] ?? 0);
    $from       = trim((string) ($in['start_date'] ?? ''));
    $to         = trim((string) ($in['end_date'] ?? ''));

    if (!$employeeId || !$typeId) {
        return $fail('Choose who the leave is for and what kind of leave it is.');
    }
    if (!leave_may_submit_for($employeeId)) {
        return $fail('You can only apply for your own leave.');
    }

    $valid = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!$valid($from) || !$valid($to)) {
        return $fail('Give a start date and an end date.');
    }
    if (strtotime($to) < strtotime($from)) {
        return $fail('The end date is before the start date.');
    }

    $employee = hr_employee_get($employeeId);
    if (!$employee) {
        return $fail('That employee record no longer exists.');
    }
    $type = leave_type_get($typeId);
    if (!$type || !$type['is_active']) {
        return $fail('That kind of leave is not available.');
    }

    if ($why = leave_eligibility($employee, $type)) {
        return $fail($why);
    }

    $days = leave_count_days($from, $to, (bool) $type['counts_calendar_days']);
    if ($days <= 0) {
        return $fail('That range contains no working days — it is all weekend and public holidays.');
    }

    //  The balance, including anything already pending, so two
    //  requests that each fit cannot both be made.
    $bal = leave_balance($employeeId, $typeId, leave_year_of($from));
    if (!$bal['uncapped'] && $days > (float) $bal['available']) {
        return $fail(sprintf(
            '%s is %s %s and only %s %s left this year (%s already taken, %s awaiting a decision).',
            $type['name'], rtrim(rtrim(number_format($days, 1), '0'), '.'),
            $days === 1.0 ? 'day' : 'days',
            rtrim(rtrim(number_format((float) $bal['available'], 1), '0'), '.'),
            ((float) $bal['available']) === 1.0 ? 'day' : 'days',
            rtrim(rtrim(number_format($bal['taken'], 1), '0'), '.'),
            rtrim(rtrim(number_format($bal['pending'], 1), '0'), '.')
        ));
    }

    $me  = current_user();
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $number = next_document_number('leave_requests', 'request_number', 'LV');

        $id = (int) db_value(
            "INSERT INTO leave_requests
                (request_number, employee_id, leave_type_id, start_date, end_date,
                 days_requested, reason, contact_while_away, document_url,
                 status, submitted_by)
             VALUES (:n, :e, :t, :f, :to, :d, :r, :c, :doc, 'submitted', :by)
             RETURNING request_id",
            [
                ':n'   => $number,
                ':e'   => $employeeId,
                ':t'   => $typeId,
                ':f'   => $from,
                ':to'  => $to,
                ':d'   => $days,
                ':r'   => trim((string) ($in['reason'] ?? '')) ?: null,
                ':c'   => trim((string) ($in['contact'] ?? '')) ?: null,
                ':doc' => $in['document_url'] ?? null,
                ':by'  => $me['id'] ?? null,
            ]
        );

        audit_log('leave.submitted', 'leave_requests', $id, [
            'number' => $number, 'employee' => hr_full_name($employee),
            'type' => $type['code'], 'from' => $from, 'to' => $to, 'days' => $days,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        //  The overlap trigger speaks in a sentence already; pass
        //  it through rather than replacing it with "could not save".
        if (str_contains($e->getMessage(), 'already covers')) {
            $m = $e->getMessage();
            $start = strpos($m, 'Leave for this employee');
            return $fail($start !== false
                ? rtrim(substr($m, $start), '.') . '.'
                : 'This employee already has leave booked across those dates.');
        }
        error_log('[LEAVE] could not submit: ' . $e->getMessage());
        return $fail('The request could not be saved.');
    }

    //  Outside the transaction: a mail server that is slow or down
    //  must not roll back a request that is validly stored.
    leave_notify_submitted($id);

    return ['ok' => true, 'request_id' => $id,
            'message' => $type['name'] . ' for ' . rtrim(rtrim(number_format($days, 1), '0'), '.')
                       . ' day(s) has been sent for approval (' . $number . ').'];
}

/**
 * Attach a document to a request already made.
 *
 * Sick leave needs a note, and the note usually arrives after
 * the application. Without this the required-document rule would
 * create requests that can never be approved.
 */
function leave_attach_document(int $requestId, string $url): array
{
    $req = leave_request_get($requestId);
    if (!$req) {
        return ['ok' => false, 'message' => 'That request no longer exists.'];
    }

    $mine     = hr_current_employee();
    $isMine   = $mine && (int) $mine['employee_id'] === (int) $req['employee_id'];
    if (!$isMine && !is_admin() && !user_has_role(ROLE_MANAGER)) {
        return ['ok' => false, 'message' => 'That is not your request.'];
    }
    if (!in_array($req['status'], ['submitted', 'dept_approved'], true)) {
        return ['ok' => false, 'message' => 'That request has already been decided.'];
    }

    db_run('UPDATE leave_requests SET document_url = :d, updated_at = CURRENT_TIMESTAMP
             WHERE request_id = :id', [':d' => $url, ':id' => $requestId]);
    audit_log('leave.document_attached', 'leave_requests', $requestId, ['number' => $req['request_number']]);

    return ['ok' => true, 'message' => 'The document has been attached.'];
}

/* ─────────────────────────────────────────────────────────────
 *  Deciding
 * ──────────────────────────────────────────────────────────── */

/**
 * Move a request along. One function for every transition, so
 * the rules about who may do what live in one place.
 *
 * @param string $action dept_approve | final_approve | reject | cancel | withdraw
 */
function leave_decide(int $requestId, string $action, string $note = ''): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'message' => $m];

    $req = leave_request_get($requestId);
    if (!$req) {
        return $fail('That request no longer exists.');
    }

    $me   = current_user();
    $note = trim($note) ?: null;

    switch ($action) {
        case 'dept_approve':
            if (!leave_may_dept_decide($req)) {
                return $fail('You cannot give the departmental decision on this request.');
            }
            $target = 'dept_approved';
            break;

        case 'final_approve':
            if (!leave_may_final_decide($req)) {
                return $fail($req['status'] === 'dept_approved'
                    ? 'Final approval has to come from somebody other than whoever approved it for the department.'
                    : 'This request still needs its departmental decision.');
            }
            //  The document rule bites here rather than at
            //  submission, because the note arrives afterwards.
            if (!empty($req['requires_document']) && empty($req['document_url'])) {
                return $fail($req['type_name'] . ' needs a supporting document attached before it can be approved.');
            }
            $target = 'approved';
            break;

        case 'reject':
            if (!leave_may_dept_decide($req) && !leave_may_final_decide($req)) {
                return $fail('You cannot decide this request.');
            }
            if ($note === null) {
                return $fail('Say why it is being refused — the employee is told the reason.');
            }
            $target = 'rejected';
            break;

        case 'cancel':
            if (!leave_may_cancel($req)) {
                return $fail('Only a manager or administrator can cancel approved leave.');
            }
            $target = 'cancelled';
            break;

        case 'withdraw':
            if (!leave_may_withdraw($req)) {
                return $fail('You can only withdraw your own request, and only before it is decided.');
            }
            $target = 'withdrawn';
            break;

        default:
            return $fail('That is not something that can be done to a leave request.');
    }

    //  Cover is recomputed at the moment of the decision, not read
    //  from whatever the form was showing — the picture may have
    //  changed since the page was drawn.
    $cover = null;
    if (in_array($target, ['dept_approved', 'approved'], true)) {
        $cover = leave_cover_warning((string) $req['start_date'], (string) $req['end_date']);
    }

    $sets = ['status = :st', 'updated_at = CURRENT_TIMESTAMP'];
    $args = [':st' => $target, ':id' => $requestId];

    if ($action === 'dept_approve' || ($action === 'reject' && $req['status'] === 'submitted')) {
        $sets[] = 'dept_decision_by = :by';
        $sets[] = 'dept_decision_at = CURRENT_TIMESTAMP';
        $sets[] = 'dept_note = :note';
        $args[':by']   = $me['id'] ?? null;
        $args[':note'] = $note;
    } else {
        $sets[] = 'final_decision_by = :by';
        $sets[] = 'final_decision_at = CURRENT_TIMESTAMP';
        $sets[] = 'final_note = :note';
        $args[':by']   = $me['id'] ?? null;
        $args[':note'] = $note;
    }

    if ($cover !== null) {
        $sets[] = 'cover_warning = :cover';
        $args[':cover'] = $cover;
    }

    try {
        db_run('UPDATE leave_requests SET ' . implode(', ', $sets) . ' WHERE request_id = :id', $args);
    } catch (Throwable $e) {
        error_log('[LEAVE] could not record decision: ' . $e->getMessage());
        return $fail('The decision could not be recorded.');
    }

    audit_log('leave.' . $action, 'leave_requests', $requestId, [
        'number'   => $req['request_number'],
        'employee' => trim($req['first_name'] . ' ' . $req['last_name']),
        'from'     => $req['status'],
        'to'       => $target,
        'uncovered' => $cover !== null,
    ]);

    leave_notify_decided($requestId, $target, $note);

    $words = [
        'dept_approved' => 'approved for the department and passed to HR',
        'approved'      => 'approved',
        'rejected'      => 'refused',
        'cancelled'     => 'cancelled',
        'withdrawn'     => 'withdrawn',
    ];

    return [
        'ok'      => true,
        'message' => $req['request_number'] . ' has been ' . ($words[$target] ?? $target) . '.',
        'cover_warning' => $cover,
    ];
}

/* ─────────────────────────────────────────────────────────────
 *  Telling people
 * ────────────────────────────────────────────────────────────
 *  Email is the channel that matters here, because it is the one
 *  that can be aimed at a particular person: the notifications
 *  table addresses a ROLE, not a user, so it can say "a request
 *  needs approving" to every manager but cannot tell one
 *  employee that theirs was granted.
 *
 *  Nothing in here is allowed to fail the thing it is reporting
 *  on. A leave request that is validly stored stays stored when
 *  the mail server is down; the failure goes to the log, and the
 *  screens show the state regardless, which is why the employee's
 *  own page is their landing page.
 * ──────────────────────────────────────────────────────────── */

/** An absolute link to a request, or null when no public URL is configured. */
function leave_request_link(int $requestId): ?string
{
    if (!function_exists('password_reset_base_url')) {
        return null;
    }
    $base = password_reset_base_url();
    return $base === null ? null : $base . '/hr/leave.php?id=' . $requestId;
}

/** Active users holding a given role, for the addresses to write to. */
function leave_role_recipients(string $roleName): array
{
    return db_all(
        "SELECT u.user_id, u.email, TRIM(u.first_name || ' ' || u.last_name) AS name
           FROM users u JOIN roles r ON r.role_id = u.role_id
          WHERE r.name = :r AND u.is_active AND COALESCE(u.email, '') <> ''",
        [':r' => $roleName]
    );
}

/** Wrap a message in the house style and send it. Never throws. */
function leave_send(string $to, string $subject, string $lead, array $facts, ?int $requestId = null): void
{
    if ($to === '' || !function_exists('mail_send') || !mail_is_configured()) {
        return;
    }

    $brand = function_exists('company_settings')
        ? (string) (company_settings()['company_name'] ?? APP_NAME)
        : APP_NAME;

    $rows = '';
    $plain = '';
    foreach ($facts as $label => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#666">'
               . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')
               . '</td><td style="padding:4px 0"><strong>'
               . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
               . '</strong></td></tr>';
        $plain .= $label . ': ' . $value . "\n";
    }

    $link = $requestId ? leave_request_link($requestId) : null;
    $cta  = $link
        ? '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Open the request</a></p>'
        : '<p style="color:#555">Sign in and open Leave to act on it.</p>';

    $html = '<p>' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">'
          . $rows . '</table>' . $cta
          . '<p style="color:#888;font-size:12px">' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '</p>';

    $text = $lead . "\n\n" . $plain
          . ($link ? "\n" . $link . "\n" : "\nSign in and open Leave to act on it.\n");

    $sent = mail_send($to, $subject, $html, $text);
    if (!$sent['sent']) {
        error_log('[LEAVE] could not email ' . $to . ': ' . $sent['error']);
    }
}

/** The facts every one of these messages repeats. */
function leave_mail_facts(array $req): array
{
    return [
        'Employee'  => trim($req['first_name'] . ' ' . $req['last_name'])
                       . ' (' . $req['staff_number'] . ')',
        'Leave'     => $req['type_name'],
        'Dates'     => date('j M Y', strtotime((string) $req['start_date']))
                       . ' to ' . date('j M Y', strtotime((string) $req['end_date'])),
        'Days'      => rtrim(rtrim(number_format((float) $req['days_requested'], 1), '0'), '.'),
        'Reference' => $req['request_number'],
    ];
}

/**
 * A request has been raised: tell whoever has to decide first.
 *
 * The department head, by name, which is what makes this useful
 * rather than a broadcast. Where no head can sign in, it falls
 * back to the administrators and says why, because the
 * alternative is a request that sits for a week while everybody
 * assumes somebody else was told.
 */
function leave_notify_submitted(int $requestId): void
{
    $req = leave_request_get($requestId);
    if (!$req) {
        return;
    }

    $facts = leave_mail_facts($req) + [
        'Department' => $req['department_name'] ?? 'none set',
        'Reason'     => $req['reason'],
    ];

    $head = hr_department_head_user($req['department_id'] ? (int) $req['department_id'] : null);

    if ($head && !empty($head['email'])) {
        leave_send(
            (string) $head['email'],
            'Leave to approve: ' . trim($req['first_name'] . ' ' . $req['last_name']),
            'A member of your department has applied for leave and is waiting for your decision.',
            $facts,
            $requestId
        );
    } else {
        $why = $req['department_name']
            ? 'No head who can sign in is set for ' . $req['department_name'] . ', so this has come to you.'
            : 'This employee is not in a department with a head, so this has come to you.';
        foreach (leave_role_recipients(ROLE_ADMIN) as $admin) {
            leave_send((string) $admin['email'],
                'Leave to approve: ' . trim($req['first_name'] . ' ' . $req['last_name']),
                $why, $facts, $requestId);
        }
    }

    leave_flag_open($req, ROLE_MANAGER);
}

/**
 * A decision has been made: tell the employee, or the next
 * approver if there is one.
 */
function leave_notify_decided(int $requestId, string $target, ?string $note): void
{
    $req = leave_request_get($requestId);
    if (!$req) {
        return;
    }
    $facts = leave_mail_facts($req);
    $who   = trim($req['first_name'] . ' ' . $req['last_name']);

    if ($target === 'dept_approved') {
        //  Half way. HR decides next.
        $facts['Approved for the department by'] = $req['dept_decider'] ?: null;
        $facts['Note'] = $note;
        foreach (leave_role_recipients(ROLE_ADMIN) as $admin) {
            leave_send((string) $admin['email'],
                'Leave needs final approval: ' . $who,
                $who . '\'s leave has been approved by their department and needs a final decision.',
                $facts, $requestId);
        }
        leave_flag_open($req, ROLE_ADMIN);
        return;
    }

    //  Terminal: the employee is told, and the queue entry goes.
    leave_clear_open($requestId);

    $address = trim((string) ($req['login_email'] ?? '')) ?: trim((string) ($req['personal_email'] ?? ''));
    if ($address === '' && !empty($req['employee_user_id'])) {
        $address = (string) db_value('SELECT email FROM users WHERE user_id = :u',
                                     [':u' => (int) $req['employee_user_id']]);
    }
    if ($address === '') {
        return;
    }

    $lead = match ($target) {
        'approved'  => 'Your leave has been approved.',
        'rejected'  => 'Your leave request has not been approved.',
        'cancelled' => 'Your approved leave has been cancelled.',
        'withdrawn' => 'Your leave request has been withdrawn.',
        default     => 'Your leave request has been updated.',
    };
    $facts['Decision'] = leave_statuses()[$target] ?? $target;
    $facts['Note']     = $note;

    leave_send($address, 'Leave ' . ($target === 'approved' ? 'approved' : $target) . ': '
             . $req['request_number'], $lead, $facts, $requestId);
}

/**
 * Put a request in the in-app queue for a role.
 *
 * One row per request, keyed on the reference, so a request that
 * moves from one approver to the next updates its entry instead
 * of leaving a stale one behind. The kind is unique to leave, which
 * keeps the notification scanner's sweep — it resolves by kind —
 * away from rows it did not create.
 */
function leave_flag_open(array $req, string $audience): void
{
    if (!table_exists('notifications')) {
        return;
    }

    try {
        db_run(
            "INSERT INTO notifications
                (kind, severity, subject_type, subject_id, title, body, url, dedupe_key, audience)
             VALUES ('leave.awaiting_approval', 'info', 'leave_requests', :sid, :title, :body, :url, :key, :aud)
             ON CONFLICT (dedupe_key) DO UPDATE
                SET title = EXCLUDED.title, body = EXCLUDED.body,
                    audience = EXCLUDED.audience, resolved_at = NULL,
                    updated_at = clock_timestamp()",
            [
                ':sid'   => (int) $req['request_id'],
                ':title' => 'Leave to approve: ' . trim($req['first_name'] . ' ' . $req['last_name']),
                ':body'  => $req['type_name'] . ', '
                          . date('j M', strtotime((string) $req['start_date'])) . ' to '
                          . date('j M', strtotime((string) $req['end_date'])),
                ':url'   => 'hr/leave.php?id=' . (int) $req['request_id'],
                ':key'   => 'leave:' . $req['request_number'],
                ':aud'   => $audience,
            ]
        );
    } catch (Throwable $e) {
        error_log('[LEAVE] could not flag for approval: ' . $e->getMessage());
    }
}

/** Decided: take it out of the queue. */
function leave_clear_open(int $requestId): void
{
    if (!table_exists('notifications')) {
        return;
    }
    try {
        db_run(
            "UPDATE notifications
                SET resolved_at = clock_timestamp(), updated_at = clock_timestamp()
              WHERE kind = 'leave.awaiting_approval'
                AND subject_id = :id AND resolved_at IS NULL",
            [':id' => $requestId]
        );
    } catch (Throwable $e) {
        error_log('[LEAVE] could not clear the approval flag: ' . $e->getMessage());
    }
}
