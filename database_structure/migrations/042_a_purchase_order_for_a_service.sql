-- ============================================================
--  042 — A purchase order for a service
-- ------------------------------------------------------------
--  The business buys services as well as selling them: logo
--  design, subcontracted cabling, a hired vehicle. The supplier
--  bills for it and it gets paid, exactly like anything else
--  bought — but nothing arrives on a shelf, so there is no goods
--  received note and there never will be.
--
--  Purchasing refused to admit that. Every product picker and
--  every line validation filtered on `goods_only_sql()`, which
--  migration 034 introduced for the SELLING side and which was
--  applied to buying too. Selling a service and buying one are
--  the same kind of thing; only stock says otherwise.
--
--  ── Where the money comes from ──────────────────────────────
--  This is the part that actually needed a column. Trade
--  payables are not what was ORDERED, they are what was
--  RECEIVED, less what was returned, less what was paid:
--
--      owed = Σ(GRN totals) − Σ(debit notes) − Σ(payments)
--
--  That is right for goods and useless for a service, whose GRN
--  never comes. Left alone, a service line would sit on a
--  purchase order for ever, owed to nobody, unpayable.
--
--  So a service line records the day the work was accepted, and
--  that date does for the service exactly what a receipt date
--  does for goods: from then on it is owed, and it is owed as
--  of that date, so an aged payables report run for last month
--  is not disturbed by work accepted this month.
--
--  ── Why a date and not a boolean ────────────────────────────
--  Every other money figure in this system is dated, because
--  every report that reads them is. A flag would answer "is it
--  owed?" and not "was it owed on the 31st?", which is the only
--  question the payables report asks.
-- ============================================================

BEGIN;

-- ─── When the work was accepted ─────────────────────────────
ALTER TABLE purchase_order_items
    ADD COLUMN IF NOT EXISTS accepted_at DATE;

COMMENT ON COLUMN purchase_order_items.accepted_at IS
    'For a service line: the day the work was accepted and became '
    'payable. Does for a service what a GRN receipt date does for '
    'goods. NULL on a goods line, which is owed for on receipt.';

-- The payables query walks this, so it earns an index.
CREATE INDEX IF NOT EXISTS idx_po_items_accepted
    ON purchase_order_items (accepted_at)
    WHERE accepted_at IS NOT NULL;

-- ─── A service is never received into stock ─────────────────
--  The mirror of trg_dni_no_services (migration 034), which
--  refuses a service on a delivery note going out. Nothing
--  arrives, so nothing can be received, and a receipt that
--  claimed otherwise would move stock that does not exist.
CREATE OR REPLACE FUNCTION grn_items_no_services()
RETURNS TRIGGER AS $$
DECLARE
    p RECORD;
BEGIN
    SELECT name, product_type INTO p
      FROM products WHERE product_id = NEW.product_id;

    IF p.product_type = 'service' THEN
        RAISE EXCEPTION
            '% is a service, so nothing arrives to receive. Mark the work as accepted on the purchase order instead.',
            p.name;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_grn_items_no_services ON grn_items;
CREATE TRIGGER trg_grn_items_no_services
    BEFORE INSERT OR UPDATE ON grn_items
    FOR EACH ROW EXECUTE FUNCTION grn_items_no_services();

-- ─── A line cannot be accepted before it was ordered ────────
CREATE OR REPLACE FUNCTION po_item_accepted_sanely()
RETURNS TRIGGER AS $$
DECLARE
    ordered DATE;
    ptype   TEXT;
    pname   TEXT;
BEGIN
    IF NEW.accepted_at IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT p.product_type, p.name INTO ptype, pname
      FROM products p WHERE p.product_id = NEW.product_id;

    -- Goods become owed for when they are received, on a GRN.
    -- Accepting them here would count them twice.
    IF ptype IS DISTINCT FROM 'service' THEN
        RAISE EXCEPTION
            '% is a product, not a service. Receive it on a goods received note.', pname;
    END IF;

    SELECT po.issue_date INTO ordered
      FROM purchase_orders po WHERE po.po_id = NEW.po_id;

    IF ordered IS NOT NULL AND NEW.accepted_at < ordered THEN
        RAISE EXCEPTION
            'The work cannot have been accepted on % — the order was only raised on %.',
            NEW.accepted_at, ordered;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_po_item_accepted_sanely ON purchase_order_items;
CREATE TRIGGER trg_po_item_accepted_sanely
    BEFORE INSERT OR UPDATE OF accepted_at ON purchase_order_items
    FOR EACH ROW EXECUTE FUNCTION po_item_accepted_sanely();

COMMIT;
