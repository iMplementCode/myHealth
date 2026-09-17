<?php

/**
 * ============================================================
 *  API — Website Order Intake
 * ------------------------------------------------------------
 *  Foundation for e-commerce integration: the website POSTs a
 *  JSON order here and it lands in sales_orders with
 *  sales_channel = 'ecommerce' and status 'draft', ready for
 *  fulfilment in the Sales Orders screen.
 *
 *  Authentication: shared secret in the X-Api-Key header,
 *  compared (constant-time) against ORDERS_API_KEY in .env.
 *  The endpoint is disabled until that key is configured.
 *
 *  Request (POST, application/json):
 *  {
 *    "customer": { "name": "...", "email": "...", "phone": "..." },
 *    "items":    [ { "sku": "CAM-001", "quantity": 2 },
 *                  { "product_id": 7, "quantity": 1 } ],
 *    "notes":    "optional"
 *  }
 *
 *  Response: { "success": true, "order_number": "SO-WEB-2026-0001" }
 * ============================================================
 */

declare(strict_types=1);

// API context: no session/redirect chrome — bootstrap pieces directly.
// Entered through the API front door; the guard in includes/
// only exists to stop those files being requested directly.
define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/documents.php';

header('Content-Type: application/json; charset=utf-8');

security_firewall();
security_force_https();

function api_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_fail('POST only.', 405);
}

// ── Rate limit ──────────────────────────────────────────────
// Counted before the key is checked, so someone guessing keys is
// throttled by the same rule as someone flooding valid orders.
// A storefront places orders at human speed; sixty a minute is
// already far more than a real shop generates.
rate_limit_or_stop('api:orders:' . client_ip(), 60, 60, 'requests');

// ── Authentication ──────────────────────────────────────────
$configuredKey = env('ORDERS_API_KEY', '');
if ($configuredKey === '' || $configuredKey === null) {
    api_fail('Order intake is not enabled. Set ORDERS_API_KEY in .env.', 503);
}
$givenKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!is_string($givenKey) || !hash_equals((string) $configuredKey, $givenKey)) {
    // A wrong key is cheap to try, so trying it is made expensive:
    // ten a minute from one address, then the door closes.
    rate_limit_or_stop('api:badkey:' . client_ip(), 10, 60, 'failed authentications');
    audit_log('api.auth_failed', '', null, ['ip' => client_ip(), 'endpoint' => 'orders']);
    api_fail('Invalid API key.', 401);
}

// ── Parse + validate payload ────────────────────────────────
$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    api_fail('Body must be valid JSON.');
}

$customer = $payload['customer'] ?? [];
$items    = $payload['items'] ?? [];
$notes    = is_string($payload['notes'] ?? null) ? trim($payload['notes']) : null;

$custName  = trim((string) ($customer['name'] ?? ''));
$custEmail = strtolower(trim((string) ($customer['email'] ?? '')));
$custPhone = trim((string) ($customer['phone'] ?? ''));

if ($custName === '' && $custEmail === '') {
    api_fail('customer.name or customer.email is required.');
}
if ($custEmail !== '' && !filter_var($custEmail, FILTER_VALIDATE_EMAIL)) {
    api_fail('customer.email is not a valid email address.');
}
if (!is_array($items) || count($items) === 0) {
    api_fail('At least one order item is required.');
}
if (count($items) > 100) {
    api_fail('Too many items in one order (max 100).');
}

$pdo = db();

try {
    $pdo->beginTransaction();

    // ── Customer: match by email, else create ───────────────
    $customerId = null;
    if ($custEmail !== '') {
        $customerId = db_value("SELECT customer_id FROM customers WHERE LOWER(email) = :e", [':e' => $custEmail]);
    }
    if ($customerId === null) {
        /*  Looking and then inserting is two steps, and a first-time
         *  customer who double-clicks Place Order sends both requests
         *  between them: neither finds a row, both insert, and
         *  customers_email_key throws out the second — which the
         *  shopper sees as "your order could not be created" for an
         *  order that was perfectly good. Measured at ten simultaneous
         *  posts from one new customer: five orders lost.
         *
         *  ON CONFLICT makes the database settle it, and DO NOTHING
         *  rather than DO UPDATE on purpose. DO UPDATE would hand back
         *  the id in one statement, but it takes a write lock on the
         *  customer row and holds it to commit — while the order
         *  insert a few lines later needs its own lock on that same
         *  row for the foreign key. Ten simultaneous orders from one
         *  new customer deadlocked on that pair, and Postgres cancels
         *  a deadlock by killing a transaction: four orders lost,
         *  where before the fix five were. DO NOTHING locks nothing,
         *  so the loser simply reads back the row the winner created.
         *
         *  A customer with no email cannot conflict (NULLs are all
         *  distinct to a unique index), so a name-only order still
         *  creates a fresh record every time, as it always has. */
        $parts = preg_split('/\s+/', $custName, 2) ?: [];
        $stmt = $pdo->prepare(
            "INSERT INTO customers (first_name, last_name, email, phone, is_active)
             VALUES (:fn, :ln, :email, :phone, TRUE)
             ON CONFLICT (email) DO NOTHING
             RETURNING customer_id"
        );
        $stmt->execute([
            ':fn'    => $parts[0] ?? ($custEmail ?: 'Web'),
            ':ln'    => $parts[1] ?? null,
            ':email' => $custEmail !== '' ? $custEmail : null,
            ':phone' => $custPhone !== '' ? $custPhone : null,
        ]);
        $inserted = $stmt->fetchColumn();
        if ($inserted === false) {
            // Somebody created them between our look and our insert.
            // Read committed gives this statement a fresh snapshot, so
            // the row the winner committed is visible now.
            $inserted = db_value(
                "SELECT customer_id FROM customers WHERE LOWER(email) = :e",
                [':e' => $custEmail]
            );
        }
        if ($inserted === null || $inserted === false) {
            throw new RuntimeException('the customer record could not be created or found.');
        }
        $customerId = (int) $inserted;
    }

    // ── Resolve items against the catalogue ─────────────────
    $resolved = [];
    $subtotal = 0.0;
    foreach ($items as $i => $item) {
        /*  The quantity is whatever a shopper typed into a box on a
         *  website. JSON will carry 1e999, which arrives here as INF,
         *  passes "greater than zero", and reaches Postgres as a
         *  number NUMERIC cannot hold — a 500 and a lost basket
         *  instead of "that is not a quantity". A finite ceiling says
         *  so plainly, and no real order is anywhere near it. */
        $qty = (float) ($item['quantity'] ?? 0);
        if (!is_finite($qty) || $qty <= 0) {
            throw new InvalidArgumentException('items[' . $i . '].quantity must be a number greater than zero.');
        }
        if ($qty > ORDER_INTAKE_MAX_QUANTITY) {
            throw new InvalidArgumentException('items[' . $i . '].quantity is above the limit of '
                . ORDER_INTAKE_MAX_QUANTITY . ' for one line.');
        }

        /*  is_published, not just is_active, and it has to match what
         *  the catalogue shows. api/products.php only ever lists
         *  products that are both, so an unpublished product is one
         *  the business has deliberately kept off the website —
         *  withdrawn, priced internally, or not ready. Accepting an
         *  order for it means a shopper who guesses a SKU can buy at
         *  whatever placeholder price the row happens to carry. */
        $product = null;
        if (!empty($item['product_id'])) {
            $product = db_one("SELECT product_id, name, selling_price, discount_price FROM products WHERE product_id = :id AND is_active = TRUE AND is_published = TRUE", [':id' => (int) $item['product_id']]);
        } elseif (!empty($item['sku'])) {
            $product = db_one("SELECT product_id, name, selling_price, discount_price FROM products WHERE sku = :sku AND is_active = TRUE AND is_published = TRUE", [':sku' => strtoupper(trim((string) $item['sku']))]);
        }
        if (!$product) {
            throw new InvalidArgumentException('items[' . $i . ']: product not found, inactive, or not published.');
        }
        // Website orders always use the catalogue price (discount price when set).
        $price = $product['discount_price'] !== null && (float) $product['discount_price'] > 0
            ? (float) $product['discount_price']
            : (float) $product['selling_price'];
        $resolved[] = ['product_id' => (int) $product['product_id'], 'qty' => $qty, 'price' => $price];
        $subtotal  += $qty * $price;
    }

    // ── Create the order ────────────────────────────────────
    $currencyId  = db_value("SELECT currency_id FROM currencies WHERE is_active = TRUE ORDER BY currency_id LIMIT 1");
    if ($currencyId === null) {
        throw new RuntimeException('No active currency configured.');
    }
    $orderNumber = next_document_number('sales_orders', 'order_number', 'SO-WEB');

    // A web order arrives unconfirmed, which in this schema is
    // 'draft' — 'pending' is not one of the statuses the table
    // allows, so every order intake was failing the check
    // constraint and returning "Order could not be created."
    $stmt = $pdo->prepare(
        "INSERT INTO sales_orders
            (order_number, customer_id, sales_channel, currency_id, subtotal, total_amount, status, created_at, updated_at)
         VALUES (:num, :cust, 'ecommerce', :cur, :sub, :total, 'draft', NOW(), NOW())
         RETURNING sales_order_id"
    );
    $stmt->execute([
        ':num' => $orderNumber, ':cust' => $customerId, ':cur' => $currencyId,
        ':sub' => round($subtotal, 2), ':total' => round($subtotal, 2),
    ]);
    $orderId = (int) $stmt->fetchColumn();

    $itemStmt = $pdo->prepare(
        "INSERT INTO sales_order_items (sales_order_id, product_id, quantity, unit_price)
         VALUES (:oid, :pid, :qty, :price)"
    );
    foreach ($resolved as $r) {
        $itemStmt->execute([':oid' => $orderId, ':pid' => $r['product_id'], ':qty' => $r['qty'], ':price' => $r['price']]);
    }

    $pdo->commit();
    audit_log('order.web_intake', 'sales_orders', $orderId, [
        'number' => $orderNumber, 'total' => round($subtotal, 2), 'items' => count($resolved),
    ]);

    echo json_encode([
        'success'      => true,
        'order_number' => $orderNumber,
        'order_id'     => $orderId,
        'total'        => round($subtotal, 2),
        'status'       => 'draft',
    ]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_fail($e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[API] order intake failed: ' . $e->getMessage());
    api_fail('Order could not be created.', 500);
}
