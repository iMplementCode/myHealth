<?php

/**
 * ============================================================
 *  Delivery Notes — PDF (print / download)
 * ------------------------------------------------------------
 *  Brand-coloured, layout-stable delivery document.
 *  ?id=<dn_id>        → inline (Print)
 *  ?id=<dn_id>&dl=1   → download
 *
 *  A delivery note carries no pricing — it evidences goods
 *  handed over, so the money columns are replaced by a
 *  signature block.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/document_pdf.php';
require_tables('delivery_notes', 'delivery_note_items');

$id = input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No delivery note specified.');
    redirect('delivery_notes/index.php');
}

$dn = db_one(
    "SELECT dn.*, so.order_number, q.quote_number, i.invoice_number,
            " . customer_name_sql('c') . " AS customer,
            c.company_name, c.first_name, c.last_name,
            c.email AS customer_email, c.phone AS customer_phone,
            c.location AS customer_location,
            TRIM(CONCAT(cu.first_name,' ',cu.last_name)) AS created_by_name
     FROM delivery_notes dn
     LEFT JOIN customers c     ON c.customer_id = dn.customer_id
     LEFT JOIN sales_orders so ON so.sales_order_id = dn.sales_order_id
     LEFT JOIN quotes q        ON q.quote_id = dn.quote_id
     LEFT JOIN invoices i      ON i.invoice_id = dn.invoice_id
     LEFT JOIN users cu        ON cu.user_id = dn.created_by
     WHERE dn.dn_id = :id",
    [':id' => $id]
);
if (!$dn) {
    flash('error', 'That delivery note does not exist.');
    redirect('delivery_notes/index.php');
}

$items = db_all(
    "SELECT dni.*, p.name AS product_name, p.sku
     FROM delivery_note_items dni
     JOIN products p ON p.product_id = dni.product_id
     WHERE dni.dn_id = :id
     ORDER BY p.name",
    [':id' => $id]
);

$custLines = [];
// Who to ask for at the gate. The company is on the line above —
// the driver needs a person to hand the goods to.
if ($contact = customer_contact_name($dn)) {
    $custLines[] = 'Attn: ' . $contact;
}
// The address typed on the note wins — a delivery can go
// somewhere other than the customer's usual place. Their location
// stands in when the note does not say, so the driver is never
// handed a document with no address on it at all.
if (!empty($dn['delivery_address'])) {
    $custLines[] = $dn['delivery_address'];
} elseif (!empty($dn['customer_location'])) {
    $custLines[] = $dn['customer_location'];
}
if (!empty($dn['customer_phone'])) {
    $custLines[] = 'Phone: ' . $dn['customer_phone'];
}
if (!empty($dn['delivered_to'])) {
    $custLines[] = 'Received by: ' . $dn['delivered_to'];
}

$meta = [['Number', $dn['dn_number']]];
if ($dn['order_number']) {
    $meta[] = ['Sales order', $dn['order_number']];
}
if ($dn['quote_number']) {
    $meta[] = ['Quote', $dn['quote_number']];
}
if ($dn['invoice_number']) {
    $meta[] = ['Invoice', $dn['invoice_number']];
}
$meta[] = ['Delivery date', fmt_date($dn['delivery_date'])];
if ($dn['vehicle_reg']) {
    $meta[] = ['Vehicle', $dn['vehicle_reg']];
}
if ($dn['driver_name']) {
    $meta[] = ['Driver', $dn['driver_name']];
}
$meta[] = ['Status', ucfirst($dn['status'])];

$rows = [];
foreach ($items as $it) {
    $rows[] = [
        'name'     => $it['product_name'],
        'sku'      => $it['sku'],
        'quantity' => num($it['quantity'], 2),
        'note'     => $it['notes'] ?: '',
    ];
}

stream_delivery_pdf([
    'title'          => 'DELIVERY NOTE',
    'filename'       => pdf_document_filename('DeliveryNote', $dn['dn_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Delivered to',
    'customer_name'  => $dn['customer'],
    'customer_lines' => $custLines,
    'items'          => $rows,
    'notes'          => $dn['notes'] ?? null,
    'footer'         => 'Prepared by ' . ($dn['created_by_name'] ?: '—') . ' · ' . APP_NAME,
]);
