<?php

/**
 * ============================================================
 *  Debit Note — PDF
 * ------------------------------------------------------------
 *  Rebuilt onto the shared renderer, for the reasons set out in
 *  print_po.php.
 *
 *  A debit note is the mirror of a credit note: goods went back
 *  to the supplier, so what we owe them falls. The document the
 *  supplier is handed has to say which receipt it reverses and
 *  why, because that is the argument it exists to settle.
 *
 *  Purchasing is a Manager's module.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/document_pdf.php';
require_once __DIR__ . '/../../../includes/workflow.php';
require_role(ROLE_MANAGER);
require_tables('debit_notes', 'debit_note_items');

$id = input_int($_GET, 'dn_id') ?? input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No debit note specified.');
    redirect('modules/purchasing/list_debit_notes.php');
}

$dn = db_one(
    "SELECT dn.*, s.name AS supplier_name, " . supplier_location_sql('s') . " AS supplier_location,
            s.email AS supplier_email, s.phone AS supplier_phone,
            g.grn_number, g.receipt_date,
            cur.code AS currency_code, cur.symbol AS currency_symbol,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
       FROM debit_notes dn
       LEFT JOIN suppliers s    ON s.supplier_id = dn.supplier_id
       LEFT JOIN grns g         ON g.grn_id = dn.grn_id
       LEFT JOIN currencies cur ON cur.currency_id = g.currency_id
       LEFT JOIN users u        ON u.user_id = dn.issued_by
      WHERE dn.debit_note_id = :id",
    [':id' => $id]
);
if (!$dn) {
    flash('error', 'That debit note does not exist.');
    redirect('modules/purchasing/list_debit_notes.php');
}

$items = db_all(
    "SELECT dni.*, p.name AS product_name, p.sku
       FROM debit_note_items dni
       LEFT JOIN products p ON p.product_id = dni.product_id
      WHERE dni.debit_note_id = :id
      ORDER BY dni.debit_item_id",
    [':id' => $id]
);

$symbol = $dn['currency_symbol'] ?: ($dn['currency_code'] ?: 'KES');

$supLines = [];
foreach ([
    $dn['supplier_location'] ?? null,
    ($dn['supplier_phone'] ?? '') !== '' ? 'Phone: ' . $dn['supplier_phone'] : null,
    ($dn['supplier_email'] ?? '') !== '' ? $dn['supplier_email'] : null,
] as $line) {
    if ($line !== null && $line !== '') {
        $supLines[] = $line;
    }
}

$meta = [
    ['Number', $dn['debit_note_number']],
    ['Issue date', fmt_date($dn['issue_date'])],
];
// Which receipt this reverses is the first thing anybody checks.
if (!empty($dn['grn_number'])) {
    $meta[] = ['Against GRN', $dn['grn_number']];
}
if (!empty($dn['receipt_date'])) {
    $meta[] = ['Received on', fmt_date($dn['receipt_date'])];
}
$meta[] = ['Status', status_label($dn['status'])];
if (!empty($dn['issued_by_name'])) {
    $meta[] = ['Raised by', $dn['issued_by_name']];
}

$rows  = [];
$value = 0.0;
foreach ($items as $it) {
    $qty  = (float) $it['quantity_returned'];
    $unit = (float) $it['unit_cost'];
    $line = (float) ($it['subtotal'] ?? ($qty * $unit));
    $value += $line;

    // Why it went back is the point of the document.
    $name   = $it['product_name'] ?? 'Item';
    $reason = trim((string) ($it['reason_description'] ?? '')) ?: trim((string) ($it['reason_code'] ?? ''));
    if ($reason !== '') {
        $name .= ' — ' . str_replace('_', ' ', $reason);
    }

    $rows[] = [
        'name'       => $name,
        'sku'        => $it['sku'] ?? '',
        'qty'        => num($qty, 2),
        'unit_price' => money($unit, $symbol),
        'discount'   => money(0, $symbol),
        'subtotal'   => money($line, $symbol),
    ];
}

$total = (float) ($dn['total_refund_amount'] ?? $value);

stream_document_pdf([
    'title'          => 'DEBIT NOTE',
    'filename'       => pdf_document_filename('DebitNote', $dn['debit_note_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Debited to',
    'customer_name'  => $dn['supplier_name'] ?: 'Supplier',
    'customer_lines' => $supLines,
    'items'          => $rows,
    'totals'         => [['Goods returned', money($value, $symbol)]],
    'grand'          => ['Total debited', money($total, $symbol)],
    'notes'          => 'This note reduces what is owed to the supplier by the amount above.',
]);
