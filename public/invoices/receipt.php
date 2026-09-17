<?php

/**
 * ============================================================
 *  Invoices — Till receipt (80mm / 58mm thermal)
 * ------------------------------------------------------------
 *  The narrow slip, for a customer who wants what a supermarket
 *  hands them rather than a sheet of A4.
 *
 *  ?id=<invoice_id>          → inline (the Receipt button)
 *  ?id=<invoice_id>&dl=1     → force download
 *  ?id=<invoice_id>&w=58     → print it for a 58mm roll
 *
 *  It is the same invoice as invoices/pdf.php, read from the
 *  same row. Not a second document and not a new number: a
 *  receipt and the A4 copy of one sale must never be able to
 *  disagree about what was sold or what it cost.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/document_pdf.php';
// payment_method_label(), for naming how the sale was settled.
// Not loaded by bootstrap, so it is asked for here rather than
// relied upon — an undefined function is a blank receipt.
require_once __DIR__ . '/../../includes/cashbook.php';
require_tables('invoices', 'invoice_items');

$id = input_int($_GET, 'id');
if ($id === null) {
    flash('error', 'No invoice specified.');
    redirect('invoices/index.php');
}

$inv = db_one(
    "SELECT i.*, cur.code AS currency_code, cur.symbol AS currency_symbol,
            c.company_name, c.first_name, c.last_name, c.tax_pin,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name
     FROM invoices i
     LEFT JOIN customers c ON c.customer_id = i.customer_id
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
    "SELECT ii.*, p.name AS product_name
     FROM invoice_items ii
     JOIN products p ON p.product_id = ii.product_id
     WHERE ii.invoice_id = :id
     ORDER BY ii.invoice_item_id",
    [':id' => $id]
);

$symbol = $inv['currency_symbol'] ?: 'KES';

/*  Money, without the currency word in front of every figure.
 *
 *  On a 46-character line "KES 24,000.00" against a name is most
 *  of the width gone. The currency is stated once, in the totals
 *  block, which is where anybody looks for it. */
$amt = static fn($v) => num((float) $v, 2);

// ── What was sold ────────────────────────────────────────────
$rows = [];
foreach ($items as $it) {
    $rows[] = [
        'name'       => $it['product_name'],
        'qty'        => num($it['quantity'], (float) $it['quantity'] == (int) $it['quantity'] ? 0 : 2),
        'unit_price' => $amt($it['unit_price']),
        'discount'   => (float) $it['discount'] > 0.005 ? $amt($it['discount']) : null,
        'amount'     => $amt($it['subtotal']),
    ];
}

// ── Who it was for, and who served them ──────────────────────
$meta = [
    ['No', $inv['invoice_number']],
    ['Date', fmt_date($inv['issue_date'], 'd M Y')],
];

$customer = customer_display_name($inv);
if ($customer !== '') {
    $meta[] = ['Customer', $customer];
}
// A customer's own PIN, because without it they cannot claim the
// VAT on this receipt back. It is the single most common reason
// somebody brings a till slip back to the counter.
if (!empty($inv['tax_pin'])) {
    $meta[] = ['Cust PIN', $inv['tax_pin']];
}
if (!empty($inv['issued_by_name'])) {
    $meta[] = ['Served by', $inv['issued_by_name']];
}

// ── The money ────────────────────────────────────────────────
$totals = [
    ['Subtotal', $amt($inv['subtotal'])],
    ['Discount', '-' . $amt($inv['discount_amount']), (float) $inv['discount_amount'] > 0.005],
    ['Tax', $amt($inv['tax_amount']), (float) $inv['tax_amount'] > 0.005],
];

/*  How it was settled.
 *
 *  Listed by method rather than as one "paid" figure, because
 *  "Cash 20,000 / M-Pesa 11,320" is the thing a customer checks
 *  and the thing a till reconciliation needs. Guarded because the
 *  application is written to survive a database one migration
 *  behind, and a receipt that will not print is worse than one
 *  that does not itemise the payment.                          */
$payments = [];
if (table_exists('invoice_payments')) {
    $byMethod = db_all(
        "SELECT method, SUM(amount) AS paid
           FROM invoice_payments
          WHERE invoice_id = :id
          GROUP BY method
          ORDER BY SUM(amount) DESC",
        [':id' => $id]
    );
    foreach ($byMethod as $p) {
        $payments[] = [payment_method_label($p['method']), $amt($p['paid'])];
    }
}
if (!$payments && (float) $inv['amount_paid'] > 0.005) {
    // Paid, but the detail is not there to show — an older row, or
    // a database without the payments table.
    $payments[] = ['Paid', $amt($inv['amount_paid'])];
}

$balanceDue = (float) ($inv['balance_due'] ?? 0);
$balance    = null;
if ($balanceDue > 0.005) {
    $balance = ['BALANCE DUE', $amt($balanceDue)];
} elseif ($payments) {
    $balance = ['PAID IN FULL', ''];
}

$count = count($items);

stream_receipt_pdf([
    // "TAX INVOICE" only when tax was actually charged. Calling a
    // receipt with no VAT on it a tax invoice is a claim the
    // business is not entitled to make.
    'title'      => (float) $inv['tax_amount'] > 0.005 ? 'TAX INVOICE' : 'INVOICE',
    'filename'   => pdf_document_filename('Receipt', $inv['invoice_number']),
    'download'   => !empty($_GET['dl']),
    'meta'       => $meta,
    'items'      => $rows,
    'totals'     => $totals,
    'grand'      => ['TOTAL ' . $symbol, $amt($inv['total_amount'])],
    'payments'   => $payments,
    'balance'    => $balance,
    'item_count' => $count . ' item' . ($count === 1 ? '' : 's'),
    'tax_note'   => $inv['tax_note'] ?? null,
    'footer'     => 'Thank you for your business',
]);
