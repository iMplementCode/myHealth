<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/document_pdf.php';
// ============================================================
//  Print Sales Order (PDF) — With iMplement Branding
//  Run AI Technologies | Dompdf + PostgreSQL
// ============================================================


use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_GET['so_id']) || empty($_GET['so_id'])) {
    die("Error: No Sales Order ID provided.");
}

$so_id = (int)$_GET['so_id'];

/*  The application's connection — see the note in print_quote.php.
 *  These two pages were the only ones in the system that built
 *  their own, reading credentials straight out of $_ENV, and they
 *  were the only two that stopped working when the credentials
 *  moved out of the environment. */
$pdo = db();

// 1. The letterhead — logo, company name, and how to reach it.
//    Shared with every other document the business issues, because
//    this file used to resolve the logo itself and got it wrong: it
//    glued the project root onto a relative path with no separator
//    between them, so the uploaded logo was never found and every
//    quote printed the one that ships with the software.
$head = company_letterhead();

// 2. Fetch Sales Order Header
$stmtSO = $pdo->prepare("
    SELECT 
        so.*, 
        " . customer_name_sql('c') . " as customer_name, 
        c.company_name, c.first_name, c.last_name, c.location as customer_location,
        c.email as customer_email, c.phone as customer_phone, c.tax_pin,
        cur.code as currency_code,
        u.first_name || ' ' || u.last_name as preparer_name
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN currencies cur ON so.currency_id = cur.currency_id
    LEFT JOIN users u ON so.sold_by = u.user_id
    WHERE so.sales_order_id = :so_id
");
$stmtSO->execute([':so_id' => $so_id]);
$so = $stmtSO->fetch();

if (!$so) {
    die("Sales Order not found.");
}

// 3. Fetch Sales Order Items
$stmtItems = $pdo->prepare("
    SELECT soi.*, p.name as product_name, p.sku
    FROM sales_order_items soi
    LEFT JOIN products p ON soi.product_id = p.product_id
    WHERE soi.sales_order_id = :so_id
    ORDER BY soi.sales_order_item_id ASC
");
$stmtItems->execute([':so_id' => $so_id]);
$items = $stmtItems->fetchAll();

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
    <title>Sales Order - ' . e($so['order_number']) . '</title>
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 0; position: relative; }
        .container { padding: 30px; padding-bottom: 80px; position: relative; min-height: 90vh; z-index: 2; }
        
        /* Cancelled Watermark/Banner */
        .watermark { position: absolute; top: 30%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 90px; font-weight: bold; color: rgba(239, 68, 68, 0.1); border: 10px solid rgba(239, 68, 68, 0.1); padding: 20px; text-transform: uppercase; letter-spacing: 15px; z-index: 1; pointer-events: none; }
        .cancelled-banner { background-color: #fef2f2; border: 1px solid #ef4444; color: #b91c1c; padding: 10px; text-align: center; font-weight: bold; margin-bottom: 20px; border-radius: 4px; text-transform: uppercase; font-size: 14px; }

        .header-table { width: 100%; margin-bottom: 30px; }
        .company-details { font-size: 11px; color: #555; line-height: 1.5; }
        .company-name { font-size: 19px; font-weight: bold; color: ' . BRAND_COLOR . '; margin-bottom: 5px; }
        /* One size for every document, chosen to fit the longest title
           the system prints — GOODS RECEIVED NOTE — inside its half of
           the header. nowrap is the guarantee: a title broken across
           lines stops looking like the name of the document. */
        .doc-title { font-size: 20px; font-weight: bold; color: ' . BRAND_COLOR . '; text-align: left; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        
        .info-table { width: 100%; margin-bottom: 30px; border-collapse: collapse; }
        .info-table td { vertical-align: top; width: 50%; }
        .box-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
        /* Labels right-aligned in their own column, values left in
           theirs: right-aligning the whole block lines up only the
           right edge, leaving the colons ragged. */
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

        .tax-note {
            clear: both; width: 45%; float: right; padding: 8px 8px 0;
            font-size: 11px; font-style: italic; color: #555; text-align: right;
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
        
        /* Combined Footer Styling */
        .footer { clear: both; margin-top: 40px; font-size: 10px; color: #777; border-top: 1px solid #ddd; padding-top: 15px; text-align: center; line-height: 1.5; }
        .developer-credit { margin-top: 15px; font-size: 9px; color: #888; border-top: 1px dashed #eee; padding-top: 10px; line-height: 1.6; }
        .developer-credit strong { color: #555; }
    </style>
</head>
<body>
    ' . ($so['status'] === 'cancelled' ? '<div class="watermark">CANCELLED</div>' : '') . '

    <div class="container">
        ' . ($so['status'] === 'cancelled' ? '<div class="cancelled-banner">THIS SALES ORDER HAS BEEN CANCELLED AND IS NO LONGER VALID</div>' : '') . '
        
        <table class="header-table">
            <tr>
                <td style="width: 60%;">
                    ' . $head['logo'] . '
                    <div class="company-name">' . $head['name'] . '</div>
                    <div class="company-details">' . $head['details'] . '</div>
                </td>
              <td style="width: 40%; text-align: right; vertical-align: top;">
                    <table class="meta-wrap"><tr><td class="meta-wrap-cell"><div class="doc-title">SALES ORDER</div>
                    <br>
                    <table class="meta-table">
                        <tr>
                            <td class="meta-label">SO Number:</td>
                            <td class="meta-value">' . e($so['order_number']) . '</td>
                        </tr>
                        <tr>
                            <td class="meta-label">Date:</td>
                            <td class="meta-value">' . date('d M, Y', strtotime($so['created_at'])) . '</td>
                        </tr>
                        ' . ($so['customer_lpo_number'] ? '
                        <tr>
                            <td class="meta-label">Client LPO:</td>
                            <td class="meta-value">' . e($so['customer_lpo_number']) . '</td>
                        </tr>' : '') . '
                        <tr>
                            <td class="meta-label">Channel:</td>
                            <td class="meta-value">' . strtoupper(e($so['sales_channel'])) . '</td>
                        </tr>
                    </table></td></tr></table>
                </td>
            </tr>
        </table>

        <table class="info-table">
            <tr>
                <td style="padding-right: 20px;">
                    <div class="box-title">Bill To (Customer)</div>
                    <strong>' . e($so['customer_name']) . '</strong><br>
                    ' . (customer_contact_name($so) ? 'Attn: ' . e(customer_contact_name($so)) . '<br>' : '') . '
                    ' . ($so['customer_location'] ? e($so['customer_location']) . '<br>' : '') . '
                    ' . ($so['customer_phone'] ? 'Phone: ' . e($so['customer_phone']) . '<br>' : '') . '
                    ' . ($so['customer_email'] ? 'Email: ' . e($so['customer_email']) . '<br>' : '') . '
                    ' . ($so['tax_pin'] ? 'Tax PIN: ' . e($so['tax_pin']) : '') . '
                </td>
                <td style="padding-left: 20px;">
                    <div class="box-title">Order Handled By</div>
                    ' . e($so['preparer_name'] ?? 'Sales Representative') . '
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
                    <th style="width: 20%;" class="text-right">Subtotal (' . e($so['currency_code']) . ')</th>
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
                <td class="text-right">' . number_format($so['subtotal'], 2) . '</td>
            </tr>';

// Only show global discount if it is greater than zero
if ((float)$so['discount_amount'] > 0) {
    $html .= '
                <tr>
                    <td>Global Discount</td>
                    <td class="text-right" style="color:red;">- ' . number_format($so['discount_amount'], 2) . '</td>
                </tr>';
}

            // No tax charged, no tax row: a "0.00" line only invites
            // the question of whether tax was forgotten.
            if ((float) $so['tax_amount'] > 0.005) {
                $html .= '
                <tr>
                    <td>Tax Amount</td>
                    <td class="text-right">' . number_format($so['tax_amount'], 2) . '</td>
                </tr>';
            }

$html .= '
            <tr class="grand-total">
                <td>Total</td>
                <td class="text-right">' . e($so['currency_code']) . ' ' . number_format($so['total_amount'], 2) . '</td>
            </tr>
        </table>

        <div style="clear: both;"></div>

        <div class="footer">
            Generated by Run AI Technologies Procurement System on ' . date('d M, Y H:i') . '. <br>
            This is a computer-generated document. No signature is required.
            
            <div class="developer-credit">
                <strong>System Architected & Maintained by iMplement</strong><br>
                Location: Gaberone Road, Montana Mall, 2nd Floor, M206 &nbsp;|&nbsp; 
                Email: implement@implement.co.ke &nbsp;|&nbsp; 
                Tel: +254 729 982 363
            </div>
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
    pdf_document_filename('Sales_Order', $so['order_number']),
    pdf_stream_options()
);
