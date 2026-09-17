<?php

/**
 * ============================================================
 *  Purchase Order — PDF
 * ------------------------------------------------------------
 *  Rebuilt onto the shared renderer. The page it replaces
 *  opened its own PDO from $_ENV, carried its own copy of the
 *  stylesheet, and ran three queries with no error handling at
 *  all — so one column it did not expect took the whole page
 *  down with a blank HTTP 500, which the browser shows as
 *  "This page isn't working" and which says nothing about what
 *  went wrong.
 *
 *  That is exactly what happened: `suppliers.address` became
 *  `suppliers.location` in migration 036, and any database that
 *  had not had it applied answered 500 for every purchasing
 *  document at once.
 *
 *  Now it shares db(), the shared logo, and one header with
 *  every other document the business issues.
 *
 *  Purchasing is a Manager's module.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/document_pdf.php';
require_once __DIR__ . '/../../../includes/workflow.php';
require_role(ROLE_MANAGER);
require_tables('purchase_orders', 'purchase_order_items');

$id = input_int($_GET, 'po_id') ?? input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No purchase order specified.');
    redirect('modules/purchasing/manage_pos.php');
}

$po = db_one(
    "SELECT po.*, s.name AS supplier_name, " . supplier_location_sql('s') . " AS supplier_location,
            s.email AS supplier_email, s.phone AS supplier_phone,
            cur.code AS currency_code, cur.symbol AS currency_symbol,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
       FROM purchase_orders po
       LEFT JOIN suppliers s    ON s.supplier_id = po.supplier_id
       LEFT JOIN currencies cur ON cur.currency_id = po.currency_id
       LEFT JOIN users u        ON u.user_id = po.issued_by
      WHERE po.po_id = :id",
    [':id' => $id]
);
if (!$po) {
    flash('error', 'That purchase order does not exist.');
    redirect('modules/purchasing/manage_pos.php');
}

$items = db_all(
    "SELECT poi.*, p.name AS product_name, p.sku
       FROM purchase_order_items poi
       LEFT JOIN products p ON p.product_id = poi.product_id
      WHERE poi.po_id = :id
      ORDER BY poi.po_item_id",
    [':id' => $id]
);

$symbol = $po['currency_symbol'] ?: ($po['currency_code'] ?: 'KES');

// ── Who it is addressed to ───────────────────────────────────
$supLines = [];
foreach ([
    $po['supplier_location'] ?? null,
    ($po['supplier_phone'] ?? '') !== '' ? 'Phone: ' . $po['supplier_phone'] : null,
    ($po['supplier_email'] ?? '') !== '' ? $po['supplier_email'] : null,
] as $line) {
    if ($line !== null && $line !== '') {
        $supLines[] = $line;
    }
}

$meta = [
    ['Number', $po['po_number']],
    ['Issue date', fmt_date($po['issue_date'])],
];
if (!empty($po['expected_delivery_date'])) {
    $meta[] = ['Expected', fmt_date($po['expected_delivery_date'])];
}
$meta[] = ['Status', status_label($po['status'])];
if (!empty($po['issued_by_name'])) {
    $meta[] = ['Raised by', $po['issued_by_name']];
}

$rows = [];
foreach ($items as $n => $it) {
    $qty  = (float) $it['quantity_ordered'];
    $unit = (float) $it['unit_price'];
    $rows[] = [
        'name'       => $it['product_name'] ?? 'Item',
        'sku'        => $it['sku'] ?? '',
        'qty'        => num($qty, 2),
        'unit_price' => money($unit, $symbol),
        'discount'   => money(0, $symbol),
        'subtotal'   => money($it['subtotal'] ?? ($qty * $unit), $symbol),
    ];
}

// Where the goods are to be sent belongs on the order the supplier
// reads, not in a column nobody prints.
$poNotes = trim((string) ($po['notes'] ?? ''));
if (!empty($po['delivery_address'])) {
    $poNotes = trim("Deliver to: " . $po['delivery_address'] . "\n\n" . $poNotes);
}

$totals = [
    ['Subtotal', money($po['subtotal'], $symbol)],
    ['Tax', money($po['tax_amount'], $symbol), (float) $po['tax_amount'] > 0.005],
];

stream_document_pdf([
    'title'          => 'PURCHASE ORDER',
    'filename'       => pdf_document_filename('PurchaseOrder', $po['po_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Order to',
    'customer_name'  => $po['supplier_name'] ?: 'Supplier',
    'customer_lines' => $supLines,
    'items'          => $rows,
    'totals'         => $totals,
    'grand'          => ['Total', money($po['total_amount'], $symbol)],
    'notes'          => $poNotes ?: null,
    'terms'          => $po['terms'] ?? null,
    'terms_label'    => 'Terms',
]);
