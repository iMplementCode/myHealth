-- ============================================================
--  Migration 012 — Delivery note ↔ invoice linking & fulfilment
-- ------------------------------------------------------------
--  1. When an invoice is raised, every delivery note covering the
--     same goods is attached to it — whether the note hangs off
--     the sales order, the quote, or the quote behind a proforma.
--     Previously only the sales-order route was linked, so notes
--     raised from a quote showed no invoice at all.
--
--  2. Delivery notes carry their own fulfilment state, so the
--     list can show whether a shipment completed the order or
--     only part of it, without opening the source document.
--
--  Additive, re-runnable, and backfills existing rows.
-- ============================================================

BEGIN;

-- ─── 1. Fulfilment state on the delivery note ───────────────
-- Derived from the source document, never typed in: it says what
-- the order looked like once this note was posted.

ALTER TABLE delivery_notes
    ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(25) NOT NULL DEFAULT 'not_delivered';

ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS ck_dn_delivery_status;
ALTER TABLE delivery_notes ADD  CONSTRAINT ck_dn_delivery_status
    CHECK (delivery_status IN ('not_delivered','partially_delivered','fully_delivered'));

CREATE INDEX IF NOT EXISTS ix_delivery_notes_delivery_status
    ON delivery_notes (delivery_status);

-- ─── 2. Attach delivery notes to their invoice ──────────────
-- Called whenever an invoice appears or gains a source reference.

CREATE OR REPLACE FUNCTION trg_link_delivery_notes_to_invoice(p_invoice_id INTEGER)
RETURNS VOID AS $$
DECLARE
    v_so    INTEGER;
    v_quote INTEGER;
BEGIN
    SELECT i.sales_order_id,
           COALESCE(i.quote_id, pi.quote_id)
      INTO v_so, v_quote
      FROM invoices i
      LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
     WHERE i.invoice_id = p_invoice_id;

    UPDATE delivery_notes dn
       SET invoice_id = p_invoice_id
     WHERE dn.invoice_id IS NULL
       AND dn.status <> 'cancelled'
       AND (
             (v_so    IS NOT NULL AND dn.sales_order_id = v_so)
          OR (v_quote IS NOT NULL AND dn.quote_id       = v_quote)
           );
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trg_invoice_link_dns() RETURNS TRIGGER AS $$
BEGIN
    PERFORM trg_link_delivery_notes_to_invoice(NEW.invoice_id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_invoice_link_dns ON invoices;
CREATE TRIGGER tg_invoice_link_dns
    AFTER INSERT OR UPDATE OF sales_order_id, quote_id, pi_id ON invoices
    FOR EACH ROW EXECUTE FUNCTION trg_invoice_link_dns();

-- A note created after the invoice already exists picks it up too.
-- Extends the Phase 3 source-validation trigger, which already fills
-- invoice_id from the sales order.

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

        IF NEW.invoice_id IS NULL THEN
            SELECT converted_invoice_id INTO NEW.invoice_id
              FROM sales_orders WHERE sales_order_id = NEW.sales_order_id;
        END IF;
    END IF;

    -- Quote route: pick up an invoice already raised from this quote,
    -- directly or through a proforma.
    IF NEW.invoice_id IS NULL AND NEW.quote_id IS NOT NULL THEN
        SELECT i.invoice_id INTO NEW.invoice_id
          FROM invoices i
          LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
         WHERE i.status <> 'cancelled'
           AND (i.quote_id = NEW.quote_id OR pi.quote_id = NEW.quote_id)
         ORDER BY i.invoice_id
         LIMIT 1;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_validate_source ON delivery_notes;
CREATE TRIGGER tg_dn_validate_source
    BEFORE INSERT OR UPDATE OF quote_id, sales_order_id ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_validate_source();

-- ─── 3. Keep the note's fulfilment state current ────────────
-- Recomputed alongside the source document's progress, so the two
-- can never disagree.

CREATE OR REPLACE FUNCTION trg_refresh_dn_fulfilment(
    p_quote_id INTEGER, p_so_id INTEGER
) RETURNS VOID AS $$
DECLARE
    src TEXT;
BEGIN
    IF p_so_id IS NOT NULL THEN
        SELECT delivery_status INTO src FROM sales_orders WHERE sales_order_id = p_so_id;
        UPDATE delivery_notes
           SET delivery_status = CASE WHEN status = 'posted'
                                      THEN COALESCE(src, 'not_delivered')
                                      ELSE 'not_delivered' END
         WHERE sales_order_id = p_so_id;
    END IF;

    IF p_quote_id IS NOT NULL THEN
        SELECT delivery_status INTO src FROM quotes WHERE quote_id = p_quote_id;
        UPDATE delivery_notes
           SET delivery_status = CASE WHEN status = 'posted'
                                      THEN COALESCE(src, 'not_delivered')
                                      ELSE 'not_delivered' END
         WHERE quote_id = p_quote_id
           AND sales_order_id IS NULL;
    END IF;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trg_dn_refresh_progress() RETURNS TRIGGER AS $$
BEGIN
    PERFORM trg_refresh_delivery_progress(NEW.quote_id, NEW.sales_order_id);
    PERFORM trg_refresh_dn_fulfilment(NEW.quote_id, NEW.sales_order_id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_refresh_progress ON delivery_notes;
CREATE TRIGGER tg_dn_refresh_progress
    AFTER UPDATE OF status ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_refresh_progress();

-- ─── Backfill ───────────────────────────────────────────────

DO $$
DECLARE r RECORD;
BEGIN
    -- Attach existing delivery notes to the invoices that bill them.
    FOR r IN SELECT invoice_id FROM invoices WHERE status <> 'cancelled' ORDER BY invoice_id LOOP
        PERFORM trg_link_delivery_notes_to_invoice(r.invoice_id);
    END LOOP;

    -- Bring fulfilment state in line with the source documents.
    FOR r IN SELECT DISTINCT quote_id FROM delivery_notes WHERE quote_id IS NOT NULL LOOP
        PERFORM trg_refresh_dn_fulfilment(r.quote_id, NULL);
    END LOOP;
    FOR r IN SELECT DISTINCT sales_order_id FROM delivery_notes WHERE sales_order_id IS NOT NULL LOOP
        PERFORM trg_refresh_dn_fulfilment(NULL, r.sales_order_id);
    END LOOP;
END $$;

COMMIT;
