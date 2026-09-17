-- ============================================================
--  Give every document its own number, even when two people
--  press Save in the same second
-- ------------------------------------------------------------
--  next_document_number() read MAX(number)+1 and handed the
--  result back:
--
--      SELECT MAX(CAST(SUBSTRING(invoice_number FROM '\d+$') AS INT))
--        FROM invoices WHERE invoice_number LIKE 'INV-2026-%'
--
--  Nothing stands between that read and the INSERT that uses it.
--  Two transactions read 41, both build INV-2026-0042, and the
--  UNIQUE constraint on invoice_number lets exactly one of them
--  through. The other gets a constraint violation and the person
--  who raised it gets an error page — with the invoice they just
--  typed gone.
--
--  Measured, against a table shaped like the real ones:
--
--      2 workers, 8 rounds    8 of 16 saves lost   (every round)
--     12 workers, 5 rounds   29 of 60 saves lost
--
--  Two is not a stress test. Two is a salesperson and the
--  accounts clerk, and it loses one of them every time.
--
--  Seventeen columns are numbered this way, all of them UNIQUE:
--  invoices, quotes, sales_orders, purchase_orders, grns,
--  delivery_notes, credit_notes, debit_notes, customer_refunds,
--  proforma_invoices, goods_return_notes, warranty_notes,
--  service_contracts, stock_counts, investments, employee_loans,
--  customer_advances.
--
--  ── One row per counter, and the row is the lock ────────────
--  The allocator becomes a single UPDATE:
--
--      UPDATE document_sequences SET last_value = last_value + 1
--       WHERE scope = 'invoices.invoice_number.INV.2026'
--   RETURNING last_value
--
--  Postgres locks that row for the rest of the transaction. A
--  second allocator does not read a stale MAX — it waits, and
--  gets the next number when the first one commits.
--
--  ── Why not a Postgres SEQUENCE ─────────────────────────────
--  nextval() never blocks and never collides, which is better on
--  both counts but for one thing: it does not roll back. A save
--  that fails validation after allocating would burn INV-2026-0042
--  permanently, and a set of books with a hole in the invoice
--  numbers is a question somebody has to answer — to an auditor,
--  or to KRA. A locked row rolls back with everything else, so a
--  failed save releases its number and the sequence stays gapless.
--
--  The cost is that document creation of one kind serialises on
--  its counter row for the length of the writing transaction.
--  Those transactions insert a header and its lines and commit;
--  waiting a few milliseconds for a number is the trade for never
--  losing the document.
--
--  ── Where the counters start ────────────────────────────────
--  Empty on purpose. The first call for a scope seeds it from
--  MAX() of the real table — the same read as before, once, and
--  then never again. That way this migration does not need to
--  know which tables exist on the database it is run against, and
--  a table numbered by hand or filled by an import is picked up
--  from wherever it actually got to.
-- ============================================================

CREATE TABLE IF NOT EXISTS document_sequences (
    scope       VARCHAR(120) PRIMARY KEY,
    last_value  INTEGER      NOT NULL CHECK (last_value >= 0),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE document_sequences IS
    'One row per document number counter, e.g. invoices.invoice_number.INV.2026. '
    'The row lock is what stops two people being handed the same number; see '
    'next_document_number() in includes/documents.php.';

-- The scope is the primary key and every read and write is by scope,
-- so there is no second index to add. The table holds one row per
-- document type per year — a few dozen rows after a decade.

-- Note for whoever numbers the next document type: 'INV' is already
-- taken twice. invoices.invoice_number uses it, and so does
-- investments.reference_no, so INV-2026-0001 can name both an invoice
-- and an investor's contribution. They are separate tables with
-- separate constraints, so nothing breaks and nothing here changes it
-- — but it is a footgun on a bank reconciliation, and the fix is a
-- new prefix on investments, which is a decision about paperwork
-- rather than about code.
