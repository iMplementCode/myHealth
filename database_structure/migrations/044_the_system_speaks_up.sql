-- ============================================================
--  Things the business needs telling about
-- ------------------------------------------------------------
--  Stock that has run out, an invoice nobody chased, a batch of
--  cameras going out of date in a fortnight. The system already
--  knows all of it — it just never said anything, so somebody had
--  to think to go and look.
--
--  Two tables. `notifications` is one row per *condition*, not per
--  sighting: the scanner runs every few minutes and an invoice
--  that has been overdue since March must not produce a hundred
--  rows. That is what dedupe_key is for, and why it is UNIQUE.
--
--  `notification_reads` is per person. A shared alert that one
--  manager dismisses must still be waiting for the other one.
--
--  The delivery columns (emailed_at, sms_at) are here now and used
--  later. Email and SMS come after deployment, and a scanner that
--  has been recording what it would have sent from day one is a
--  much better starting point than one that has to be taught.
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    notification_id BIGSERIAL PRIMARY KEY,

    -- What kind of thing this is: 'invoice_overdue', 'stock_out',
    -- 'batch_expiring'. The scanner owns this vocabulary.
    kind            VARCHAR(40)  NOT NULL,

    -- How loudly to say it. Drives colour, ordering, and later
    -- whether it is worth an SMS at 9pm.
    severity        VARCHAR(10)  NOT NULL DEFAULT 'info'
                    CHECK (severity IN ('info', 'warning', 'critical')),

    -- What it is about, so the notification can link to the thing
    -- itself rather than to a list the reader has to search.
    subject_type    VARCHAR(40),
    subject_id      INTEGER,

    title           VARCHAR(200) NOT NULL,
    body            TEXT,
    url             VARCHAR(300),

    -- One row per condition. "invoice_overdue:412" stays one row
    -- however many times the scanner sees it, and the title is
    -- rewritten in place as the figures move.
    dedupe_key      VARCHAR(160) NOT NULL UNIQUE,

    -- Who this concerns. NULL means everybody who can sign in.
    -- A salesperson has no use for a supplier payment alert and
    -- should not be shown the company's payables.
    audience        VARCHAR(50),

    -- Cleared when the condition stops being true — the invoice was
    -- paid, the stock came in. Resolved rows are kept: "this went
    -- unpaid for six weeks" is worth being able to look up.
    resolved_at     TIMESTAMPTZ,

    -- Filled in when the notification actually goes out. Both stay
    -- NULL until the sending is built.
    emailed_at      TIMESTAMPTZ,
    sms_at          TIMESTAMPTZ,

    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- The bell asks one question on every page load: what is open, for
-- this person, newest first. This is the index that answers it.
CREATE INDEX IF NOT EXISTS idx_notifications_open
    ON notifications (resolved_at, severity, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_kind
    ON notifications (kind, resolved_at);

CREATE TABLE IF NOT EXISTS notification_reads (
    notification_id BIGINT  NOT NULL
                    REFERENCES notifications (notification_id) ON DELETE CASCADE,
    user_id         INTEGER NOT NULL
                    REFERENCES users (user_id) ON DELETE CASCADE,
    read_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_notification_reads_user
    ON notification_reads (user_id);

-- ── When the scanner last ran ────────────────────────────────
--  A single row. The scan is triggered by whoever happens to open
--  a page, so several requests can arrive at the same moment and
--  every one of them would otherwise run the same twelve queries.
--
--  The claim is made with one atomic UPDATE ... RETURNING: exactly
--  one caller sees a row back and does the work, everybody else
--  gets nothing and carries on rendering the page. No locks, no
--  advisory-lock cleanup, nothing left behind if PHP dies midway.
CREATE TABLE IF NOT EXISTS notification_scan_state (
    only_row    BOOLEAN PRIMARY KEY DEFAULT TRUE CHECK (only_row),
    last_run_at TIMESTAMPTZ NOT NULL DEFAULT '1970-01-01'::TIMESTAMPTZ,
    last_run_ms INTEGER
);

INSERT INTO notification_scan_state (only_row) VALUES (TRUE)
ON CONFLICT DO NOTHING;
