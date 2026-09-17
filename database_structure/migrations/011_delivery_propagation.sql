-- ============================================================
--  Migration 011 — Delivery status propagation & invoice lock
-- ------------------------------------------------------------
--  Three refinements to the Phase 3 delivery workflow:
--
--    1. Proforma invoices carry a delivery status of their own,
--       so the whole quote → proforma → invoice chain shows the
--       same delivery progress.
--    2. Delivery progress now propagates along the quote chain,
--       not just the sales-order chain. An invoice raised from a
--       proforma reports what has actually been delivered.
--    3. A posted delivery note that belongs to an invoice can no
--       longer be cancelled. Once the customer has been billed,
--       reversing the delivery is a credit-note matter, which is
--       a later phase.
--
--  Additive and re-runnable.
-- ============================================================

BEGIN;

-- ─── 1. Proformas track delivery too ────────────────────────

ALTER TABLE proforma_invoices
    ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(25) NOT NULL DEFAULT 'not_delivered';

ALTER TABLE proforma_invoices DROP CONSTRAINT IF EXISTS ck_pi_delivery_status;
ALTER TABLE proforma_invoices ADD  CONSTRAINT ck_pi_delivery_status
    CHECK (delivery_status IN ('not_delivered','partially_delivered','fully_delivered'));

-- ─── 2. Propagate progress along the quote chain ────────────
-- Replaces the Phase 3 version. Sales-order behaviour is
-- unchanged; what is new is that a quote's delivery progress now
-- flows to its proformas and to any invoice raised from them.

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
               status = CASE
                   WHEN status IN ('invoiced','closed','cancelled','draft') THEN status
                   WHEN dstatus = 'fully_delivered'     THEN 'fully_delivered'
                   WHEN dstatus = 'partially_delivered' THEN 'partially_delivered'
                   ELSE 'confirmed'
               END
         WHERE sales_order_id = p_so_id;

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

        dstatus := COALESCE(dstatus, 'not_delivered');

        UPDATE quotes SET delivery_status = dstatus WHERE quote_id = p_quote_id;

        -- The proformas raised from this quote show the same progress.
        UPDATE proforma_invoices
           SET delivery_status = dstatus
         WHERE quote_id = p_quote_id;

        -- …and so do the invoices raised from that quote, whether they
        -- came straight from it or through a proforma. Invoices backed
        -- by a sales order are driven by the branch above instead.
        UPDATE invoices i
           SET delivery_status = dstatus
         WHERE i.sales_order_id IS NULL
           AND (
                i.quote_id = p_quote_id
                OR EXISTS (
                     SELECT 1 FROM proforma_invoices pi
                      WHERE pi.pi_id = i.pi_id
                        AND pi.quote_id = p_quote_id
                   )
               );
    END IF;
END;
$$ LANGUAGE plpgsql;

-- ─── 3. An invoiced delivery note is final ──────────────────
-- Cancelling a posted note reverses stock. Once the goods are on
-- an invoice the customer has been billed for them, so the
-- correction belongs on a credit note rather than by unwinding
-- the delivery. Refused here so no code path can bypass it.

CREATE OR REPLACE FUNCTION trg_dn_guard_posted() RETURNS TRIGGER AS $$
DECLARE
    inv_status TEXT;
    inv_number TEXT;
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

    -- Posted → anything else, while a live invoice covers these goods.
    -- The invoice may be attached to the note directly, or reach it
    -- through the sales order or the quote → proforma chain, so all
    -- three routes are checked.
    IF OLD.status = 'posted' AND NEW.status <> 'posted' THEN
        SELECT i.status, i.invoice_number INTO inv_status, inv_number
          FROM invoices i
          LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
         WHERE i.status <> 'cancelled'
           AND (
                 i.invoice_id     = OLD.invoice_id
              OR (OLD.sales_order_id IS NOT NULL AND i.sales_order_id = OLD.sales_order_id)
              OR (OLD.quote_id IS NOT NULL AND (i.quote_id = OLD.quote_id OR pi.quote_id = OLD.quote_id))
               )
         LIMIT 1;

        IF inv_number IS NOT NULL THEN
            RAISE EXCEPTION
                'These goods are invoiced on %. Raise a credit note instead of reversing the delivery.',
                inv_number
                USING ERRCODE = 'check_violation';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_guard_posted ON delivery_notes;
CREATE TRIGGER tg_dn_guard_posted
    BEFORE UPDATE ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_guard_posted();

-- ─── Backfill ───────────────────────────────────────────────
-- Bring existing documents in line with the rules above.

DO $$
DECLARE q RECORD;
BEGIN
    FOR q IN SELECT DISTINCT quote_id FROM delivery_notes WHERE quote_id IS NOT NULL LOOP
        PERFORM trg_refresh_delivery_progress(q.quote_id, NULL);
    END LOOP;
    FOR q IN SELECT DISTINCT sales_order_id FROM delivery_notes WHERE sales_order_id IS NOT NULL LOOP
        PERFORM trg_refresh_delivery_progress(NULL, q.sales_order_id);
    END LOOP;
END $$;

COMMIT;
