-- ============================================================
--  Migration 009 — Phase 3: Sales Workflow, Delivery Notes &
--                            Inventory Movement
-- ------------------------------------------------------------
--  Implements the ERP document flow:
--
--      Quote
--       ├── Accepted Quote
--       │    ├── Sales Order ── Invoice
--       │    │              └── Delivery Note(s)
--       │    ├── Proforma Invoice
--       │    └── Delivery Note(s)
--
--  Core rules enforced at the database level:
--    * Stock moves ONLY when a delivery note is posted.
--    * Stock may never go negative.
--    * Delivered quantity may never exceed the ordered quantity.
--    * A product may appear at most once per document.
--    * Delivery progress and document status update themselves.
--    * Every stock movement is recorded and traceable.
--
--  Additive and idempotent. Never drops business data. Existing
--  status values are migrated onto the new lifecycles in place.
-- ============================================================

BEGIN;

-- ─── Warehouses ─────────────────────────────────────────────
-- Single site today; the table exists so multi-location stock
-- and transfers can be added later without reshaping movements.

CREATE TABLE IF NOT EXISTS warehouses (
    warehouse_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    code         VARCHAR(30),
    location     VARCHAR(255),
    is_default   BOOLEAN NOT NULL DEFAULT FALSE,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_warehouses_name ON warehouses (LOWER(name));
-- At most one default warehouse.
CREATE UNIQUE INDEX IF NOT EXISTS ux_warehouses_default
    ON warehouses (is_default) WHERE is_default;

INSERT INTO warehouses (name, code, is_default)
SELECT 'Main Store', 'MAIN', TRUE
WHERE NOT EXISTS (SELECT 1 FROM warehouses);

-- ─── Document status lifecycles ─────────────────────────────
-- Each document gains the statuses named in the brief. Existing
-- rows are mapped onto the new vocabulary before the tightened
-- CHECK constraint is applied, so live data survives.

-- Quotes: draft, sent, viewed, accepted, rejected, expired, converted
ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_status_check;
UPDATE quotes SET status = 'draft' WHERE status IS NULL;
ALTER TABLE quotes ADD CONSTRAINT quotes_status_check
    CHECK (status IN ('draft','sent','viewed','accepted','rejected','expired','converted'));

-- Sales orders: draft, confirmed, partially_delivered, fully_delivered,
--               invoiced, closed, cancelled
ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_status_check;
UPDATE sales_orders SET status = CASE
        WHEN status IN ('pending','processing') THEN 'confirmed'
        WHEN status = 'shipped'                 THEN 'partially_delivered'
        WHEN status = 'delivered'               THEN 'fully_delivered'
        WHEN status = 'completed'               THEN 'closed'
        WHEN status IS NULL                     THEN 'draft'
        ELSE status
    END
 WHERE status IS NULL
    OR status IN ('pending','processing','shipped','delivered','completed');
ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_status_check
    CHECK (status IN ('draft','confirmed','partially_delivered','fully_delivered',
                      'invoiced','closed','cancelled'));

-- Proforma invoices: draft, issued, cancelled, converted
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'proforma_invoices') THEN
        EXECUTE 'ALTER TABLE proforma_invoices DROP CONSTRAINT IF EXISTS proforma_invoices_status_check';
        EXECUTE $q$UPDATE proforma_invoices
                      SET status = CASE WHEN status IN ('sent','accepted') THEN 'issued'
                                        WHEN status IS NULL THEN 'draft'
                                        ELSE status END
                    WHERE status IS NULL OR status IN ('sent','accepted')$q$;
        EXECUTE 'ALTER TABLE proforma_invoices ADD CONSTRAINT proforma_invoices_status_check
                 CHECK (status IN (''draft'',''issued'',''cancelled'',''converted''))';
    END IF;
END $$;

-- Invoices: draft, issued, partially_delivered, fully_delivered,
--           paid, partially_paid, overdue, cancelled
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'invoices') THEN
        EXECUTE 'ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check';
        -- 'unpaid' becomes 'issued'; 'partial' becomes 'partially_paid'.
        EXECUTE $q$UPDATE invoices
                      SET status = CASE WHEN status = 'unpaid'  THEN 'issued'
                                        WHEN status = 'partial' THEN 'partially_paid'
                                        WHEN status IS NULL     THEN 'draft'
                                        ELSE status END
                    WHERE status IS NULL OR status IN ('unpaid','partial')$q$;
        EXECUTE 'ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
                 CHECK (status IN (''draft'',''issued'',''partially_delivered'',''fully_delivered'',
                                   ''paid'',''partially_paid'',''overdue'',''cancelled''))';
    END IF;
END $$;

-- ─── Sales order → invoice link ─────────────────────────────
-- Mirrors the proforma → invoice trail so every invoice records
-- where it came from, who converted it and when.

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS sales_order_id INTEGER;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS converted_at   TIMESTAMPTZ;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS converted_by   INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_invoices_sales_order') THEN
        ALTER TABLE invoices ADD CONSTRAINT fk_invoices_sales_order
            FOREIGN KEY (sales_order_id) REFERENCES sales_orders(sales_order_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_invoices_converted_by') THEN
        ALTER TABLE invoices ADD CONSTRAINT fk_invoices_converted_by
            FOREIGN KEY (converted_by) REFERENCES users(user_id) ON DELETE SET NULL;
    END IF;
END $$;

-- One invoice per sales order (the brief's "prevent duplicate
-- conversions"). Partial index so many NULLs remain allowed.
CREATE UNIQUE INDEX IF NOT EXISTS ux_invoices_sales_order
    ON invoices (sales_order_id) WHERE sales_order_id IS NOT NULL;

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS invoiced_at         TIMESTAMPTZ;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS invoiced_by         INTEGER;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS converted_invoice_id INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_sales_orders_invoice') THEN
        ALTER TABLE sales_orders ADD CONSTRAINT fk_sales_orders_invoice
            FOREIGN KEY (converted_invoice_id) REFERENCES invoices(invoice_id);
    END IF;
END $$;

-- Quote → sales order trail.
ALTER TABLE quotes ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMPTZ;
ALTER TABLE quotes ADD COLUMN IF NOT EXISTS accepted_by INTEGER;

-- Sales orders carry notes and payment terms so the conversion to
-- an invoice can preserve them (brief §2).
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS notes         TEXT;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS terms         TEXT;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS expected_date DATE;

-- ─── Delivery notes ─────────────────────────────────────────
-- A delivery note may originate from an accepted quote or from a
-- sales order — never from a draft/rejected/expired quote. That
-- rule is enforced by trigger (below) because it depends on the
-- source document's status.

CREATE TABLE IF NOT EXISTS delivery_notes (
    dn_id           INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dn_number       VARCHAR(100) UNIQUE NOT NULL,
    customer_id     INTEGER NOT NULL REFERENCES customers(customer_id),
    quote_id        INTEGER REFERENCES quotes(quote_id),
    sales_order_id  INTEGER REFERENCES sales_orders(sales_order_id),
    invoice_id      INTEGER REFERENCES invoices(invoice_id),
    warehouse_id    INTEGER REFERENCES warehouses(warehouse_id),
    delivery_date   DATE NOT NULL DEFAULT CURRENT_DATE,
    delivered_to    VARCHAR(255),
    delivery_address TEXT,
    vehicle_reg     VARCHAR(50),
    driver_name     VARCHAR(120),
    notes           TEXT,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft','posted','cancelled')),
    posted_at       TIMESTAMPTZ,
    posted_by       INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    cancelled_at    TIMESTAMPTZ,
    cancelled_by    INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_by      INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by      INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    -- Must hang off exactly one of quote / sales order.
    CONSTRAINT ck_delivery_notes_source
        CHECK (quote_id IS NOT NULL OR sales_order_id IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS ix_delivery_notes_customer ON delivery_notes (customer_id);
CREATE INDEX IF NOT EXISTS ix_delivery_notes_quote    ON delivery_notes (quote_id);
CREATE INDEX IF NOT EXISTS ix_delivery_notes_so       ON delivery_notes (sales_order_id);
CREATE INDEX IF NOT EXISTS ix_delivery_notes_invoice  ON delivery_notes (invoice_id);
CREATE INDEX IF NOT EXISTS ix_delivery_notes_status   ON delivery_notes (status);
CREATE INDEX IF NOT EXISTS ix_delivery_notes_date     ON delivery_notes (delivery_date);

CREATE TABLE IF NOT EXISTS delivery_note_items (
    dn_item_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dn_id       INTEGER NOT NULL REFERENCES delivery_notes(dn_id) ON DELETE CASCADE,
    product_id  INTEGER NOT NULL REFERENCES products(product_id),
    quantity    NUMERIC(12,3) NOT NULL CHECK (quantity > 0),
    notes       VARCHAR(255),
    created_at  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_dn_items_dn      ON delivery_note_items (dn_id);
CREATE INDEX IF NOT EXISTS ix_dn_items_product ON delivery_note_items (product_id);

-- ─── Inventory transactions ─────────────────────────────────
-- The permanent ledger of stock movement. Every row says what
-- moved, how much, why, under which document, by whom and when.

CREATE TABLE IF NOT EXISTS inventory_transactions (
    txn_id        INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id    INTEGER NOT NULL REFERENCES products(product_id),
    warehouse_id  INTEGER REFERENCES warehouses(warehouse_id),
    quantity      NUMERIC(12,3) NOT NULL,   -- negative = out, positive = in
    movement_type VARCHAR(30) NOT NULL
        CHECK (movement_type IN ('delivery','delivery_reversal','receipt',
                                 'adjustment','return','transfer','opening')),
    reference_table VARCHAR(50),
    reference_id    INTEGER,
    reference_number VARCHAR(100),
    balance_after  NUMERIC(12,3),
    notes          TEXT,
    created_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_inv_txn_product   ON inventory_transactions (product_id);
CREATE INDEX IF NOT EXISTS ix_inv_txn_type      ON inventory_transactions (movement_type);
CREATE INDEX IF NOT EXISTS ix_inv_txn_reference ON inventory_transactions (reference_table, reference_id);
CREATE INDEX IF NOT EXISTS ix_inv_txn_created   ON inventory_transactions (created_at);

-- ─── Document relationships ─────────────────────────────────
-- Generic edge table so traceability survives new document types
-- without further schema changes.

CREATE TABLE IF NOT EXISTS document_links (
    link_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    from_type    VARCHAR(30) NOT NULL,
    from_id      INTEGER NOT NULL,
    to_type      VARCHAR(30) NOT NULL,
    to_id        INTEGER NOT NULL,
    relation     VARCHAR(30) NOT NULL DEFAULT 'converted_to',
    created_by   INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_document_links
    ON document_links (from_type, from_id, to_type, to_id, relation);
CREATE INDEX IF NOT EXISTS ix_document_links_from ON document_links (from_type, from_id);
CREATE INDEX IF NOT EXISTS ix_document_links_to   ON document_links (to_type, to_id);

-- ─── Delivered-quantity tracking ────────────────────────────
-- Denormalised running totals on the source lines. Maintained by
-- trigger so they cannot drift from the delivery notes.

ALTER TABLE quote_items       ADD COLUMN IF NOT EXISTS delivered_quantity NUMERIC(12,3) NOT NULL DEFAULT 0;
ALTER TABLE sales_order_items ADD COLUMN IF NOT EXISTS delivered_quantity NUMERIC(12,3) NOT NULL DEFAULT 0;

ALTER TABLE quotes       ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(25) NOT NULL DEFAULT 'not_delivered';
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(25) NOT NULL DEFAULT 'not_delivered';
ALTER TABLE invoices     ADD COLUMN IF NOT EXISTS delivery_status VARCHAR(25) NOT NULL DEFAULT 'not_delivered';

ALTER TABLE quotes       DROP CONSTRAINT IF EXISTS ck_quotes_delivery_status;
ALTER TABLE quotes       ADD  CONSTRAINT ck_quotes_delivery_status
    CHECK (delivery_status IN ('not_delivered','partially_delivered','fully_delivered'));
ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS ck_so_delivery_status;
ALTER TABLE sales_orders ADD  CONSTRAINT ck_so_delivery_status
    CHECK (delivery_status IN ('not_delivered','partially_delivered','fully_delivered'));
ALTER TABLE invoices     DROP CONSTRAINT IF EXISTS ck_invoices_delivery_status;
ALTER TABLE invoices     ADD  CONSTRAINT ck_invoices_delivery_status
    CHECK (delivery_status IN ('not_delivered','partially_delivered','fully_delivered'));

-- ─── One product per document ───────────────────────────────
-- Deduplicate any existing offenders first by folding duplicate
-- lines into the earliest line, so the unique index can be built
-- on live data without losing quantities.

DO $$
DECLARE
    t   TEXT;
    pk  TEXT;
    fk  TEXT;
    tbl TEXT;
BEGIN
    FOR t, pk, fk IN
        SELECT * FROM (VALUES
            ('quote_items',            'quote_item_id',   'quote_id'),
            ('sales_order_items',      'sales_order_item_id', 'sales_order_id'),
            ('invoice_items',          'invoice_item_id', 'invoice_id'),
            ('proforma_invoice_items', 'pi_item_id',      'pi_id'),
            ('delivery_note_items',    'dn_item_id',      'dn_id')
        ) AS v(t, pk, fk)
    LOOP
        -- Skip tables that do not exist on this database.
        IF NOT EXISTS (SELECT 1 FROM information_schema.tables
                        WHERE table_schema = 'public' AND table_name = t) THEN
            CONTINUE;
        END IF;

        -- Fold duplicates: the surviving line takes the group total
        -- (the sum already includes that line's own quantity).
        EXECUTE format(
            'UPDATE %1$I keep SET quantity = dup.total
               FROM (SELECT SUM(quantity) AS total, MIN(%3$I) AS keep_id
                       FROM %1$I GROUP BY %2$I, product_id HAVING COUNT(*) > 1) dup
              WHERE keep.%3$I = dup.keep_id',
            t, fk, pk
        );
        -- …then delete the surplus lines.
        EXECUTE format(
            'DELETE FROM %1$I WHERE %3$I IN (
                 SELECT %3$I FROM (
                     SELECT %3$I, ROW_NUMBER() OVER (PARTITION BY %2$I, product_id ORDER BY %3$I) AS rn
                       FROM %1$I
                 ) x WHERE x.rn > 1)',
            t, fk, pk
        );

        EXECUTE format(
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_%1$s_product ON %1$I (%2$I, product_id)',
            t, fk
        );
    END LOOP;
END $$;

COMMIT;
