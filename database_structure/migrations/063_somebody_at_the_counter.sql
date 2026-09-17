-- ============================================================
--  Migration 063 — The person in front of you
-- ------------------------------------------------------------
--  Two things the counter needs that the ERP never did.
--
--  1. Somebody to sell to.
--
--     invoices.customer_id is NOT NULL, because in the trade this
--     code grew up in every sale was to a named company with an
--     account. A pharmacy's usual customer walked in off the
--     street and will not be back for a month.
--
--     A shared "Walk-in Customer" rather than making the column
--     nullable: every report, statement and receivables query
--     downstream joins on it, and loosening the column would mean
--     auditing all of them for a NULL they have never seen. A
--     walk-in sale is paid on the spot so it never reaches
--     receivables anyway.
--
--     The counter can still attach a real customer when there is
--     one — an account holder, or later a patient.
--
--  2. Who prescribed it.
--
--     A prescription-only medicine handed over with no record of
--     who prescribed it is the thing an inspector is looking for.
--
--     Two columns on the invoice, not a prescriptions table. That
--     is a deliberate floor rather than a design: a real
--     prescription has a patient, dose, frequency, duration and
--     refills, and it belongs with the patient record rather than
--     bolted to a receipt. What is here is the minimum that is
--     not a lie — the counter refuses to sell an Rx-only drug
--     without it — and a prescriptions table can read these
--     columns when it arrives.
-- ============================================================

BEGIN;

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS prescriber       VARCHAR(160);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS prescription_ref VARCHAR(60);

COMMENT ON COLUMN invoices.prescriber IS
    'Who prescribed, when the sale included a prescription-only medicine.';

/*  One walk-in customer, created only if the shop has not already
 *  got one. Matched on the flag rather than the name so that
 *  renaming it to "Cash Sale" or translating it does not cause a
 *  second one to appear on the next deploy. */
ALTER TABLE customers ADD COLUMN IF NOT EXISTS is_walk_in BOOLEAN NOT NULL DEFAULT FALSE;

/*  Partial unique index: any number of ordinary customers, but
 *  never two walk-ins. Without this, two containers starting
 *  together both find none and both insert one, and every report
 *  splits the counter's takings down the middle. */
CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_one_walk_in
    ON customers ((TRUE)) WHERE is_walk_in;

INSERT INTO customers (company_name, first_name, last_name, is_walk_in, is_active)
SELECT 'Walk-in Customer', 'Walk-in', 'Customer', TRUE, TRUE
 WHERE NOT EXISTS (SELECT 1 FROM customers WHERE is_walk_in);

COMMENT ON COLUMN customers.is_walk_in IS
    'The counter''s anonymous customer. At most one, enforced by a partial unique index.';

COMMIT;
