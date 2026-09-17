-- ============================================================
--  019 — Delivery status across the whole document chain
-- ------------------------------------------------------------
--  A delivery note raised against a proforma updated nothing.
--
--  Migration 014 let a note hang off a proforma (delivery_notes
--  .pi_id), but the propagation written in 010 and 011 only ever
--  took a quote id and a sales-order id. A note whose only origin
--  was a proforma therefore reached no document at all: the
--  proforma stayed "not delivered", and so did the invoice raised
--  from it, however much stock had gone out.
--
--  The direct-invoice path was wrong in a second way. It set
--  'partially_delivered' the moment any posted note existed and
--  never compared quantities, so an invoice delivered in full
--  never said so.
--
--  Both are replaced here by one rule, applied to every document
--  on the chain:
--
--      nothing delivered            → not_delivered
--      every line delivered in full → fully_delivered
--      anything in between          → partially_delivered
--
--  and one definition of which notes count towards a document:
--  those pointing at it directly, and those pointing at any
--  document it descends from. A proforma is covered by notes
--  raised against it or against its quote; an invoice by notes
--  against it, its proforma, its sales order or its quote — the
--  same walk the application does in resolve_delivery_source().
--
--  Existing rows are recomputed at the end, so a database that
--  has been quietly showing "not delivered" corrects itself on
--  migration.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ─── One proforma, from every note that covers it ───────────

CREATE OR REPLACE FUNCTION refresh_proforma_delivery(p_pi_id INTEGER)
RETURNS TEXT AS $$
DECLARE
    dstatus TEXT;
BEGIN
    IF p_pi_id IS NULL THEN
        RETURN NULL;
    END IF;

    UPDATE proforma_invoice_items pii
       SET delivered_quantity = COALESCE((
             SELECT SUM(dni.quantity)
               FROM delivery_note_items dni
               JOIN delivery_notes dn ON dn.dn_id = dni.dn_id
               JOIN proforma_invoices pi ON pi.pi_id = p_pi_id
              WHERE dn.status = 'posted'
                AND dni.product_id = pii.product_id
                AND (
                     dn.pi_id = pi.pi_id
                     OR (pi.quote_id IS NOT NULL AND dn.quote_id = pi.quote_id)
                    )), 0)
     WHERE pii.pi_id = p_pi_id;

    SELECT CASE
             WHEN COUNT(*) = 0                              THEN 'not_delivered'
             WHEN SUM(delivered_quantity) <= 0              THEN 'not_delivered'
             WHEN BOOL_AND(delivered_quantity >= quantity)  THEN 'fully_delivered'
             ELSE 'partially_delivered'
           END
      INTO dstatus
      FROM proforma_invoice_items WHERE pi_id = p_pi_id;

    dstatus := COALESCE(dstatus, 'not_delivered');
    UPDATE proforma_invoices SET delivery_status = dstatus WHERE pi_id = p_pi_id;
    RETURN dstatus;
END;
$$ LANGUAGE plpgsql;

-- ─── One invoice, from every note that covers it ────────────

CREATE OR REPLACE FUNCTION refresh_invoice_delivery(p_invoice_id INTEGER)
RETURNS TEXT AS $$
DECLARE
    dstatus TEXT;
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
              WHERE dn.status = 'posted'
                AND dni.product_id = ii.product_id
                AND (
                     dn.invoice_id = i.invoice_id
                     OR (i.pi_id           IS NOT NULL AND dn.pi_id           = i.pi_id)
                     OR (i.sales_order_id  IS NOT NULL AND dn.sales_order_id  = i.sales_order_id)
                     OR (i.quote_id        IS NOT NULL AND dn.quote_id        = i.quote_id)
                    )), 0)
     WHERE ii.invoice_id = p_invoice_id;

    SELECT CASE
             WHEN COUNT(*) = 0                              THEN 'not_delivered'
             WHEN SUM(delivered_quantity) <= 0              THEN 'not_delivered'
             WHEN BOOL_AND(delivered_quantity >= quantity)  THEN 'fully_delivered'
             ELSE 'partially_delivered'
           END
      INTO dstatus
      FROM invoice_items WHERE invoice_id = p_invoice_id;

    dstatus := COALESCE(dstatus, 'not_delivered');
    UPDATE invoices SET delivery_status = dstatus WHERE invoice_id = p_invoice_id;
    RETURN dstatus;
END;
$$ LANGUAGE plpgsql;

-- ─── Everything a single note touches ───────────────────────
--  Given one delivery note, refresh every document it can affect:
--  its sales order and quote as before, plus every proforma and
--  invoice downstream of any of its origins.

CREATE OR REPLACE FUNCTION refresh_delivery_for_note(p_dn_id INTEGER)
RETURNS VOID AS $$
DECLARE
    dn      RECORD;
    dstatus TEXT;
    rec     RECORD;
BEGIN
    SELECT * INTO dn FROM delivery_notes WHERE dn_id = p_dn_id;
    IF NOT FOUND THEN
        RETURN;
    END IF;

    -- ── Sales order ──
    IF dn.sales_order_id IS NOT NULL THEN
        UPDATE sales_order_items soi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes d ON d.dn_id = dni.dn_id
                  WHERE d.sales_order_id = dn.sales_order_id
                    AND d.status = 'posted'
                    AND dni.product_id = soi.product_id), 0)
         WHERE soi.sales_order_id = dn.sales_order_id;

        SELECT CASE
                 WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM sales_order_items WHERE sales_order_id = dn.sales_order_id;
        dstatus := COALESCE(dstatus, 'not_delivered');

        UPDATE sales_orders
           SET delivery_status = dstatus,
               -- Delivery only moves the lifecycle while the order is
               -- open; invoiced, closed and cancelled are terminal.
               status = CASE
                   WHEN status IN ('invoiced','closed','cancelled','draft') THEN status
                   WHEN dstatus = 'fully_delivered'     THEN 'fully_delivered'
                   WHEN dstatus = 'partially_delivered' THEN 'partially_delivered'
                   ELSE 'confirmed'
               END
         WHERE sales_order_id = dn.sales_order_id;
    END IF;

    -- ── Quote ──
    IF dn.quote_id IS NOT NULL THEN
        UPDATE quote_items qi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes d ON d.dn_id = dni.dn_id
                  WHERE d.quote_id = dn.quote_id
                    AND d.status = 'posted'
                    AND dni.product_id = qi.product_id), 0)
         WHERE qi.quote_id = dn.quote_id;

        SELECT CASE
                 WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM quote_items WHERE quote_id = dn.quote_id;

        UPDATE quotes SET delivery_status = COALESCE(dstatus, 'not_delivered')
         WHERE quote_id = dn.quote_id;
    END IF;

    -- ── Every proforma downstream of this note ──
    FOR rec IN
        SELECT DISTINCT pi.pi_id
          FROM proforma_invoices pi
         WHERE pi.pi_id = dn.pi_id
            OR (dn.quote_id IS NOT NULL AND pi.quote_id = dn.quote_id)
    LOOP
        PERFORM refresh_proforma_delivery(rec.pi_id);
    END LOOP;

    -- ── Every invoice downstream of this note ──
    --  Directly attached, raised from the same proforma, or backed
    --  by the same sales order or quote. This is the step the old
    --  code was missing for proforma-sourced notes.
    FOR rec IN
        SELECT DISTINCT i.invoice_id
          FROM invoices i
         WHERE i.invoice_id = dn.invoice_id
            OR (dn.pi_id          IS NOT NULL AND i.pi_id          = dn.pi_id)
            OR (dn.sales_order_id IS NOT NULL AND i.sales_order_id = dn.sales_order_id)
            OR (dn.quote_id       IS NOT NULL AND i.quote_id       = dn.quote_id)
            OR (dn.quote_id       IS NOT NULL AND i.pi_id IN (
                    SELECT pi.pi_id FROM proforma_invoices pi WHERE pi.quote_id = dn.quote_id))
    LOOP
        PERFORM refresh_invoice_delivery(rec.invoice_id);
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- ─── Wire it to the delivery note ───────────────────────────

CREATE OR REPLACE FUNCTION trg_dn_refresh_progress() RETURNS TRIGGER AS $$
BEGIN
    -- On delete the note is already gone, so the recompute has to
    -- run against what remains — hence OLD here.
    PERFORM refresh_delivery_for_note(COALESCE(NEW.dn_id, OLD.dn_id));
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

-- Re-pointing a note matters as much as posting one: converting a
-- sales order to an invoice attaches its notes to the new invoice,
-- and that invoice has to pick the delivery up.
DROP TRIGGER IF EXISTS tg_dn_refresh_progress ON delivery_notes;
CREATE TRIGGER tg_dn_refresh_progress
    AFTER INSERT
       OR UPDATE OF status, invoice_id, pi_id, sales_order_id, quote_id
       ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_refresh_progress();

-- The old invoice-only trigger set 'partially_delivered' on sight
-- of any posted note and never reached 'fully_delivered'. It is
-- folded into the function above.
DROP TRIGGER IF EXISTS tg_dn_refresh_invoice ON delivery_notes;
DROP FUNCTION IF EXISTS trg_dn_refresh_invoice();

-- Lines can still change while a note is a draft, and a draft can
-- be deleted outright. Both alter what has been delivered once the
-- note is posted, so both recompute.
CREATE OR REPLACE FUNCTION trg_dn_items_refresh() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_delivery_for_note(COALESCE(NEW.dn_id, OLD.dn_id));
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_items_refresh ON delivery_note_items;
CREATE TRIGGER tg_dn_items_refresh
    AFTER INSERT OR UPDATE OR DELETE ON delivery_note_items
    FOR EACH ROW EXECUTE FUNCTION trg_dn_items_refresh();

-- A deleted note must not leave its quantities behind.
CREATE OR REPLACE FUNCTION trg_dn_deleted_refresh() RETURNS TRIGGER AS $$
DECLARE
    rec RECORD;
BEGIN
    IF OLD.quote_id IS NOT NULL THEN
        UPDATE quote_items qi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes d ON d.dn_id = dni.dn_id
                  WHERE d.quote_id = OLD.quote_id AND d.status = 'posted'
                    AND dni.product_id = qi.product_id), 0)
         WHERE qi.quote_id = OLD.quote_id;
    END IF;

    FOR rec IN
        SELECT DISTINCT pi.pi_id FROM proforma_invoices pi
         WHERE pi.pi_id = OLD.pi_id
            OR (OLD.quote_id IS NOT NULL AND pi.quote_id = OLD.quote_id)
    LOOP
        PERFORM refresh_proforma_delivery(rec.pi_id);
    END LOOP;

    FOR rec IN
        SELECT DISTINCT i.invoice_id FROM invoices i
         WHERE i.invoice_id = OLD.invoice_id
            OR (OLD.pi_id          IS NOT NULL AND i.pi_id          = OLD.pi_id)
            OR (OLD.sales_order_id IS NOT NULL AND i.sales_order_id = OLD.sales_order_id)
            OR (OLD.quote_id       IS NOT NULL AND i.quote_id       = OLD.quote_id)
    LOOP
        PERFORM refresh_invoice_delivery(rec.invoice_id);
    END LOOP;

    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_deleted_refresh ON delivery_notes;
CREATE TRIGGER tg_dn_deleted_refresh
    AFTER DELETE ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_deleted_refresh();

-- ─── Correct the rows already in the database ───────────────
--  Anything delivered against a proforma has been reporting "not
--  delivered" since migration 014. Recomputing every proforma and
--  invoice puts them right without anyone re-entering a thing.

DO $$
DECLARE
    rec RECORD;
BEGIN
    FOR rec IN SELECT pi_id FROM proforma_invoices LOOP
        PERFORM refresh_proforma_delivery(rec.pi_id);
    END LOOP;

    FOR rec IN SELECT invoice_id FROM invoices LOOP
        PERFORM refresh_invoice_delivery(rec.invoice_id);
    END LOOP;
END $$;
