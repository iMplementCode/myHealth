-- ============================================================
--  035 — Stock is worth what it actually cost
-- ------------------------------------------------------------
--  23 cameras came in last month at 2,600. Thirteen were sold.
--  Fifteen more came in this month at 3,400. There are 25 on the
--  shelf and they cost:
--
--      10 × 2,600  =  26,000     what is left of the first lot
--      15 × 3,400  =  51,000     the second lot
--                     ───────
--                     77,000
--
--  The system said 65,000. It valued all 25 at 2,600, because
--  `products.cost_price` was typed in once when the product was
--  created and **no receipt has ever changed it**. Buy at a new
--  price and the books never hear about it.
--
--  Twelve thousand shillings missing on one product. On a
--  balance sheet, a year end, or an insurance claim, that is the
--  figure that matters.
--
--  ── Where the right answer already was ──────────────────────
--  Nowhere new. `grn_items` has recorded `unit_cost` against
--  `quantity_received` for every receipt since the purchasing
--  module was built. Every price ever paid is already in the
--  database; nothing was reading it.
--
--  ── The rule: first in, first out ───────────────────────────
--  The oldest stock is sold first — which is what actually
--  happens in a store room, and one of the two methods IAS 2
--  allows (the other is weighted average; LIFO is not permitted).
--  So what is on the shelf is the **most recent** receipts:
--  walk the receipts newest-first until they account for the
--  quantity on hand, and that is what it cost.
--
--  Consumption is not tracked receipt by receipt, and does not
--  need to be. Under FIFO the stock remaining *is* the newest
--  receipts, so the layers can be derived from the receipt
--  history and the quantity on hand alone. No new table, and
--  nothing to keep in step.
--
--  ── cost_price now means something precise ──────────────────
--  It is no longer a number somebody typed. It is the average
--  unit cost of the stock actually on hand:
--
--      cost_price = FIFO value of stock on hand / quantity on hand
--
--  which for the cameras above is 77,000 / 25 = 3,080.
--
--  Maintaining it on the row rather than computing it in every
--  report is deliberate: the stock valuation, the daily
--  position, cost of sales, the margin on the Sales Analysis and
--  the dashboard all read `cost_price` already. Give it the
--  right value and every one of them is right, with no query to
--  rewrite and no second definition to drift.
--
--  It is recomputed by trigger on every event that can change
--  the answer: a receipt, an edit to a receipt, a receipt
--  cancelled, goods returned to the supplier, and any movement
--  of stock — because selling the oldest units leaves a dearer
--  mix behind and the average has to rise.
--
--  ── A product with no receipts keeps its typed cost ─────────
--  Stock entered by hand at go-live has no purchase behind it.
--  There is nothing to derive from, so what was typed stands,
--  and this migration leaves it alone. The same applies to the
--  part of a quantity that receipts do not cover: valued at the
--  typed cost, and product_stock_cost() reports how much of the
--  quantity that was so the gap is visible rather than assumed.
-- ============================================================

BEGIN;

-- ─── What the stock on hand cost, receipt by receipt ────────
--  Returns one row: the quantity, how much of it receipts
--  actually account for, the total cost and the average.
CREATE OR REPLACE FUNCTION product_stock_cost(p_product_id INTEGER)
RETURNS TABLE (on_hand NUMERIC, covered NUMERIC, total_cost NUMERIC, avg_cost NUMERIC)
AS $$
DECLARE
    v_on_hand   NUMERIC;
    v_typed     NUMERIC;
    v_remaining NUMERIC;
    v_total     NUMERIC := 0;
    v_take      NUMERIC;
    r           RECORD;
BEGIN
    SELECT p.stock_quantity, p.cost_price
      INTO v_on_hand, v_typed
      FROM products p WHERE p.product_id = p_product_id;

    IF v_on_hand IS NULL THEN
        RETURN;                        -- no such product
    END IF;

    v_remaining := GREATEST(v_on_hand, 0);

    -- Newest first: under FIFO the oldest went out, so what is
    -- still here is the most recent arrivals.
    FOR r IN
        SELECT gi.unit_cost,
               gi.quantity_received
                 - COALESCE((
                     SELECT SUM(dni.quantity_returned)
                       FROM debit_note_items dni
                       JOIN debit_notes dn ON dn.debit_note_id = dni.debit_note_id
                      WHERE dni.grn_item_id = gi.grn_item_id
                        AND dn.status <> 'cancelled'), 0) AS qty
          FROM grn_items gi
          JOIN grns g ON g.grn_id = gi.grn_id
         WHERE gi.product_id = p_product_id
           AND g.status <> 'cancelled'
         ORDER BY g.receipt_date DESC, g.grn_id DESC, gi.grn_item_id DESC
    LOOP
        EXIT WHEN v_remaining <= 0.0000001;
        CONTINUE WHEN r.qty IS NULL OR r.qty <= 0;   -- fully returned to the supplier

        v_take      := LEAST(v_remaining, r.qty);
        v_total     := v_total + v_take * r.unit_cost;
        v_remaining := v_remaining - v_take;
    END LOOP;

    covered := GREATEST(v_on_hand, 0) - v_remaining;

    -- Whatever the receipts do not reach is stock that was there
    -- before the system was. Valued at the typed cost, and the
    -- caller can see from `covered` how much of the figure that is.
    v_total := v_total + v_remaining * COALESCE(v_typed, 0);

    on_hand    := v_on_hand;
    total_cost := v_total;
    avg_cost   := CASE WHEN v_on_hand > 0 THEN v_total / v_on_hand
                       ELSE COALESCE(v_typed, 0) END;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql STABLE;

-- ─── Put that average on the product ────────────────────────
CREATE OR REPLACE FUNCTION refresh_product_cost(p_product_id INTEGER)
RETURNS VOID AS $$
DECLARE
    v   RECORD;
    new_cost NUMERIC;
BEGIN
    IF p_product_id IS NULL THEN
        RETURN;
    END IF;

    -- No receipts, nothing to derive from: the typed cost stands.
    -- Overwriting it with zero would wipe the cost of every product
    -- whose opening stock was entered by hand.
    IF NOT EXISTS (
        SELECT 1 FROM grn_items gi
          JOIN grns g ON g.grn_id = gi.grn_id
         WHERE gi.product_id = p_product_id AND g.status <> 'cancelled'
    ) THEN
        RETURN;
    END IF;

    SELECT * INTO v FROM product_stock_cost(p_product_id);
    IF v.avg_cost IS NULL THEN
        RETURN;
    END IF;

    new_cost := ROUND(v.avg_cost, 2);

    -- Only cost_price is written, which is what keeps the
    -- stock_quantity trigger below from firing itself in a loop:
    -- it is declared UPDATE OF stock_quantity, and stock_quantity
    -- is not in this SET list.
    UPDATE products
       SET cost_price = new_cost
     WHERE product_id = p_product_id
       AND cost_price IS DISTINCT FROM new_cost;
END;
$$ LANGUAGE plpgsql;

-- ─── Everything that can change the answer ──────────────────

-- A receipt line: added, corrected, or removed.
CREATE OR REPLACE FUNCTION trg_grn_items_refresh_cost() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_product_cost(NEW.product_id);
    IF TG_OP = 'UPDATE' AND OLD.product_id IS DISTINCT FROM NEW.product_id THEN
        PERFORM refresh_product_cost(OLD.product_id);
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trg_grn_items_refresh_cost_del() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_product_cost(OLD.product_id);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_grn_items_refresh_cost ON grn_items;
CREATE TRIGGER tg_grn_items_refresh_cost
    AFTER INSERT OR UPDATE OF product_id, quantity_received, unit_cost ON grn_items
    FOR EACH ROW EXECUTE FUNCTION trg_grn_items_refresh_cost();

DROP TRIGGER IF EXISTS tg_grn_items_refresh_cost_del ON grn_items;
CREATE TRIGGER tg_grn_items_refresh_cost_del
    AFTER DELETE ON grn_items
    FOR EACH ROW EXECUTE FUNCTION trg_grn_items_refresh_cost_del();

-- A receipt cancelled or reinstated takes all its lines with it.
CREATE OR REPLACE FUNCTION trg_grn_refresh_cost() RETURNS TRIGGER AS $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT DISTINCT product_id FROM grn_items WHERE grn_id = NEW.grn_id LOOP
        PERFORM refresh_product_cost(r.product_id);
    END LOOP;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_grn_refresh_cost ON grns;
CREATE TRIGGER tg_grn_refresh_cost
    AFTER UPDATE OF status, receipt_date ON grns
    FOR EACH ROW EXECUTE FUNCTION trg_grn_refresh_cost();

-- Goods sent back to the supplier shrink the layer they came from.
CREATE OR REPLACE FUNCTION trg_dni_refresh_cost() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_product_cost(COALESCE(NEW.product_id, OLD.product_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dni_refresh_cost ON debit_note_items;
CREATE TRIGGER tg_dni_refresh_cost
    AFTER INSERT OR DELETE OR UPDATE OF quantity_returned, grn_item_id ON debit_note_items
    FOR EACH ROW EXECUTE FUNCTION trg_dni_refresh_cost();

CREATE OR REPLACE FUNCTION trg_dn_refresh_cost() RETURNS TRIGGER AS $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT DISTINCT product_id FROM debit_note_items
              WHERE debit_note_id = NEW.debit_note_id LOOP
        PERFORM refresh_product_cost(r.product_id);
    END LOOP;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_refresh_cost ON debit_notes;
CREATE TRIGGER tg_dn_refresh_cost
    AFTER UPDATE OF status ON debit_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_refresh_cost();

-- And any movement of stock. Selling the oldest units leaves a
-- dearer mix behind, so the average has to rise.
CREATE OR REPLACE FUNCTION trg_products_refresh_cost() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_product_cost(NEW.product_id);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_products_refresh_cost ON products;
CREATE TRIGGER tg_products_refresh_cost
    AFTER UPDATE OF stock_quantity ON products
    FOR EACH ROW EXECUTE FUNCTION trg_products_refresh_cost();

-- ─── Correct what is already on the books ───────────────────
DO $$
DECLARE
    r       RECORD;
    v       RECORD;
    changed INTEGER := 0;
BEGIN
    FOR r IN SELECT DISTINCT gi.product_id
               FROM grn_items gi
               JOIN grns g ON g.grn_id = gi.grn_id
              WHERE g.status <> 'cancelled'
    LOOP
        SELECT * INTO v FROM product_stock_cost(r.product_id);
        IF v.avg_cost IS NOT NULL
           AND (SELECT cost_price FROM products WHERE product_id = r.product_id)
               IS DISTINCT FROM ROUND(v.avg_cost, 2) THEN
            changed := changed + 1;
        END IF;
        PERFORM refresh_product_cost(r.product_id);
    END LOOP;
    RAISE NOTICE 'Cost price corrected on % product(s) from what was actually paid.', changed;
END $$;

COMMIT;
