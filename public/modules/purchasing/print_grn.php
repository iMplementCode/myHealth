<?php

/**
 * ============================================================
 *  Goods Received Note — PDF
 * ------------------------------------------------------------
 *  Rebuilt onto the shared renderer, for the reasons set out in
 *  print_po.php: the page it replaces opened its own PDO and ran
 *  its queries unprotected, so a single unexpected column
 *  answered a blank HTTP 500.
 *
 *  A GRN is the warehouse's record of what actually turned up,
 *  so it prints what was received AND what was rejected. A
 *  receipt that hides the rejected column is the one somebody
 *  argues with the supplier about later.
 *
 *  Purchasing is a Manager's module.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/document_pdf.php';
require_once __DIR__ . '/../../../includes/workflow.php';
require_role(ROLE_MANAGER);
require_tables('grns', 'grn_items');

$id = input_int($_GET, 'grn_id') ?? input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No goods received note specified.');
    redirect('modules/purchasing/manage_grns.php');
}

$grn = db_one(
    "SELECT g.*, s.name AS supplier_name, " . supplier_location_sql('s') . " AS supplier_location,
            s.email AS supplier_email, s.phone AS supplier_phone,
            po.po_number,
            cur.code AS currency_code, cur.symbol AS currency_symbol,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS received_by_name
       FROM grns g
       LEFT JOIN suppliers s        ON s.supplier_id = g.supplier_id
       LEFT JOIN purchase_orders po ON po.po_id = g.po_id
       LEFT JOIN currencies cur     ON cur.currency_id = g.currency_id
       LEFT JOIN users u            ON u.user_id = g.received_by
      WHERE g.grn_id = :id",
    [':id' => $id]
);
if (!$grn) {
    flash('error', 'That goods received note does not exist.');
    redirect('modules/purchasing/manage_grns.php');
}

$items = db_all(
    "SELECT gi.*, p.name AS product_name, p.sku
       FROM grn_items gi
       LEFT JOIN products p ON p.product_id = gi.product_id
      WHERE gi.grn_id = :id
      ORDER BY gi.grn_item_id",
    [':id' => $id]
);

$symbol = $grn['currency_symbol'] ?: ($grn['currency_code'] ?: 'KES');

$supLines = [];
foreach ([
    $grn['supplier_location'] ?? null,
    ($grn['supplier_phone'] ?? '') !== '' ? 'Phone: ' . $grn['supplier_phone'] : null,
    ($grn['supplier_email'] ?? '') !== '' ? $grn['supplier_email'] : null,
] as $line) {
    if ($line !== null && $line !== '') {
        $supLines[] = $line;
    }
}

$meta = [
    ['Number', $grn['grn_number']],
    ['Received', fmt_date($grn['receipt_date'])],
];
if (!empty($grn['po_number'])) {
    $meta[] = ['Against PO', $grn['po_number']];
}
if (!empty($grn['supplier_invoice_no'])) {
    $meta[] = ['Supplier invoice', $grn['supplier_invoice_no']];
}
$meta[] = ['Status', status_label($grn['status'])];
if (!empty($grn['received_by_name'])) {
    $meta[] = ['Received by', $grn['received_by_name']];
}

// What was rejected only earns a column when something was.
$anyRejected = false;
foreach ($items as $it) {
    if ((float) ($it['quantity_rejected'] ?? 0) > 0.0005) {
        $anyRejected = true;
        break;
    }
}

$rows     = [];
$received = 0.0;
$rejected = 0.0;
foreach ($items as $it) {
    $qty  = (float) $it['quantity_received'];
    $rej  = (float) ($it['quantity_rejected'] ?? 0);
    $unit = (float) $it['unit_cost'];
    $received += $qty;
    $rejected += $rej;

    $name = $it['product_name'] ?? 'Item';
    if ($anyRejected && $rej > 0.0005) {
        // The renderer's columns are fixed, so the rejection rides
        // with the name rather than being dropped for want of a
        // column of its own.
        $name .= ' — ' . num($rej, 2) . ' rejected';
    }
    $rows[] = [
        'name'       => $name,
        'sku'        => $it['sku'] ?? '',
        'qty'        => num($qty, 2),
        'unit_price' => money($unit, $symbol),
        'discount'   => money(0, $symbol),
        'subtotal'   => money($it['subtotal'] ?? ($qty * $unit), $symbol),
    ];
}

$goodsValue = 0.0;
foreach ($items as $it) {
    $goodsValue += (float) ($it['subtotal'] ?? ((float) $it['quantity_received'] * (float) $it['unit_cost']));
}

$totals = [
    ['Goods received', money($goodsValue, $symbol)],
    ['Tax', money($grn['tax_amount'] ?? 0, $symbol), (float) ($grn['tax_amount'] ?? 0) > 0.005],
];

$notes = trim((string) ($grn['notes'] ?? ''));
if ($rejected > 0.0005) {
    $notes = trim(sprintf(
        "%s unit(s) were rejected on receipt and are not in stock. They remain the supplier's.\n\n%s",
        num($rejected, 2), $notes
    ));
}

stream_document_pdf([
    'title'          => 'GOODS RECEIVED NOTE',
    'filename'       => pdf_document_filename('GRN', $grn['grn_number']),
    'download'       => !empty($_GET['dl']),
    'meta'           => $meta,
    'customer_label' => 'Received from',
    'customer_name'  => $grn['supplier_name'] ?: 'Supplier',
    'customer_lines' => $supLines,
    'items'          => $rows,
    'totals'         => $totals,
    'grand'          => ['Total', money($grn['total_amount'] ?? ($goodsValue + (float) ($grn['tax_amount'] ?? 0)), $symbol)],
    'notes'          => $notes ?: null,
]);
