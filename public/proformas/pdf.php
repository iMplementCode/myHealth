<?php

/**
 * ============================================================
 *  Proforma Invoices — PDF (print / download)
 * ------------------------------------------------------------
 *  Streams a brand-coloured, layout-stable PDF of a proforma.
 *  ?id=<pi_id>          → inline (used by the Print button)
 *  ?id=<pi_id>&dl=1     → force download
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/document_pdf.php';
require_tables('proforma_invoices', 'proforma_invoice_items');

$id = input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No proforma specified.');
    redirect('proformas/index.php');
}

$pi = db_one(
    "SELECT pi.*, q.quote_number, inv.invoice_number,
            cur.code AS currency_code, cur.symbol AS currency_symbol,
            c.company_name, c.first_name, c.last_name, c.email AS customer_email, c.phone AS customer_phone, c.tax_pin,
            c.location AS customer_location,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS prepared_by_name
     FROM proforma_invoices pi
     LEFT JOIN customers c ON c.customer_id = pi.customer_id
     LEFT JOIN quotes q ON q.quote_id = pi.quote_id
     LEFT JOIN invoices inv ON inv.invoice_id = pi.converted_invoice_id
     LEFT JOIN currencies cur ON cur.currency_id = pi.currency_id
     LEFT JOIN users u ON u.user_id = pi.prepared_by
     WHERE pi.pi_id = :id",
    [':id' => $id]
);
if (!$pi) {
    flash('error', 'That proforma does not exist.');
    redirect('proformas/index.php');
}

$items = db_all(
    "SELECT pii.*, p.name AS product_name, p.sku
     FROM proforma_invoice_items pii
     JOIN products p ON p.product_id = pii.product_id
     WHERE pii.pi_id = :id
     ORDER BY pii.pi_item_id",
    [':id' => $id]
);

$symbol       = $pi['currency_symbol'] ?: 'KES';
// Company first, contact underneath. See invoices/pdf.php.
$customerName = customer_display_name($pi);

$custLines = [];
if ($contact = customer_contact_name($pi)) {
    $custLines[] = 'Attn: ' . $contact;
}
// Where they are, under the name — the address somebody
// reads off the document when they have to go there.
if (!empty($pi['customer_location'])) {
    $custLines[] = $pi['customer_location'];
}
if (!empty($pi['customer_email'])) {
    $custLines[] = $pi['customer_email'];
}
if (!empty($pi['customer_phone'])) {
    $custLines[] = 'Phone: ' . $pi['customer_phone'];
}
if (!empty($pi['tax_pin'])) {
    $custLines[] = 'PIN: ' . $pi['tax_pin'];
}

$meta = [
    ['Number', $pi['pi_number']],
];
if ($pi['quote_number']) {
    $meta[] = ['Quote', $pi['quote_number']];
}
if ($pi['invoice_number']) {
    $meta[] = ['Invoice', $pi['invoice_number']];
}
$meta[] = ['Issue date', fmt_date($pi['issue_date'])];
$meta[] = ['Valid until', fmt_date($pi['expiry_date'])];
$meta[] = ['Status', ucfirst($pi['status'])];

$rows = [];
foreach ($items as $it) {
    $rows[] = [
        'name'       => $it['product_name'],
        'sku'        => $it['sku'],
        'qty'        => num($it['quantity'], 2),
        'unit_price' => money($it['unit_price'], $symbol),
        'discount'   => (float) $it['discount'] > 0 ? '- ' . money($it['discount'], $symbol) : '—',
        'subtotal'   => money($it['subtotal'], $symbol),
        // Carried from the quote. Absent on a database that has not
        // run migration 056, and on any line without a note.
        'note'       => $it['note'] ?? null,
    ];
}

$totals = [
    ['Subtotal', money($pi['subtotal'], $symbol)],
    ['Discount', '- ' . money($pi['discount_amount'], $symbol), (float) $pi['discount_amount'] > 0.005],
    ['Tax', money($pi['tax_amount'], $symbol), (float) $pi['tax_amount'] > 0.005],
];

stream_document_pdf([
    'title'          => 'PROFORMA INVOICE',
    'filename'       => pdf_document_filename('Proforma', $pi['pi_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Prepared for',
    'customer_name'  => $customerName,
    'customer_lines' => $custLines,
    'items'          => $rows,
    'totals'         => $totals,
    'grand'          => ['Total', money($pi['total_amount'], $symbol)],
    'notes'          => $pi['notes'] ?? null,
    'terms'          => $pi['terms'] ?? null,
    'pay_to'         => company_payment_details($pi['pi_number'] ?? null),
    'tax_note'       => $pi['tax_note'] ?? null,
    'terms_label'    => 'Terms',
    'footer'         => 'Prepared by ' . ($pi['prepared_by_name'] ?: '—') . ' · ' . APP_NAME,
]);
