-- ============================================================
--  025 — The delivery note decides whether goods were delivered
-- ------------------------------------------------------------
--  An invoice could read "Delivered" while the delivery note it
--  was reading said "Not delivered". Both figures were computed,
--  neither was wrong on its own terms, and the two terms were
--  different — which is the worst kind of disagreement, because
--  nothing looks broken until somebody reads both.
--
--  ── The two facts a note carries ────────────────────────────
--    status           draft | posted | cancelled
--                     the document's own life. Posting is what
--                     releases stock.
--    delivery_status  not_delivered | partially | fully
--                     whether the goods reached the customer,
--                     with delivery_status_override as the human
--                     override for what the quantities cannot
--                     know: a refused load, a short shipment
--                     accepted as complete.
--
--  ── What was wrong ──────────────────────────────────────────
--  1. Nothing maintained a note's delivery_status unless it hung
--     off a quote or a sales order. trg_refresh_dn_fulfilment
--     took a quote id and a sales-order id and nothing else, so a
--     note raised against an invoice or a proforma — the common
--     case since migration 014 — sat at its column default,
--     'not_delivered', for ever.
--
--  2. The parent documents never looked at it. refresh_invoice
--     _delivery() and refresh_proforma_delivery() counted a note's
--     quantities on dn.status = 'posted' alone. So the moment a
--     note was posted its invoice said delivered, whatever the
--     note itself said.
--
--  3. Marking a note by hand changed nothing upstream. The
--     trigger fired on status and the origin columns, not on
--     delivery_status_override, so the override was invisible to
--     the chain it was meant to correct.
--
--  ── The rule now ────────────────────────────────────────────
--  The note is the authority, and it is honest by default:
--
--      draft or cancelled           → delivers nothing
--      posted                       → delivers what is on it
--      posted, but marked otherwise → whatever the mark says
--
--  and a parent counts a note's lines only when that note has
--  actually delivered something. A parent cannot read "fully
--  delivered" while a note covering it is only partly delivered,
--  however the quantities add up — the goods are out there
--  somewhere, and saying otherwise is how a customer gets chased
--  for something they never received.
--
--  Existing rows are recomputed at the end, so a database already
--  showing the contradiction corrects itself on migration.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ─── What a note has actually delivered ─────────────────────

CREATE OR REPLACE FUNCTION dn_effective_state(
    p_status TEXT, p_computed TEXT, p_override TEXT
) RETURNS TEXT AS $$
    SELECT CASE
        WHEN p_status IS DISTINCT FROM 'posted' THEN 'not_delivered'
        ELSE COALESCE(p_override, p_computed, 'not_delivered')
    END;
$$ LANGUAGE sql IMMUTABLE;

-- ─── A note's own status follows its own posting ────────────
--  For every note, not only the ones hanging off a sales order.
--  The override is never touched: it is the human's veto and
--  outlives any recomputation.

CREATE OR REPLACE FUNCTION refresh_dn_delivery_status(p_dn_id INTEGER)
RETURNS VOID AS $$
BEGIN
    UPDATE delivery_notes
       SET delivery_status = CASE WHEN status = 'posted'
                                  THEN 'fully_delivered'
                                  ELSE 'not_delivered' END
     WHERE dn_id = p_dn_id
       AND delivery_status IS DISTINCT FROM
           (CASE WHEN status = 'posted' THEN 'fully_delivered' ELSE 'not_delivered' END);
END;
$$ LANGUAGE plpgsql;

-- The old mirror is retired. It copied a sales order's delivery
-- status onto its notes, which is backwards — the notes are what
-- the order's status is computed *from* — and it is why a note
-- with no sales order was never maintained at all. Kept as a
-- function so migration 012 and 014 still resolve their calls;
-- it now simply recomputes each note from its own posting state.
CREATE OR REPLACE FUNCTION trg_refresh_dn_fulfilment(
    p_quote_id INTEGER, p_so_id INTEGER
) RETURNS VOID AS $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT dn_id FROM delivery_notes
         WHERE (p_so_id    IS NOT NULL AND sales_order_id = p_so_id)
            OR (p_quote_id IS NOT NULL AND quote_id = p_quote_id AND sales_order_id IS NULL)
    LOOP
        PERFORM refresh_dn_delivery_status(r.dn_id);
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- ─── One proforma, from the notes that actually delivered ───

CREATE OR REPLACE FUNCTION refresh_proforma_delivery(p_pi_id INTEGER)
RETURNS TEXT AS $$
DECLARE
    dstatus     TEXT;
    has_partial BOOLEAN;
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
              WHERE dn_effective_state(dn.status, dn.delivery_status,
                                       dn.delivery_status_override) <> 'not_delivered'
                AND dni.product_id = pii.product_id
                AND (
                     dn.pi_id = pi.pi_id
                     OR (pi.quote_id IS NOT NULL AND dn.quote_id = pi.quote_id)
                    )), 0)
     WHERE pii.pi_id = p_pi_id;

    SELECT EXISTS (
        SELECT 1
          FROM delivery_notes dn
          JOIN proforma_invoices pi ON pi.pi_id = p_pi_id
         WHERE dn_effective_state(dn.status, dn.delivery_status,
                                  dn.delivery_status_override) = 'partially_delivered'
           AND (dn.pi_id = pi.pi_id
                OR (pi.quote_id IS NOT NULL AND dn.quote_id = pi.quote_id))
    ) INTO has_partial;

    SELECT CASE
             WHEN COUNT(*) = 0                             THEN 'not_delivered'
             WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
             WHEN BOOL_AND(delivered_quantity >= quantity)
                  AND NOT has_partial                      THEN 'fully_delivered'
             ELSE 'partially_delivered'
           END
      INTO dstatus
      FROM proforma_invoice_items WHERE pi_id = p_pi_id;

    dstatus := COALESCE(dstatus, 'not_delivered');
    UPDATE proforma_invoices SET delivery_status = dstatus WHERE pi_id = p_pi_id;
    RETURN dstatus;
END;
$$ LANGUAGE plpgsql;

-- ─── One invoice, from the notes that actually delivered ────

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
              WHERE dn_effective_state(dn.status, dn.delivery_status,
                                       dn.delivery_status_override) <> 'not_delivered'
                AND dni.product_id = ii.product_id
                AND (
                     dn.invoice_id = i.invoice_id
                     OR (i.pi_id           IS NOT NULL AND dn.pi_id           = i.pi_id)
                     OR (i.sales_order_id  IS NOT NULL AND dn.sales_order_id  = i.sales_order_id)
                     OR (i.quote_id        IS NOT NULL AND dn.quote_id        = i.quote_id)
                    )), 0)
     WHERE ii.invoice_id = p_invoice_id;

    SELECT EXISTS (
        SELECT 1
          FROM delivery_notes dn
          JOIN invoices i ON i.invoice_id = p_invoice_id
         WHERE dn_effective_state(dn.status, dn.delivery_status,
                                  dn.delivery_status_override) = 'partially_delivered'
           AND (dn.invoice_id = i.invoice_id
                OR (i.pi_id          IS NOT NULL AND dn.pi_id          = i.pi_id)
                OR (i.sales_order_id IS NOT NULL AND dn.sales_order_id = i.sales_order_id)
                OR (i.quote_id       IS NOT NULL AND dn.quote_id       = i.quote_id))
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
    UPDATE invoices SET delivery_status = dstatus WHERE invoice_id = p_invoice_id;
    RETURN dstatus;
END;
$$ LANGUAGE plpgsql;

-- ─── Everything one note touches ────────────────────────────
--  Same walk as migration 019, with the same rule about which
--  notes count applied to the sales order and the quote too.

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
                  WHERE dn_effective_state(d.status, d.delivery_status,
                                           d.delivery_status_override) <> 'not_delivered'
                    AND d.sales_order_id = dn.sales_order_id
                    AND dni.product_id = soi.product_id), 0)
         WHERE soi.sales_order_id = dn.sales_order_id;

        SELECT CASE
                 WHEN COUNT(*) = 0                             THEN 'not_delivered'
                 WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM sales_order_items WHERE sales_order_id = dn.sales_order_id;

        UPDATE sales_orders
           SET delivery_status = COALESCE(dstatus, 'not_delivered')
         WHERE sales_order_id = dn.sales_order_id;
    END IF;

    -- ── Quote ──
    IF dn.quote_id IS NOT NULL THEN
        UPDATE quote_items qi
           SET delivered_quantity = COALESCE((
                 SELECT SUM(dni.quantity)
                   FROM delivery_note_items dni
                   JOIN delivery_notes d ON d.dn_id = dni.dn_id
                  WHERE dn_effective_state(d.status, d.delivery_status,
                                           d.delivery_status_override) <> 'not_delivered'
                    AND d.quote_id = dn.quote_id
                    AND dni.product_id = qi.product_id), 0)
         WHERE qi.quote_id = dn.quote_id;

        SELECT CASE
                 WHEN COUNT(*) = 0                             THEN 'not_delivered'
                 WHEN SUM(delivered_quantity) <= 0             THEN 'not_delivered'
                 WHEN BOOL_AND(delivered_quantity >= quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM quote_items WHERE quote_id = dn.quote_id;

        UPDATE quotes
           SET delivery_status = COALESCE(dstatus, 'not_delivered')
         WHERE quote_id = dn.quote_id;
    END IF;

    -- ── Every proforma this note can reach ──
    FOR rec IN
        SELECT DISTINCT pi.pi_id
          FROM proforma_invoices pi
         WHERE pi.pi_id = dn.pi_id
            OR (dn.quote_id IS NOT NULL AND pi.quote_id = dn.quote_id)
    LOOP
        PERFORM refresh_proforma_delivery(rec.pi_id);
    END LOOP;

    -- ── Every invoice this note can reach ──
    FOR rec IN
        SELECT DISTINCT i.invoice_id
          FROM invoices i
         WHERE i.invoice_id = dn.invoice_id
            OR (dn.pi_id          IS NOT NULL AND i.pi_id          = dn.pi_id)
            OR (dn.sales_order_id IS NOT NULL AND i.sales_order_id = dn.sales_order_id)
            OR (dn.quote_id       IS NOT NULL AND i.quote_id       = dn.quote_id)
    LOOP
        PERFORM refresh_invoice_delivery(rec.invoice_id);
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- ─── Keep a note's own status in step with its posting ──────
--  BEFORE, so the row about to be written already carries the
--  right computed value and the AFTER trigger below propagates
--  it in the same statement.

CREATE OR REPLACE FUNCTION trg_dn_sync_delivery_status() RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' OR NEW.status IS DISTINCT FROM OLD.status THEN
        NEW.delivery_status := CASE WHEN NEW.status = 'posted'
                                    THEN 'fully_delivered'
                                    ELSE 'not_delivered' END;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dn_sync_delivery_status ON delivery_notes;
CREATE TRIGGER tg_dn_sync_delivery_status
    BEFORE INSERT OR UPDATE OF status ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_sync_delivery_status();

-- ─── Propagate a hand-marked note too ───────────────────────
--  Migration 019 widened this to the origin columns. Marking a
--  note delivered or not delivered by hand is exactly the event
--  the chain most needs to hear about, and it was the one column
--  the trigger did not watch.

DROP TRIGGER IF EXISTS tg_dn_refresh_progress ON delivery_notes;
CREATE TRIGGER tg_dn_refresh_progress
    AFTER INSERT
       OR UPDATE OF status, delivery_status, delivery_status_override,
                    invoice_id, pi_id, sales_order_id, quote_id
       OR DELETE
       ON delivery_notes
    FOR EACH ROW EXECUTE FUNCTION trg_dn_refresh_progress();

-- ─── Bring every existing document in line ──────────────────

DO $$
DECLARE
    r RECORD;
BEGIN
    -- Notes first: the parents are computed from them.
    FOR r IN SELECT dn_id FROM delivery_notes LOOP
        PERFORM refresh_dn_delivery_status(r.dn_id);
    END LOOP;

    FOR r IN SELECT dn_id FROM delivery_notes LOOP
        PERFORM refresh_delivery_for_note(r.dn_id);
    END LOOP;

    -- Documents no note points at must read "not delivered"
    -- rather than keep whatever they were left with.
    FOR r IN SELECT invoice_id FROM invoices LOOP
        PERFORM refresh_invoice_delivery(r.invoice_id);
    END LOOP;

    FOR r IN SELECT pi_id FROM proforma_invoices LOOP
        PERFORM refresh_proforma_delivery(r.pi_id);
    END LOOP;
END $$;
