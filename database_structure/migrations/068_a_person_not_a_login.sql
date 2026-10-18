-- ============================================================
--  A person, not a login
-- ------------------------------------------------------------
--  This system already lends money to its staff. migration 016
--  created employee_loans, and the borrower is users(user_id) —
--  a row holding a username, a password hash and a role. There
--  is no hire date on it, no job title, no salary, no next of
--  kin, no KRA PIN. The shop can advance somebody three months'
--  pay and cannot say what their pay is, when they started, or
--  who to telephone if they collapse behind the counter.
--
--  So "add leave" and "add payroll" are not two features. They
--  are two things standing on a foundation that was never laid.
--  This lays it.
--
--  Two tables, and the order matters: departments names a head
--  who is an employee, and an employee belongs to a department,
--  so the second reference is added once both tables exist.
--
--  Why the head is an employee and not a user: the head of
--  dispensing is a person who works here, and that is a fact
--  about the organisation whether or not they have ever logged
--  in. Approving leave does need a login, and the application
--  resolves head -> employee -> user at that point and says so
--  plainly when there is no account behind the name. Storing a
--  user here instead would have made the org chart a property of
--  who happens to have a password.
-- ============================================================

-- ─── Departments ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS departments (
    department_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name             VARCHAR(100) NOT NULL UNIQUE,
    description      TEXT,
    --  Set below, once employees exists.
    head_employee_id INTEGER,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE departments IS
    'Organisational units. The head is the first approver for leave raised '
    'by anybody in the department.';

-- ─── Employees ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS employees (
    employee_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    --  At most one login per employee, and an employee may have
    --  none: a casual or a cleaner has no reason to hold an
    --  account. ON DELETE SET NULL because deleting an account
    --  must never delete the employment record it was attached
    --  to — the person still worked here, and the loans, leave
    --  and payslips against them still have to balance.
    user_id          INTEGER UNIQUE REFERENCES users(user_id) ON DELETE SET NULL,

    staff_number     VARCHAR(30) NOT NULL UNIQUE,
    first_name       VARCHAR(100) NOT NULL,
    last_name        VARCHAR(100) NOT NULL,
    other_names      VARCHAR(100),

    --  Unique where present: one national ID is one person. Left
    --  nullable because a new hire's papers do not always arrive
    --  on their first day, and a system that refuses to record
    --  somebody who is already working gets worked around.
    national_id      VARCHAR(30) UNIQUE,
    date_of_birth    DATE,

    --  Needed because the statutory leave types are not all open
    --  to everybody — maternity and paternity differ in length
    --  and in who may take them. Nullable, and nothing else in
    --  the application reads it.
    sex              VARCHAR(10) CHECK (sex IN ('female', 'male', 'other')),

    phone            VARCHAR(30),
    personal_email   VARCHAR(255),
    physical_address TEXT,

    -- ── Employment ──
    department_id    INTEGER REFERENCES departments(department_id) ON DELETE SET NULL,
    job_title        VARCHAR(120) NOT NULL,

    --  Which professional cadre, because in a pharmacy this is not
    --  a job title — it is who may legally be in charge of the
    --  dispensary. includes/hr.php names the cadres that satisfy
    --  that, in one place, because the answer depends on the
    --  premises licence class and will be argued about.
    cadre            VARCHAR(40) NOT NULL DEFAULT 'support'
        CHECK (cadre IN ('pharmacist', 'pharmaceutical_technologist',
                         'nurse', 'clinical_officer', 'support')),

    employment_type  VARCHAR(20) NOT NULL DEFAULT 'permanent'
        CHECK (employment_type IN ('permanent', 'contract', 'probation',
                                   'casual', 'intern', 'locum')),

    hire_date        DATE NOT NULL,
    end_date         DATE,
    exit_reason      TEXT,

    status           VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'probation', 'suspended', 'on_notice', 'exited')),

    --  A leaver has a leaving date, and somebody still here does
    --  not. Stated as a constraint because an exited employee
    --  with no end date silently keeps accruing leave.
    CONSTRAINT employees_exit_dated
        CHECK ((status = 'exited') = (end_date IS NOT NULL)),
    CONSTRAINT employees_exit_after_hire
        CHECK (end_date IS NULL OR end_date >= hire_date),

    -- ── Professional registration ──
    --  Tracked for people exactly as migration 061 tracks it for
    --  products. A pharmacy records the PPB registration of every
    --  medicine on its shelves and, until now, nothing at all
    --  about the registration of the person handing them over.
    --  An expired one is not a reminder, it is a closed shop.
    prof_body            VARCHAR(40),
    prof_registration_no VARCHAR(60),
    prof_expiry          DATE,

    -- ── Statutory identifiers ──
    --  Needed by payroll and by the returns that follow it. Held
    --  here rather than invented later, so payroll is a module
    --  and not another migration against this table.
    kra_pin          VARCHAR(20),
    nssf_number      VARCHAR(30),
    shif_number      VARCHAR(30),

    -- ── Pay and bank ──
    basic_salary     NUMERIC(14,2) CHECK (basic_salary IS NULL OR basic_salary >= 0),
    bank_name        VARCHAR(120),
    bank_branch      VARCHAR(120),
    bank_account_no  VARCHAR(40),

    -- ── Next of kin ──
    nok_name         VARCHAR(160),
    nok_relationship VARCHAR(60),
    nok_phone        VARCHAR(30),

    notes            TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE employees IS
    'The people employed here. Distinct from users, which is an account: an '
    'employee may have no login, and a login (a system administrator) need '
    'not be an employee.';
COMMENT ON COLUMN employees.cadre IS
    'Professional cadre. Decides who can satisfy the supervision requirement '
    'for the dispensary — see hr_cover_cadres() in includes/hr.php.';
COMMENT ON COLUMN employees.basic_salary IS
    'Monthly basic pay. Read only by administrators; see hr_can_see_pay().';

--  The circular half, now that both tables exist. RESTRICT, not
--  CASCADE: deleting the person who runs a department must not
--  delete the department, and must not silently leave leave
--  requests with nobody to approve them either — it fails, and
--  somebody names a new head.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'departments_head_employee_fk'
    ) THEN
        ALTER TABLE departments
            ADD CONSTRAINT departments_head_employee_fk
            FOREIGN KEY (head_employee_id) REFERENCES employees(employee_id)
            ON DELETE RESTRICT;
    END IF;
END $$;

-- ─── Indexes ────────────────────────────────────────────────
--  Only the ones something reads by. The audit in migration 067
--  is the argument against adding more: an index on every
--  foreign key is a slower write bought for a query nobody runs.

--  The leave screens list a department's people, and the cover
--  check asks for the registered dispensers who are still here.
CREATE INDEX IF NOT EXISTS ix_employees_department ON employees (department_id);
CREATE INDEX IF NOT EXISTS ix_employees_status     ON employees (status);
CREATE INDEX IF NOT EXISTS ix_employees_cadre      ON employees (cadre)
    WHERE status <> 'exited';
--  "Whose registration lapses next month" — the notification.
CREATE INDEX IF NOT EXISTS ix_employees_prof_expiry ON employees (prof_expiry)
    WHERE prof_expiry IS NOT NULL AND status <> 'exited';
CREATE INDEX IF NOT EXISTS ix_departments_head ON departments (head_employee_id);

-- ─── The Staff role ─────────────────────────────────────────
--  A fourth role, and the first one that is MEANT to see less
--  than a salesperson rather than more. It exists so that a
--  storekeeper can apply for leave without thereby being handed
--  the till, the patient records and the cash book.
--
--  Adding it to this table is the easy half. The half that
--  matters is in includes/auth.php: most pages in this
--  application carry no role guard at all, which has been
--  correct while every role was trusted with the shop floor, and
--  stops being correct the moment a role exists that is not. So
--  this role is default-deny at the bootstrap — it may reach an
--  allowlist of pages and nothing else, which means a page added
--  next year is closed to it without anybody remembering to say so.
INSERT INTO roles (name, description, is_system_role)
SELECT v.name, v.description, v.is_system
FROM (VALUES
    ('Staff', 'Apply for leave and see your own record. No access to the shop floor, money or patients.', FALSE)
) AS v(name, description, is_system)
WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.name = v.name);
