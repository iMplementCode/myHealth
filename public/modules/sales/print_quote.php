<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/document_pdf.php';
// ============================================================
//  Print Quotation (PDF)
//  Run AI Technologies | Dompdf + PostgreSQL
// ============================================================


use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_GET['quote_id']) || empty($_GET['quote_id'])) {
    die("Error: No Quote ID provided.");
}

$quote_id = (int)$_GET['quote_id'];

/*  The application's connection, not one of this file's own.
 *
 *  This page used to build its own PDO from $_ENV['DB_USER'] and
 *  $_ENV['DB_PASS'], which made it the only page in the system with
 *  its own opinion about where the database is — and the day the
 *  credentials moved out of the environment, every other page kept
 *  working and this one answered "Database connection failed."
 *  Printing a quote broke while the quote list beside it was fine,
 *  which is the most confusing shape a fault can take.
 *
 *  db() is how the other ninety-odd pages connect. One definition,
 *  so this cannot drift again wherever the credentials live next. */
$pdo = db();

// 1. The letterhead — logo, company name, and how to reach it.
//    Shared with every other document the business issues, because
//    this file used to resolve the logo itself and got it wrong: it
//    glued the project root onto a relative path with no separator
//    between them, so the uploaded logo was never found and every
//    quote printed the one that ships with the software.
$head = company_letterhead();

//Fetch Quote & Customer Info
$stmtQuote = $pdo->prepare("
  SELECT 
        q.*, 
        " . customer_name_sql('c') . " as customer_name, 
        c.company_name, c.first_name, c.last_name, c.location as customer_location,
        c.email as customer_email, 
        c.phone as customer_phone,
        cur.code as currency_code,
       u.first_name || ' ' || u.last_name as preparer_name 
    FROM quotes q
    LEFT JOIN customers c ON q.customer_id = c.customer_id
    LEFT JOIN currencies cur ON q.currency_id = cur.currency_id
    LEFT JOIN users u ON q.prepared_by = u.user_id
    WHERE q.quote_id = :quote_id
");
$stmtQuote->execute([':quote_id' => $quote_id]);
$quote = $stmtQuote->fetch();

if (!$quote) {
    die("Quotation not found.");
}

// 3. Fetch Quote Items
$stmtItems = $pdo->prepare("
    SELECT qi.*, p.name as product_name, p.sku
    FROM quote_items qi
    LEFT JOIN products p ON qi.product_id = p.product_id
    WHERE qi.quote_id = :quote_id
    ORDER BY qi.quote_item_id ASC
");
$stmtItems->execute([':quote_id' => $quote_id]);
$items = $stmtItems->fetchAll();

/*  How to pay, from Settings → Company Details. A quote a customer
 *  accepts is a quote they want to pay, and making them ask for the
 *  paybill is a day lost. Empty until it is configured, and then no
 *  block is printed at all.                                        */
$payRows = '';
foreach (company_payment_details($quote['quote_number'] ?? null) as $row) {
    $payRows .= '<tr><td class="pay-label">' . e($row[0]) . '</td>'
              . '<td class="pay-value">' . nl2br(e($row[1])) . '</td></tr>';
}
$payBlock = $payRows === '' ? '' :
    '<div class="pay-box"><div class="pay-title">How to pay</div>'
    . '<table class="pay-table">' . $payRows . '</table></div>';

// Check if any items actually have a discount to decide if we show the column
$hasLineItemDiscounts = false;
foreach ($items as $item) {
    if ((float)$item['discount'] > 0) {
        $hasLineItemDiscounts = true;
        break;
    }
}

// 4. Build HTML
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quote - ' . e($quote['quote_number']) . '</title>
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 0; }
        .container { padding: 30px; }
        
        .header-table { width: 100%; margin-bottom: 30px; }
        .company-details { font-size: 11px; color: #555; line-height: 1.5; }
        .company-name { font-size: 19px; font-weight: bold; color: ' . BRAND_COLOR . '; margin-bottom: 5px; }
        /* One size for every document, chosen to fit the longest title
           the system prints — GOODS RECEIVED NOTE — inside its half of
           the header. nowrap is the guarantee: a title broken across
           lines stops looking like the name of the document. */
        .doc-title { font-size: 20px; font-weight: bold; color: ' . BRAND_COLOR . '; text-align: left; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        
        /* A note belonging to one line, printed under the item it is
           about. Indented and set in italic so it reads as a remark
           on the product above it rather than as a second product —
           which is exactly what it looked like when it was simply
           another line of text at the same size. */
        .line-note {
            margin-top: 5px;
            padding-left: 9px;
            border-left: 2px solid #ddd;
            font-size: 10.5px;
            font-style: italic;
            color: #666;
            line-height: 1.45;
        }

        .info-table { width: 100%; margin-bottom: 30px; border-collapse: collapse; }
        .info-table td { vertical-align: top; width: 50%; }
        .box-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
        /* Labels in a right-aligned column and values in a
           left-aligned one. The previous rule fixed the labels at
           100px inside a right-aligned block, which lines up the
           right edge of each line and nothing else — so the colons
           and the values came out ragged. */
        /* The right-hand header cell mirrors the left one: a title,
           then details beneath it, all sharing one left edge. Labels
           in the first column, values in the second, table sized to
           its own content so the two sit together. Same block in
           includes/document_pdf.php — every document the system
           prints has this header, and they have to match. */
        .meta-wrap { width: auto; margin-left: auto; border-collapse: collapse; }
        .meta-wrap-cell { padding: 0 !important; }
        .meta-table { border-collapse: collapse; width: auto; }
        .meta-table td { padding: 2px 0; font-size: 12px; white-space: nowrap; }
        .meta-label { text-align: left; padding-right: 14px !important; font-weight: bold; }
        .meta-value { text-align: left; }

        /* Inside the totals table rather than floated beside it:
           dompdf overlaps two floated blocks, which put the note
           straight through the subtotal figure. */
        .tax-note td {
            padding-top: 10px !important;
            border-bottom: 0 !important;
            font-size: 11px;
            font-style: italic;
            color: #555;
            text-align: right;
        }
        
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .items-table th { background: #f8f9fa; border-bottom: 2px solid #333; padding: 10px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .items-table td { border-bottom: 1px solid #eee; padding: 12px 8px; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        
        .totals-table { width: 45%; float: right; border-collapse: collapse; }
        .totals-table td { padding: 8px; border-bottom: 1px solid #eee; }
        /* An amount must never break across two lines: in a narrow
           totals column "KES" ended up above "40,000.00", which reads
           as a different figure entirely. */
        .totals-table td.text-right, .totals-table .amount { white-space: nowrap; }
        .totals-table .grand-total { font-weight: bold; font-size: 16px; border-top: 2px solid #333; border-bottom: 2px solid #333; color: ' . BRAND_COLOR . '; }
        
        /* Matches the block in includes/document_pdf.php — a quote
           and the invoice that follows it must not present the
           paybill two different ways. */
        .pay-box { clear: both; margin-top: 26px; border: 1px solid #ddd; border-radius: 4px; padding: 12px 14px; background: #fafbfc; }
        .pay-title { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.05em; color: #666; margin-bottom: 7px; }
        .pay-table { border-collapse: collapse; }
        .pay-table td { padding: 3px 0; font-size: 11.5px; vertical-align: top; }
        .pay-label { color: #666; padding-right: 16px !important; white-space: nowrap; }
        .pay-value { font-weight: bold; }

        .footer { clear: both; margin-top: 50px; font-size: 11px; color: #777; border-top: 1px solid #ddd; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        
        <table class="header-table">
            <tr>
                <td style="width: 60%;">
                    ' . $head['logo'] . '
                    <div class="company-name">' . $head['name'] . '</div>
                    <div class="company-details">' . $head['details'] . '</div>
                </td>
                <td style="width: 40%; text-align: right; vertical-align: top;">
                    <table class="meta-wrap"><tr><td class="meta-wrap-cell"><div class="doc-title">QUOTATION</div>
                    <br>
                    <table class="meta-table">
                        <tr>
                            <td class="meta-label">Quote Ref:</td>
                            <td class="meta-value">' . e($quote['quote_number']) . '</td>
                        </tr>
                        <tr>
                            <td class="meta-label">Issue Date:</td>
                            <td class="meta-value">' . date('d M, Y', strtotime($quote['issue_date'])) . '</td>
                        </tr>
                        <tr>
                            <td class="meta-label">Valid Until:</td>
                            <td class="meta-value"><span style="color:#e74c3c; font-weight:bold;">' . date('d M, Y', strtotime($quote['expiry_date'])) . '</span></td>
                        </tr>
                    </table></td></tr></table>
                </td>
            </tr>
        </table>

        <table class="info-table">
            <tr>
                <td style="padding-right: 20px;">
                    <div class="box-title">Quote For</div>
                    <strong>' . e($quote['customer_name']) . '</strong><br>
                    ' . (customer_contact_name($quote) ? 'Attn: ' . e(customer_contact_name($quote)) . '<br>' : '') . '
                    ' . ($quote['customer_location'] ? e($quote['customer_location']) . '<br>' : '') . '
                    ' . ($quote['customer_phone'] ? 'Phone: ' . e($quote['customer_phone']) . '<br>' : '') . '
                    ' . ($quote['customer_email'] ? 'Email: ' . e($quote['customer_email']) : '') . '
                </td>
                <td style="padding-left: 20px;">
                    <div class="box-title">Prepared By</div>
                    ' . e($quote['preparer_name'] ?? 'Sales Representative') . '
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: ' . ($hasLineItemDiscounts ? '35%' : '45%') . ';">Description</th>
                    <th style="width: 15%;" class="text-center">Qty</th>
                    <th style="width: 15%;" class="text-right">Unit Price</th>
                    ' . ($hasLineItemDiscounts ? '<th style="width: 10%;" class="text-right">Disc.</th>' : '') . '
                    <th style="width: 20%;" class="text-right">Subtotal (' . e($quote['currency_code']) . ')</th>
                </tr>
            </thead>
            <tbody>';
            
            $counter = 1;
            foreach ($items as $item) {
                $html .= '
                <tr>
                    <td>' . $counter++ . '</td>
                    <td>
                        <strong>' . e($item['product_name']) . '</strong><br>
                        <span style="font-size:10px; color:#888;">SKU: ' . e($item['sku'] ?: 'N/A') . '</span>
                        ' . (trim((string) ($item['note'] ?? '')) !== ''
                                ? '<div class="line-note">' . nl2br(e($item['note'])) . '</div>'
                                : '') . '
                    </td>
                    <td class="text-center">' . number_format($item['quantity'], 2) . '</td>
                    <td class="text-right">' . number_format($item['unit_price'], 2) . '</td>';
                    
                    if ($hasLineItemDiscounts) {
                        $html .= '<td class="text-right" style="color:red;">' . ((float)$item['discount'] > 0 ? '-' . number_format($item['discount'], 2) : '-') . '</td>';
                    }

                $html .= '
                    <td class="text-right">' . number_format($item['subtotal'], 2) . '</td>
                </tr>';
            }

$html .= '
            </tbody>
        </table>

        <table class="totals-table">
            <tr>
                <td>Subtotal</td>
                <td class="text-right">' . number_format($quote['subtotal'], 2) . '</td>
            </tr>';

            // Only show global discount if it's greater than zero
            if ((float)$quote['discount_amount'] > 0) {
                $html .= '
                <tr>
                    <td>Global Discount</td>
                    <td class="text-right" style="color:red;">- ' . number_format($quote['discount_amount'], 2) . '</td>
                </tr>';
            }

            // A "Tax 0.00" line invites the question of whether tax
            // was forgotten, so a document with no tax carries no
            // tax row at all.
            if ((float) $quote['tax_amount'] > 0.005) {
                $html .= '
                <tr>
                    <td>Tax Amount</td>
                    <td class="text-right">' . number_format($quote['tax_amount'], 2) . '</td>
                </tr>';
            }

$html .= '
            <tr class="grand-total">
                <td>Total</td>
                <td class="text-right">' . e($quote['currency_code']) . ' ' . number_format($quote['total_amount'], 2) . '</td>
            </tr>
            ' . (!empty($quote['tax_note'])
                    ? '<tr class="tax-note"><td colspan="2">'
                      . nl2br(e($quote['tax_note'])) . '</td></tr>'
                    : '') . '
        </table>

        <div style="clear: both;"></div>

        ' . $payBlock . '

        ' . ($quote['notes'] || $quote['terms'] ? '
        <div style="margin-top: 30px;">
            ' . ($quote['notes'] ? '<div class="box-title">Notes</div><p style="font-size: 11px;">' . nl2br(e($quote['notes'])) . '</p><br>' : '') . '
            ' . ($quote['terms'] ? '<div class="box-title">Terms & Conditions</div><p style="font-size: 11px;">' . nl2br(e($quote['terms'])) . '</p>' : '') . '
        </div>
        ' : '') . '

        <div class="footer">
            Thank you for your business! If you have any questions about this quote, please contact us.
        </div>
    </div>
</body>
</html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream(
    pdf_document_filename('Quote', $quote['quote_number']),
    pdf_stream_options()
);
?>