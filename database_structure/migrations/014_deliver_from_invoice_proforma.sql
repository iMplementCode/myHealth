-- ============================================================
--  Migration 014 — Deliver directly from an invoice or proforma
-- ------------------------------------------------------------
--  Until now a delivery note could only hang off a quote or a
--  sales order, so an invoice or proforma raised without one had
--  no way to be delivered at all.
--
--  Both can now be delivery sources in their own right. Where an
--  upstream document exists the application still delivers
--  against that (see delivery_notes/create.php) so a single deal
--  is never counted twice; these columns carry the case where
--  the invoice or proforma *is* the origin.
--
--  Additive and re-runnable.
-- ============================================================

BEGIN;

-- ─── Proforma as a delivery source ──────────────────────────

ALTER TABLE delivery_notes ADD COLUMN IF NOT EXISTS pi_id INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_delivery_notes_pi') THEN
        ALTER TABLE delivery_notes ADD CONSTRAINT fk_delivery_notes_pi
            FOREIGN KEY (pi_id) REFERENCES proforma_invoices(pi_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_delivery_notes_pi ON delivery_notes (pi_id);

-- A note must still hang off exactly one origin — now one of four.
ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS ck_delivery_notes_source;
ALTER TABLE delivery_notes ADD  CONSTRAINT ck_delivery_notes_source
    CHECK (quote_id IS NOT NULL
        OR sales_order_id IS NOT NULL
        OR pi_id IS NOT NULL
        OR invoice_id IS NOT NULL);

-- ─── Delivered quantities on the billing documents ──────────

ALTER TABLE invoice_items
    ADD COLUMN IF NOT EXISTS delivered_quantity NUMERIC(12,3) NOT NULL DEFAULT 0;
ALTER TABLE proforma_invoice_items
    ADD COLUMN IF NOT EXISTS delivered_quantity NUMERIC(12,3) NOT NULL DEFAULT 0;

-- ─── Which document does a note deliver against? ────────────
-- Sales order first, then quote, then proforma, then invoice —
-- matching the order the application resolves them in.

CREATE OR REPLACE FUNCTION dn_source_of(p_dn delivery_notes)
RETURNS TABLE (kind TEXT, id INTEGER) AS $$
BEGIN
    IF p_dn.sales_order_id IS NOT NULL THEN
        RETURN QUERY SELECT 'sales_order'::TEXT, p_dn.sales_order_id;
    ELSIF p_dn.quote_id IS NOT NULL THEN
        RETURN QUERY SELECT 'quote'::TEXT, p_dn.quote_id;
    ELSIF p_dn.pi_id IS NOT NULL THEN
        RETURN QUERY SELECT 'proforma'::TEXT, p_dn.pi_id;
    ELSIF p_dn.invoice_id IS NOT NULL THEN
        RETURN QUERY SELECT 'invoice'::TEXT, p_dn.invoice_id;
    END IF;
END;
$$ LANGUAGE plpgsql STABLE;

-- ─── Over-delivery guard, extended to the new sources ───────

CREATE OR REPLACE FUNCTION trg_dn_check_over_delivery(p_dn_id INTEGER) RETURNS VOID AS $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT dni.product_id,
                dni.quantity                 AS delivering,
                COALESCE(src.ordered, 0)     AS ordered,
                COALESCE(src.delivered, 0)   AS already,
                p.name                       AS product_name
          FROM delivery_note_items dni
          JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
          JOIN products p ON p.product_id = dni.product_id
          LEFT JOIN LATERAL (
                SELECT
                  CASE
                    WHEN dn.sales_order_id IS NOT NULL THEN
                      (SELECT soi.quantity FROM sales_order_items soi
                        WHERE soi.sales_order_id = dn.sales_order_id AND soi.product_id = dni.product_id)
                    WHEN dn.quote_id IS NOT NULL THEN
                      (SELECT qi.quantity FROM quote_items qi
                        WHERE qi.quote_id = dn.quote_id AND qi.product_id = dni.product_id)
                    WHEN dn.pi_id IS NOT NULL THEN
                      (SELECT pii.quantity FROM proforma_invoice_items pii
                        WHERE pii.pi_id = dn.pi_id AND pii.product_id = dni.product_id)
                    ELSE
                      (SELECT ii.quantity FROM invoice_items ii
                        WHERE ii.invoice_id = dn.invoice_id AND ii.product_id = dni.product_id)
                  END AS ordered,
                  CASE
                    WHEN dn.sales_order_id IS NOT NULL THEN
                      (SELECT soi.delivered_quantity FROM sales_order_items soi
                        WHERE soi.sales_order_id = dn.sales_order_id AND soi.product_id = dni.product_id)
                    WHEN dn.quote_id IS NOT NULL THEN
                      (SELECT qi.delivered_quantity FROM quote_items qi
                        WHERE qi.quote_id = dn.quote_id AND qi.product_id = dni.product_id)
                    WHEN dn.pi_id IS NOT NULL THEN
                      (SELECT pii.delivered_quantity FROM proforma_invoice_items pii
                        WHERE pii.pi_id = dn.pi_id AND pii.product_id = dni.product_id)
                    ELSE
                      (SELECT ii.delivered_quantity FROM invoice_items ii
                        WHERE ii.invoice_id = dn.invoice_id AND ii.product_id = dni.product_id)
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

-- ─── Progress for invoice- and proforma-sourced notes ───────

CREATE OR REPLACE FUNCTION trg_refresh_direct_progress(
    p_pi_id INTEGER, p_invoice_id INTEGER
) RETURNS VOID AS $$
DECLARE
    dstatus TEXT;
BEGIN
    IF p_pi_id IS NOT NULL THEN
        UPDATE proforma_invoice_items pii
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
                  WHERE dn.pi_id = p_pi_id
                    AND dn.status = 'posted'
                    AND dni.product_id = pii.product_id), 0)
         WHERE pii.pi_id = p_pi_id;

        SELECT CASE
                 WHEN SUM(delivered_quantity) <= 0 THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM proforma_invoice_items WHERE pi_id = p_pi_id;

        dstatus := COALESCE(dstatus, 'not_delivered');
        UPDATE proforma_invoices SET delivery_status = dstatus WHERE pi_id = p_pi_id;
        UPDATE invoices SET delivery_status = dstatus
         WHERE pi_id = p_pi_id AND sales_order_id IS NULL;
    END IF;

    IF p_invoice_id IS NOT NULL THEN
        UPDATE invoice_items ii
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
                  WHERE dn.invoice_id = p_invoice_id
                    AND dn.quote_id IS NULL
                    AND dn.sales_order_id IS NULL
                    AND dn.pi_id IS NULL
                    AND dn.status = 'posted'
                    AND dni.product_id = ii.product_id), 0)
         WHERE ii.invoice_id = p_invoice_id;

        SELECT CASE
                 WHEN SUM(delivered_quantity) <= 0 THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM invoice_items WHERE invoice_id = p_invoice_id;

        UPDATE invoices SET delivery_status = COALESCE(dstatus, 'not_delivered')
         WHERE invoice_id = p_invoice_id
           AND sales_order_id IS NULL
           AND pi_id IS NULL
           AND quote_id IS NULL;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- ─── Wire the new sources into the existing refresh ─────────

CREATE OR REPLACE FUNCTION trg_dn_refresh_progress() RETURNS TRIGGER AS $$
BEGIN
    PERFORM trg_refresh_delivery_progress(NEW.quote_id, NEW.sales_order_id);
    PERFORM trg_refresh_dn_fulfilment(NEW.quote_id, NEW.sales_order_id);
    PERFORM trg_refresh_direct_progress(NEW.pi_id,
        CASE WHEN NEW.quote_id IS NULL AND NEW.sales_order_id IS NULL AND NEW.pi_id IS NULL
             THEN NEW.invoice_id ELSE NULL END);

    -- A note sourced straight from a proforma or invoice takes its
    -- fulfilment from that document.
    IF NEW.quote_id IS NULL AND NEW.sales_order_id IS NULL THEN
        UPDATE delivery_notes dn
           SET delivery_status = CASE WHEN dn.status = 'posted' THEN COALESCE((
                     SELECT pi.delivery_status FROM proforma_invoices pi WHERE pi.pi_id = dn.pi_id
                     UNION ALL
                     SELECT i.delivery_status FROM invoices i WHERE i.invoice_id = dn.invoice_id
                     LIMIT 1
                 ), 'not_delivered') ELSE 'not_delivered' END
         WHERE dn.dn_id = NEW.dn_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_refresh_progress ON delivery_notes;
CREATE TRIGGER tg_dn_refresh_progress
    AFTER UPDATE OF status ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_refresh_progress();

-- ─── Source validation for the new origins ──────────────────

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

    -- A cancelled proforma or invoice is not deliverable.
    IF NEW.pi_id IS NOT NULL THEN
        SELECT status INTO src_status FROM proforma_invoices WHERE pi_id = NEW.pi_id;
        IF src_status IS NULL THEN
            RAISE EXCEPTION 'Proforma % does not exist.', NEW.pi_id;
        END IF;
        IF src_status = 'cancelled' THEN
            RAISE EXCEPTION 'A cancelled proforma cannot be delivered.'
                USING ERRCODE = 'check_violation';
        END IF;
        -- Follow the proforma to its invoice when one exists.
        IF NEW.invoice_id IS NULL THEN
            SELECT converted_invoice_id INTO NEW.invoice_id
              FROM proforma_invoices WHERE pi_id = NEW.pi_id;
        END IF;
    END IF;

    IF NEW.invoice_id IS NOT NULL
       AND NEW.quote_id IS NULL AND NEW.sales_order_id IS NULL AND NEW.pi_id IS NULL THEN
        SELECT status INTO src_status FROM invoices WHERE invoice_id = NEW.invoice_id;
        IF src_status IS NULL THEN
            RAISE EXCEPTION 'Invoice % does not exist.', NEW.invoice_id;
        END IF;
        IF src_status = 'cancelled' THEN
            RAISE EXCEPTION 'A cancelled invoice cannot be delivered.'
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    -- Quote route: pick up an invoice already raised from this quote.
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
    BEFORE INSERT OR UPDATE OF quote_id, sales_order_id, pi_id ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_validate_source();

COMMIT;
