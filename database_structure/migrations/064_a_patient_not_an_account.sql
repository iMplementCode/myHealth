-- ============================================================
--  Migration 064 — A patient, and the visit everything hangs off
-- ------------------------------------------------------------
--  The pharmacy needs perhaps six of these columns today. The
--  rest are here because the shape is the expensive decision and
--  migrating live patient data later is the expensive mistake.
--
--  ── Why a patient is not a customer ─────────────────────────
--
--  `customers` is a billing party: a company name, a tax PIN, a
--  location. That is who pays. A patient is who the medicine is
--  for, and the two are routinely different — a parent paying for
--  a child, an employer for staff, an insurer for a member.
--  Folding them together means either a tax PIN column on a
--  six-year-old or a date of birth on a limited company.
--
--  So: separate, with an optional link to the customer who pays.
--
--  ── Why a visit, when a pharmacy has no visits ──────────────
--
--  Because a hospital is entirely visits, and this is the join
--  everything later attaches to:
--
--      ward_admissions  -> visit_id
--      lab_orders       -> visit_id
--      prescriptions    -> visit_id
--      theatre_bookings -> visit_id
--      invoices         -> visit_id   (already, below)
--
--  Retro-fitting that centre later means rewriting every one of
--  those relationships and the reports over them. Adding an empty
--  table now costs a migration.
--
--  A counter sale may carry a visit or not. Somebody buying
--  paracetamol has no encounter and should not be made to have
--  one; somebody collecting a course of antibiotics from the
--  outpatient clinic does.
--
--  ── Allergies, honestly ─────────────────────────────────────
--
--  Free text, and it stays free text until there is a coded drug
--  dictionary to match against. A system that claims to check for
--  allergies but matches on substrings would miss "penicillin"
--  written as "pen VK" and, far worse, would be trusted. Shown
--  prominently to the person dispensing; never silently relied
--  on.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS patients (
    patient_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    -- What gets written on a card and asked for at the window.
    patient_number  VARCHAR(30) NOT NULL UNIQUE,

    first_name      VARCHAR(80) NOT NULL,
    last_name       VARCHAR(80),
    date_of_birth   DATE,

    /*  Recorded, not assumed. 'unknown' is a real answer for
     *  somebody brought in unconscious, and forcing a guess puts a
     *  wrong one in the record permanently. */
    sex             VARCHAR(10),

    phone           VARCHAR(30),
    email           VARCHAR(160),
    national_id     VARCHAR(30),

    -- Kenya's Social Health Authority membership.
    sha_number      VARCHAR(40),

    blood_group     VARCHAR(5),

    /*  The two a dispenser needs in front of them. Free text —
     *  see the header for why that is deliberate rather than
     *  lazy. */
    allergies           TEXT,
    chronic_conditions  TEXT,

    next_of_kin_name  VARCHAR(160),
    next_of_kin_phone VARCHAR(30),

    /*  Who pays, when that is not the patient. Nullable, and
     *  ON DELETE SET NULL: losing a billing account must never
     *  take a clinical record with it. */
    customer_id     INTEGER REFERENCES customers(customer_id) ON DELETE SET NULL,

    notes           TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,

    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      INTEGER REFERENCES users(user_id),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by      INTEGER REFERENCES users(user_id)
);

ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_sex_ck;
ALTER TABLE patients ADD CONSTRAINT patients_sex_ck CHECK (
    sex IS NULL OR sex IN ('female', 'male', 'intersex', 'unknown')
);

ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_dob_ck;
ALTER TABLE patients ADD CONSTRAINT patients_dob_ck CHECK (
    date_of_birth IS NULL OR date_of_birth <= CURRENT_DATE
);

-- The window asks for a name or a phone number, in that order.
CREATE INDEX IF NOT EXISTS idx_patients_name
    ON patients (LOWER(last_name), LOWER(first_name));
CREATE INDEX IF NOT EXISTS idx_patients_phone ON patients (phone);
CREATE INDEX IF NOT EXISTS idx_patients_sha   ON patients (sha_number)
 WHERE sha_number IS NOT NULL;


CREATE TABLE IF NOT EXISTS visits (
    visit_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    visit_number  VARCHAR(30) NOT NULL UNIQUE,

    patient_id    INTEGER NOT NULL REFERENCES patients(patient_id),

    /*  'pharmacy' is what a dispensary records against. The rest
     *  are here so the list does not have to change on the day
     *  the first ward opens. */
    visit_type    VARCHAR(20) NOT NULL DEFAULT 'pharmacy',
    status        VARCHAR(20) NOT NULL DEFAULT 'open',

    started_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at      TIMESTAMPTZ,

    -- Who is looking after them. A user here, a clinician later.
    attending_by  INTEGER REFERENCES users(user_id),

    -- Free text for the referring doctor when they are not staff.
    referred_by   VARCHAR(160),

    notes         TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INTEGER REFERENCES users(user_id)
);

ALTER TABLE visits DROP CONSTRAINT IF EXISTS visits_type_ck;
ALTER TABLE visits ADD CONSTRAINT visits_type_ck CHECK (
    visit_type IN ('pharmacy', 'outpatient', 'inpatient', 'emergency', 'review')
);

ALTER TABLE visits DROP CONSTRAINT IF EXISTS visits_status_ck;
ALTER TABLE visits ADD CONSTRAINT visits_status_ck CHECK (
    status IN ('open', 'closed', 'cancelled')
);

/*  An end that precedes the beginning is always a mistake, and
 *  the reports that measure length of stay would quietly produce
 *  negative numbers rather than complain. */
ALTER TABLE visits DROP CONSTRAINT IF EXISTS visits_span_ck;
ALTER TABLE visits ADD CONSTRAINT visits_span_ck CHECK (
    ended_at IS NULL OR ended_at >= started_at
);

CREATE INDEX IF NOT EXISTS idx_visits_patient ON visits (patient_id, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_visits_open    ON visits (status) WHERE status = 'open';


/*  What was wrong with them. Many per visit, because one rarely
 *  is — and a coded column rather than a paragraph, because the
 *  first question anybody asks of a year of records is "how many
 *  cases of X", and that cannot be answered over prose.
 *
 *  The code system is named rather than assumed: ICD-10 today,
 *  ICD-11 eventually, and a shop may use its own shorthand before
 *  either. */
CREATE TABLE IF NOT EXISTS visit_diagnoses (
    diagnosis_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    visit_id      INTEGER NOT NULL REFERENCES visits(visit_id) ON DELETE CASCADE,

    code_system   VARCHAR(20) NOT NULL DEFAULT 'icd10',
    code          VARCHAR(20),
    description   VARCHAR(255) NOT NULL,

    -- The one being treated, as against the things also true.
    is_primary    BOOLEAN NOT NULL DEFAULT FALSE,

    recorded_by   INTEGER REFERENCES users(user_id),
    recorded_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_diagnoses_visit ON visit_diagnoses (visit_id);
CREATE INDEX IF NOT EXISTS idx_diagnoses_code  ON visit_diagnoses (code_system, code)
 WHERE code IS NOT NULL;

/*  One primary diagnosis per visit. Two is not a richer record,
 *  it is an unanswerable question. */
CREATE UNIQUE INDEX IF NOT EXISTS ux_diagnoses_one_primary
    ON visit_diagnoses (visit_id) WHERE is_primary;


/*  The link that makes the pharmacy's sale part of a clinical
 *  record. Both nullable: a walk-in buying plasters has neither,
 *  and must not be made to. */
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS patient_id INTEGER REFERENCES patients(patient_id);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS visit_id   INTEGER REFERENCES visits(visit_id);

CREATE INDEX IF NOT EXISTS idx_invoices_patient ON invoices (patient_id)
 WHERE patient_id IS NOT NULL;

COMMENT ON TABLE patients IS
    'Who the medicine is for. customers is who pays; the two are routinely different.';
COMMENT ON TABLE visits IS
    'The encounter everything clinical attaches to. Wards, lab and prescriptions will reference visit_id.';
COMMENT ON COLUMN patients.allergies IS
    'Free text, shown to whoever dispenses. Never matched automatically — see migration 064.';

COMMIT;
