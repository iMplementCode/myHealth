-- ============================================================
--  Migration 006 — Phase 2: Expenses, Invoices, Proformas, Audit
-- ------------------------------------------------------------
--  * Expense Management: expense_categories + expenses (+ seeds)
--  * Invoicing: invoices + invoice_items
--  * Proforma workflow: proforma_invoices (+ items) created if
--    missing, plus conversion-tracking columns and guards that
--    prevent duplicate conversions
--  * Audit: audit_logs table and created_by/updated_by columns
--    on key business tables
--
--  Additive and idempotent. Never drops or rewrites existing
--  data. Defensive against live databases whose tables predate
--  this refactor (IF NOT EXISTS everywhere; no assumptions
--  about unique constraints).
-- ============================================================

BEGIN;

-- ─── Expense management ─────────────────────────────────────

CREATE TABLE IF NOT EXISTS expense_categories (
    category_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    is_active   BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_expense_categories_name
    ON expense_categories (LOWER(name));

INSERT INTO expense_categories (name)
SELECT v.name FROM (VALUES
    ('Rent'), ('Salaries'), ('Fuel'), ('Utilities'), ('Transport'),
    ('Maintenance'), ('Internet'), ('Office Supplies'), ('Marketing'),
    ('Miscellaneous')
) AS v(name)
WHERE NOT EXISTS (
    SELECT 1 FROM expense_categories c WHERE LOWER(c.name) = LOWER(v.name)
);

CREATE TABLE IF NOT EXISTS expenses (
    expense_id        INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category_id       INTEGER NOT NULL REFERENCES expense_categories(category_id),
    expense_date      DATE NOT NULL DEFAULT CURRENT_DATE,
    amount            NUMERIC(12,2) NOT NULL CHECK (amount > 0),
    description       TEXT,
    receipt_reference VARCHAR(100),
    status            VARCHAR(20) NOT NULL DEFAULT 'recorded'
        CHECK (status IN ('recorded', 'approved', 'rejected')),
    created_by        INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    updated_by        INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at        TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_expenses_date     ON expenses (expense_date);
CREATE INDEX IF NOT EXISTS ix_expenses_category ON expenses (category_id);
CREATE INDEX IF NOT EXISTS ix_expenses_status   ON expenses (status);

-- ─── Proforma invoices (create if missing; live DBs may
--     already have this table from the original db.sql) ──────

CREATE TABLE IF NOT EXISTS proforma_invoices (
    pi_id           INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pi_number       VARCHAR(100) UNIQUE NOT NULL,
    quote_id        INTEGER,
    customer_id     INTEGER NOT NULL,
    prepared_by     INTEGER NOT NULL REFERENCES users(user_id),
    issue_date      DATE DEFAULT CURRENT_DATE,
    expiry_date     DATE,
    currency_id     INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal        NUMERIC(12,2) DEFAULT 0,
    discount_amount NUMERIC(12,2) DEFAULT 0,
    tax_amount      NUMERIC(12,2) DEFAULT 0,
    total_amount    NUMERIC(12,2) DEFAULT 0,
    notes           TEXT,
    terms           TEXT,
    status          VARCHAR(20) DEFAULT 'draft' CHECK (
        status IN ('draft', 'sent', 'confirmed', 'converted', 'cancelled')
    ),
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS proforma_invoice_items (
    pi_item_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pi_id       INTEGER NOT NULL REFERENCES proforma_invoices(pi_id) ON DELETE CASCADE,
    product_id  INTEGER NOT NULL REFERENCES products(product_id),
    quantity    NUMERIC(12,3) NOT NULL CHECK (quantity > 0),
    unit_price  NUMERIC(12,2) NOT NULL,
    discount    NUMERIC(12,2) DEFAULT 0,
    subtotal    NUMERIC(12,2) GENERATED ALWAYS AS (quantity * unit_price - discount) STORED,
    created_at  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_pi_items_pi ON proforma_invoice_items (pi_id);

-- Conversion tracking on proformas (works whether the table was
-- just created above or already existed).
ALTER TABLE proforma_invoices ADD COLUMN IF NOT EXISTS converted_invoice_id INTEGER;
ALTER TABLE proforma_invoices ADD COLUMN IF NOT EXISTS converted_at TIMESTAMPTZ;
ALTER TABLE proforma_invoices ADD COLUMN IF NOT EXISTS converted_by INTEGER;

-- One proforma per quote (prevents duplicate quote→proforma).
CREATE UNIQUE INDEX IF NOT EXISTS ux_proformas_quote
    ON proforma_invoices (quote_id) WHERE quote_id IS NOT NULL;

-- ─── Invoices ───────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS invoices (
    invoice_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_number  VARCHAR(100) UNIQUE NOT NULL,
    customer_id     INTEGER NOT NULL,
    pi_id           INTEGER REFERENCES proforma_invoices(pi_id),
    quote_id        INTEGER,
    issued_by       INTEGER REFERENCES users(user_id),
    issue_date      DATE DEFAULT CURRENT_DATE,
    due_date        DATE,
    currency_id     INTEGER REFERENCES currencies(currency_id),
    subtotal        NUMERIC(12,2) DEFAULT 0,
    discount_amount NUMERIC(12,2) DEFAULT 0,
    tax_amount      NUMERIC(12,2) DEFAULT 0,
    total_amount    NUMERIC(12,2) DEFAULT 0,
    amount_paid     NUMERIC(12,2) DEFAULT 0,
    notes           TEXT,
    terms           TEXT,
    status          VARCHAR(20) NOT NULL DEFAULT 'unpaid'
        CHECK (status IN ('unpaid', 'partial', 'paid', 'cancelled')),
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS invoice_items (
    invoice_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id      INTEGER NOT NULL REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    product_id      INTEGER NOT NULL REFERENCES products(product_id),
    quantity        NUMERIC(12,3) NOT NULL CHECK (quantity > 0),
    unit_price      NUMERIC(12,2) NOT NULL,
    discount        NUMERIC(12,2) DEFAULT 0,
    subtotal        NUMERIC(12,2) GENERATED ALWAYS AS (quantity * unit_price - discount) STORED,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_invoices_customer ON invoices (customer_id);
CREATE INDEX IF NOT EXISTS ix_invoices_status   ON invoices (status);
CREATE INDEX IF NOT EXISTS ix_invoices_created  ON invoices (created_at);
CREATE INDEX IF NOT EXISTS ix_invoice_items_inv ON invoice_items (invoice_id);

-- One invoice per proforma (hard duplicate-conversion guard).
CREATE UNIQUE INDEX IF NOT EXISTS ux_invoices_pi
    ON invoices (pi_id) WHERE pi_id IS NOT NULL;

-- ─── Audit logging ──────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audit_logs (
    log_id     BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id    INTEGER,
    action     VARCHAR(100) NOT NULL,
    entity     VARCHAR(50),
    entity_id  VARCHAR(50),
    details    TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_audit_logs_created ON audit_logs (created_at);
CREATE INDEX IF NOT EXISTS ix_audit_logs_user    ON audit_logs (user_id);
CREATE INDEX IF NOT EXISTS ix_audit_logs_entity  ON audit_logs (entity, entity_id);

-- ─── Audit columns on key business tables (nullable → safe
--     for existing INSERT statements in legacy modules) ──────

ALTER TABLE products  ADD COLUMN IF NOT EXISTS created_by INTEGER;
ALTER TABLE products  ADD COLUMN IF NOT EXISTS updated_by INTEGER;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS created_by INTEGER;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS updated_by INTEGER;
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS created_by INTEGER;
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS updated_by INTEGER;

COMMIT;
