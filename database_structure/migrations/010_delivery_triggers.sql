-- ============================================================
--  Migration 010 — Phase 3: Business-rule triggers
-- ------------------------------------------------------------
--  Enforces at the database level the rules that must hold no
--  matter which code path (UI, API, future portal, manual SQL)
--  writes the data:
--
--    1. A delivery note may only come from an accepted quote or
--       a confirmed sales order.
--    2. A posted delivery note is immutable.
--    3. Posting moves stock; stock may never go negative.
--    4. Delivered quantity may never exceed the ordered quantity.
--    5. Delivery progress and document statuses maintain
--       themselves from the posted delivery notes.
--    6. Every stock movement writes an inventory transaction.
--    7. updated_at maintains itself.
--
--  All functions are CREATE OR REPLACE and all triggers are
--  dropped-then-created, so the migration is safely re-runnable.
-- ============================================================

BEGIN;

-- ─── Generic: keep updated_at fresh ─────────────────────────

CREATE OR REPLACE FUNCTION trg_touch_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at := CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['delivery_notes','delivery_note_items','warehouses',
                             'quotes','sales_orders','invoices','products']
    LOOP
        IF EXISTS (SELECT 1 FROM information_schema.tables
                    WHERE table_schema='public' AND table_name=t) THEN
            EXECUTE format('DROP TRIGGER IF EXISTS tg_%1$s_touch ON %1$I', t);
            EXECUTE format(
                'CREATE TRIGGER tg_%1$s_touch BEFORE UPDATE ON %1$I
                 FOR EACH ROW EXECUTE FUNCTION trg_touch_updated_at()', t);
        END IF;
    END LOOP;
END $$;

-- ─── 1. Delivery note source must be deliverable ────────────
-- Only an accepted quote, or a sales order that is confirmed or
-- already part-delivered, may be delivered against. Draft, sent,
-- rejected and expired quotes are refused outright.

CREATE OR REPLACE FUNCTION trg_dn_validate_source() RETURNS TRIGGER AS $$
DECLARE
    src_status TEXT;
BEGIN
    IF NEW.quote_id IS NOT NULL THEN
        SELECT status INTO src_status FROM quotes WHERE quote_id = NEW.quote_id;
        IF src_status IS NULL THEN
            RAISE EXCEPTION 'Quote % does not exist.', NEW.quote_id;
        END IF;
        IF src_status NOT IN ('accepted','converted') THEN
            RAISE EXCEPTION
                'A delivery note can only be raised against an accepted quote (quote is "%").', src_status
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    IF NEW.sales_order_id IS NOT NULL THEN
        SELECT status INTO src_status FROM sales_orders WHERE sales_order_id = NEW.sales_order_id;
        IF src_status IS NULL THEN
            RAISE EXCEPTION 'Sales order % does not exist.', NEW.sales_order_id;
        END IF;
        IF src_status NOT IN ('confirmed','partially_delivered','fully_delivered','invoiced') THEN
            RAISE EXCEPTION
                'A delivery note can only be raised against a confirmed sales order (order is "%").', src_status
                USING ERRCODE = 'check_violation';
        END IF;

        -- Keep the Invoice ↔ Delivery Note relationship intact for notes
        -- raised after the order was invoiced (brief §6), whichever code
        -- path created them.
        IF NEW.invoice_id IS NULL THEN
            SELECT converted_invoice_id INTO NEW.invoice_id
              FROM sales_orders WHERE sales_order_id = NEW.sales_order_id;
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_validate_source ON delivery_notes;
CREATE TRIGGER tg_dn_validate_source
    BEFORE INSERT OR UPDATE OF quote_id, sales_order_id ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_validate_source();

-- ─── 2. A posted delivery note is immutable ─────────────────
-- Once stock has moved, the note is a historical record. Only the
-- transition to 'cancelled' (which reverses the stock) is allowed.

CREATE OR REPLACE FUNCTION trg_dn_guard_posted() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status = 'posted' AND NEW.status = 'posted' THEN
        IF NEW.quote_id       IS DISTINCT FROM OLD.quote_id
        OR NEW.sales_order_id IS DISTINCT FROM OLD.sales_order_id
        OR NEW.customer_id    IS DISTINCT FROM OLD.customer_id
        OR NEW.warehouse_id   IS DISTINCT FROM OLD.warehouse_id THEN
            RAISE EXCEPTION 'A posted delivery note cannot be re-pointed; cancel it instead.'
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
        RAISE EXCEPTION 'A cancelled delivery note cannot be reopened.'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_guard_posted ON delivery_notes;
CREATE TRIGGER tg_dn_guard_posted
    BEFORE UPDATE ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_guard_posted();

-- Lines may not be touched once the note is posted.
CREATE OR REPLACE FUNCTION trg_dn_items_guard_posted() RETURNS TRIGGER AS $$
DECLARE
    parent_status TEXT;
    parent        INTEGER;
BEGIN
    parent := COALESCE(NEW.dn_id, OLD.dn_id);
    SELECT status INTO parent_status FROM delivery_notes WHERE dn_id = parent;
    IF parent_status IN ('posted','cancelled') THEN
        RAISE EXCEPTION 'Lines cannot be changed on a % delivery note.', parent_status
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_items_guard ON delivery_note_items;
CREATE TRIGGER tg_dn_items_guard
    BEFORE INSERT OR UPDATE OR DELETE ON delivery_note_items
    FOR EACH ROW EXECUTE FUNCTION trg_dn_items_guard_posted();

-- ─── 3/4. Over-delivery guard ───────────────────────────────
-- Compares what this note delivers against what the source
-- document still owes. Runs when the note is posted, so a draft
-- can be edited freely up to the moment it is committed.

CREATE OR REPLACE FUNCTION trg_dn_check_over_delivery(p_dn_id INTEGER) RETURNS VOID AS $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT dni.product_id,
               dni.quantity                        AS delivering,
               COALESCE(src.ordered, 0)            AS ordered,
               COALESCE(src.delivered, 0)          AS already,
               p.name                              AS product_name
          FROM delivery_note_items dni
          JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
          JOIN products p ON p.product_id = dni.product_id
          LEFT JOIN LATERAL (
                SELECT CASE WHEN dn.sales_order_id IS NOT NULL
                            THEN (SELECT soi.quantity FROM sales_order_items soi
                                   WHERE soi.sales_order_id = dn.sales_order_id
                                     AND soi.product_id = dni.product_id)
                            ELSE (SELECT qi.quantity FROM quote_items qi
                                   WHERE qi.quote_id = dn.quote_id
                                     AND qi.product_id = dni.product_id)
                       END AS ordered,
                       CASE WHEN dn.sales_order_id IS NOT NULL
                            THEN (SELECT soi.delivered_quantity FROM sales_order_items soi
                                   WHERE soi.sales_order_id = dn.sales_order_id
                                     AND soi.product_id = dni.product_id)
                            ELSE (SELECT qi.delivered_quantity FROM quote_items qi
                                   WHERE qi.quote_id = dn.quote_id
                                     AND qi.product_id = dni.product_id)
                       END AS delivered
          ) src ON TRUE
         WHERE dni.dn_id = p_dn_id
    LOOP
        IF r.ordered = 0 THEN
            RAISE EXCEPTION '% is not on the originating document.', r.product_name
                USING ERRCODE = 'check_violation';
        END IF;
        IF r.already + r.delivering > r.ordered THEN
            RAISE EXCEPTION
                'Over-delivery of %: ordered %, already delivered %, this note adds %.',
                r.product_name, r.ordered, r.already, r.delivering
                USING ERRCODE = 'check_violation';
        END IF;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- ─── 5/6. Posting moves stock and writes the ledger ─────────
-- The single place where sales-side stock leaves the building.
-- Cancelling a posted note reverses every movement symmetrically.

CREATE OR REPLACE FUNCTION trg_dn_apply_stock() RETURNS TRIGGER AS $$
DECLARE
    r          RECORD;
    new_bal    NUMERIC(12,3);
    wh         INTEGER;
    direction  INTEGER;
    mv_type    TEXT;
    actor      INTEGER;
BEGIN
    -- Only act on the draft→posted and posted→cancelled edges.
    IF NEW.status = OLD.status THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'draft' AND NEW.status = 'posted' THEN
        direction := -1; mv_type := 'delivery'; actor := NEW.posted_by;
        PERFORM trg_dn_check_over_delivery(NEW.dn_id);
    ELSIF OLD.status = 'posted' AND NEW.status = 'cancelled' THEN
        direction := 1;  mv_type := 'delivery_reversal'; actor := NEW.cancelled_by;
    ELSE
        RETURN NEW;   -- draft→cancelled: nothing ever moved
    END IF;

    SELECT COALESCE(NEW.warehouse_id,
                    (SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1))
      INTO wh;

    FOR r IN
        SELECT dni.product_id, dni.quantity
          FROM delivery_note_items dni
         WHERE dni.dn_id = NEW.dn_id
         ORDER BY dni.product_id      -- stable order avoids deadlocks
    LOOP
        -- Lock the product row, then move stock.
        UPDATE products
           SET stock_quantity = stock_quantity + (direction * r.quantity)
         WHERE product_id = r.product_id
        RETURNING stock_quantity INTO new_bal;

        -- Rule: stock may never go negative.
        IF new_bal < 0 THEN
            RAISE EXCEPTION
                'Insufficient stock for "%": short by %.',
                (SELECT name FROM products WHERE product_id = r.product_id),
                ABS(new_bal)
                USING ERRCODE = 'check_violation';
        END IF;

        INSERT INTO inventory_transactions
            (product_id, warehouse_id, quantity, movement_type,
             reference_table, reference_id, reference_number, balance_after, created_by)
        VALUES
            (r.product_id, wh, direction * r.quantity, mv_type,
             'delivery_notes', NEW.dn_id, NEW.dn_number, new_bal, actor);
    END LOOP;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_apply_stock ON delivery_notes;
CREATE TRIGGER tg_dn_apply_stock
    AFTER UPDATE OF status ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_apply_stock();

-- ─── 5. Delivered quantities & document statuses ────────────
-- Recomputed from the posted delivery notes, so the figures can
-- never drift no matter how the notes were created or cancelled.

CREATE OR REPLACE FUNCTION trg_refresh_delivery_progress(
    p_quote_id INTEGER, p_so_id INTEGER
) RETURNS VOID AS $$
DECLARE
    dstatus TEXT;
BEGIN
    IF p_so_id IS NOT NULL THEN
        UPDATE sales_order_items soi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
                  WHERE dn.sales_order_id = p_so_id
                    AND dn.status = 'posted'
                    AND dni.product_id = soi.product_id), 0)
         WHERE soi.sales_order_id = p_so_id;

        SELECT CASE
                 WHEN SUM(delivered_quantity) <= 0 THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM sales_order_items WHERE sales_order_id = p_so_id;

        UPDATE sales_orders
           SET delivery_status = COALESCE(dstatus, 'not_delivered'),
               -- Only reflect delivery in the lifecycle while the order
               -- is still open; invoiced/closed/cancelled are terminal.
               status = CASE
                   WHEN status IN ('invoiced','closed','cancelled','draft') THEN status
                   WHEN dstatus = 'fully_delivered'     THEN 'fully_delivered'
                   WHEN dstatus = 'partially_delivered' THEN 'partially_delivered'
                   ELSE 'confirmed'
               END
         WHERE sales_order_id = p_so_id;

        -- Mirror onto any invoice raised from this order.
        UPDATE invoices
           SET delivery_status = COALESCE(dstatus, 'not_delivered')
         WHERE sales_order_id = p_so_id;
    END IF;

    IF p_quote_id IS NOT NULL THEN
        UPDATE quote_items qi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
                  WHERE dn.quote_id = p_quote_id
                    AND dn.status = 'posted'
                    AND dni.product_id = qi.product_id), 0)
         WHERE qi.quote_id = p_quote_id;

        SELECT CASE
                 WHEN SUM(delivered_quantity) <= 0 THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM quote_items WHERE quote_id = p_quote_id;

        UPDATE quotes SET delivery_status = COALESCE(dstatus, 'not_delivered')
         WHERE quote_id = p_quote_id;
    END IF;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trg_dn_refresh_progress() RETURNS TRIGGER AS $$
BEGIN
    PERFORM trg_refresh_delivery_progress(NEW.quote_id, NEW.sales_order_id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_refresh_progress ON delivery_notes;
CREATE TRIGGER tg_dn_refresh_progress
    AFTER UPDATE OF status ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_refresh_progress();

-- ─── Invoice delivery status for direct invoices ────────────
-- An invoice raised straight from a proforma has no sales order;
-- its delivery status follows the notes pointed at the invoice.

CREATE OR REPLACE FUNCTION trg_dn_refresh_invoice() RETURNS TRIGGER AS $$
DECLARE
    dstatus TEXT;
BEGIN
    IF NEW.invoice_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT CASE WHEN COUNT(*) FILTER (WHERE dn.status = 'posted') = 0
                THEN 'not_delivered' ELSE 'partially_delivered' END
      INTO dstatus
      FROM delivery_notes dn WHERE dn.invoice_id = NEW.invoice_id;

    UPDATE invoices SET delivery_status = COALESCE(dstatus,'not_delivered')
     WHERE invoice_id = NEW.invoice_id
       AND sales_order_id IS NULL;   -- SO-backed invoices are driven above
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_refresh_invoice ON delivery_notes;
CREATE TRIGGER tg_dn_refresh_invoice
    AFTER UPDATE OF status ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_refresh_invoice();

-- ─── Stock floor, belt and braces ───────────────────────────
-- Any path that would drive stock negative is refused, not just
-- the delivery path.

CREATE OR REPLACE FUNCTION trg_products_no_negative_stock() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.stock_quantity < 0 THEN
        RAISE EXCEPTION 'Stock for "%" cannot go negative (attempted %).',
            NEW.name, NEW.stock_quantity
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_products_no_negative_stock ON products;
CREATE TRIGGER tg_products_no_negative_stock
    BEFORE UPDATE OF stock_quantity ON products
    FOR EACH ROW EXECUTE FUNCTION trg_products_no_negative_stock();

COMMIT;
