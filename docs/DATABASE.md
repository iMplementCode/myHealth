# Database

PostgreSQL. Two ways to set up:

- **Fresh install** → `database_structure/schema.sql` (authoritative, ordered,
  idempotent) then `php database_structure/migrate.php` for seeds/indexes.
- **Existing database** → `php database_structure/migrate.php` only. Migrations
  are additive and **never drop data**.

## Migration runner
`database_structure/migrate.php` applies every `migrations/*.sql` file in
numeric order exactly once, tracking applied files in a `schema_migrations`
table. Safe to re-run.

**Run it after every deployment.** Code and schema ship in the same commit
but do not arrive at the same instant, and pulling the code without running
the migrations leaves the database a step behind.

### Code must survive being one migration ahead

Between deploying code and running its migration there is a window. A page
that has nothing to do with the new feature must not go down in it — a
column added for services should never be able to take out the daily
position.

So **anything a migration adds is read through a guard**:

| What the migration adds | Guard |
|---|---|
| a table | `table_exists('x')` — or `require_tables('x')` for a whole page, which renders a setup notice instead of a 500 |
| a column | `column_exists('products', 'product_type')`, usually wrapped in a helper such as `goods_only_sql()` that degrades to `TRUE` |

`column_exists()` caches per request, because it is asked inside report
queries and the answer cannot change mid-request.

This was learnt the expensive way. Migration 034 added
`products.product_type`, and the code read it unguarded: on a database one
migration behind, `finance/position.php` and every stock page returned
"This page isn't working". `t_premigration.py` now hides that column and
walks the pages that read it, so the next migration-gated column cannot
repeat it.

| File | Purpose |
|------|---------|
| `001_missing_core_tables.sql`   | Creates `users`, `products`, `product_images` — referenced by the app and other tables but **missing** from the original `db.sql`. |
| `002_auth_columns_and_tokens.sql` | Adds `username` + `last_login` to `users`; creates `remember_tokens`; unique index on username. |
| `003_roles_and_seed_reference.sql` | Seeds roles (Administrator, Manager, Salesperson), baseline permissions, corrected units of measurement, a default KES currency. |
| `004_indexes.sql` | Foreign-key / lookup indexes for dashboards, listings and searches. |
| `005_default_admin.sql` | Creates the default administrator if absent. |

## Bugs fixed from the original `db.sql`

The original schema file could not build a working database. The corrected
`schema.sql` and migrations fix:

1. **Missing tables.** `users`, `products` and `product_images` were referenced
   by foreign keys and by the application, but never created. Added.
2. **Broken `units_of_measurement` seed.** The `INSERT` used non-existent
   columns (`uom_name`, `uom_short_nam`) and provided 4 values for 3 columns.
   Rewritten against the real columns (`name`, `abbreviation`, `allow_decimals`,
   `description`).
3. **Broken `user_roles` seed.** Referenced an `assigned` column that doesn't
   exist (the column is `assigned_by`) and ran before `users` existed. Fixed and
   reordered.
4. **Table ordering.** `user_roles` (and others) were defined before the
   `users`/`products` tables they reference. `schema.sql` orders tables by
   dependency.
5. **Stray statements.** Debugging `SELECT * FROM …` and `DROP TABLE …`
   statements were interleaved with DDL in the original file. Removed.

## Hardening added
- **Indexes** on all foreign keys and common filter/search columns
  (`004_indexes.sql`).
- **Check constraints** on prices (`>= 0`) and a unique, case-insensitive
  username index.
- **Cascading deletes** kept where they model ownership
  (`product_images → products`, `remember_tokens → users`, order items → orders)
  and `ON DELETE SET NULL` on optional lookups (category/supplier/uom on
  products) so deleting a lookup never orphans or destroys a product.

## Key tables added

**`users`** — `user_id, username, first_name, last_name, email, mobile,
password_hash, role_id → roles, is_active, last_login, created_at, updated_at`.

**`products`** — `product_id, name, slug, description, category_id, supplier_id,
uom_id, sku, barcode, cost_price, selling_price, discount_price, stock_quantity,
low_quantity_threshold, is_active, timestamps`.

**`product_images`** — `image_id, product_id → products (CASCADE), image_url,
alt_text, is_primary, sort_order, timestamps`.

**`remember_tokens`** — `id, selector (unique), validator_hash (SHA-256),
user_id → users (CASCADE), expires_at`.

## Credentials & secrets
Database credentials live in `.env` (now git-ignored). The password for the
default admin is stored **only as a bcrypt hash** in migration `005`; it is
never stored or committed in plain text. Change it after first login.

## Phase 3 — Delivery notes & inventory movement (migrations 009–010)

| Table | Purpose |
|---|---|
| `warehouses` | Stock locations. Seeded with a default "Main Store"; future-proofs multi-location inventory. |
| `delivery_notes` | Goods dispatched to a customer. Hangs off an accepted quote or a confirmed sales order, and links to the invoice. Statuses: draft, posted, cancelled. |
| `delivery_note_items` | Lines on a delivery note (product + quantity). Frozen once the note is posted. |
| `inventory_transactions` | The permanent stock ledger: product, warehouse, signed quantity, movement type, reference document, running balance, user, timestamp. |
| `document_links` | Generic document graph (`from_type`/`from_id` → `to_type`/`to_id`), so traceability survives new document types. |

### Columns added

- `quote_items.delivered_quantity`, `sales_order_items.delivered_quantity`, `proforma_invoice_items.delivered_quantity`, `invoice_items.delivered_quantity` — running totals maintained by trigger.
- `quotes.delivery_status`, `sales_orders.delivery_status`, `proforma_invoices.delivery_status`, `invoices.delivery_status` — not_delivered / partially_delivered / fully_delivered, derived from posted delivery notes.
- `invoices.sales_order_id`, `converted_at`, `converted_by` — the Sales Order → Invoice trail.
- `sales_orders.converted_invoice_id`, `invoiced_at`, `invoiced_by`, `notes`, `terms`, `expected_date`.
- `quotes.accepted_at`, `accepted_by` — set when a quote is accepted, which is what unlocks delivery.

### Constraints & indexes

- `ux_invoices_sales_order` — one invoice per sales order (partial index; NULLs stay allowed).
- `ux_<table>_product` on quote / sales order / invoice / proforma / delivery-note items — a product may appear at most once per document. Pre-existing duplicates are folded into a single line (quantities summed) before the index is built.
- `ck_delivery_notes_source` — a delivery note must hang off a quote or a sales order.
- Status `CHECK` constraints rewritten for the Phase 3 lifecycles; existing rows are mapped onto the new vocabulary in place.

See `docs/PHASE3_NOTES.md` for the trigger inventory and the full rule set.

### Delivery status across the chain (migration 019)

One rule decides every document's `delivery_status`:

| Delivered | Status |
|---|---|
| nothing | `not_delivered` |
| every line in full | `fully_delivered` |
| anything in between | `partially_delivered` |

and one definition of which notes count towards a document: those
pointing at it directly, plus those pointing at any document it
descends from. A proforma is covered by notes raised against it or
against its quote; an invoice by notes against it, its proforma, its
sales order or its quote — the same walk `resolve_delivery_source()`
does in the application.

Three functions do the work, and can be called from anywhere:

- `refresh_proforma_delivery(pi_id)` → the new status
- `refresh_invoice_delivery(invoice_id)` → the new status
- `refresh_delivery_for_note(dn_id)` — refreshes every document one
  note can affect

They fire from `delivery_notes` (insert, status change, and a change
of any of the four source columns), from `delivery_note_items`
(insert / update / delete, since a draft's lines can still move) and
on note deletion. `proformas/convert.php` and
`modules/sales/convert_to_invoice.php` also call
`refresh_invoice_delivery()` directly, so an invoice raised *after*
the goods have gone out opens life reporting the delivery rather than
claiming nothing was sent.
