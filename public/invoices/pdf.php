<?php

/**
 * ============================================================
 *  Invoices — PDF (print / download)
 * ------------------------------------------------------------
 *  Streams a brand-coloured, layout-stable PDF of an invoice.
 *  ?id=<invoice_id>          → inline (used by the Print button)
 *  ?id=<invoice_id>&dl=1     → force download
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/document_pdf.php';
require_tables('invoices', 'invoice_items');

$id = input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No invoice specified.');
    redirect('invoices/index.php');
}

$inv = db_one(
    "SELECT i.*, pi.pi_number, cur.code AS currency_code, cur.symbol AS currency_symbol,
            c.company_name, c.first_name, c.last_name, c.email AS customer_email, c.phone AS customer_phone, c.tax_pin,
            c.location AS customer_location,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
     FROM invoices i
     LEFT JOIN customers c ON c.customer_id = i.customer_id
     LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
     LEFT JOIN currencies cur ON cur.currency_id = i.currency_id
     LEFT JOIN users u ON u.user_id = i.issued_by
     WHERE i.invoice_id = :id",
    [':id' => $id]
);
if (!$inv) {
    flash('error', 'That invoice does not exist.');
    redirect('invoices/index.php');
}

$items = db_all(
    "SELECT ii.*, p.name AS product_name, p.sku
     FROM invoice_items ii
     JOIN products p ON p.product_id = ii.product_id
     WHERE ii.invoice_id = :id
     ORDER BY ii.invoice_item_id",
    [':id' => $id]
);

$symbol       = $inv['currency_symbol'] ?: 'KES';
// The company is billed; the contact is who to ask for. So the
// company leads and the person follows it, never the other way
// round — an invoice addressed to "Mitchelle" is addressed to
// nobody the accounts department recognises.
$customerName = customer_display_name($inv);

$custLines = [];
if ($contact = customer_contact_name($inv)) {
    $custLines[] = 'Attn: ' . $contact;
}
// Where they are, under the name — the address somebody
// reads off the document when they have to go there.
if (!empty($inv['customer_location'])) {
    $custLines[] = $inv['customer_location'];
}
if (!empty($inv['customer_email'])) {
    $custLines[] = $inv['customer_email'];
}
if (!empty($inv['customer_phone'])) {
    $custLines[] = 'Phone: ' . $inv['customer_phone'];
}
if (!empty($inv['tax_pin'])) {
    $custLines[] = 'PIN: ' . $inv['tax_pin'];
}

$meta = [
    ['Number', $inv['invoice_number']],
];
if ($inv['pi_number']) {
    $meta[] = ['Proforma', $inv['pi_number']];
}
$meta[] = ['Issue date', fmt_date($inv['issue_date'])];
$meta[] = ['Due date', fmt_date($inv['due_date'])];
$meta[] = ['Status', ucfirst($inv['status'])];

$rows = [];
foreach ($items as $it) {
    $rows[] = [
        'name'       => $it['product_name'],
        'sku'        => $it['sku'],
        'qty'        => num($it['quantity'], 2),
        'unit_price' => money($it['unit_price'], $symbol),
        'discount'   => (float) $it['discount'] > 0 ? '- ' . money($it['discount'], $symbol) : '—',
        'subtotal'   => money($it['subtotal'], $symbol),
        // Carried from the quote this invoice grew out of. Absent on
        // a database that has not run migration 056, and on any line
        // nobody wrote a note against.
        'note'       => $it['note'] ?? null,
    ];
}

$totals = [
    ['Subtotal', money($inv['subtotal'], $symbol)],
    ['Discount', '- ' . money($inv['discount_amount'], $symbol), (float) $inv['discount_amount'] > 0.005],
    ['Tax', money($inv['tax_amount'], $symbol), (float) $inv['tax_amount'] > 0.005],
];
if ((float) $inv['amount_paid'] > 0) {
    $totals[] = ['Amount paid', money($inv['amount_paid'], $symbol)];
    $totals[] = ['Balance due', money((float) $inv['balance_due'], $symbol)];
}

stream_document_pdf([
    'title'          => 'INVOICE',
    'filename'       => pdf_document_filename('Invoice', $inv['invoice_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Billed to',
    'customer_name'  => $customerName,
    'customer_lines' => $custLines,
    'items'          => $rows,
    'totals'         => $totals,
    'grand'          => ['Total', money($inv['total_amount'], $symbol)],
    'notes'          => $inv['notes'] ?? null,
    'terms'          => $inv['terms'] ?? null,
    // How to pay, from Settings → Company Details. Empty until it
    // is filled in, and then the block simply is not printed.
    'pay_to'         => company_payment_details($inv['invoice_number'] ?? null),
    'tax_note'       => $inv['tax_note'] ?? null,
    'terms_label'    => 'Payment terms',
    'footer'         => 'Issued by ' . ($inv['issued_by_name'] ?: '—') . ' · ' . APP_NAME,
]);
