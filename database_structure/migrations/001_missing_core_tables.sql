-- ============================================================
--  Migration 001 — Missing core tables
-- ------------------------------------------------------------
--  The original db.sql referenced users, products and
--  product_images but never created them. This adds them
--  idempotently. Existing data is preserved (IF NOT EXISTS).
-- ============================================================

BEGIN;

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

COMMIT;
