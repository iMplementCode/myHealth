<?php

/**
 * ============================================================
 *  Catalogue query layer
 * ------------------------------------------------------------
 *  Read-only product queries shaped for the e-commerce
 *  storefront. Used by api/products.php (and reusable by any
 *  future web-preview screen in the ERP). Only products that
 *  are both is_active AND is_published are ever exposed.
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


/** Build an absolute URL for a stored asset path (e.g. product image). */
function catalog_asset_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path; // already absolute
    }
    $base = rtrim((string) env('APP_PUBLIC_URL', ''), '/');
    if ($base === '') {
        // Derive from the current request as a fallback.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base   = $scheme . '://' . $host;
    }
    return $base . '/' . ltrim($path, '/');
}

/** Map a raw product row into the storefront JSON shape (list card). */
function catalog_shape_card(array $r): array
{
    $sell     = (float) $r['selling_price'];
    $discount = $r['discount_price'] !== null ? (float) $r['discount_price'] : null;
    $onSale   = $discount !== null && $discount > 0 && $discount < $sell;
    $price    = $onSale ? $discount : $sell;

    return [
        'id'             => (int) $r['product_id'],
        'name'           => $r['name'],
        'slug'           => $r['slug'],
        'sku'            => $r['sku'],
        'short_description' => $r['short_description'],
        'brand'          => $r['brand_name'] ? ['name' => $r['brand_name'], 'slug' => $r['brand_slug']] : null,
        'category'       => $r['category_name'] ? ['name' => $r['category_name'], 'slug' => $r['category_slug']] : null,
        'price'          => round($price, 2),
        'selling_price'  => round($sell, 2),
        'discount_price' => $discount !== null ? round($discount, 2) : null,
        'on_sale'        => $onSale,
        'currency'       => $r['currency_code'] ?? 'KES',
        'in_stock'       => (float) $r['stock_quantity'] > 0,
        'stock_quantity' => (float) $r['stock_quantity'],
        'is_featured'    => (bool) $r['is_featured'],
        'rating'         => ['average' => (float) $r['rating_avg'], 'count' => (int) $r['rating_count']],
        'image'          => catalog_asset_url($r['primary_image'] ?? null),
    ];
}

/**
 * List published products with storefront filters + pagination.
 * $f keys: q, category, brand, min_price, max_price, in_stock,
 *          on_sale, featured, sort, page, per_page.
 * Returns ['data' => [...cards], 'pagination' => [...]].
 */
function catalog_list_products(array $f): array
{
    $where  = ["p.is_active = TRUE", "p.is_published = TRUE"];
    $params = [];

    if (!empty($f['q'])) {
        $where[] = "(LOWER(p.name) LIKE :q OR LOWER(COALESCE(p.sku,'')) LIKE :q OR LOWER(COALESCE(p.short_description,'')) LIKE :q)";
        $params[':q'] = '%' . strtolower((string) $f['q']) . '%';
    }
    if (!empty($f['category'])) {
        // Accept slug or numeric id.
        if (ctype_digit((string) $f['category'])) {
            $where[] = "p.category_id = :cat";
            $params[':cat'] = (int) $f['category'];
        } else {
            $where[] = "LOWER(c.slug) = :cat";
            $params[':cat'] = strtolower((string) $f['category']);
        }
    }
    if (!empty($f['brand'])) {
        $where[] = "LOWER(b.slug) = :brand";
        $params[':brand'] = strtolower((string) $f['brand']);
    }
    if (isset($f['min_price']) && is_numeric($f['min_price'])) {
        $where[] = "COALESCE(NULLIF(p.discount_price,0), p.selling_price) >= :minp";
        $params[':minp'] = (float) $f['min_price'];
    }
    if (isset($f['max_price']) && is_numeric($f['max_price'])) {
        $where[] = "COALESCE(NULLIF(p.discount_price,0), p.selling_price) <= :maxp";
        $params[':maxp'] = (float) $f['max_price'];
    }
    if (!empty($f['in_stock'])) {
        $where[] = "p.stock_quantity > 0";
    }
    if (!empty($f['on_sale'])) {
        $where[] = "p.discount_price IS NOT NULL AND p.discount_price > 0 AND p.discount_price < p.selling_price";
    }
    if (!empty($f['featured'])) {
        $where[] = "p.is_featured = TRUE";
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sortMap = [
        'price_asc'  => 'COALESCE(NULLIF(p.discount_price,0), p.selling_price) ASC',
        'price_desc' => 'COALESCE(NULLIF(p.discount_price,0), p.selling_price) DESC',
        'newest'     => 'p.published_at DESC NULLS LAST, p.created_at DESC',
        'name'       => 'p.name ASC',
        'rating'     => 'p.rating_avg DESC, p.rating_count DESC',
    ];
    $orderBy = $sortMap[$f['sort'] ?? 'name'] ?? $sortMap['name'];

    $perPage = min(60, max(1, (int) ($f['per_page'] ?? 24)));
    $page    = max(1, (int) ($f['page'] ?? 1));

    $joins = "FROM products p
              LEFT JOIN categories c ON c.category_id = p.category_id
              LEFT JOIN brands b     ON b.brand_id = p.brand_id
              LEFT JOIN currencies cur ON cur.currency_id = p.currency_id
              $whereSql";

    $total      = (int) db_value("SELECT COUNT(*) $joins", $params);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $rows = db_all(
        "SELECT p.product_id, p.name, p.slug, p.sku, p.short_description, p.selling_price, p.discount_price,
                p.stock_quantity, p.is_featured, p.rating_avg, p.rating_count,
                b.name AS brand_name, b.slug AS brand_slug,
                c.name AS category_name, c.slug AS category_slug,
                cur.code AS currency_code,
                (SELECT image_url FROM product_images WHERE product_id = p.product_id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image
         $joins
         ORDER BY $orderBy
         LIMIT $perPage OFFSET $offset",
        $params
    );

    return [
        'data' => array_map('catalog_shape_card', $rows),
        'pagination' => [
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
        ],
    ];
}

/** Full detail for a single published product (by id or slug), or null. */
function catalog_get_product($idOrSlug): ?array
{
    $byId = ctype_digit((string) $idOrSlug);
    $row = db_one(
        "SELECT p.*, b.name AS brand_name, b.slug AS brand_slug,
                c.name AS category_name, c.slug AS category_slug,
                cur.code AS currency_code, cur.symbol AS currency_symbol,
                u.abbreviation AS uom_abbr
         FROM products p
         LEFT JOIN brands b ON b.brand_id = p.brand_id
         LEFT JOIN categories c ON c.category_id = p.category_id
         LEFT JOIN currencies cur ON cur.currency_id = p.currency_id
         LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
         WHERE p.is_active = TRUE AND p.is_published = TRUE
           AND " . ($byId ? "p.product_id = :key" : "LOWER(p.slug) = :key"),
        [':key' => $byId ? (int) $idOrSlug : strtolower((string) $idOrSlug)]
    );
    if (!$row) {
        return null;
    }

    $images = db_all(
        "SELECT image_url, alt_text, is_primary, sort_order
         FROM product_images WHERE product_id = :id
         ORDER BY is_primary DESC, sort_order ASC",
        [':id' => $row['product_id']]
    );
    $specs = db_all(
        "SELECT spec_group, spec_name, spec_value
         FROM product_specifications WHERE product_id = :id
         ORDER BY sort_order ASC, spec_id ASC",
        [':id' => $row['product_id']]
    );

    // Group specs by spec_group for a tidy spec sheet.
    $grouped = [];
    foreach ($specs as $s) {
        $g = $s['spec_group'] ?: 'General';
        $grouped[$g][] = ['name' => $s['spec_name'], 'value' => $s['spec_value']];
    }
    $specSheet = [];
    foreach ($grouped as $group => $items) {
        $specSheet[] = ['group' => $group, 'items' => $items];
    }

    $card = catalog_shape_card($row + ['primary_image' => $images[0]['image_url'] ?? null]);

    return $card + [
        'description'     => $row['description'],
        'barcode'         => $row['barcode'],
        'weight_kg'       => $row['weight_kg'] !== null ? (float) $row['weight_kg'] : null,
        'unit'            => $row['uom_abbr'],
        'currency_symbol' => $row['currency_symbol'] ?? 'KSh',
        'meta'            => ['title' => $row['meta_title'], 'description' => $row['meta_description']],
        'images'          => array_map(fn($im) => [
            'url'        => catalog_asset_url($im['image_url']),
            'alt'        => $im['alt_text'],
            'is_primary' => (bool) $im['is_primary'],
        ], $images),
        'specifications'  => $specSheet,
    ];
}

/** Public list of active brands (for storefront filters). */
function catalog_brands(): array
{
    return array_map(
        fn($b) => ['name' => $b['name'], 'slug' => $b['slug'], 'logo' => catalog_asset_url($b['logo_url'])],
        db_all("SELECT name, slug, logo_url FROM brands WHERE is_active = TRUE ORDER BY name")
    );
}

/** Public list of active categories with product counts (for storefront filters). */
function catalog_categories(): array
{
    return array_map(
        fn($c) => [
            'name'  => $c['name'],
            'slug'  => $c['slug'],
            'icon'  => $c['icon'],
            'count' => (int) $c['product_count'],
        ],
        db_all(
            "SELECT c.name, c.slug, c.icon,
                    (SELECT COUNT(*) FROM products p WHERE p.category_id = c.category_id AND p.is_active AND p.is_published) AS product_count
             FROM categories c
             WHERE c.is_active = TRUE
             ORDER BY c.sort_order, c.name"
        )
    );
}
