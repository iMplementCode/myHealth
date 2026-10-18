-- ============================================================
--  Leave that knows who is on duty
-- ------------------------------------------------------------
--  Four tables. The first three are what any leave module has.
--  The fourth is the one that makes this a pharmacy's leave
--  module rather than a generic one.
--
--  A pharmacy's premises licence turns on supervision: the
--  dispensary has to be in the charge of appropriately
--  registered personnel. Which means leave approval is exactly
--  where a shop breaks its own licence — the superintendent
--  pharmacist asks for Thursday off, a manager clicks approve,
--  and Thursday is now a day the shop should not open. Every
--  off-the-shelf leave module approves that cheerfully, because
--  all it knows about is entitlement balances.
--
--  So leave_requests carries cover_warning: what the system said
--  about dispensary cover at the moment of approval, stored on
--  the row. Not a flash message that disappears — a record, so
--  that six months later it is possible to say whether the shop
--  knew it was uncovered and went ahead anyway. The application
--  warns loudly and still lets a manager proceed, because
--  arranging a locum happens on the telephone and a system that
--  flatly refuses would be worked around by not recording the
--  leave at all.
--
--  On the seeded leave types: these are the Employment Act 2007
--  minimums as at this migration, and they are DATA, not
--  constants, precisely because they are minimums. A shop that
--  gives 24 days' annual leave edits a row. A statute that
--  changes edits a row. Neither needs a deploy.
-- ============================================================

-- ─── Public holidays ────────────────────────────────────────
--  Leave is counted in working days, and a working day is not a
--  weekend and not a holiday. Without this table, twenty-one
--  days of annual leave over Christmas costs an employee four
--  days they are entitled to keep.

CREATE TABLE IF NOT EXISTS public_holidays (
    holiday_date DATE PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE public_holidays IS
    'Days that do not count against a leave entitlement. Kenya''s fixed-date '
    'holidays are seeded; the moveable ones (Good Friday, Easter Monday, the '
    'two Idds) have to be added each year because they are not computable '
    'from a calendar.';

--  Seeded for the current year and the two following it. Fixed
--  dates only. A holiday that lands on a Sunday is still stored:
--  the counting code treats weekends and holidays the same way,
--  so a double-counted day costs nothing.
INSERT INTO public_holidays (holiday_date, name)
SELECT make_date(y, m, d), nm
FROM generate_series(
        EXTRACT(YEAR FROM CURRENT_DATE)::int,
        EXTRACT(YEAR FROM CURRENT_DATE)::int + 2
     ) AS y,
     (VALUES
        (1,  1,  'New Year''s Day'),
        (5,  1,  'Labour Day'),
        (6,  1,  'Madaraka Day'),
        (10, 10, 'Utamaduni Day'),
        (10, 20, 'Mashujaa Day'),
        (12, 12, 'Jamhuri Day'),
        (12, 25, 'Christmas Day'),
        (12, 26, 'Boxing Day')
     ) AS h(m, d, nm)
ON CONFLICT (holiday_date) DO NOTHING;

-- ─── Leave types ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS leave_types (
    leave_type_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code             VARCHAR(30) NOT NULL UNIQUE,
    name             VARCHAR(100) NOT NULL,
    description      TEXT,

    --  NULL means uncapped, which is what leave without pay is:
    --  there is no annual allowance of it to run out of.
    days_per_year    INTEGER CHECK (days_per_year IS NULL OR days_per_year >= 0),

    --  How those days are paid. Sick leave in Kenya is seven days
    --  at full pay and then seven at half, so one number cannot
    --  describe it. Payroll will read these; leave only counts
    --  days, and stores the split here so payroll does not need
    --  its own migration against this table.
    full_pay_days    INTEGER NOT NULL DEFAULT 0 CHECK (full_pay_days >= 0),
    half_pay_days    INTEGER NOT NULL DEFAULT 0 CHECK (half_pay_days >= 0),

    --  Annual leave is 21 WORKING days; maternity is 3 calendar
    --  months. Counting both the same way is wrong by about a
    --  third, in opposite directions.
    counts_calendar_days BOOLEAN NOT NULL DEFAULT FALSE,

    --  Who may take it, where the statute distinguishes. NULL is
    --  everybody, which is almost everything.
    sex_restriction  VARCHAR(10) CHECK (sex_restriction IN ('female', 'male')),

    --  Months of service before the entitlement opens: twelve for
    --  annual leave, two for sick leave, under the Act.
    min_service_months INTEGER NOT NULL DEFAULT 0 CHECK (min_service_months >= 0),

    --  A sick note, a burial permit. The application asks for a
    --  file and files it through documents/view.php, so it is not
    --  readable off the disk by a URL.
    requires_document BOOLEAN NOT NULL DEFAULT FALSE,

    carry_over_max_days INTEGER NOT NULL DEFAULT 0 CHECK (carry_over_max_days >= 0),

    --  True for the ones the law sets a floor under. The screen
    --  says so, so that whoever edits 21 down to 14 knows what
    --  they are doing.
    is_statutory     BOOLEAN NOT NULL DEFAULT FALSE,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order       INTEGER NOT NULL DEFAULT 0,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    --  The split cannot exceed the allowance.
    CONSTRAINT leave_types_pay_split_fits
        CHECK (days_per_year IS NULL OR full_pay_days + half_pay_days <= days_per_year)
);

INSERT INTO leave_types
    (code, name, description, days_per_year, full_pay_days, half_pay_days,
     counts_calendar_days, sex_restriction, min_service_months,
     requires_document, carry_over_max_days, is_statutory, sort_order)
SELECT * FROM (VALUES
    ('ANNUAL', 'Annual leave',
     'Employment Act 2007 s.28 sets a floor of 21 working days after twelve consecutive months of service. Raise it if your contracts are more generous.',
     21, 21, 0, FALSE, NULL, 12, FALSE, 0, TRUE, 1),

    ('SICK', 'Sick leave',
     'Employment Act 2007 s.30: after two months of service, seven days at full pay and then seven at half pay in each twelve-month period.',
     14, 7, 7, FALSE, NULL, 2, TRUE, 0, TRUE, 2),

    ('MATERNITY', 'Maternity leave',
     'Employment Act 2007 s.29: three months on full pay. Counted in calendar days, and does not reduce annual leave.',
     90, 90, 0, TRUE, 'female', 0, TRUE, 0, TRUE, 3),

    ('PATERNITY', 'Paternity leave',
     'Employment Act 2007 s.29: two weeks on full pay, in calendar days.',
     14, 14, 0, TRUE, 'male', 0, FALSE, 0, TRUE, 4),

    ('COMPASSIONATE', 'Compassionate leave',
     'Not statutory. A common house policy of three days for a bereavement; edit or switch it off to match yours.',
     3, 3, 0, FALSE, NULL, 0, FALSE, 0, FALSE, 5),

    ('UNPAID', 'Leave without pay',
     'Uncapped and unpaid. Approved case by case; payroll deducts the days.',
     NULL, 0, 0, FALSE, NULL, 0, FALSE, 0, FALSE, 6)
) AS v(code, name, description, days_per_year, full_pay_days, half_pay_days,
       counts_calendar_days, sex_restriction, min_service_months,
       requires_document, carry_over_max_days, is_statutory, sort_order)
WHERE NOT EXISTS (SELECT 1 FROM leave_types t WHERE t.code = v.code);

-- ─── Per-employee entitlement overrides ─────────────────────
--  Only written when something differs from the type's default:
--  a contract with more annual leave, days carried over from last
--  year, a correction. Everybody else's balance comes from
--  leave_types, so this table stays small and there is no yearly
--  job to run that creates a row per person per type.

CREATE TABLE IF NOT EXISTS leave_entitlements (
    entitlement_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id      INTEGER NOT NULL REFERENCES employees(employee_id) ON DELETE CASCADE,
    leave_type_id    INTEGER NOT NULL REFERENCES leave_types(leave_type_id) ON DELETE CASCADE,
    leave_year       INTEGER NOT NULL CHECK (leave_year BETWEEN 2000 AND 2200),
    days_entitled    NUMERIC(5,1) CHECK (days_entitled IS NULL OR days_entitled >= 0),
    days_carried_over NUMERIC(5,1) NOT NULL DEFAULT 0 CHECK (days_carried_over >= 0),
    adjustment       NUMERIC(5,1) NOT NULL DEFAULT 0,
    note             TEXT,
    created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (employee_id, leave_type_id, leave_year)
);

COMMENT ON COLUMN leave_entitlements.days_entitled IS
    'Overrides the leave type default for this employee and year. NULL keeps '
    'the default and lets carry-over or an adjustment stand on its own.';

-- ─── Leave requests ────────────────────────────────────────

CREATE TABLE IF NOT EXISTS leave_requests (
    request_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    request_number   VARCHAR(30) NOT NULL UNIQUE,

    --  RESTRICT: an employment record with leave against it is
    --  not deletable. The leave happened.
    employee_id      INTEGER NOT NULL REFERENCES employees(employee_id) ON DELETE RESTRICT,
    leave_type_id    INTEGER NOT NULL REFERENCES leave_types(leave_type_id) ON DELETE RESTRICT,

    start_date       DATE NOT NULL,
    end_date         DATE NOT NULL,
    CONSTRAINT leave_requests_dates_ordered CHECK (end_date >= start_date),

    --  Counted when the request is made and then stored, never
    --  recomputed. A holiday added to the calendar next March
    --  must not silently change how many days somebody was
    --  charged last January.
    days_requested   NUMERIC(5,1) NOT NULL CHECK (days_requested > 0),

    reason           TEXT,
    contact_while_away VARCHAR(120),
    --  A sick note. Stored as a path under uploads and served
    --  only through documents/view.php.
    document_url     TEXT,

    status           VARCHAR(20) NOT NULL DEFAULT 'submitted'
        CHECK (status IN ('submitted', 'dept_approved', 'approved',
                          'rejected', 'cancelled', 'withdrawn')),

    submitted_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    submitted_at     TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    --  Step one: the department head.
    dept_decision_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    dept_decision_at TIMESTAMPTZ,
    dept_note        TEXT,

    --  Step two: HR, or an administrator.
    final_decision_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    final_decision_at TIMESTAMPTZ,
    final_note        TEXT,

    --  What the system said about dispensary cover when this was
    --  approved, kept verbatim. See the header.
    cover_warning    TEXT,

    created_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON COLUMN leave_requests.days_requested IS
    'Working or calendar days as the leave type dictates, counted at '
    'submission and frozen. Never recomputed from the dates.';
COMMENT ON COLUMN leave_requests.cover_warning IS
    'The dispensary cover warning shown to the approver, if any. Present on '
    'an approved row means the shop was told and went ahead.';

CREATE INDEX IF NOT EXISTS ix_leave_requests_employee
    ON leave_requests (employee_id, start_date DESC);
--  The approval queue: everything not yet decided.
CREATE INDEX IF NOT EXISTS ix_leave_requests_open
    ON leave_requests (status, submitted_at)
    WHERE status IN ('submitted', 'dept_approved');
--  "Who is off that week", and the cover check, both ask by date.
CREATE INDEX IF NOT EXISTS ix_leave_requests_dates
    ON leave_requests (start_date, end_date)
    WHERE status IN ('submitted', 'dept_approved', 'approved');

-- ─── One person cannot be on leave twice ────────────────────
--  Expressed in the database rather than in PHP, for the same
--  reason migration 066 put the journal balance here: two
--  requests can be submitted from two tabs in the same second,
--  and a check in PHP sees neither.
--
--  A trigger rather than an exclusion constraint, because the
--  latter needs btree_gist, and an extension that may not be
--  installable by a non-superuser is a migration that fails on
--  exactly the tightened-down database this system is being moved
--  towards.

CREATE OR REPLACE FUNCTION leave_request_no_overlap()
RETURNS TRIGGER AS $$
DECLARE
    clash RECORD;
BEGIN
    IF NEW.status NOT IN ('submitted', 'dept_approved', 'approved') THEN
        RETURN NEW;
    END IF;

    SELECT request_number, start_date, end_date INTO clash
      FROM leave_requests
     WHERE employee_id = NEW.employee_id
       AND request_id <> NEW.request_id
       AND status IN ('submitted', 'dept_approved', 'approved')
       AND start_date <= NEW.end_date
       AND end_date   >= NEW.start_date
     LIMIT 1;

    IF clash IS NOT NULL THEN
        RAISE EXCEPTION
            'Leave for this employee already covers % to % (request %)',
            clash.start_date, clash.end_date, clash.request_number
            USING ERRCODE = 'exclusion_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_leave_request_no_overlap ON leave_requests;
CREATE TRIGGER trg_leave_request_no_overlap
    BEFORE INSERT OR UPDATE OF employee_id, start_date, end_date, status
    ON leave_requests
    FOR EACH ROW EXECUTE FUNCTION leave_request_no_overlap();
