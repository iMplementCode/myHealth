-- ============================================================
--  031 — Goods that were credited are no longer owed
-- ------------------------------------------------------------
--  An invoice for two products. One went out on a delivery note.
--  The other was credited — the customer is not having it and is
--  not paying for it. Nothing is outstanding, and the invoice
--  read "Partially Delivered" for ever, still offering to raise
--  a delivery note for goods nobody is ever going to send.
--
--  ── Why ─────────────────────────────────────────────────────
--  Delivery status compared delivered_quantity against the
--  quantity **invoiced**, and a credit note has nothing to do
--  with the quantity invoiced. So a credited line stayed
--  outstanding for ever: it can never be delivered, and it was
--  the only thing stopping the invoice from closing.
--
--  ── The rule ────────────────────────────────────────────────
--  What is still owed to the customer, line by line:
--
--      outstanding = invoiced − delivered − credited
--
--  A credit note is the customer and the business agreeing that
--  part of the sale is not happening. It cancels the obligation
--  to deliver in exactly the way it cancels the obligation to
--  pay — migration 027 already did the money half:
--
--      owed = invoiced − received − credited + refunded
--
--  This is the same sentence about goods.
--
--  ── What it does not do ─────────────────────────────────────
--  It does not touch delivered_quantity. Goods delivered and
--  then returned were still delivered; the return note brings
--  the stock back and the credit note settles the money, and
--  rewriting history to say the goods never went would lose the
--  delivery that actually happened.
--
--  It also will not call an invoice "fully delivered" when
--  nothing was ever delivered. An invoice credited in full has
--  no outstanding lines, but saying it was delivered is a lie on
--  a document. It stays "not delivered", which is true, and the
--  pages stop offering a delivery note because there is nothing
--  left outstanding — a different question, asked separately.
--
--  Existing rows are recomputed at the end.
--  Idempotent: safe to re-run.
-- ============================================================

BEGIN;

-- ─── What each invoice line has had credited ────────────────
--  Mirrors delivered_quantity: kept on the line so every page
--  can read "invoiced, delivered, credited" without three
--  different joins inventing three different answers.
ALTER TABLE invoice_items
    ADD COLUMN IF NOT EXISTS credited_quantity NUMERIC(12,3) NOT NULL DEFAULT 0;

COMMENT ON COLUMN invoice_items.credited_quantity IS
    'Quantity credited back on approved credit notes. Not owed to the customer and never will be.';

CREATE OR REPLACE FUNCTION refresh_invoice_credited(p_invoice_id INTEGER)
RETURNS VOID AS $$
BEGIN
    IF p_invoice_id IS NULL THEN
        RETURN;
    END IF;

    UPDATE invoice_items ii
       SET credited_quantity = COALESCE((
             SELECT SUM(cni.quantity)
               FROM credit_note_items cni
               JOIN credit_notes cn ON cn.credit_note_id = cni.credit_note_id
              WHERE cn.invoice_id = p_invoice_id
                AND cn.status = 'approved'
                AND cni.product_id = ii.product_id), 0)
     WHERE ii.invoice_id = p_invoice_id;
END;
$$ LANGUAGE plpgsql;

-- ─── Delivery status, net of what was credited ──────────────
CREATE OR REPLACE FUNCTION refresh_invoice_delivery(p_invoice_id INTEGER)
RETURNS TEXT AS $$
DECLARE
    dstatus     TEXT;
    has_partial BOOLEAN;
    any_out     BOOLEAN;
    any_deliv   BOOLEAN;
BEGIN
    IF p_invoice_id IS NULL THEN
        RETURN NULL;
    END IF;

    UPDATE invoice_items ii
       SET delivered_quantity = COALESCE((
             SELECT SUM(dni.quantity)
               FROM delivery_note_items dni
               JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
               JOIN invoices i ON i.invoice_id = p_invoice_id
               LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
              WHERE dn_effective_state(dn.status, dn.delivery_status,
                                       dn.delivery_status_override) <> 'not_delivered'
                AND dni.product_id = ii.product_id
                AND (
                     dn.invoice_id = i.invoice_id
                     OR (i.pi_id           IS NOT NULL AND dn.pi_id           = i.pi_id)
                     OR (i.sales_order_id  IS NOT NULL AND dn.sales_order_id  = i.sales_order_id)
                     OR (i.quote_id        IS NOT NULL AND dn.quote_id        = i.quote_id)
                     OR (pi.quote_id       IS NOT NULL AND dn.quote_id        = pi.quote_id)
                    )), 0)
     WHERE ii.invoice_id = p_invoice_id;

    PERFORM refresh_invoice_credited(p_invoice_id);

    SELECT EXISTS (
        SELECT 1
          FROM delivery_notes dn
          JOIN invoices i ON i.invoice_id = p_invoice_id
          LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
         WHERE dn_effective_state(dn.status, dn.delivery_status,
                                  dn.delivery_status_override) = 'partially_delivered'
           AND (dn.invoice_id = i.invoice_id
                OR (i.pi_id          IS NOT NULL AND dn.pi_id          = i.pi_id)
                OR (i.sales_order_id IS NOT NULL AND dn.sales_order_id = i.sales_order_id)
                OR (i.quote_id       IS NOT NULL AND dn.quote_id       = i.quote_id)
                OR (pi.quote_id      IS NOT NULL AND dn.quote_id       = pi.quote_id))
    ) INTO has_partial;

    -- outstanding = invoiced − delivered − credited
    SELECT BOOL_OR(quantity - delivered_quantity - credited_quantity > 0.0005),
           COALESCE(SUM(delivered_quantity), 0) > 0
      INTO any_out, any_deliv
      FROM invoice_items WHERE invoice_id = p_invoice_id;

    dstatus := CASE
        -- No lines at all: a header without a body has delivered nothing.
        WHEN any_out IS NULL                      THEN 'not_delivered'
        -- Nothing ever went out. True even when the whole invoice has
        -- been credited away — saying "delivered" there would be a lie.
        WHEN NOT any_deliv                        THEN 'not_delivered'
        -- Something went out and nothing is left owing. A note that
        -- only part-delivered its own lines still holds it open.
        WHEN NOT any_out AND NOT has_partial      THEN 'fully_delivered'
        ELSE 'partially_delivered'
    END;

    UPDATE invoices SET delivery_status = dstatus
     WHERE invoice_id = p_invoice_id AND delivery_status IS DISTINCT FROM dstatus;
    RETURN dstatus;
END;
$$ LANGUAGE plpgsql;

-- ─── A credit note changes what is owed, both ways ──────────
--  Approving one is the event that closes the delivery, and
--  cancelling one re-opens it.
CREATE OR REPLACE FUNCTION trg_cn_refresh_delivery() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_invoice_delivery(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_cn_refresh_delivery ON credit_notes;
CREATE TRIGGER tg_cn_refresh_delivery
    AFTER INSERT OR DELETE OR UPDATE OF status, invoice_id ON credit_notes
    FOR EACH ROW EXECUTE FUNCTION trg_cn_refresh_delivery();

CREATE OR REPLACE FUNCTION trg_cn_items_refresh_delivery() RETURNS TRIGGER AS $$
DECLARE v_inv INTEGER;
BEGIN
    SELECT invoice_id INTO v_inv FROM credit_notes
     WHERE credit_note_id = COALESCE(NEW.credit_note_id, OLD.credit_note_id);
    PERFORM refresh_invoice_delivery(v_inv);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_cn_items_refresh_delivery ON credit_note_items;
CREATE TRIGGER tg_cn_items_refresh_delivery
    AFTER INSERT OR DELETE OR UPDATE OF product_id, quantity ON credit_note_items
    FOR EACH ROW EXECUTE FUNCTION trg_cn_items_refresh_delivery();

-- ─── Correct everything already recorded ────────────────────
DO $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT invoice_id FROM invoices LOOP
        PERFORM refresh_invoice_delivery(r.invoice_id);
    END LOOP;
END $$;

COMMIT;
