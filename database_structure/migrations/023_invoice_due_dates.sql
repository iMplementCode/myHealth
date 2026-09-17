-- ============================================================
--  023 — Give every invoice a due date
-- ------------------------------------------------------------
--  Aged receivables ages an invoice from its due date, falling
--  back to the issue date when there is none. Two of the three
--  ways an invoice can be raised never set one:
--
--    sales order → invoice    set it (issue + 14 days)
--    proforma    → invoice    passed the proforma's *expiry*
--                             date, which is the last day the
--                             quoted price stands, not the day
--                             payment is due — and NULL whenever
--                             the proforma had no expiry
--    goods not returned       set nothing at all
--
--  So most invoices had no due date, were aged from the issue
--  date, and counted as overdue the morning after they were
--  raised. On a day when everything happened to be invoiced, the
--  overdue figure read zero instead — which is what sent someone
--  looking for the reason.
--
--  The application now sets it on all three routes from one
--  constant (INVOICE_PAYMENT_TERMS_DAYS, default 14). This gives
--  the invoices already in the books the same terms, so the
--  ageing report tells the truth about them too.
--
--  Cancelled and draft invoices are left alone: neither is in the
--  ageing report, and a draft's date is not settled yet.
--
--  Idempotent: safe to re-run — it only ever fills a blank.
-- ============================================================

UPDATE invoices
   SET due_date = issue_date + INTERVAL '14 days'
 WHERE due_date IS NULL
   AND issue_date IS NOT NULL
   AND status NOT IN ('draft', 'cancelled');

-- An invoice with no issue date either is too broken to guess at,
-- so it is dated from when the row was created rather than left
-- with nothing for the report to age from.
UPDATE invoices
   SET due_date = (created_at AT TIME ZONE 'UTC')::date + INTERVAL '14 days'
 WHERE due_date IS NULL
   AND issue_date IS NULL
   AND created_at IS NOT NULL
   AND status NOT IN ('draft', 'cancelled');

-- Reporting reads it on every ageing run.
CREATE INDEX IF NOT EXISTS ix_invoices_due_date ON invoices (due_date);
