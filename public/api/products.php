<?php

/**
 * ============================================================
 *  API — Public Product Catalogue (read)
 * ------------------------------------------------------------
 *  The endpoint the e-commerce storefront fetches products
 *  from. Read-only; exposes only published + active products.
 *
 *  Routes (GET):
 *    /api/products.php                      → list (see filters)
 *    /api/products.php?id=123               → single product
 *    /api/products.php?slug=dome-camera     → single product
 *    /api/products.php?resource=brands      → brand filter list
 *    /api/products.php?resource=categories  → category filter list
 *
 *  List filters (query string):
 *    q, category (slug|id), brand (slug), min_price, max_price,
 *    in_stock=1, on_sale=1, featured=1,
 *    sort=price_asc|price_desc|newest|name|rating,
 *    page, per_page (max 60)
 *
 *  Auth: optional. If CATALOG_API_KEY is set in .env it must be
 *  sent as X-Api-Key (server-to-server). If it is empty the
 *  endpoint is public (catalogue data is public anyway).
 *
 *  CORS: set WEB_ORIGIN in .env to your storefront origin to
 *  allow browser fetch(); defaults to '*' for GET.
 * ============================================================
 */

declare(strict_types=1);

// Entered through the API front door; the guard in includes/
// only exists to stop those files being requested directly.
define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/catalog.php';

security_firewall();

// The one endpoint anyone on the internet may call without a
// key, which makes it the one worth scraping. The limit is set
// where a storefront rendering a category page never notices it
// and a scraper walking every product does.
rate_limit_or_stop('api:catalog:' . client_ip(), 240, 60, 'requests');

// ── CORS (read-only, GET) ───────────────────────────────────
$allowOrigin = (string) env('WEB_ORIGIN', '*');
header('Access-Control-Allow-Origin: ' . ($allowOrigin !== '' ? $allowOrigin : '*'));
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: X-Api-Key, Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function api_out($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function api_err(string $message, int $status = 400): void
{
    api_out(['success' => false, 'error' => $message], $status);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_err('Only GET is supported.', 405);
}

// ── Optional API key ────────────────────────────────────────
$configuredKey = (string) env('CATALOG_API_KEY', '');
if ($configuredKey !== '') {
    $given = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($given) || !hash_equals($configuredKey, $given)) {
        api_err('Invalid or missing API key.', 401);
    }
}

try {
    // ── Filter-facet resources ──────────────────────────────
    $resource = $_GET['resource'] ?? null;
    if ($resource === 'brands') {
        api_out(['success' => true, 'data' => catalog_brands()]);
    }
    if ($resource === 'categories') {
        api_out(['success' => true, 'data' => catalog_categories()]);
    }

    // ── Single product ──────────────────────────────────────
    if (isset($_GET['id']) || isset($_GET['slug'])) {
        $product = catalog_get_product($_GET['id'] ?? $_GET['slug']);
        if (!$product) {
            api_err('Product not found.', 404);
        }
        api_out(['success' => true, 'data' => $product]);
    }

    // ── Product list ────────────────────────────────────────
    $result = catalog_list_products([
        'q'         => $_GET['q'] ?? null,
        'category'  => $_GET['category'] ?? null,
        'brand'     => $_GET['brand'] ?? null,
        'min_price' => $_GET['min_price'] ?? null,
        'max_price' => $_GET['max_price'] ?? null,
        'in_stock'  => $_GET['in_stock'] ?? null,
        'on_sale'   => $_GET['on_sale'] ?? null,
        'featured'  => $_GET['featured'] ?? null,
        'sort'      => $_GET['sort'] ?? null,
        'page'      => $_GET['page'] ?? null,
        'per_page'  => $_GET['per_page'] ?? null,
    ]);

    api_out([
        'success'    => true,
        'data'       => $result['data'],
        'pagination' => $result['pagination'],
    ]);
} catch (Throwable $e) {
    error_log('[API/products] ' . $e->getMessage());
    api_err('The catalogue is temporarily unavailable.', 500);
}
