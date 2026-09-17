<?php

/**
 * ============================================================
 *  Goods Return Note — PDF (print / download)
 * ------------------------------------------------------------
 *  ?id=<grn_return_id>        → inline (Print)
 *  ?id=<grn_return_id>&dl=1   → download
 *
 *  The counterpart of a delivery note: goods coming back rather
 *  than going out. It is a **receiving document, not a
 *  commercial one**, so it carries the product and the quantity
 *  and no money at all — the value of the return is the credit
 *  note's business, and a figure on this sheet only invites an
 *  argument at the counter about a price nobody is settling.
 *
 *  Two quantity columns, because they answer different
 *  questions:
 *
 *    Expected   what the credit note says should come back
 *    Received   what actually arrived
 *
 *  and a short column for the difference, which is what the
 *  person signing is really confirming. Printed before the
 *  goods arrive, Received is blank on purpose — it is a sheet
 *  to count against.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(ROLE_MANAGER);
require_once __DIR__ . '/../../includes/returns.php';
require_once __DIR__ . '/../../includes/document_pdf.php';
require_tables('goods_return_notes', 'goods_return_items');

$ret = return_note_row(input_int($_GET, 'id'));
if (!$ret) {
    flash('error', 'That goods return note does not exist.');
    redirect('returns/index.php');
}

$items    = return_note_items((int) $ret['grn_return_id']);
$customer = db_one(
    "SELECT company_name, first_name, last_name, location, email, phone
       FROM customers WHERE customer_id = :id",
    [':id' => $ret['customer_id']]
);

// Company first, contact underneath. See invoices/pdf.php.
$contact   = customer_contact_name($customer ?? []);
$custLines = [];
foreach ([
    $contact !== '' ? 'Attn: ' . $contact : null,
    ($customer['location'] ?? '') !== '' ? $customer['location'] : null,
    ($customer['phone'] ?? '') !== '' ? 'Phone: ' . $customer['phone'] : null,
    ($customer['email'] ?? '') !== '' ? $customer['email'] : null,
] as $line) {
    if ($line !== null && $line !== '') {
        $custLines[] = $line;
    }
}

$meta = [
    ['Number',      $ret['return_number']],
    ['Credit note', $ret['cn_number']],
];
if ($ret['invoice_number']) {
    $meta[] = ['Original invoice', $ret['invoice_number']];
}
$meta[] = ['Date', fmt_date($ret['return_date'])];
$meta[] = ['Status', $ret['status'] === 'awaiting' ? 'Awaiting goods' : ucfirst($ret['status'])];
if ($ret['shortfall_invoice_number']) {
    $meta[] = ['Shortfall billed', $ret['shortfall_invoice_number']];
}

$received = $ret['status'] !== 'awaiting';

$rows = [];
foreach ($items as $it) {
    $expected = (float) $it['quantity_expected'];
    $got      = (float) $it['quantity_received'];
    $short    = $expected - $got;

    // Before the goods arrive there is nothing to report but the
    // expectation, and printing a received column of zeros would
    // read as "none of it came back" rather than "not counted yet".
    $note = !$received
        ? ''
        : ($short > 0.0005
            ? num($short, 2) . ' short'
            : ($short < -0.0005 ? num(-$short, 2) . ' over' : 'Complete'));
    if ($received && ($it['condition'] ?? 'good') !== 'good') {
        $note = trim($note . ' · ' . ucfirst(str_replace('_', ' ', $it['condition'])));
    }
    if (!empty($it['notes'])) {
        $note = trim($note . ($note !== '' ? ' · ' : '') . $it['notes']);
    }

    $rows[] = [
        'name'     => $it['product_name'],
        'sku'      => $it['sku'],
        'expected' => num($expected, 2),
        'quantity' => $received ? num($got, 2) : '',
        'note'     => $note,
    ];
}

// The same renderer as a delivery note — it is the same document
// facing the other way — with the extra expected column and the
// signatures the other way round.
stream_delivery_pdf([
    'title'          => 'GOODS RETURN NOTE',
    'filename'       => pdf_document_filename('GoodsReturn', $ret['return_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Returned by',
    'customer_name'  => $ret['customer_name'],
    'customer_lines' => $custLines,
    'items'          => $rows,
    'qty_label'      => 'Received',
    'sign_left'      => 'Returned by (customer)',
    'sign_right'     => 'Received into stock by',
    'notes'          => $ret['notes'] ?? null,
    'footer_note'    => $received
        ? 'Quantities above are what was received into stock.'
        : 'Count the goods against the expected column and record what arrived.',
    'footer'         => 'Goods return ' . $ret['return_number'] . ' against credit note '
                        . $ret['cn_number'] . ' · ' . APP_NAME,
]);
