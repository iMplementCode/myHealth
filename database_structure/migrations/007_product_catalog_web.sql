-- ============================================================
--  Migration 007 — Product catalogue for e-commerce
-- ------------------------------------------------------------
--  Extends the product model so the storefront
--  (run_ai_technologies_limited) can fetch everything it needs:
--  brand, structured specifications, web publishing/SEO fields,
--  shipping attributes and review aggregates.
--
--  Additive, idempotent and shape-defensive (IF NOT EXISTS
--  everywhere; no assumptions about existing constraints).
--  Aligns the ERP categories with the website's category slugs.
-- ============================================================

BEGIN;

-- ─── Brands (storefront filter facet) ───────────────────────
CREATE TABLE IF NOT EXISTS brands (
    brand_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    slug       VARCHAR(120) NOT NULL,
    logo_url   VARCHAR(255),
    website    VARCHAR(255),
    is_active  BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_brands_slug ON brands (LOWER(slug));
CREATE UNIQUE INDEX IF NOT EXISTS ux_brands_name ON brands (LOWER(name));

-- Brands the storefront already filters by.
INSERT INTO brands (name, slug)
SELECT v.name, v.slug FROM (VALUES
    ('Tiandy', 'tiandy'), ('Dahua', 'dahua'), ('Hikvision', 'hikvision'),
    ('Ezviz', 'ezviz'), ('TP-Link', 'tp-link'), ('MikroTik', 'mikrotik')
) AS v(name, slug)
WHERE NOT EXISTS (SELECT 1 FROM brands b WHERE LOWER(b.slug) = v.slug);

-- ─── Product web / catalogue columns ────────────────────────
ALTER TABLE products ADD COLUMN IF NOT EXISTS brand_id          INTEGER REFERENCES brands(brand_id) ON DELETE SET NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS short_description VARCHAR(500);
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_published      BOOLEAN DEFAULT FALSE;  -- visible on the website
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_featured       BOOLEAN DEFAULT FALSE;  -- highlighted on the storefront
ALTER TABLE products ADD COLUMN IF NOT EXISTS weight_kg         NUMERIC(10,3);          -- shipping
ALTER TABLE products ADD COLUMN IF NOT EXISTS warranty_months   INTEGER;
ALTER TABLE products ADD COLUMN IF NOT EXISTS meta_title        VARCHAR(255);           -- SEO
ALTER TABLE products ADD COLUMN IF NOT EXISTS meta_description  VARCHAR(500);
ALTER TABLE products ADD COLUMN IF NOT EXISTS rating_avg        NUMERIC(3,2) DEFAULT 0; -- review aggregate
ALTER TABLE products ADD COLUMN IF NOT EXISTS rating_count      INTEGER DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS published_at      TIMESTAMPTZ;
-- Per-product currency (the storefront model carries this; align the ERP).
ALTER TABLE products ADD COLUMN IF NOT EXISTS currency_id       INTEGER REFERENCES currencies(currency_id) ON DELETE SET NULL;
UPDATE products SET currency_id = (SELECT currency_id FROM currencies WHERE is_active ORDER BY currency_id LIMIT 1)
WHERE currency_id IS NULL;

CREATE INDEX IF NOT EXISTS ix_products_brand     ON products (brand_id);
CREATE INDEX IF NOT EXISTS ix_products_published ON products (is_published) WHERE is_published;
CREATE INDEX IF NOT EXISTS ix_products_featured  ON products (is_featured) WHERE is_featured;

-- ─── Structured specifications (spec sheet on the website) ──
CREATE TABLE IF NOT EXISTS product_specifications (
    spec_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES products(product_id) ON DELETE CASCADE,
    spec_group VARCHAR(100),           -- e.g. 'General', 'Video', 'Network'
    spec_name  VARCHAR(150) NOT NULL,  -- e.g. 'Resolution'
    spec_value TEXT NOT NULL,          -- e.g. '1920x1080 @ 30fps'
    sort_order INTEGER DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS ix_product_specs_product ON product_specifications (product_id);

-- ─── Category alignment with the storefront ─────────────────
-- Categories carry a URL slug and (new) an icon + web ordering.
ALTER TABLE categories ADD COLUMN IF NOT EXISTS icon       VARCHAR(60);
ALTER TABLE categories ADD COLUMN IF NOT EXISTS sort_order INTEGER DEFAULT 0;

-- Seed the categories the storefront expects (idempotent by slug).
INSERT INTO categories (name, slug, is_active, sort_order)
SELECT v.name, v.slug, TRUE, v.ord FROM (VALUES
    ('CCTV Cameras',   'cameras',     1),
    ('NVRs & DVRs',    'nvr',         2),
    ('Networking',     'networking',  3),
    ('Access Control', 'access',      4),
    ('Storage',        'storage',     5),
    ('Solar & Power',  'solar',       6),
    ('Smart Watches',  'watches',     7),
    ('Accessories',    'accessories', 8)
) AS v(name, slug, ord)
WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE LOWER(c.slug) = v.slug OR LOWER(c.name) = LOWER(v.name));

COMMIT;
