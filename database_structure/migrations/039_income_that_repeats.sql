-- ============================================================
--  039 — Income that repeats
-- ------------------------------------------------------------
--  Every invoice in this system so far is a one-off: somebody
--  quoted a job, fitted it, and billed for it once.
--
--  That is not how a security firm actually earns. The steady
--  money is the maintenance contract and the monitoring
--  subscription — the same amount, from the same customer, every
--  month or every quarter, for as long as the agreement runs.
--  The system had no idea those existed, so they were billed by
--  somebody remembering, and the month nobody remembered was
--  simply lost.
--
--  ── A contract is not an invoice ────────────────────────────
--  It is the AGREEMENT to invoice. It carries who, how much, how
--  often, from when, and how far it has been billed. The
--  invoices it produces are ordinary invoices: they age, they
--  are paid, they are credited, they appear on the VAT return
--  like everything else. Nothing downstream needs to know a
--  contract existed.
--
--  ── The one rule that matters ───────────────────────────────
--  A billing run must never charge the same period twice.
--
--  It will be run twice — by a cron that fires on a retry, by
--  somebody pressing the button again because the page was slow,
--  by two managers at once on the first of the month. So the
--  period being billed is written onto the invoice, and a unique
--  index makes a second attempt at the same period impossible
--  rather than merely unlikely. Idempotence enforced by the
--  database, not by hoping.
--
--  ── Why period_start is on the invoice, not just the run ────
--  Because the invoice is the thing that must not be duplicated.
--  Putting the guard anywhere else leaves it possible to write
--  the invoice and fail before the guard is recorded.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS service_contracts (
    contract_id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    contract_number VARCHAR(50)  NOT NULL UNIQUE,
    customer_id     INTEGER      NOT NULL REFERENCES customers (customer_id) ON DELETE RESTRICT,
    title           VARCHAR(200) NOT NULL,
    description     TEXT,

    -- What it is worth each time it falls due.
    currency_id     INTEGER REFERENCES currencies (currency_id) ON DELETE SET NULL,
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    tax_rate        NUMERIC(6,4)  NOT NULL DEFAULT 0 CHECK (tax_rate >= 0 AND tax_rate < 1),

    -- The service line the generated invoice carries. Optional: a
    -- contract with no product bills as a plain description.
    product_id      INTEGER REFERENCES products (product_id) ON DELETE SET NULL,

    -- How often, and from when.
    frequency       VARCHAR(20) NOT NULL
                    CHECK (frequency IN ('monthly','quarterly','half_yearly','yearly')),
    start_date      DATE NOT NULL,
    end_date        DATE,
    -- The start of the next period to bill. Everything before this
    -- has been invoiced; this is the contract's whole memory of
    -- where it has got to.
    next_due_date   DATE NOT NULL,

    -- How long the customer gets to pay each generated invoice.
    payment_terms_days INTEGER NOT NULL DEFAULT 30 CHECK (payment_terms_days >= 0),

    status          VARCHAR(20) NOT NULL DEFAULT 'active'
                    CHECK (status IN ('active','paused','ended','cancelled')),

    notes           TEXT,
    created_by      INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- A contract that ends before it starts bills nothing and is
    -- almost certainly a typo in the second date.
    CONSTRAINT ck_contract_dates CHECK (end_date IS NULL OR end_date >= start_date)
);

CREATE INDEX IF NOT EXISTS idx_contracts_customer ON service_contracts (customer_id);
CREATE INDEX IF NOT EXISTS idx_contracts_status   ON service_contracts (status);
-- The billing run's own query: what is active and due.
CREATE INDEX IF NOT EXISTS idx_contracts_due
    ON service_contracts (next_due_date) WHERE status = 'active';

COMMENT ON TABLE service_contracts IS
    'A standing agreement to invoice a customer on a schedule — a '
    'maintenance contract or a monitoring subscription. The invoices it '
    'raises are ordinary invoices in every other respect.';

COMMENT ON COLUMN service_contracts.next_due_date IS
    'Start of the next period to bill. Everything before this date has '
    'been invoiced. Moved forward by the billing run, never by hand.';

-- ─── Which invoice covers which period ──────────────────────
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS contract_id INTEGER
    REFERENCES service_contracts (contract_id) ON DELETE SET NULL;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS period_start DATE;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS period_end   DATE;

CREATE INDEX IF NOT EXISTS idx_invoices_contract ON invoices (contract_id);

-- ─── The same period cannot be billed twice ─────────────────
--  This is the whole safety of the feature. A run that is
--  repeated — retried by cron, double-clicked, or started by two
--  people at once — hits this and stops, rather than sending the
--  customer a second bill for a month they have already paid.
--
--  A cancelled invoice is excluded: cancelling January's invoice
--  because it was wrong has to leave January billable again.
CREATE UNIQUE INDEX IF NOT EXISTS ux_invoice_contract_period
    ON invoices (contract_id, period_start)
    WHERE contract_id IS NOT NULL AND status <> 'cancelled';

COMMENT ON COLUMN invoices.period_start IS
    'For a contract invoice, the first day of the period it covers. '
    'Unique per contract (excluding cancelled invoices), which is what '
    'makes a repeated billing run safe.';

-- ─── How far forward one period runs ────────────────────────
CREATE OR REPLACE FUNCTION contract_period_end(p_start DATE, p_frequency TEXT)
RETURNS DATE AS $$
BEGIN
    -- The period runs up to the day before the next one starts, so
    -- a monthly contract from the 1st reads "1 Jan – 31 Jan" rather
    -- than overlapping February by a day.
    RETURN (p_start + CASE p_frequency
        WHEN 'monthly'     THEN INTERVAL '1 month'
        WHEN 'quarterly'   THEN INTERVAL '3 months'
        WHEN 'half_yearly' THEN INTERVAL '6 months'
        WHEN 'yearly'      THEN INTERVAL '1 year'
        ELSE INTERVAL '1 month'
    END)::date - 1;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

CREATE OR REPLACE FUNCTION contract_next_period(p_start DATE, p_frequency TEXT)
RETURNS DATE AS $$
BEGIN
    RETURN (p_start + CASE p_frequency
        WHEN 'monthly'     THEN INTERVAL '1 month'
        WHEN 'quarterly'   THEN INTERVAL '3 months'
        WHEN 'half_yearly' THEN INTERVAL '6 months'
        WHEN 'yearly'      THEN INTERVAL '1 year'
        ELSE INTERVAL '1 month'
    END)::date;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- ─── A contract cannot start behind itself ──────────────────
CREATE OR REPLACE FUNCTION contract_keeps_its_place()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.next_due_date < NEW.start_date THEN
        RAISE EXCEPTION
            'Contract % would bill from % but does not start until %.',
            NEW.contract_number, NEW.next_due_date, NEW.start_date;
    END IF;

    -- A contract whose end has passed has nothing left to bill.
    -- Saying so here keeps the run's query simple and honest.
    IF NEW.status = 'active' AND NEW.end_date IS NOT NULL
       AND NEW.next_due_date > NEW.end_date THEN
        NEW.status := 'ended';
    END IF;

    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_contract_keeps_its_place ON service_contracts;
CREATE TRIGGER trg_contract_keeps_its_place
    BEFORE INSERT OR UPDATE ON service_contracts
    FOR EACH ROW EXECUTE FUNCTION contract_keeps_its_place();

COMMIT;
