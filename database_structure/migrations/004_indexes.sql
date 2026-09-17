-- ============================================================
--  Migration 004 — Performance indexes
-- ------------------------------------------------------------
--  Foreign-key and lookup columns are frequently filtered or
--  joined by the app (dashboards, listings, searches). These
--  indexes are all idempotent and safe to re-run.
-- ============================================================

BEGIN;

-- Users
CREATE INDEX IF NOT EXISTS ix_users_role       ON users (role_id);
CREATE INDEX IF NOT EXISTS ix_users_is_active  ON users (is_active);

-- Products (catalogue listings, dashboards, low-stock checks)
CREATE INDEX IF NOT EXISTS ix_products_category ON products (category_id);
CREATE INDEX IF NOT EXISTS ix_products_supplier ON products (supplier_id);
CREATE INDEX IF NOT EXISTS ix_products_active   ON products (is_active);
CREATE INDEX IF NOT EXISTS ix_products_name     ON products (LOWER(name));
CREATE INDEX IF NOT EXISTS ix_product_images_pid ON product_images (product_id);
CREATE INDEX IF NOT EXISTS ix_product_images_primary ON product_images (product_id) WHERE is_primary;

-- Sales
CREATE INDEX IF NOT EXISTS ix_quotes_customer   ON quotes (customer_id);
CREATE INDEX IF NOT EXISTS ix_quotes_status     ON quotes (status);
CREATE INDEX IF NOT EXISTS ix_quotes_created    ON quotes (created_at);
CREATE INDEX IF NOT EXISTS ix_quote_items_quote ON quote_items (quote_id);
CREATE INDEX IF NOT EXISTS ix_so_customer       ON sales_orders (customer_id);
CREATE INDEX IF NOT EXISTS ix_so_status         ON sales_orders (status);
CREATE INDEX IF NOT EXISTS ix_so_created        ON sales_orders (created_at);
CREATE INDEX IF NOT EXISTS ix_so_items_order    ON sales_order_items (sales_order_id);
CREATE INDEX IF NOT EXISTS ix_so_items_product  ON sales_order_items (product_id);

-- Purchasing
CREATE INDEX IF NOT EXISTS ix_po_supplier       ON purchase_orders (supplier_id);
CREATE INDEX IF NOT EXISTS ix_po_status         ON purchase_orders (status);
CREATE INDEX IF NOT EXISTS ix_po_items_po       ON purchase_order_items (po_id);
CREATE INDEX IF NOT EXISTS ix_grns_supplier     ON grns (supplier_id);
CREATE INDEX IF NOT EXISTS ix_grn_items_grn     ON grn_items (grn_id);
CREATE INDEX IF NOT EXISTS ix_batches_product   ON product_batches (product_id);

-- Customers
CREATE INDEX IF NOT EXISTS ix_customers_active  ON customers (is_active);
CREATE INDEX IF NOT EXISTS ix_cust_addr_cust    ON customer_addresses (customer_id);

COMMIT;
