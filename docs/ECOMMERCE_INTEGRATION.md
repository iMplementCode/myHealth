# E-commerce integration

How the storefront (`run_ai_technologies_limited`) fetches products from the
ERP, and what the ERP now captures so it can serve them.

The ERP is the **single source of truth** for the catalogue. The website reads
products over HTTP JSON and never touches the ERP database directly.

```
┌────────────────┐   GET /api/products.php     ┌──────────────┐
│  Storefront    │ ─────────────────────────▶  │     ERP      │
│ (run_ai_...)   │ ◀───────────  JSON  ─────────│  products DB │
└────────────────┘                             └──────────────┘
        │  POST /api/orders.php (X-Api-Key)            ▲
        └──────────────── website orders ─────────────┘
```

## What the ERP now captures per product
Migration `007_product_catalog_web.sql` adds everything the storefront needs:

| Field | Purpose on the website |
|-------|------------------------|
| `brand_id` → **brands** | Brand filter (Tiandy, Dahua, Hikvision, Ezviz, TP-Link, MikroTik seeded) |
| `short_description` | Product card blurb / meta |
| `is_published` | Whether the product appears on the website at all |
| `is_featured` | Featured / homepage products |
| `discount_price` | Drives the **On Sale** badge and effective price |
| `weight_kg` | Shipping calculations |
| `warranty_months` | Displayed on the product page |
| `meta_title`, `meta_description` | SEO |
| `rating_avg`, `rating_count` | Review stars (aggregate) |
| `currency_id` → **currencies** | Per-product currency |
| **product_specifications** (table) | The structured spec sheet, grouped |
| **product_images** (existing) | Gallery, primary image first |
| **categories** (`slug`, `icon`, `sort_order`) | Category filter, aligned to the site's slugs |

Products are managed in the ERP under **Inventory → Products**. The edit modal
has a *Website / e-commerce* section for brand, publishing, featured, short
description, weight, warranty, specifications and SEO. Only products that are
**both `is_active` and `is_published`** are ever exposed by the API.

## Catalogue API (read)

`GET /api/products.php`

Read-only, returns published + active products. CORS-enabled (set `WEB_ORIGIN`).
If `CATALOG_API_KEY` is set in the ERP `.env`, send it as an `X-Api-Key`
header; if it's empty the endpoint is public.

### List
```
GET /api/products.php
    ?q=camera            search (name / sku / short description)
    &category=cameras    category slug (or numeric id)
    &brand=hikvision     brand slug
    &min_price=1000&max_price=50000
    &in_stock=1
    &on_sale=1
    &featured=1
    &sort=price_asc|price_desc|newest|name|rating
    &page=1&per_page=24  (per_page max 60)
```
Response:
```json
{
  "success": true,
  "data": [
    {
      "id": 1, "name": "Tiandy TC-C32 Dome Camera",
      "slug": "tiandy-tc-c32-dome", "sku": "TND-C32",
      "short_description": "2MP ColorMaker dome…",
      "brand": { "name": "Tiandy", "slug": "tiandy" },
      "category": { "name": "CCTV Cameras", "slug": "cameras" },
      "price": 2500, "selling_price": 2800, "discount_price": 2500,
      "on_sale": true, "currency": "KES",
      "in_stock": true, "stock_quantity": 8,
      "is_featured": true,
      "rating": { "average": 0, "count": 0 },
      "image": "https://erp.example.com/uploads/products/…jpg"
    }
  ],
  "pagination": { "page": 1, "per_page": 24, "total": 1, "total_pages": 1 }
}
```

### Single product
```
GET /api/products.php?slug=tiandy-tc-c32-dome
GET /api/products.php?id=1
```
Adds `description`, `barcode`, `weight_kg`, `warranty_months`, `unit`,
`currency_symbol`, `meta`, the full `images` array, and a grouped
`specifications` array:
```json
"specifications": [
  { "group": "General", "items": [ { "name": "Resolution", "value": "1920x1080 @ 30fps" } ] }
]
```
Unpublished / unknown products return `404`.

### Filter facets
```
GET /api/products.php?resource=brands       → [{ name, slug, logo }]
GET /api/products.php?resource=categories   → [{ name, slug, icon, count }]
```

## Order intake API (write) — already available
`POST /api/orders.php` with `X-Api-Key: <ORDERS_API_KEY>` — the storefront
pushes a placed order back into the ERP as a `pending` e-commerce sales order
(see `README.md`). Catalogue prices are applied server-side.

## ERP `.env` settings for the integration
```
APP_PUBLIC_URL=https://erp.example.com   # builds absolute image URLs
CATALOG_API_KEY=                          # optional; empty = public read
WEB_ORIGIN=https://shop.example.com       # CORS origin (defaults to * for GET)
ORDERS_API_KEY=…                          # required to enable order intake
```

## Storefront wiring (when you build it)
Replace the hard-coded product cards in `includes/shop.php` and
`includes/product_detail.php` with calls to this API — e.g. server-side
`file_get_contents`/cURL to `GET /api/products.php?...`, decode the JSON, and
render. The site's existing filters (brand, category, price, on-sale, in-stock)
map 1:1 to the query parameters above.

## Follow-ups (not built yet)
- **Reviews**: the ERP stores rating aggregates (`rating_avg`/`rating_count`);
  a small `POST /api/reviews.php` (or a nightly push from the storefront) can
  keep them in sync once the website has a reviews feature.
- **Brands/Categories admin**: seeded and API-exposed; a CRUD screen in the ERP
  can follow the same list+modal pattern as Suppliers.
- **Inventory sync**: `stock_quantity` is live in every response, so the site
  always shows current stock; a webhook/poll can invalidate the site's cache on
  stock changes.
