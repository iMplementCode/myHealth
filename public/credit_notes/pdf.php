<?php

/**
 * ============================================================
 *  Credit Note — PDF (print / download)
 * ------------------------------------------------------------
 *  ?id=<credit_note_id>        → inline (Print)
 *  ?id=<credit_note_id>&dl=1   → download
 *
 *  A credit note is the customer's evidence that a sale was
 *  undone, so it carries what an invoice carries — the goods,
 *  their prices, the tax and the total — and states which
 *  invoice it reverses. Without it the customer has our word
 *  and nothing to file against their own copy of the invoice.
 *
 *  A draft is deliberately printable. The customer standing at
 *  the counter with the goods needs paper before anyone gets
 *  round to approving it, and a draft says DRAFT across its
 *  status line so it cannot be mistaken for the final one.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(ROLE_MANAGER);
require_once __DIR__ . '/../../includes/returns.php';
require_once __DIR__ . '/../../includes/document_pdf.php';
require_tables('credit_notes', 'credit_note_items');

$cn = credit_note_row(input_int($_GET, 'id'));
if (!$cn) {
    flash('error', 'That credit note does not exist.');
    redirect('credit_notes/index.php');
}

$items    = credit_note_items((int) $cn['credit_note_id']);
$customer = db_one(
    "SELECT company_name, first_name, last_name, location, email, phone, tax_pin
       FROM customers WHERE customer_id = :id",
    [':id' => $cn['customer_id']]
);
$symbol = db_value(
    "SELECT cur.symbol FROM invoices i
       JOIN currencies cur ON cur.currency_id = i.currency_id
      WHERE i.invoice_id = :id",
    [':id' => $cn['invoice_id']]
) ?: 'KES';

// Company first, contact underneath. See invoices/pdf.php.
$contact   = customer_contact_name($customer ?? []);
$custLines = [];
foreach ([
    $contact !== '' ? 'Attn: ' . $contact : null,
    ($customer['location'] ?? '') !== '' ? $customer['location'] : null,
    ($customer['phone'] ?? '') !== '' ? 'Phone: ' . $customer['phone'] : null,
    ($customer['email'] ?? '') !== '' ? $customer['email'] : null,
    ($customer['tax_pin'] ?? '') !== '' ? 'PIN: ' . $customer['tax_pin'] : null,
] as $line) {
    if ($line !== null && $line !== '') {
        $custLines[] = $line;
    }
}

// Which invoice this reverses is the first thing anybody checks,
// so it sits directly under the number.
$meta = [
    ['Number',          $cn['cn_number']],
    ['Against invoice', $cn['invoice_number']],
    ['Date',            fmt_date($cn['issue_date'])],
    ['Scope',           $cn['return_scope'] === 'full' ? 'Full return' : 'Part return'],
    ['Status',          ucfirst($cn['status'])],
];
if ($cn['return_number']) {
    $meta[] = ['Goods return', $cn['return_number']];
}
if ($cn['approved_by_name']) {
    $meta[] = ['Approved by', $cn['approved_by_name']];
}

$rows = [];
foreach ($items as $it) {
    $rows[] = [
        'name'       => $it['product_name'],
        'sku'        => $it['sku'],
        'qty'        => num($it['quantity'], 2),
        'unit_price' => num($it['unit_price'], 2),
        'discount'   => num($it['discount'], 2),
        'subtotal'   => num($it['subtotal'], 2),
    ];
}

$totals = [['Subtotal', money($cn['subtotal'], $symbol)]];
if ((float) $cn['tax_amount'] > 0.005) {
    $totals[] = [vat_rate_label($cn['tax_rate']), money($cn['tax_amount'], $symbol)];
}

// The reason is the whole justification for the document, so it is
// printed rather than left in the system.
$notes = trim(($cn['reason'] ?? '') . "\n" . ($cn['notes'] ?? ''));

stream_document_pdf([
    'title'          => 'CREDIT NOTE',
    'filename'       => pdf_document_filename('CreditNote', $cn['cn_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Credited to',
    'customer_name'  => $cn['customer_name'],
    'customer_lines' => $custLines,
    'items'          => $rows,
    'totals'         => $totals,
    'grand'          => ['Total credited', money($cn['total_amount'], $symbol)],
    'notes'          => $notes !== '' ? $notes : null,
    'terms'          => 'This credit note reduces what is owed on '
                        . $cn['invoice_number'] . '. Where that invoice was already '
                        . 'settled, the amount above is refundable.',
    'terms_label'    => 'What this means',
    'footer'         => 'Credit note ' . $cn['cn_number'] . ' against invoice '
                        . $cn['invoice_number'] . ' · ' . APP_NAME,
]);
