-- ============================================================
--  030 — A document's delivery status follows its own lines
-- ------------------------------------------------------------
--  Deliver a quote, then raise the invoice, and the invoice read
--  "Not delivered" for ever — while offering to create a
--  delivery note for goods that had already gone out.
--
--  ── Why ─────────────────────────────────────────────────────
--  Migration 025 made the delivery note the authority and wired
--  every recomputation to changes on the *note*. Nothing was
--  wired to changes on the document's own **lines**.
--
--  That is fine when the note comes last. It is wrong whenever
--  the paperwork comes last, which is the ordinary way round for
--  a customer who takes the goods and is invoiced afterwards:
--
--    1. INSERT INTO invoices …            header only, no lines
--    2. tg_invoice_link_dns fires, finds the delivery note
--       raised against the quote, and back-links it
--    3. that UPDATE fires the note refresh, which computes the
--       invoice's status from invoice_items —
--       *there are none yet* — so COUNT(*) = 0 → not_delivered
--    4. the lines are inserted a moment later, and nothing
--       recomputes anything ever again
--
--  The answer was not wrong when it was worked out. It was
--  worked out too early, and then frozen.
--
--  A proforma raised after a delivery had the same hole and got
--  away with it by luck: nothing recomputed it at creation
--  either, but raising an invoice from it later cascaded back
--  through the note and fixed it. Raise a proforma and stop, and
--  it stayed wrong.
--
--  ── The rule ────────────────────────────────────────────────
--  Delivery status is derived from two things — what the note
--  delivered, and what the document asked for. A change to
--  either has to recompute it. Migration 025 covered the first
--  half; this covers the second.
--
--  ── Also ────────────────────────────────────────────────────
--  refresh_invoice_delivery now follows a note to an invoice
--  through the proforma's quote as well:
--
--      dn.quote_id → pi.quote_id → i.pi_id
--
--  It used to rely on dn.invoice_id having been back-filled by
--  trg_link_delivery_notes_to_invoice, which only fills a note
--  whose invoice_id is still NULL. A quote invoiced twice — a
--  part invoice, then the rest — left the second invoice with no
--  route back to the note at all.
--
--  Existing rows are recomputed at the end, so a database
--  already carrying the contradiction corrects itself.
--
--  Idempotent: safe to re-run.
-- ============================================================

BEGIN;

-- ─── Follow the note to the invoice through the proforma ────
CREATE OR REPLACE FUNCTION refresh_invoice_delivery(p_invoice_id INTEGER)
RETURNS TEXT AS $$
DECLARE
    dstatus     TEXT;
    has_partial BOOLEAN;
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
                     -- quote → proforma → invoice, the ordinary chain
                     OR (pi.quote_id       IS NOT NULL AND dn.quote_id        = pi.quote_id)
                    )), 0)
     WHERE ii.invoice_id = p_invoice_id;

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

    SELECT CASE
             WHEN COUNT(*) = 0                             THEN 'not_delivered'
             WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
             WHEN BOOL_AND(delivered_quantity >= quantity)
                  AND NOT has_partial                      THEN 'fully_delivered'
             ELSE 'partially_delivered'
           END
      INTO dstatus
      FROM invoice_items WHERE invoice_id = p_invoice_id;

    dstatus := COALESCE(dstatus, 'not_delivered');
    UPDATE invoices SET delivery_status = dstatus
     WHERE invoice_id = p_invoice_id AND delivery_status IS DISTINCT FROM dstatus;
    RETURN dstatus;
END;
$$ LANGUAGE plpgsql;

-- ─── Lines changed: recompute the document that owns them ───
--  A header with no lines is a document that has not been
--  written yet, and "no lines" reads as not_delivered. Waiting
--  for the lines is the whole point.

CREATE OR REPLACE FUNCTION trg_invoice_items_refresh_delivery() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_invoice_delivery(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_invoice_items_refresh_delivery ON invoice_items;
CREATE TRIGGER tg_invoice_items_refresh_delivery
    AFTER INSERT OR DELETE OR UPDATE OF product_id, quantity ON invoice_items
    FOR EACH ROW EXECUTE FUNCTION trg_invoice_items_refresh_delivery();

CREATE OR REPLACE FUNCTION trg_pi_items_refresh_delivery() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_proforma_delivery(COALESCE(NEW.pi_id, OLD.pi_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_pi_items_refresh_delivery ON proforma_invoice_items;
CREATE TRIGGER tg_pi_items_refresh_delivery
    AFTER INSERT OR DELETE OR UPDATE OF product_id, quantity ON proforma_invoice_items
    FOR EACH ROW EXECUTE FUNCTION trg_pi_items_refresh_delivery();

-- ─── A header pointed at a new source has to recompute too ──
--  Setting pi_id or quote_id on an invoice changes which notes
--  can reach it. tg_invoice_link_dns already back-links the
--  notes; this makes the invoice re-read them once its own lines
--  are in place.

CREATE OR REPLACE FUNCTION trg_pi_refresh_delivery() RETURNS TRIGGER AS $$
BEGIN
    PERFORM refresh_proforma_delivery(NEW.pi_id);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_pi_refresh_delivery ON proforma_invoices;
CREATE TRIGGER tg_pi_refresh_delivery
    AFTER INSERT OR UPDATE OF quote_id ON proforma_invoices
    FOR EACH ROW EXECUTE FUNCTION trg_pi_refresh_delivery();

-- ─── Quote and sales-order lines, for the same reason ───────
CREATE OR REPLACE FUNCTION trg_source_items_refresh_delivery() RETURNS TRIGGER AS $$
DECLARE
    v_id  INTEGER;
    dstat TEXT;
BEGIN
    IF TG_TABLE_NAME = 'quote_items' THEN
        v_id := COALESCE(NEW.quote_id, OLD.quote_id);

        UPDATE quote_items qi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes d ON d.dn_id = dni.dn_id
                  WHERE dn_effective_state(d.status, d.delivery_status,
                                           d.delivery_status_override) <> 'not_delivered'
                    AND d.quote_id = v_id
                    AND dni.product_id = qi.product_id), 0)
         WHERE qi.quote_id = v_id;

        SELECT CASE
                 WHEN COUNT(*) = 0                             THEN 'not_delivered'
                 WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstat FROM quote_items WHERE quote_id = v_id;

        UPDATE quotes SET delivery_status = COALESCE(dstat, 'not_delivered')
         WHERE quote_id = v_id
           AND delivery_status IS DISTINCT FROM COALESCE(dstat, 'not_delivered');
    ELSE
        v_id := COALESCE(NEW.sales_order_id, OLD.sales_order_id);

        UPDATE sales_order_items soi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes d ON d.dn_id = dni.dn_id
                  WHERE dn_effective_state(d.status, d.delivery_status,
                                           d.delivery_status_override) <> 'not_delivered'
                    AND d.sales_order_id = v_id
                    AND dni.product_id = soi.product_id), 0)
         WHERE soi.sales_order_id = v_id;

        SELECT CASE
                 WHEN COUNT(*) = 0                             THEN 'not_delivered'
                 WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstat FROM sales_order_items WHERE sales_order_id = v_id;

        UPDATE sales_orders SET delivery_status = COALESCE(dstat, 'not_delivered')
         WHERE sales_order_id = v_id
           AND delivery_status IS DISTINCT FROM COALESCE(dstat, 'not_delivered');
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_quote_items_refresh_delivery ON quote_items;
CREATE TRIGGER tg_quote_items_refresh_delivery
    AFTER INSERT OR DELETE OR UPDATE OF product_id, quantity ON quote_items
    FOR EACH ROW EXECUTE FUNCTION trg_source_items_refresh_delivery();

DROP TRIGGER IF EXISTS tg_so_items_refresh_delivery ON sales_order_items;
CREATE TRIGGER tg_so_items_refresh_delivery
    AFTER INSERT OR DELETE OR UPDATE OF product_id, quantity ON sales_order_items
    FOR EACH ROW EXECUTE FUNCTION trg_source_items_refresh_delivery();

-- ─── Correct everything already recorded ────────────────────
DO $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT dn_id FROM delivery_notes LOOP
        PERFORM refresh_delivery_for_note(r.dn_id);
    END LOOP;
    FOR r IN SELECT pi_id FROM proforma_invoices LOOP
        PERFORM refresh_proforma_delivery(r.pi_id);
    END LOOP;
    FOR r IN SELECT invoice_id FROM invoices LOOP
        PERFORM refresh_invoice_delivery(r.invoice_id);
    END LOOP;
END $$;

COMMIT;
