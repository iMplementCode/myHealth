<?php

/**
 * ============================================================
 *  People
 * ------------------------------------------------------------
 *  The employee half of HR: who works here, in what capacity,
 *  and — the part that is specific to a pharmacy — who is
 *  registered to be in charge of the dispensary.
 *
 *  The distinction this file exists to keep is between a USER
 *  and an EMPLOYEE. A user is an account: a username, a hash, a
 *  role. An employee is a person with a hire date, a salary and
 *  a next of kin. They are one-to-one at most, and often not
 *  one-to-one at all — the casual who stacks shelves has no
 *  login, and the administrator account that runs the migrations
 *  is not a person who can be sent on leave.
 *
 *  Everything that decides what a signed-in person may see about
 *  THEMSELVES goes through hr_current_employee(), which reads the
 *  session and nothing else. No function here takes an employee
 *  id from a request and trusts it.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

//  For next_document_number(), which allocates staff numbers and
//  leave references from document_sequences rather than from
//  MAX()+1 — see the argument in documents.php. Not loaded by the
//  bootstrap, so it is named here rather than assumed.
require_once __DIR__ . '/documents.php';

/**
 * The cadres that can satisfy dispensary supervision.
 *
 * One list, one place, because this is the question the leave
 * cover check turns on and the answer depends on the premises
 * licence class — which means somebody will eventually need to
 * change it, and they should have to change it once.
 *
 * It is deliberately NOT a column on employees. A boolean
 * "counts as cover" per person, defaulted from the cadre, is two
 * sources of truth that drift the first time somebody edits one
 * and not the other.
 */
function hr_cover_cadres(): array
{
    return ['pharmacist', 'pharmaceutical_technologist'];
}

/** Is this module usable on this database yet? */
function hr_ready(): bool
{
    return table_exists('employees') && table_exists('departments');
}

/** Cadre values, with the words a person would use for them. */
function hr_cadres(): array
{
    return [
        'pharmacist'                  => 'Pharmacist',
        'pharmaceutical_technologist' => 'Pharmaceutical technologist',
        'nurse'                       => 'Nurse',
        'clinical_officer'            => 'Clinical officer',
        'support'                     => 'Support / non-clinical',
    ];
}

function hr_employment_types(): array
{
    return [
        'permanent' => 'Permanent',
        'contract'  => 'Contract',
        'probation' => 'Probation',
        'casual'    => 'Casual',
        'intern'    => 'Intern',
        'locum'     => 'Locum',
    ];
}

function hr_statuses(): array
{
    return [
        'active'    => 'Active',
        'probation' => 'On probation',
        'suspended' => 'Suspended',
        'on_notice' => 'On notice',
        'exited'    => 'Left',
    ];
}

/** Tone for a status badge, matching the rest of the application. */
function hr_status_tone(string $status): string
{
    return match ($status) {
        'active'    => 'active',
        'probation' => 'pending',
        'suspended' => 'expired',
        'on_notice' => 'warn',
        'exited'    => 'inactive',
        default     => 'inactive',
    };
}

/** "Asha Wanjiru", from a row. */
function hr_full_name(array $e): string
{
    return trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));
}

/**
 * May the person reading the page see pay and bank details?
 *
 * Administrators only. A department head needs to approve leave,
 * which is not a reason to show them what their team earns, and
 * the employee list is the screen where that would leak by
 * default if nobody asked this question.
 */
function hr_can_see_pay(): bool
{
    return is_admin();
}

/** The columns an employee form writes, and how to clean each one. */
function hr_employee_fields(): array
{
    return [
        'staff_number'     => 'text',
        'first_name'       => 'text',
        'last_name'        => 'text',
        'other_names'      => 'text',
        'national_id'      => 'text',
        'date_of_birth'    => 'date',
        'sex'              => 'text',
        'phone'            => 'text',
        'personal_email'   => 'text',
        'physical_address' => 'text',
        'department_id'    => 'int',
        'job_title'        => 'text',
        'cadre'            => 'text',
        'employment_type'  => 'text',
        'hire_date'        => 'date',
        'end_date'         => 'date',
        'exit_reason'      => 'text',
        'status'           => 'text',
        'prof_body'        => 'text',
        'prof_registration_no' => 'text',
        'prof_expiry'      => 'date',
        'kra_pin'          => 'text',
        'nssf_number'      => 'text',
        'shif_number'      => 'text',
        'basic_salary'     => 'money',
        'bank_name'        => 'text',
        'bank_branch'      => 'text',
        'bank_account_no'  => 'text',
        'nok_name'         => 'text',
        'nok_relationship' => 'text',
        'nok_phone'        => 'text',
        'notes'            => 'text',
    ];
}

/**
 * The employee record belonging to a user account, or null.
 *
 * This is the only door to "my own record". It takes a user id
 * from the session, never an employee id from a form, which is
 * what stops one member of staff reading another's file by
 * editing a number in the address bar.
 */
function hr_employee_for_user(?int $userId): ?array
{
    if (!$userId || !hr_ready()) {
        return null;
    }
    return db_one(
        "SELECT e.*, d.name AS department_name
           FROM employees e
           LEFT JOIN departments d ON d.department_id = e.department_id
          WHERE e.user_id = :u",
        [':u' => $userId]
    ) ?: null;
}

/** The signed-in person's own employee record, or null. */
function hr_current_employee(): ?array
{
    //  Keyed by user id, not a single slot. One web request has one
    //  signed-in person, so a bare cache looked equivalent — but it
    //  silently returns the first person it ever saw to any later
    //  caller, which is wrong in a CLI tool, wrong in a test, and
    //  the kind of wrong that returns somebody else's employee
    //  record. Keying it costs nothing.
    static $cache = [];

    $me  = current_user();
    $key = $me ? (int) $me['id'] : 0;

    if (!array_key_exists($key, $cache)) {
        $cache[$key] = hr_employee_for_user($key ?: null);
    }
    return $cache[$key];
}

/** One employee by id, for a screen that is allowed to ask. */
function hr_employee_get(int $id): ?array
{
    if (!hr_ready()) {
        return null;
    }
    return db_one(
        "SELECT e.*, d.name AS department_name,
                u.username, u.email AS login_email, u.is_active AS login_active,
                r.name AS role_name
           FROM employees e
           LEFT JOIN departments d ON d.department_id = e.department_id
           LEFT JOIN users u       ON u.user_id = e.user_id
           LEFT JOIN roles r       ON r.role_id = u.role_id
          WHERE e.employee_id = :id",
        [':id' => $id]
    ) ?: null;
}

/** The WHERE fragment and parameters shared by the list and its count. */
function hr_employee_filter(string $search, string $status, ?int $departmentId): array
{
    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(LOWER(e.first_name || ' ' || e.last_name) LIKE :q
                     OR LOWER(e.staff_number) LIKE :q
                     OR LOWER(COALESCE(e.job_title, '')) LIKE :q
                     OR LOWER(COALESCE(e.phone, '')) LIKE :q)";
        $params[':q'] = '%' . strtolower($search) . '%';
    }
    if ($status !== '' && array_key_exists($status, hr_statuses())) {
        $where[] = 'e.status = :status';
        $params[':status'] = $status;
    }
    if ($departmentId) {
        $where[] = 'e.department_id = :dept';
        $params[':dept'] = $departmentId;
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function hr_employee_count(string $search = '', string $status = '', ?int $departmentId = null): int
{
    if (!hr_ready()) {
        return 0;
    }
    [$sql, $params] = hr_employee_filter($search, $status, $departmentId);
    return (int) db_value("SELECT COUNT(*) FROM employees e $sql", $params);
}

function hr_employee_list(
    string $search = '',
    string $status = '',
    ?int $departmentId = null,
    int $limit = 25,
    int $offset = 0
): array {
    if (!hr_ready()) {
        return [];
    }
    [$sql, $params] = hr_employee_filter($search, $status, $departmentId);
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);

    return db_all(
        "SELECT e.employee_id, e.staff_number, e.first_name, e.last_name,
                e.job_title, e.cadre, e.employment_type, e.status,
                e.hire_date, e.phone, e.prof_expiry, e.user_id,
                d.name AS department_name
           FROM employees e
           LEFT JOIN departments d ON d.department_id = e.department_id
           $sql
          ORDER BY e.status <> 'exited' DESC, e.first_name, e.last_name
          LIMIT $limit OFFSET $offset",
        $params
    );
}

/** Departments, with their head's name and how many people are in them. */
function hr_departments(bool $activeOnly = false): array
{
    if (!hr_ready()) {
        return [];
    }
    return db_all(
        "SELECT d.*,
                TRIM(COALESCE(h.first_name, '') || ' ' || COALESCE(h.last_name, '')) AS head_name,
                h.user_id AS head_user_id,
                (SELECT COUNT(*) FROM employees e
                  WHERE e.department_id = d.department_id AND e.status <> 'exited') AS headcount
           FROM departments d
           LEFT JOIN employees h ON h.employee_id = d.head_employee_id
          " . ($activeOnly ? 'WHERE d.is_active' : '') . "
          ORDER BY d.name"
    );
}

/**
 * The user account that approves for a department, or null.
 *
 * Resolved head -> employee -> user, because the organisation
 * chart is kept in people and approving needs an account. Null
 * here is not an error to swallow: the caller tells somebody
 * that the department has no head who can sign in, which is a
 * real configuration problem with a real fix.
 */
function hr_department_head_user(?int $departmentId): ?array
{
    if (!$departmentId || !hr_ready()) {
        return null;
    }
    return db_one(
        "SELECT u.user_id, u.email, u.first_name, u.last_name, r.name AS role_name,
                e.employee_id, e.first_name AS emp_first, e.last_name AS emp_last
           FROM departments d
           JOIN employees e ON e.employee_id = d.head_employee_id
           JOIN users u     ON u.user_id = e.user_id AND u.is_active
           LEFT JOIN roles r ON r.role_id = u.role_id
          WHERE d.department_id = :d",
        [':d' => $departmentId]
    ) ?: null;
}

/** Is this user the head of this department? */
function hr_user_heads_department(?int $userId, ?int $departmentId): bool
{
    if (!$userId || !$departmentId) {
        return false;
    }
    $head = hr_department_head_user($departmentId);
    return $head !== null && (int) $head['user_id'] === $userId;
}

/**
 * Save an employee. Returns ['ok' => bool, 'message' => string,
 * 'employee_id' => ?int].
 *
 * Validation here rather than in the page, so the self-service
 * screen, the admin screen and any future import all refuse the
 * same things.
 */
function hr_employee_save(array $in, ?int $employeeId = null): array
{
    if (!hr_ready()) {
        return ['ok' => false, 'message' => 'The employee tables are not in this database yet.', 'employee_id' => null];
    }

    $row = [];
    foreach (hr_employee_fields() as $field => $kind) {
        $raw = trim((string) ($in[$field] ?? ''));
        $row[$field] = match ($kind) {
            'int'   => $raw === '' ? null : (int) $raw,
            'money' => $raw === '' ? null : round((float) str_replace(',', '', $raw), 2),
            'date'  => $raw === '' ? null : $raw,
            default => $raw === '' ? null : $raw,
        };
    }

    //  The handful that cannot be empty.
    foreach (['first_name' => 'a first name', 'last_name' => 'a last name',
              'job_title' => 'a job title', 'hire_date' => 'a hire date'] as $field => $what) {
        if (($row[$field] ?? null) === null) {
            return ['ok' => false, 'message' => 'Every employee needs ' . $what . '.', 'employee_id' => null];
        }
    }

    //  Allowlists, so a hand-made POST cannot put a value in that
    //  the CHECK constraints would refuse with a database error
    //  rather than a sentence.
    foreach ([
        'cadre'           => hr_cadres(),
        'employment_type' => hr_employment_types(),
        'status'          => hr_statuses(),
    ] as $field => $allowed) {
        if ($row[$field] === null) {
            $row[$field] = array_key_first($allowed);
        } elseif (!array_key_exists($row[$field], $allowed)) {
            return ['ok' => false, 'message' => 'That is not a ' . str_replace('_', ' ', $field) . ' this system knows.', 'employee_id' => null];
        }
    }
    if ($row['sex'] !== null && !in_array($row['sex'], ['female', 'male', 'other'], true)) {
        $row['sex'] = null;
    }

    //  The two date rules the table also enforces, said in words
    //  before the database says them in SQLSTATE.
    if ($row['status'] === 'exited' && $row['end_date'] === null) {
        return ['ok' => false, 'message' => 'Somebody who has left needs a leaving date.', 'employee_id' => null];
    }
    if ($row['status'] !== 'exited' && $row['end_date'] !== null) {
        return ['ok' => false, 'message' => 'Only somebody marked as having left can have a leaving date.', 'employee_id' => null];
    }
    if ($row['end_date'] !== null && $row['end_date'] < $row['hire_date']) {
        return ['ok' => false, 'message' => 'The leaving date is before the hire date.', 'employee_id' => null];
    }
    if ($row['prof_registration_no'] !== null && $row['prof_expiry'] === null) {
        return ['ok' => false, 'message' => 'A registration number needs an expiry date, or it cannot be checked.', 'employee_id' => null];
    }

    //  Only an administrator may set pay. A manager editing a
    //  record keeps whatever is already there rather than blanking
    //  it, because their form never showed it.
    if (!hr_can_see_pay()) {
        unset($row['basic_salary'], $row['bank_name'], $row['bank_branch'], $row['bank_account_no']);
    }

    $pdo = db();
    $owns = $pdo->inTransaction();
    if (!$owns) {
        $pdo->beginTransaction();
    }

    try {
        if ($employeeId === null) {
            if (($row['staff_number'] ?? null) === null) {
                $row['staff_number'] = next_document_number('employees', 'staff_number', 'EMP');
            }
            $cols = array_keys($row);
            $ph   = array_map(static fn(string $c): string => ':' . $c, $cols);
            $args = [];
            foreach ($row as $k => $v) {
                $args[':' . $k] = $v;
            }
            $newId = (int) db_value(
                'INSERT INTO employees (' . implode(', ', $cols) . ')
                 VALUES (' . implode(', ', $ph) . ') RETURNING employee_id',
                $args
            );
            audit_log('hr.employee_added', 'employees', $newId, [
                'staff_number' => $row['staff_number'], 'name' => hr_full_name($row),
            ]);
            if (!$owns) {
                $pdo->commit();
            }
            return ['ok' => true, 'message' => hr_full_name($row) . ' has been added.', 'employee_id' => $newId];
        }

        $sets = [];
        $args = [':id' => $employeeId];
        foreach ($row as $k => $v) {
            $sets[] = "$k = :$k";
            $args[':' . $k] = $v;
        }
        $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        db_run('UPDATE employees SET ' . implode(', ', $sets) . ' WHERE employee_id = :id', $args);

        audit_log('hr.employee_updated', 'employees', $employeeId, ['name' => hr_full_name($row)]);
        if (!$owns) {
            $pdo->commit();
        }
        return ['ok' => true, 'message' => hr_full_name($row) . ' has been saved.', 'employee_id' => $employeeId];
    } catch (Throwable $e) {
        if (!$owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[HR] could not save employee: ' . $e->getMessage());

        //  The two uniques a person actually trips over.
        $msg = $e->getMessage();
        if (str_contains($msg, 'employees_staff_number_key')) {
            return ['ok' => false, 'message' => 'That staff number is already in use.', 'employee_id' => null];
        }
        if (str_contains($msg, 'employees_national_id_key')) {
            return ['ok' => false, 'message' => 'That national ID is already on another employee.', 'employee_id' => null];
        }
        if (str_contains($msg, 'employees_user_id_key')) {
            return ['ok' => false, 'message' => 'That login is already attached to another employee.', 'employee_id' => null];
        }
        return ['ok' => false, 'message' => 'The employee could not be saved.', 'employee_id' => null];
    }
}

/**
 * Attach or detach a login.
 *
 * Kept apart from hr_employee_save() because it crosses into the
 * users table and is an administrator's decision, not part of
 * editing somebody's phone number.
 */
function hr_employee_set_login(int $employeeId, ?int $userId): array
{
    if ($userId !== null) {
        $taken = db_value(
            'SELECT employee_id FROM employees WHERE user_id = :u AND employee_id <> :e',
            [':u' => $userId, ':e' => $employeeId]
        );
        if ($taken) {
            return ['ok' => false, 'message' => 'That login already belongs to another employee.'];
        }
    }
    db_run('UPDATE employees SET user_id = :u, updated_at = CURRENT_TIMESTAMP WHERE employee_id = :e',
           [':u' => $userId, ':e' => $employeeId]);
    audit_log($userId === null ? 'hr.login_detached' : 'hr.login_attached', 'employees', $employeeId,
              ['user_id' => $userId]);
    return ['ok' => true, 'message' => $userId === null ? 'The login has been detached.' : 'The login has been attached.'];
}

/**
 * Registrations that have lapsed or are about to.
 *
 * The pharmacy equivalent of an expired stock report, and it
 * matters for the same reason: an expired registration is not a
 * reminder, it is a shop that should not be dispensing.
 */
function hr_registrations_expiring(int $withinDays = 60): array
{
    if (!hr_ready()) {
        return [];
    }
    return db_all(
        "SELECT employee_id, staff_number, first_name, last_name, job_title,
                cadre, prof_body, prof_registration_no, prof_expiry,
                (prof_expiry - CURRENT_DATE) AS days_left
           FROM employees
          WHERE status <> 'exited'
            AND prof_expiry IS NOT NULL
            AND prof_expiry <= CURRENT_DATE + (:d || ' days')::INTERVAL
          ORDER BY prof_expiry",
        [':d' => (string) max(0, $withinDays)]
    );
}

/** Everybody who can currently be in charge of the dispensary. */
function hr_cover_staff(): array
{
    if (!hr_ready()) {
        return [];
    }
    $cadres = hr_cover_cadres();
    $place  = [];
    $args   = [];
    foreach ($cadres as $i => $c) {
        $place[]        = ":c$i";
        $args[":c$i"]   = $c;
    }

    return db_all(
        "SELECT employee_id, staff_number, first_name, last_name, cadre,
                prof_registration_no, prof_expiry
           FROM employees
          WHERE status IN ('active', 'probation')
            AND cadre IN (" . implode(',', $place) . ")
          ORDER BY first_name, last_name",
        $args
    );
}
