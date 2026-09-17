-- ============================================================
--  034 — Services are sold, not stocked
-- ------------------------------------------------------------
--  The business does not only sell cameras. It installs them,
--  it transports them, it comes back and configures them. All
--  of that goes on the same quote and the same invoice as the
--  hardware, and none of it sits on a shelf.
--
--  ── Why a flag on products, and not a second table ──────────
--  Because a service line on a quote is the same line as a
--  hardware line: a description, a quantity, a unit price, a
--  discount, a total. Everything that already carries a
--  product_id — quote_items, proforma_invoice_items,
--  sales_order_items, invoice_items, credit_note_items — would
--  need a second nullable foreign key and a CASE in every join
--  to answer "what was sold?". One column on products answers
--  it instead, and every document, export, PDF and report keeps
--  working with no change at all.
--
--  What it costs is this migration: everything that assumed a
--  product is a physical thing has to be told otherwise.
--
--  ── The rule ────────────────────────────────────────────────
--  A service is sold and invoiced exactly like goods, and is
--  invisible to everything about stock:
--
--    · it has no stock, and cannot be given any
--    · it is never counted in a valuation or a stock take
--    · it never appears on a delivery note
--    · it never holds a document at "not delivered"
--
--  That last one matters most. Delivery status asks whether
--  anything is still owed to the customer in goods. Labour is
--  not owed in goods, so a service line must not be able to
--  keep an invoice open for ever — which is exactly what it
--  would do, since a service can never be delivered on a note.
--
--  A document whose lines are *all* services therefore settles
--  at 'fully_delivered': there is nothing left to send. That is
--  deliberately an existing status rather than a fourth one.
--  Roughly a dozen places in the application ask
--  `delivery_status <> 'fully_delivered'` to mean "still needs
--  delivering", and a new status would have read as outstanding
--  in every one of them — recreating, for services, the exact
--  "still shows not delivered" fault that migrations 030 and
--  031 were written to fix.
-- ============================================================

BEGIN;

-- ─── 1. What kind of thing is this? ─────────────────────────
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS product_type VARCHAR(20) NOT NULL DEFAULT 'goods';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conrelid = to_regclass('products')
           AND conname  = 'products_product_type_check'
    ) THEN
        ALTER TABLE products
            ADD CONSTRAINT products_product_type_check
            CHECK (product_type IN ('goods', 'service'));
    END IF;
END $$;

-- Every report that values stock, counts it or reorders it now
-- filters on this, so it is worth an index.
CREATE INDEX IF NOT EXISTS idx_products_type ON products (product_type);

-- ─── 2. A service has no stock, and cannot be given any ─────
--  Not a CHECK, because the delivery trigger and the stock take
--  both write stock_quantity blind. Silently holding services at
--  zero is kinder than an error from a code path that had no
--  business touching a service in the first place — and the
--  paths that *should* refuse are guarded explicitly below.
CREATE OR REPLACE FUNCTION trg_products_service_has_no_stock() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.product_type = 'service' THEN
        NEW.stock_quantity         := 0;
        NEW.low_quantity_threshold := 0;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_products_service_has_no_stock ON products;
CREATE TRIGGER tg_products_service_has_no_stock
    BEFORE INSERT OR UPDATE ON products
    FOR EACH ROW EXECUTE FUNCTION trg_products_service_has_no_stock();

-- Anything already flagged as a service by this migration being
-- re-run keeps its promise.
UPDATE products SET stock_quantity = 0, low_quantity_threshold = 0
 WHERE product_type = 'service'
   AND (stock_quantity <> 0 OR low_quantity_threshold <> 0);

-- ─── 3. A service never goes on a delivery note ─────────────
--  The form does not offer them, but a delivery note is the
--  document that moves stock, and the database is where that
--  rule belongs. Without this the stock trigger would try to
--  decrement a service and fail with "Insufficient stock",
--  which is a true statement about the wrong problem.
CREATE OR REPLACE FUNCTION trg_dni_no_services() RETURNS TRIGGER AS $$
DECLARE
    v_name TEXT;
BEGIN
    SELECT name INTO v_name FROM products
     WHERE product_id = NEW.product_id AND product_type = 'service';

    IF v_name IS NOT NULL THEN
        RAISE EXCEPTION
            '"%" is a service, so it cannot go on a delivery note. Services are invoiced, not delivered.',
            v_name
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS tg_dni_no_services ON delivery_note_items;
CREATE TRIGGER tg_dni_no_services
    BEFORE INSERT OR UPDATE OF product_id ON delivery_note_items
    FOR EACH ROW EXECUTE FUNCTION trg_dni_no_services();

-- ─── 4. Delivery status counts goods, and only goods ────────
--  Four functions, one change in each: the lines that decide
--  the status are restricted to goods, and a document with
--  lines but no goods lines has nothing outstanding.

CREATE OR REPLACE FUNCTION refresh_invoice_delivery(p_invoice_id INTEGER)
RETURNS TEXT AS $$
DECLARE
    dstatus     TEXT;
    has_partial BOOLEAN;
    any_out     BOOLEAN;
    any_deliv   BOOLEAN;
    n_lines     INTEGER;
    n_goods     INTEGER;
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

    SELECT COUNT(*) INTO n_lines FROM invoice_items WHERE invoice_id = p_invoice_id;

    -- outstanding = invoiced − delivered − credited, over goods
    SELECT COUNT(*),
           BOOL_OR(ii.quantity - ii.delivered_quantity - ii.credited_quantity > 0.0005),
           COALESCE(SUM(ii.delivered_quantity), 0) > 0
      INTO n_goods, any_out, any_deliv
      FROM invoice_items ii
      JOIN products p ON p.product_id = ii.product_id
     WHERE ii.invoice_id = p_invoice_id AND p.product_type = 'goods';

    dstatus := CASE
        -- No lines at all: a header without a body has delivered nothing.
        WHEN n_lines = 0                          THEN 'not_delivered'
        -- Lines, but not one of them is a physical thing. Labour,
        -- transport, a site visit — there is nothing to send, so
        -- nothing is outstanding and no delivery note is owed.
        WHEN n_goods = 0                          THEN 'fully_delivered'
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

CREATE OR REPLACE FUNCTION refresh_proforma_delivery(p_pi_id INTEGER)
RETURNS TEXT AS $$
DECLARE
    dstatus     TEXT;
    has_partial BOOLEAN;
    n_lines     INTEGER;
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

    SELECT COUNT(*) INTO n_lines FROM proforma_invoice_items WHERE pi_id = p_pi_id;

    SELECT CASE
             WHEN n_lines = 0                              THEN 'not_delivered'
             WHEN COUNT(*) = 0                             THEN 'fully_delivered'
             WHEN SUM(pii.delivered_quantity) <= 0         THEN 'not_delivered'
             WHEN BOOL_AND(pii.delivered_quantity >= pii.quantity)
                  AND NOT has_partial                      THEN 'fully_delivered'
             ELSE 'partially_delivered'
           END
      INTO dstatus
      FROM proforma_invoice_items pii
      JOIN products p ON p.product_id = pii.product_id
     WHERE pii.pi_id = p_pi_id AND p.product_type = 'goods';

    dstatus := COALESCE(dstatus, CASE WHEN n_lines = 0 THEN 'not_delivered' ELSE 'fully_delivered' END);
    UPDATE proforma_invoices SET delivery_status = dstatus WHERE pi_id = p_pi_id;
    RETURN dstatus;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION trg_source_items_refresh_delivery() RETURNS TRIGGER AS $$
DECLARE
    v_id    INTEGER;
    dstat   TEXT;
    n_lines INTEGER;
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

        SELECT COUNT(*) INTO n_lines FROM quote_items WHERE quote_id = v_id;

        SELECT CASE
                 WHEN n_lines = 0                                  THEN 'not_delivered'
                 WHEN COUNT(*) = 0                                 THEN 'fully_delivered'
                 WHEN SUM(qi.delivered_quantity) <= 0              THEN 'not_delivered'
                 WHEN BOOL_AND(qi.delivered_quantity >= qi.quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstat
          FROM quote_items qi
          JOIN products p ON p.product_id = qi.product_id
         WHERE qi.quote_id = v_id AND p.product_type = 'goods';

        dstat := COALESCE(dstat, CASE WHEN n_lines = 0 THEN 'not_delivered' ELSE 'fully_delivered' END);
        UPDATE quotes SET delivery_status = dstat
         WHERE quote_id = v_id AND delivery_status IS DISTINCT FROM dstat;
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

        SELECT COUNT(*) INTO n_lines FROM sales_order_items WHERE sales_order_id = v_id;

        SELECT CASE
                 WHEN n_lines = 0                                    THEN 'not_delivered'
                 WHEN COUNT(*) = 0                                   THEN 'fully_delivered'
                 WHEN SUM(soi.delivered_quantity) <= 0               THEN 'not_delivered'
                 WHEN BOOL_AND(soi.delivered_quantity >= soi.quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstat
          FROM sales_order_items soi
          JOIN products p ON p.product_id = soi.product_id
         WHERE soi.sales_order_id = v_id AND p.product_type = 'goods';

        dstat := COALESCE(dstat, CASE WHEN n_lines = 0 THEN 'not_delivered' ELSE 'fully_delivered' END);
        UPDATE sales_orders SET delivery_status = dstat
         WHERE sales_order_id = v_id AND delivery_status IS DISTINCT FROM dstat;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- The posted-note path from migration 010, same treatment.
CREATE OR REPLACE FUNCTION trg_refresh_delivery_progress(
    p_quote_id INTEGER, p_so_id INTEGER
) RETURNS VOID AS $$
DECLARE
    dstatus TEXT;
    n_lines INTEGER;
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

        SELECT COUNT(*) INTO n_lines FROM sales_order_items WHERE sales_order_id = p_so_id;

        SELECT CASE
                 WHEN COUNT(*) = 0                                     THEN 'fully_delivered'
                 WHEN SUM(soi.delivered_quantity) <= 0                 THEN 'not_delivered'
                 WHEN BOOL_AND(soi.delivered_quantity >= soi.quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM sales_order_items soi
          JOIN products p ON p.product_id = soi.product_id
         WHERE soi.sales_order_id = p_so_id AND p.product_type = 'goods';

        IF n_lines = 0 THEN
            dstatus := 'not_delivered';
        END IF;
        dstatus := COALESCE(dstatus, 'not_delivered');

        UPDATE sales_orders
           SET delivery_status = dstatus,
               status = CASE
                   WHEN status IN ('invoiced','closed','cancelled','draft') THEN status
                   WHEN dstatus = 'fully_delivered'     THEN 'fully_delivered'
                   WHEN dstatus = 'partially_delivered' THEN 'partially_delivered'
                   ELSE 'confirmed'
               END
         WHERE sales_order_id = p_so_id;

        UPDATE invoices SET delivery_status = dstatus WHERE sales_order_id = p_so_id;
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

        SELECT COUNT(*) INTO n_lines FROM quote_items WHERE quote_id = p_quote_id;

        SELECT CASE
                 WHEN COUNT(*) = 0                                   THEN 'fully_delivered'
                 WHEN SUM(qi.delivered_quantity) <= 0                THEN 'not_delivered'
                 WHEN BOOL_AND(qi.delivered_quantity >= qi.quantity) THEN 'fully_delivered'
                 ELSE 'partially_delivered'
               END
          INTO dstatus
          FROM quote_items qi
          JOIN products p ON p.product_id = qi.product_id
         WHERE qi.quote_id = p_quote_id AND p.product_type = 'goods';

        IF n_lines = 0 THEN
            dstatus := 'not_delivered';
        END IF;

        UPDATE quotes SET delivery_status = COALESCE(dstatus, 'not_delivered')
         WHERE quote_id = p_quote_id;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- ─── 5. Re-decide every open document ───────────────────────
--  Nothing is a service yet, so this changes nothing today. It
--  is here so that flagging an existing product as a service
--  later is a one-line job: the statuses that product is
--  holding open get recomputed by the triggers above as soon as
--  its lines are touched, and this catches the rest.
DO $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT invoice_id FROM invoices WHERE status NOT IN ('draft', 'cancelled') LOOP
        PERFORM refresh_invoice_delivery(r.invoice_id);
    END LOOP;
    FOR r IN SELECT pi_id FROM proforma_invoices LOOP
        PERFORM refresh_proforma_delivery(r.pi_id);
    END LOOP;
END $$;

COMMIT;
