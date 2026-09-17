-- ============================================================
--  018 — Tax notes on documents, and shareable currency symbols
-- ------------------------------------------------------------
--  Two unrelated corrections, both small.
--
--  1. Currency symbols are not unique.
--
--     The currencies table declared `symbol VARCHAR(5) UNIQUE`,
--     which is simply not how currency symbols work: ¥ is both the
--     yen and the yuan, and $ is the US, Canadian, Australian,
--     Singapore and Hong Kong dollar. Adding CNY therefore failed
--     against JPY's ¥ — and because the error named "code or
--     symbol", it read as though the code were taken, which it was
--     not. The code stays unique; the symbol does not.
--
--  2. Documents can carry a tax note.
--
--     When a price is quoted without VAT the document has to say
--     so, in words, next to the total. A free-text note on the
--     quote, proforma and invoice carries that sentence, and
--     follows the document through conversion.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ─── 1. Currency symbols may repeat ─────────────────────────

DO $$
DECLARE
    con TEXT;
BEGIN
    -- The constraint was declared inline, so PostgreSQL named it.
    -- Find it by what it constrains rather than by a guessed name.
    SELECT c.conname INTO con
      FROM pg_constraint c
      JOIN pg_class t ON t.oid = c.conrelid
      JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (c.conkey)
     WHERE t.relname = 'currencies'
       AND c.contype = 'u'
       AND a.attname = 'symbol'
       AND array_length(c.conkey, 1) = 1
     LIMIT 1;

    IF con IS NOT NULL THEN
        EXECUTE format('ALTER TABLE currencies DROP CONSTRAINT %I', con);
    END IF;
END $$;

-- Some installations may carry it as a plain unique index instead.
DROP INDEX IF EXISTS currencies_symbol_key;

-- The code remains unique, and case-insensitively so: 'cny' and
-- 'CNY' are the same currency however they were typed.
CREATE UNIQUE INDEX IF NOT EXISTS ux_currencies_code_lower
    ON currencies (UPPER(code));

-- ─── 2. Tax notes on sales documents ────────────────────────
--  Rendered under the totals, whether or not tax was charged. The
--  common use is to say prices exclude VAT, but it is free text so
--  it can carry an exemption reference or a zero-rating reason
--  just as well.

ALTER TABLE quotes             ADD COLUMN IF NOT EXISTS tax_note TEXT;
ALTER TABLE proforma_invoices  ADD COLUMN IF NOT EXISTS tax_note TEXT;
ALTER TABLE invoices           ADD COLUMN IF NOT EXISTS tax_note TEXT;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'sales_orders') THEN
        ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS tax_note TEXT;
    END IF;
END $$;

-- ─── 3. Brands are managed, not seeded once ─────────────────
--  The table arrived with migration 007 carrying six brands and no
--  way to add a seventh. It now has a page, so it needs the
--  columns a managed list wants.

ALTER TABLE brands ADD COLUMN IF NOT EXISTS notes TEXT;
ALTER TABLE brands ADD COLUMN IF NOT EXISTS sort_order INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS ix_brands_active ON brands (is_active);
