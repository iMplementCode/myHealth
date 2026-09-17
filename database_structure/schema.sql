-- ============================================================
--  iMplement ERP — Authoritative Schema (fresh install)
-- ------------------------------------------------------------
--  A corrected, dependency-ordered, idempotent version of the
--  original database_structure/db.sql. Safe to run on an empty
--  database. For an EXISTING database, apply the ordered files
--  in ./migrations/ instead (they are additive and preserve
--  existing data).
--
--  Fixes over the original db.sql:
--    * Adds the missing `users`, `products`, `product_images`
--      tables that other tables and the app referenced.
--    * Correct table ordering (roles → users → user_roles, etc.)
--    * Fixes the broken units_of_measurement seed (wrong column
--      names and mismatched value counts).
--    * Fixes the user_roles seed (assigned_by, not "assigned").
--    * Removes stray SELECT/DROP debugging statements.
--    * Adds indexes, foreign keys and constraints.
-- ============================================================

BEGIN;

-- ─── Reference data ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS company_settings (
    id            INTEGER DEFAULT 1 PRIMARY KEY CHECK (id = 1),  -- single row
    company_name  VARCHAR(100) NOT NULL,
    location      TEXT,
    email         VARCHAR(100),
    mobile        VARCHAR(50),
    website       VARCHAR(100),
    logo_path     VARCHAR(255),
    updated_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS currencies (
    currency_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name          VARCHAR(50) NOT NULL,
    code          VARCHAR(3)  UNIQUE NOT NULL,
    symbol        VARCHAR(5)  UNIQUE NOT NULL,
    exchange_rate NUMERIC(10,4) DEFAULT 1.00,
    is_active     BOOLEAN DEFAULT TRUE,
    created_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS roles (
    role_id        INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           VARCHAR(100) UNIQUE NOT NULL,
    description    TEXT,
    is_system_role BOOLEAN DEFAULT FALSE,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permissions (
    permission_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name          VARCHAR(100) UNIQUE NOT NULL,
    module        VARCHAR(50)  NOT NULL,
    description   TEXT,
    created_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS units_of_measurement (
    uom_id         INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           VARCHAR(100) UNIQUE NOT NULL,
    abbreviation   VARCHAR(15)  UNIQUE NOT NULL,
    allow_decimals BOOLEAN DEFAULT FALSE,
    description    TEXT,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    category_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id   INTEGER REFERENCES categories(category_id) ON DELETE SET NULL,
    name        VARCHAR(100) UNIQUE NOT NULL,
    slug        VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    is_active   BOOLEAN DEFAULT TRUE,
    created_at  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ─── Users & access control ─────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    user_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username      VARCHAR(50)  UNIQUE,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(255) UNIQUE NOT NULL,
    mobile        VARCHAR(50)  UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id       INTEGER REFERENCES roles(role_id) ON DELETE SET NULL,
    is_active     BOOLEAN DEFAULT TRUE,
    last_login    TIMESTAMPTZ,
    created_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id     INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    role_id     INTEGER NOT NULL REFERENCES roles(role_id) ON DELETE CASCADE,
    assigned_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    assigned_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INTEGER NOT NULL REFERENCES roles(role_id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permissions(permission_id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);

-- "Remember me" tokens (selector/validator pattern).
CREATE TABLE IF NOT EXISTS remember_tokens (
    id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    selector       VARCHAR(24)  UNIQUE NOT NULL,
    validator_hash VARCHAR(64)  NOT NULL,
    user_id        INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    expires_at     TIMESTAMPTZ NOT NULL,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ─── Catalogue ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS suppliers (
    supplier_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         VARCHAR(255) UNIQUE NOT NULL,
    contact_name VARCHAR(255),
    phone        VARCHAR(50)  UNIQUE NOT NULL,
    email        VARCHAR(255) UNIQUE,
    tax_pin      VARCHAR(50)  UNIQUE,
    address      TEXT,
    is_active    BOOLEAN DEFAULT TRUE,
    created_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS products (
    product_id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                   VARCHAR(255) NOT NULL,
    slug                   VARCHAR(255) UNIQUE,
    description            TEXT,
    category_id            INTEGER REFERENCES categories(category_id) ON DELETE SET NULL,
    supplier_id            INTEGER REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
    uom_id                 INTEGER REFERENCES units_of_measurement(uom_id) ON DELETE SET NULL,
    sku                    VARCHAR(100) UNIQUE,
    barcode                VARCHAR(50)  UNIQUE,
    cost_price             NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (cost_price >= 0),
    selling_price          NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (selling_price >= 0),
    discount_price         NUMERIC(12,2) CHECK (discount_price IS NULL OR discount_price >= 0),
    stock_quantity         NUMERIC(12,3) NOT NULL DEFAULT 0,
    low_quantity_threshold NUMERIC(12,3) NOT NULL DEFAULT 5,
    is_active              BOOLEAN DEFAULT TRUE,
    created_at             TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_images (
    image_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(product_id) ON DELETE CASCADE,
    image_url  VARCHAR(255) NOT NULL,
    alt_text   VARCHAR(255),
    is_primary BOOLEAN DEFAULT FALSE,
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ─── Customers ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS customers (
    customer_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    company_name VARCHAR(255),
    first_name   VARCHAR(100),
    last_name    VARCHAR(100),
    email        VARCHAR(255) UNIQUE,
    phone        VARCHAR(20)  UNIQUE,
    tax_pin      VARCHAR(50)  UNIQUE,
    is_active    BOOLEAN DEFAULT TRUE,
    created_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_customer_name CHECK (first_name IS NOT NULL OR company_name IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS customer_addresses (
    address_id            INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id           INTEGER NOT NULL REFERENCES customers(customer_id) ON DELETE CASCADE,
    address_type          VARCHAR(20) NOT NULL CHECK (address_type IN ('billing', 'shipping')),
    recipient_name        VARCHAR(255),
    recipient_phone       VARCHAR(20),
    address_line1         VARCHAR(255) NOT NULL,
    address_line2         VARCHAR(255),
    city                  VARCHAR(100) NOT NULL,
    county                VARCHAR(100),
    postal_code           VARCHAR(50),
    country               VARCHAR(100) DEFAULT 'Kenya',
    delivery_instructions TEXT,
    is_default            BOOLEAN DEFAULT FALSE,
    created_at            TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ─── Sales: quotes & orders ─────────────────────────────────

CREATE TABLE IF NOT EXISTS quotes (
    quote_id        INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    quote_number    VARCHAR(100) UNIQUE NOT NULL,
    customer_id     INTEGER NOT NULL REFERENCES customers(customer_id),
    prepared_by     INTEGER NOT NULL REFERENCES users(user_id),
    issue_date      DATE DEFAULT CURRENT_DATE,
    expiry_date     DATE NOT NULL,
    currency_id     INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal        NUMERIC(12,2) DEFAULT 0,
    discount_amount NUMERIC(12,2) DEFAULT 0,
    tax_amount      NUMERIC(12,2) DEFAULT 0,
    total_amount    NUMERIC(12,2) DEFAULT 0,
    notes           TEXT,
    terms           TEXT,
    status          VARCHAR(20) DEFAULT 'draft'
        CHECK (status IN ('draft','sent','accepted','rejected','expired','converted')),
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS quote_items (
    quote_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    quote_id      INTEGER NOT NULL REFERENCES quotes(quote_id) ON DELETE CASCADE,
    product_id    INTEGER NOT NULL REFERENCES products(product_id),
    quantity      NUMERIC(12,3) NOT NULL CHECK (quantity > 0),
    unit_price    NUMERIC(12,2) NOT NULL,
    discount      NUMERIC(12,2) DEFAULT 0,
    subtotal      NUMERIC(12,2) GENERATED ALWAYS AS (quantity * unit_price - discount) STORED,
    created_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sales_orders (
    sales_order_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_number        VARCHAR(100) UNIQUE NOT NULL,
    customer_id         INTEGER NOT NULL REFERENCES customers(customer_id),
    quote_id            INTEGER REFERENCES quotes(quote_id),
    customer_lpo_number VARCHAR(100),
    sales_channel       VARCHAR(20) NOT NULL CHECK (sales_channel IN ('pos','ecommerce','b2b')),
    sold_by             INTEGER REFERENCES users(user_id),
    currency_id         INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal            NUMERIC(12,2) DEFAULT 0,
    discount_amount     NUMERIC(12,2) DEFAULT 0,
    tax_amount          NUMERIC(12,2) DEFAULT 0,
    total_amount        NUMERIC(12,2) DEFAULT 0,
    shipping_address_id INTEGER REFERENCES customer_addresses(address_id),
    status              VARCHAR(20) DEFAULT 'pending'
        CHECK (status IN ('pending','processing','shipped','delivered','completed','cancelled')),
    created_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sales_order_items (
    sales_order_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sales_order_id      INTEGER NOT NULL REFERENCES sales_orders(sales_order_id) ON DELETE CASCADE,
    product_id          INTEGER NOT NULL REFERENCES products(product_id),
    quantity            NUMERIC(12,3) NOT NULL CHECK (quantity > 0),
    unit_price          NUMERIC(12,2) NOT NULL,
    discount            NUMERIC(12,2) DEFAULT 0,
    subtotal            NUMERIC(12,2) GENERATED ALWAYS AS ((quantity * unit_price) - discount) STORED,
    created_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ─── Purchasing ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS purchase_orders (
    po_id                  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    po_number              VARCHAR(100) UNIQUE NOT NULL,
    supplier_id            INTEGER NOT NULL REFERENCES suppliers(supplier_id),
    issued_by              INTEGER NOT NULL REFERENCES users(user_id),
    issue_date             DATE DEFAULT CURRENT_DATE,
    expected_delivery_date DATE,
    currency_id            INTEGER NOT NULL REFERENCES currencies(currency_id),
    subtotal               NUMERIC(12,2) DEFAULT 0,
    tax_amount             NUMERIC(12,2) DEFAULT 0,
    total_amount           NUMERIC(12,2) DEFAULT 0,
    delivery_address       TEXT,
    notes                  TEXT,
    terms                  TEXT,
    status                 VARCHAR(20) DEFAULT 'draft'
        CHECK (status IN ('draft','sent','acknowledged','partially_received','received','cancelled')),
    created_at             TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS purchase_order_items (
    po_item_id         INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    po_id              INTEGER NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    product_id         INTEGER NOT NULL REFERENCES products(product_id),
    quantity_ordered   NUMERIC(12,3) NOT NULL CHECK (quantity_ordered > 0),
    quantity_fulfilled NUMERIC(12,3) NOT NULL DEFAULT 0,
    unit_price         NUMERIC(12,2) NOT NULL,
    subtotal           NUMERIC(12,2) GENERATED ALWAYS AS (quantity_ordered * unit_price) STORED,
    created_at         TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS grns (
    grn_id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    grn_number          VARCHAR(100) UNIQUE NOT NULL,
    supplier_id         INTEGER NOT NULL REFERENCES suppliers(supplier_id),
    po_id               INTEGER REFERENCES purchase_orders(po_id),
    received_by         INTEGER NOT NULL REFERENCES users(user_id),
    receipt_date        DATE DEFAULT CURRENT_DATE,
    supplier_invoice_no VARCHAR(100),
    currency_id         INTEGER NOT NULL REFERENCES currencies(currency_id),
    total_amount        NUMERIC(12,2) DEFAULT 0,
    notes               TEXT,
    status              VARCHAR(20) DEFAULT 'draft'
        CHECK (status IN ('draft','confirmed','partially_received','cancelled')),
    created_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS grn_items (
    grn_item_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    grn_id            INTEGER NOT NULL REFERENCES grns(grn_id) ON DELETE CASCADE,
    product_id        INTEGER NOT NULL REFERENCES products(product_id),
    po_item_id        INTEGER REFERENCES purchase_order_items(po_item_id),
    batch_number      VARCHAR(100),
    expiry_date       DATE,
    quantity_ordered  NUMERIC(12,3),
    quantity_received NUMERIC(12,3) NOT NULL CHECK (quantity_received >= 0),
    quantity_rejected NUMERIC(12,3) NOT NULL DEFAULT 0,
    unit_cost         NUMERIC(12,2) NOT NULL,
    subtotal          NUMERIC(12,2) GENERATED ALWAYS AS (quantity_received * unit_cost) STORED,
    created_at        TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_batches (
    batch_id           INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id         INTEGER NOT NULL REFERENCES products(product_id),
    supplier_id        INTEGER REFERENCES suppliers(supplier_id),
    grn_item_id        INTEGER REFERENCES grn_items(grn_item_id),
    batch_number       VARCHAR(100) NOT NULL,
    expiry_date        DATE,
    manufacture_date   DATE,
    quantity_received  NUMERIC(12,3) NOT NULL CHECK (quantity_received > 0),
    quantity_remaining NUMERIC(12,3) NOT NULL CHECK (quantity_remaining >= 0),
    unit_cost          NUMERIC(12,2) NOT NULL,
    received_by        INTEGER REFERENCES users(user_id),
    created_at         TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (product_id, batch_number)
);

CREATE TABLE IF NOT EXISTS debit_notes (
    debit_note_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    debit_note_number   VARCHAR(100) UNIQUE NOT NULL,
    grn_id              INTEGER NOT NULL REFERENCES grns(grn_id),
    supplier_id         INTEGER NOT NULL REFERENCES suppliers(supplier_id),
    issued_by           INTEGER NOT NULL REFERENCES users(user_id),
    issue_date          DATE DEFAULT CURRENT_DATE,
    total_refund_amount NUMERIC(12,2) DEFAULT 0,
    status              VARCHAR(20) DEFAULT 'draft'
        CHECK (status IN ('draft','sent','processed','cancelled')),
    created_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS debit_note_items (
    debit_item_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    debit_note_id      INTEGER NOT NULL REFERENCES debit_notes(debit_note_id) ON DELETE CASCADE,
    product_id         INTEGER NOT NULL REFERENCES products(product_id),
    grn_item_id        INTEGER REFERENCES grn_items(grn_item_id),
    quantity_returned  NUMERIC(12,3) NOT NULL CHECK (quantity_returned > 0),
    reason_code        VARCHAR(50) NOT NULL
        CHECK (reason_code IN ('damaged','expired','short_expiry','wrong_item','defective')),
    reason_description TEXT,
    unit_cost          NUMERIC(12,2) NOT NULL,
    subtotal           NUMERIC(12,2) GENERATED ALWAYS AS (quantity_returned * unit_cost) STORED,
    created_at         TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

COMMIT;

-- Indexes, seed data and the default admin live in ./migrations/.
-- Run them in numeric order after this file for a complete install.
