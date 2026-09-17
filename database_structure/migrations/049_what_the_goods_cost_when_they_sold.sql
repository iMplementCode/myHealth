-- ============================================================
--  What the goods cost on the day they were sold
-- ------------------------------------------------------------
--  "How much did I make on that camera?" is the question the
--  business most wants answered, and until now the answer moved.
--  Margin was worked out as
--
--      quantity sold × products.cost_price
--
--  where cost_price is what the product costs TODAY. Buy the next
--  batch 15% cheaper and last year's profit silently goes up.
--  Buy it dearer and a good month turns into a bad one. Nothing
--  changed on the invoice; the report just said something else.
--  reports/sales.php has been carrying a footnote admitting this.
--
--  So the cost is captured on the line, at the moment of sale,
--  and never looked up again. Exactly the rule the warranty terms
--  follow, and the one credit_note_items has followed since it
--  was written — it already has a unit_cost column, which is what
--  made the gap on the sales side obvious.
--
--  ── Why a trigger and not four edits ────────────────────────
--  Invoice lines are written from at least four places: the quote
--  conversion, the proforma conversion, a goods return, and the
--  contract biller. A fifth will be added by somebody who has
--  never read this file. A DEFAULT cannot help — it cannot see
--  which product the row is for — so the stamp goes in a trigger
--  and every path gets it, including the ones not yet written.
--
--  ── Old lines stay NULL, on purpose ─────────────────────────
--  Rows written before today have no honest cost to record. The
--  weighted average of every receipt since would be a number
--  nobody chose, sitting in a column that claims to say what was
--  actually paid. So they are left NULL, the reports fall back to
--  today's cost price for them exactly as before, and each report
--  says how many lines it had to estimate.
-- ============================================================

ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS unit_cost NUMERIC(12,2);

COMMENT ON COLUMN invoice_items.unit_cost IS
    'What one unit cost us on the day this line was invoiced. Stamped '
    'by trg_invoice_items_stamp_cost. NULL on lines written before '
    'migration 049 — reports fall back to products.cost_price and say so.';

CREATE OR REPLACE FUNCTION invoice_item_stamp_cost()
RETURNS TRIGGER AS $$
BEGIN
    -- An explicit cost wins: a correction, or an import that knows
    -- better than the product record does.
    IF NEW.unit_cost IS NULL THEN
        SELECT cost_price INTO NEW.unit_cost
          FROM products WHERE product_id = NEW.product_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_invoice_items_stamp_cost ON invoice_items;
CREATE TRIGGER trg_invoice_items_stamp_cost
    BEFORE INSERT ON invoice_items
    FOR EACH ROW EXECUTE FUNCTION invoice_item_stamp_cost();

-- ── Reading one product's whole life ─────────────────────────
--  The product card walks every receipt, every sale and every
--  movement for a single product. Each of those is a scan by
--  product_id over a table that only ever grows.
CREATE INDEX IF NOT EXISTS ix_invoice_items_product
    ON invoice_items (product_id);

CREATE INDEX IF NOT EXISTS ix_grn_items_product
    ON grn_items (product_id);
