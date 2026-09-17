-- ============================================================
--  036 — Where the customer is
-- ------------------------------------------------------------
--  A customer record held a name, an email, a phone number and a
--  tax PIN, and nothing at all about where they are. For a
--  business whose work is driving to premises and fitting
--  cameras to walls, that is the field the job actually needs.
--
--  It was not on the form because it was not in the table.
--
--  ── One word for it ─────────────────────────────────────────
--  The system already calls this "location": it is what
--  `company_settings.location` holds, and what prints under the
--  company name on every document. Suppliers called the same
--  thing `address`. Two words for one idea, on two sides of the
--  same transaction, is how a field ends up filled in on one
--  form and left blank on the other.
--
--  So `suppliers.address` is renamed rather than duplicated —
--  the data is kept exactly as it is, and every party in the
--  system now has a `location`.
--
--  ── Not split into town, street, postcode ───────────────────
--  Deliberately one free-text field. Kenyan addresses are
--  written as a road and a landmark — "Gaberone Road, Montana
--  Mall 2nd Floor, Shop M206" — and forcing that into boxes
--  designed for a different postal system produces empty fields
--  and a worse address than the one somebody would have typed.
--  It is searched as text and printed as typed.
-- ============================================================

BEGIN;

-- ─── Suppliers: the same field, under the system's own name ──
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'suppliers' AND column_name = 'address'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'suppliers' AND column_name = 'location'
    ) THEN
        ALTER TABLE suppliers RENAME COLUMN address TO location;
    END IF;
END $$;

-- A database that never had the old column still needs the new one.
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS location TEXT;

-- ─── Customers: the field that was missing entirely ─────────
ALTER TABLE customers ADD COLUMN IF NOT EXISTS location TEXT;

-- Both are searched from their list pages, and a customer's
-- location is searched when picking one for a quote.
CREATE INDEX IF NOT EXISTS idx_customers_location
    ON customers (LOWER(location));
CREATE INDEX IF NOT EXISTS idx_suppliers_location
    ON suppliers (LOWER(location));

COMMIT;
