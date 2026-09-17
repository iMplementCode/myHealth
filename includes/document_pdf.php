<?php

/**
 * ============================================================
 *  Shared document PDF renderer (dompdf)
 * ------------------------------------------------------------
 *  Renders invoices, proformas and similar line-item documents
 *  to a pixel-stable, colour PDF. Because the layout is built
 *  from HTML tables (never flexbox) it renders identically in
 *  dompdf and never reflows the way a browser "Print" can.
 *
 *  The company name, document title and grand total are drawn
 *  in the configured BRAND_COLOR so every document carries the
 *  company's brand colour.
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


require_once __DIR__ . '/company.php';

/* ─────────────────────────────────────────────────────────────
 *  What a saved PDF is called
 * ────────────────────────────────────────────────────────────
 *  Every document's PDF must arrive in the Downloads folder
 *  under its own number, or the second one saves as
 *  "print_quote (1).pdf" and nobody can tell them apart a week
 *  later.
 *
 *  Two things have to be true for that, and only the first was:
 *
 *    1. the response has to *say* the name, in Content-Disposition
 *    2. it has to say `attachment`. Served inline, the file goes
 *       to the browser's own PDF viewer, and what that viewer
 *       calls the file when you press Save is its business — most
 *       often the last segment of the URL, which is the same
 *       print_quote.php for every quote ever printed.
 * ──────────────────────────────────────────────────────────── */

/**
 * A filename built from the document's own number.
 *
 * The number is scrubbed before it goes anywhere near a header:
 * a slash or a newline in Content-Disposition is a header
 * injection, and a document number is user-entered text.
 */
function pdf_document_filename(string $prefix, ?string $number): string
{
    $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim((string) $number));
    $clean = trim((string) $clean, '-.');

    if ($clean === '') {
        // Nothing usable — better a timestamp than every file
        // sharing one name, which is the bug this exists to fix.
        $clean = date('Ymd-His');
    }
    return $prefix . '_' . $clean . '.pdf';
}

/**
 * Download, or show in the browser?
 *
 * `?dl=1` saves the file; anything else opens it for reading and
 * printing. Every PDF page in the application reads the same
 * flag, so a "Download" link behaves the same wherever it is.
 */
function pdf_stream_options(): array
{
    return ['Attachment' => !empty($_GET['dl']) ? 1 : 0];
}

/**
 * Stream a document as a PDF.
 *
 * @param array $doc {
 *   title:          string  Document name, e.g. "INVOICE" (drawn in brand colour)
 *   filename:       string  Download file name, e.g. "Invoice_INV-2026-0007.pdf"
 *   download:       bool    true = force download, false = inline (for print preview)
 *   meta:           array   List of [label, value] rows shown top-right
 *   customer_label: string  e.g. "Billed to" / "Prepared for"
 *   customer_name:  string
 *   customer_lines: string[] Extra customer lines (company, email, phone, PIN)
 *   items:          array   List of rows, each:
 *                           [name, sku, qty, unit_price, discount, subtotal] (strings)
 *   totals:         array   List of [label, value] rows (subtotal, discount, tax, ...)
 *   grand:          array   [label, value] — the highlighted grand total
 *   notes:          ?string
 *   terms:          ?string
 *   terms_label:    string  e.g. "Terms" / "Payment terms"
 *   footer:         string
 * }
 */
function stream_document_pdf(array $doc): void
{
    $brand = defined('BRAND_COLOR') ? BRAND_COLOR : '#29c5f0';

    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // ── Company block (left) ─────────────────────────────────
    $head           = company_letterhead();
    $companyName    = $head['name'];
    $companyDetails = $head['details'];

    // ── Meta rows (right) ────────────────────────────────────
    // A two-column table, not <br>-separated lines: right-aligning a
    // block only lines up its right edge, leaving the colons and the
    // values ragged. Here the labels end in one column and the
    // values start in another, so both read as columns.
    $metaRows = '';
    foreach ($doc['meta'] ?? [] as $row) {
        $value = $esc($row[1]);
        if (!empty($row[2])) {           // optional colour, e.g. an expiry date
            $value = '<span style="color:' . $esc($row[2]) . '; font-weight:bold;">' . $value . '</span>';
        }
        $metaRows .= '
            <tr>
                <td class="meta-label">' . $esc($row[0]) . ':</td>
                <td class="meta-value">' . $value . '</td>
            </tr>';
    }

    // ── Customer block ───────────────────────────────────────
    $custLines = '';
    foreach ($doc['customer_lines'] ?? [] as $line) {
        $custLines .= $esc($line) . '<br>';
    }

    // ── Items ────────────────────────────────────────────────
    $rows    = '';
    $counter = 1;
    foreach ($doc['items'] ?? [] as $it) {
        // A note belonging to this one line, when the document has
        // one. Optional: a caller that passes no note gets exactly
        // the row it got before this existed.
        $lineNote = trim((string) ($it['note'] ?? '')) !== ''
            ? '<div class="line-note">' . nl2br($esc($it['note'])) . '</div>'
            : '';
        $rows .= '
            <tr>
                <td>' . $counter++ . '</td>
                <td>
                    <strong>' . $esc($it['name']) . '</strong><br>
                    <span style="font-size:10px; color:#888;">SKU: ' . $esc($it['sku'] ?: 'N/A') . '</span>
                    ' . $lineNote . '
                </td>
                <td class="text-center">' . $esc($it['qty']) . '</td>
                <td class="text-right">' . $esc($it['unit_price']) . '</td>
                <td class="text-right">' . $esc($it['discount']) . '</td>
                <td class="text-right">' . $esc($it['subtotal']) . '</td>
            </tr>';
    }

    // ── Totals ───────────────────────────────────────────────
    // A row may declare itself omittable: tax that was not charged
    // and a discount that was not given are noise on the printed
    // document, and a "Tax 0.00" line invites the question of
    // whether tax was forgotten.
    $totalRows = '';
    foreach ($doc['totals'] ?? [] as $row) {
        if (($row[2] ?? true) === false) {
            continue;
        }
        $totalRows .= '
            <tr>
                <td>' . $esc($row[0]) . '</td>
                <td class="text-right">' . $esc($row[1]) . '</td>
            </tr>';
    }
    $grand = $doc['grand'] ?? ['Total', ''];

    // ── Tax note ─────────────────────────────────────────────
    // Sits with the totals rather than in the notes block: it says
    // something about the figures beside it, most often that they
    // exclude VAT, and belongs where those figures are read.
    $taxNote = '';
    if (!empty($doc['tax_note'])) {
        $taxNote = '<tr class="tax-note"><td colspan="2">'
                 . nl2br($esc($doc['tax_note'])) . '</td></tr>';
    }

    // ── Notes / terms ────────────────────────────────────────
    $extra = '';
    if (!empty($doc['notes']) || !empty($doc['terms'])) {
        $extra .= '<div style="margin-top: 30px;">';
        if (!empty($doc['notes'])) {
            $extra .= '<div class="box-title">Notes</div><p style="font-size:11px;">'
                . nl2br($esc($doc['notes'])) . '</p><br>';
        }
        if (!empty($doc['terms'])) {
            $extra .= '<div class="box-title">' . $esc($doc['terms_label'] ?? 'Terms') . '</div>'
                . '<p style="font-size:11px;">' . nl2br($esc($doc['terms'])) . '</p>';
        }
        $extra .= '</div>';
    }

    /*  How to pay. Only on documents that ask for money — a purchase
     *  order tells a supplier what we want, and printing our own
     *  paybill on it would be an invitation to nobody.
     *
     *  The page passes 'pay_to' rather than the renderer deciding,
     *  so a new document type has to opt in deliberately instead of
     *  inheriting a payment block by accident.                     */
    $payBlock = '';
    if (!empty($doc['pay_to'])) {
        $payRows = '';
        foreach ($doc['pay_to'] as $row) {
            $payRows .= '<tr><td class="pay-label">' . $esc($row[0]) . '</td>'
                      . '<td class="pay-value">' . nl2br($esc($row[1])) . '</td></tr>';
        }
        $payBlock = '<div class="pay-box"><div class="pay-title">How to pay</div>'
                  . '<table class="pay-table">' . $payRows . '</table></div>';
    }

    $logoImg = $head['logo'];

    $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . $esc($doc['title'] . ' - ' . ($doc['filename'] ?? '')) . '</title>
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 0; }
        .container { padding: 30px; }

        .header-table { width: 100%; margin-bottom: 30px; }
        .company-details { font-size: 11px; color: #555; line-height: 1.5; }
        .company-name { font-size: 19px; font-weight: bold; color: ' . $brand . '; margin-bottom: 5px; }
        /* One size for every document, chosen to fit the longest title
           the system prints — GOODS RECEIVED NOTE — inside its half of
           the header. nowrap is the guarantee: a title broken across
           lines stops looking like the name of the document. */
        .doc-title { font-size: 20px; font-weight: bold; color: ' . $brand . '; text-align: left; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }

        .info-table { width: 100%; margin-bottom: 30px; border-collapse: collapse; }
        .info-table td { vertical-align: top; }
        .box-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .items-table th { background: #f8f9fa; border-bottom: 2px solid #333; padding: 10px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .items-table td { border-bottom: 1px solid #eee; padding: 12px 8px; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }

        /* A note belonging to one line, printed under the item it is
           about. Indented and italic so it reads as a remark on the
           product above it rather than as a second product — which
           is what it looked like set at the same size and margin. */
        .line-note {
            margin-top: 5px;
            padding-left: 9px;
            border-left: 2px solid #ddd;
            font-size: 10.5px;
            font-style: italic;
            color: #666;
            line-height: 1.45;
        }

        /* Meta block. The right-hand header cell is a mirror of the
           left one: a title, then details beneath it, everything
           sharing one left edge. Labels in the first column, values
           in the second, and the table sized to its own content so
           the two sit next to each other.

           It was briefly stretched to the full width of the cell,
           labels pinned left and values pinned right. That does line
           the values up on the page margin, but it opens a hand-span
           of white between "Number:" and the number, and a label
           separated from its value by half the header no longer
           reads as one fact. The company block on the left never had
           that problem, and this now matches it.

           NOTE: this CSS sits inside a single-quoted PHP string, so
           an apostrophe here ends the string. Do not write one. */
        /* Shrink-wraps the title and the meta rows together and pushes
           the pair against the right margin, so the header uses the
           width of the page instead of stopping halfway across it.
           Inside the wrapper both are left-aligned, which is what
           keeps the title over its own details. */
        .meta-wrap { width: auto; margin-left: auto; border-collapse: collapse; }
        .meta-wrap-cell { padding: 0 !important; }

        .meta-table { width: auto; border-collapse: collapse; margin-top: 10px; }
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

        .totals-table { width: 45%; float: right; border-collapse: collapse; }
        .totals-table td { padding: 8px; border-bottom: 1px solid #eee; }
        /* An amount must never break across two lines: in a narrow
           totals column "KES" ended up above "40,000.00", which reads
           as a different figure entirely. */
        .totals-table td.text-right, .totals-table .amount { white-space: nowrap; }
        .totals-table .grand-total { font-weight: bold; font-size: 16px; border-top: 2px solid #333; border-bottom: 2px solid #333; color: ' . $brand . '; }

        /* How to pay. Boxed rather than run in with the notes: a
           customer scanning an invoice for the paybill needs to find
           it without reading, and a number in a paragraph is not
           findable. */
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
                <td style="width: 55%; vertical-align: top;">
                    ' . $logoImg . '
                    <div class="company-name">' . $companyName . '</div>
                    <div class="company-details">' . $companyDetails . '</div>
                </td>
                <td style="width: 45%; vertical-align: top;">
                    <table class="meta-wrap"><tr><td class="meta-wrap-cell">
                        <div class="doc-title">' . $esc($doc['title']) . '</div>
                        <table class="meta-table">' . $metaRows . '</table>
                    </td></tr></table>
                </td>
            </tr>
        </table>

        <!-- One box, full width. The second cell was always empty on
             every document this renders, so the rule under BILL TO
             stopped half way across the page while every other rule
             ran the full width. -->
        <table class="info-table">
            <tr>
                <td>
                    <div class="box-title">' . $esc($doc['customer_label'] ?? 'Bill To') . '</div>
                    <strong>' . $esc($doc['customer_name'] ?? '') . '</strong><br>
                    ' . $custLines . '
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 40%;">Description</th>
                    <th style="width: 10%;" class="text-center">Qty</th>
                    <th style="width: 15%;" class="text-right">Unit Price</th>
                    <th style="width: 12%;" class="text-right">Disc.</th>
                    <th style="width: 18%;" class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>

        <table class="totals-table">' . $totalRows . '
            <tr class="grand-total">
                <td>' . $esc($grand[0]) . '</td>
                <td class="text-right">' . $esc($grand[1]) . '</td>
            </tr>
            ' . $taxNote . '
        </table>

        <div style="clear: both;"></div>

        ' . $payBlock . '
        ' . $extra . '

        <div class="footer">' . $esc($doc['footer'] ?? '') . '</div>
    </div>
</body>
</html>';

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dompdf->stream(
        $doc['filename'] ?? 'document.pdf',
        ['Attachment' => !empty($doc['download']) ? 1 : 0]
    );
    exit;
}

/**
 * Stream a goods-movement note as a PDF: a delivery note, or a
 * goods return note, which is the same document facing the other
 * way.
 *
 * Neither evidences money — one says goods went out, the other
 * says goods came back — so both carry quantities instead of
 * pricing and end in a signature block. Putting a value on either
 * only starts an argument at the counter about a price nobody is
 * settling there.
 *
 * @param array $doc Same shape as stream_document_pdf(), except
 *                   `items` rows are [name, sku, quantity, note]
 *                   and there are no totals. Optionally:
 *
 *   items[]['expected']  a second quantity column — what was meant
 *                        to arrive, against what did. Present on
 *                        any row, and the column appears.
 *   qty_label     heading over the quantity column ("Received")
 *   sign_left     signature block, left  ("Delivered by")
 *   sign_right    signature block, right ("Received by")
 *   footer_note   the sentence above the footer line
 */
function stream_delivery_pdf(array $doc): void
{
    $brand = defined('BRAND_COLOR') ? BRAND_COLOR : '#29c5f0';
    $esc   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $head = company_letterhead();

    // A table, not <strong>Label:</strong> value<br>. Inline text
    // cannot form a column, so every line ended where its own words
    // happened to end.
    $metaRows = '';
    foreach ($doc['meta'] ?? [] as $row) {
        $metaRows .= '
            <tr>
                <td class="meta-label">' . $esc($row[0]) . ':</td>
                <td class="meta-value">' . $esc($row[1]) . '</td>
            </tr>';
    }

    $custLines = '';
    foreach ($doc['customer_lines'] ?? [] as $line) {
        $custLines .= $esc($line) . '<br>';
    }

    // A return counts against an expectation, so it carries two
    // quantity columns; a delivery has only the one. The column
    // appears if any row offers it.
    $hasExpected = false;
    foreach ($doc['items'] ?? [] as $it) {
        if (isset($it['expected'])) {
            $hasExpected = true;
            break;
        }
    }

    $rows    = '';
    $counter = 1;
    foreach ($doc['items'] ?? [] as $it) {
        $rows .= '
            <tr>
                <td>' . $counter++ . '</td>
                <td>
                    <strong>' . $esc($it['name']) . '</strong><br>
                    <span style="font-size:10px; color:#888;">SKU: ' . $esc($it['sku'] ?: 'N/A') . '</span>
                </td>'
                . ($hasExpected
                    ? '<td class="text-center">' . $esc($it['expected'] ?? '') . '</td>'
                    : '') . '
                <td class="text-center"><strong>' . $esc($it['quantity']) . '</strong></td>
                <td>' . $esc($it['note'] ?? '') . '</td>
            </tr>';
    }

    $itemHead = '
                    <th style="width: 6%;">#</th>
                    <th style="width: ' . ($hasExpected ? '41%' : '49%') . ';">Description</th>'
              . ($hasExpected ? '<th style="width: 13%;" class="text-center">Expected</th>' : '') . '
                    <th style="width: 15%;" class="text-center">'
                        . $esc($doc['qty_label'] ?? 'Quantity') . '</th>
                    <th style="width: ' . ($hasExpected ? '25%' : '30%') . ';">Remarks</th>';

    $notes = '';
    if (!empty($doc['notes'])) {
        $notes = '<div style="margin-top:24px;"><div class="box-title">Notes</div>'
               . '<p style="font-size:11px;">' . nl2br($esc($doc['notes'])) . '</p></div>';
    }

    $logoImg = $head['logo'];

    $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . $esc($doc['title']) . '</title>
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 0; }
        .container { padding: 30px; }
        .header-table { width: 100%; margin-bottom: 30px; }
        .company-details { font-size: 11px; color: #555; line-height: 1.5; }
        .company-name { font-size: 19px; font-weight: bold; color: ' . $brand . '; margin-bottom: 5px; }
        /* One size for every document, chosen to fit the longest title
           the system prints — GOODS RECEIVED NOTE — inside its half of
           the header. nowrap is the guarantee: a title broken across
           lines stops looking like the name of the document. */
        .doc-title { font-size: 20px; font-weight: bold; color: ' . $brand . '; text-align: left; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        /* Shrink-wraps the title and the meta rows together and pushes
           the pair against the right margin, so the header uses the
           width of the page instead of stopping halfway across it.
           Inside the wrapper both are left-aligned, which is what
           keeps the title over its own details. */
        .meta-wrap { width: auto; margin-left: auto; border-collapse: collapse; }
        .meta-wrap-cell { padding: 0 !important; }

        .meta-table { width: auto; border-collapse: collapse; margin-top: 10px; }
        .meta-table td { padding: 2px 0; font-size: 12px; white-space: nowrap; }
        .meta-label { text-align: left; padding-right: 14px !important; font-weight: bold; }
        .meta-value { text-align: left; }
        .info-table { width: 100%; margin-bottom: 30px; border-collapse: collapse; }
        .info-table td { vertical-align: top; }
        .box-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .items-table th { background: #f8f9fa; border-bottom: 2px solid #333; padding: 10px 8px; text-align: left; font-size: 11px; text-transform: uppercase; }
        .items-table td { border-bottom: 1px solid #eee; padding: 12px 8px; }
        .text-right { text-align: right !important; }
        .text-center { text-align: center !important; }
        .sign-table { width: 100%; margin-top: 40px; border-collapse: collapse; }
        .sign-table td { width: 50%; padding-top: 40px; font-size: 11px; color: #555; }
        .sign-line { border-top: 1px solid #999; padding-top: 6px; margin-right: 30px; }
        .footer { clear: both; margin-top: 40px; font-size: 11px; color: #777; border-top: 1px solid #ddd; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <table class="header-table">
            <tr>
                <td style="width: 55%; vertical-align: top;">
                    ' . $logoImg . '
                    <div class="company-name">' . $head['name'] . '</div>
                    <div class="company-details">' . $head['details'] . '</div>
                </td>
                <td style="width: 45%; vertical-align: top;">
                    <table class="meta-wrap"><tr><td class="meta-wrap-cell">
                        <div class="doc-title">' . $esc($doc['title']) . '</div>
                        <table class="meta-table">' . $metaRows . '</table>
                    </td></tr></table>
                </td>
            </tr>
        </table>

        <!-- One box, full width. The second cell was always empty on
             every document this renders, so the rule under BILL TO
             stopped half way across the page while every other rule
             ran the full width. -->
        <table class="info-table">
            <tr>
                <td>
                    <div class="box-title">' . $esc($doc['customer_label'] ?? 'Delivered to') . '</div>
                    <strong>' . $esc($doc['customer_name'] ?? '') . '</strong><br>
                    ' . $custLines . '
                </td>
            </tr>
        </table>

        <table class="items-table">
            <thead>
                <tr>' . $itemHead . '</tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>

        ' . $notes . '

        <table class="sign-table">
            <tr>
                <td><div class="sign-line">'
                    . $esc($doc['sign_left'] ?? 'Delivered by') . ' (name, signature &amp; date)</div></td>
                <td><div class="sign-line">'
                    . $esc($doc['sign_right'] ?? 'Received by') . ' (name, signature &amp; date)</div></td>
            </tr>
        </table>

        <div class="footer">
            ' . $esc($doc['footer_note']
                     ?? 'Goods received in good order and condition unless noted above.') . '<br>
            ' . $esc($doc['footer'] ?? '') . '
        </div>
    </div>
</body>
</html>';

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $dompdf->stream(
        $doc['filename'] ?? 'delivery-note.pdf',
        ['Attachment' => !empty($doc['download']) ? 1 : 0]
    );
    exit;
}

/**
 * Stream a voucher: one amount, some facts about it, and somewhere
 * to sign.
 *
 * An expense is not a line-item document. It has no quantities, no
 * unit prices and nothing to total up — it is a single figure and
 * the story of where it went. Rendering it through
 * stream_document_pdf() would put it in a table headed
 * "# / Description / Qty / Unit price / Disc. / Subtotal", five
 * columns of which would be blank.
 *
 * What it does share with every other document is the letterhead,
 * and that comes from company_letterhead() — the same call the
 * other two renderers make. That is the part that has to agree, and
 * it is the part that is not duplicated here.
 *
 * @param array $doc {
 *   title:       string   e.g. "EXPENSE VOUCHER"
 *   filename:    string   the document's own number, for the file name
 *   download:    bool
 *   meta:        array    [label, value] rows, top right
 *   facts:       array    [label, value] rows — the body of the voucher
 *   amount_label:string
 *   amount:      string   already formatted, with its currency
 *   words:       ?string  the amount written out, as a cheque carries it
 *   note:        ?string
 *   signatures:  string[] captions for the signature blocks
 *   footer:      string
 * }
 */
function stream_voucher_pdf(array $doc): void
{
    $brand = defined('BRAND_COLOR') ? BRAND_COLOR : '#29c5f0';
    $esc   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $head  = company_letterhead();

    $metaRows = '';
    foreach ($doc['meta'] ?? [] as $row) {
        $metaRows .= '
            <tr>
                <td class="meta-label">' . $esc($row[0]) . ':</td>
                <td class="meta-value">' . $esc($row[1]) . '</td>
            </tr>';
    }

    // The body. A row whose value is empty is dropped rather than
    // printed as a label with nothing after it — a voucher with
    // "Paid to:" and a blank looks like a document somebody failed
    // to finish.
    $factRows = '';
    foreach ($doc['facts'] ?? [] as $row) {
        if (trim((string) ($row[1] ?? '')) === '') {
            continue;
        }
        $factRows .= '
            <tr>
                <td class="fact-label">' . $esc($row[0]) . '</td>
                <td class="fact-value">' . nl2br($esc($row[1])) . '</td>
            </tr>';
    }

    $signs = '';
    foreach ($doc['signatures'] ?? [] as $caption) {
        $signs .= '<td class="sign"><div class="sign-rule"></div>'
                . '<span class="sign-cap">' . $esc($caption) . '</span></td>';
    }

    $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . $esc($doc['title'] . ' - ' . ($doc['filename'] ?? '')) . '</title>
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 0; }
        .container { padding: 30px; }

        .header-table { width: 100%; margin-bottom: 30px; }
        .company-details { font-size: 11px; color: #555; line-height: 1.5; }
        .company-name { font-size: 19px; font-weight: bold; color: ' . $brand . '; margin-bottom: 5px; }
        .doc-title { font-size: 20px; font-weight: bold; color: ' . $brand . '; text-align: left; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }

        .meta-wrap { width: auto; margin-left: auto; border-collapse: collapse; }
        .meta-wrap-cell { padding: 0 !important; }
        .meta-table { width: auto; border-collapse: collapse; margin-top: 10px; }
        .meta-table td { padding: 2px 0; font-size: 12px; white-space: nowrap; }
        .meta-label { text-align: left; padding-right: 14px !important; font-weight: bold; }
        .meta-value { text-align: left; }

        .box-title { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 8px; }

        /* The facts. A label column sized to its content and a value
           column taking the rest, so the values start on one line
           however long the longest label is. */
        .facts { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
        .facts td { padding: 9px 0; border-bottom: 1px solid #eee; vertical-align: top; }
        .fact-label { width: 150px; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }
        .fact-value { font-size: 12.5px; }

        /* The amount, which is the reason the page exists. */
        .amount-box { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .amount-box td { padding: 14px 16px; background: #f8f9fa; border: 1px solid #e5e5e5; }
        .amount-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #666; }
        .amount-value { font-size: 20px; font-weight: bold; color: ' . $brand . '; text-align: right; white-space: nowrap; }
        /* text-transform: none, explicitly. This sits inside the
           uppercase label cell and inherited it, so the words came
           out shouting — which is the one thing a cheque line must
           not do, since its whole job is to be read carefully. */
        .amount-words { font-size: 11px; font-style: italic; color: #555; padding-top: 4px; text-transform: none; }

        .note { font-size: 11px; color: #555; margin-bottom: 26px; }

        /* Signature blocks. A voucher that nobody signed is a note. */
        .signs { width: 100%; border-collapse: collapse; margin-top: 46px; }
        .sign { width: 33%; padding: 0 14px; vertical-align: bottom; }
        .sign-rule { border-bottom: 1px solid #999; height: 30px; }
        .sign-cap { font-size: 10.5px; color: #777; text-transform: uppercase; letter-spacing: 0.05em; }

        .footer { margin-top: 40px; font-size: 11px; color: #777; border-top: 1px solid #ddd; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="container">

        <table class="header-table">
            <tr>
                <td style="width: 55%; vertical-align: top;">
                    ' . $head['logo'] . '
                    <div class="company-name">' . $head['name'] . '</div>
                    <div class="company-details">' . $head['details'] . '</div>
                </td>
                <td style="width: 45%; vertical-align: top;">
                    <table class="meta-wrap"><tr><td class="meta-wrap-cell">
                        <div class="doc-title">' . $esc($doc['title']) . '</div>
                        <table class="meta-table">' . $metaRows . '</table>
                    </td></tr></table>
                </td>
            </tr>
        </table>

        <div class="box-title">Details</div>
        <table class="facts">' . $factRows . '</table>

        <table class="amount-box">
            <tr>
                <td class="amount-label">' . $esc($doc['amount_label'] ?? 'Amount') . '
                    ' . (!empty($doc['words'])
                            ? '<div class="amount-words">' . $esc($doc['words']) . '</div>'
                            : '') . '
                </td>
                <td class="amount-value">' . $esc($doc['amount'] ?? '') . '</td>
            </tr>
        </table>

        ' . (!empty($doc['note'])
                ? '<p class="note">' . nl2br($esc($doc['note'])) . '</p>'
                : '') . '

        ' . ($signs ? '<table class="signs"><tr>' . $signs . '</tr></table>' : '') . '

        <div class="footer">' . $esc($doc['footer'] ?? '') . '</div>
    </div>
</body>
</html>';

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $dompdf->stream(
        $doc['filename'] ?? 'voucher.pdf',
        ['Attachment' => !empty($doc['download']) ? 1 : 0]
    );
    exit;
}

/**
 * Stream a note as a PDF, on the company letterhead.
 *
 * A note is not a document with line items — it is a piece of
 * writing that somebody needs on paper, so this renders a memo:
 * the letterhead, a title, who wrote it and when, and the body
 * as it was typed.
 *
 * The letterhead comes from company_letterhead(), which is the
 * same call every other document here makes. That is the whole
 * reason the logo is right: a note cannot end up printing an old
 * logo, or none, because it does not know where the logo comes
 * from — it asks the one function that does.
 *
 * @param array $doc {
 *   title:     string   e.g. "NOTE" (drawn in brand colour)
 *   heading:   string   the note's own title, drawn above the body
 *   filename:  string   download file name
 *   download:  bool     true = force download, false = inline
 *   meta:      array    list of [label, value] rows shown top-right
 *   facts:     array    optional [label, value] rows above the body
 *   body:      string   the note itself, as typed
 *   footer:    string
 * }
 */
function stream_note_pdf(array $doc): void
{
    $brand = defined('BRAND_COLOR') ? BRAND_COLOR : '#29c5f0';
    $esc   = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $head  = company_letterhead();

    $metaRows = '';
    foreach ($doc['meta'] ?? [] as $row) {
        $metaRows .= '
            <tr>
                <td class="meta-label">' . $esc($row[0]) . ':</td>
                <td class="meta-value">' . $esc($row[1]) . '</td>
            </tr>';
    }

    // A fact with nothing after it is a label the system failed to
    // fill in, so it is dropped rather than printed empty — the same
    // rule the payment voucher follows.
    $factRows = '';
    foreach ($doc['facts'] ?? [] as $row) {
        if (trim((string) ($row[1] ?? '')) === '') {
            continue;
        }
        $factRows .= '
            <tr>
                <td class="fact-label">' . $esc($row[0]) . '</td>
                <td class="fact-value">' . nl2br($esc($row[1])) . '</td>
            </tr>';
    }

    /*  The body is the point of the page, and it is prose somebody
     *  typed, so it is printed as typed: nl2br after escaping, which
     *  keeps the line breaks and the blank lines between paragraphs
     *  without letting a note that happens to contain "<b>" style
     *  itself — or anything worse. */
    $body = nl2br($esc($doc['body'] ?? ''));

    $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . $esc(($doc['title'] ?? 'Note') . ' - ' . ($doc['filename'] ?? '')) . '</title>
    <style>
        body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; font-size: 12px; color: #333; margin: 0; padding: 0; }
        .container { padding: 30px; }

        .header-table { width: 100%; margin-bottom: 30px; }
        .company-details { font-size: 11px; color: #555; line-height: 1.5; }
        .company-name { font-size: 19px; font-weight: bold; color: ' . $brand . '; margin-bottom: 5px; }
        .doc-title { font-size: 20px; font-weight: bold; color: ' . $brand . '; text-align: left; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }

        .meta-wrap { width: auto; margin-left: auto; border-collapse: collapse; }
        .meta-wrap-cell { padding: 0 !important; }
        .meta-table { width: auto; border-collapse: collapse; margin-top: 10px; }
        .meta-table td { padding: 2px 0; font-size: 12px; white-space: nowrap; }
        .meta-label { text-align: left; padding-right: 14px !important; font-weight: bold; }
        .meta-value { text-align: left; }

        .facts { width: 100%; border-collapse: collapse; margin-bottom: 26px; }
        .facts td { padding: 9px 0; border-bottom: 1px solid #eee; vertical-align: top; }
        .fact-label { width: 150px; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }
        .fact-value { font-size: 12.5px; }

        /* The note title, over its own rule. Larger than the body so
           the page has somewhere to start reading, but not competing
           with the document title in the header. */
        .note-heading { font-size: 15px; font-weight: bold; color: #222; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 16px; }

        /* Prose, so it is set for reading rather than scanning: a
           longer line height than the tables above it, and a size
           that survives a photocopier. */
        .note-body { font-size: 12.5px; line-height: 1.75; color: #333; }

        .footer { margin-top: 44px; font-size: 11px; color: #777; border-top: 1px solid #ddd; padding-top: 15px; }
    </style>
</head>
<body>
    <div class="container">

        <table class="header-table">
            <tr>
                <td style="width: 55%; vertical-align: top;">
                    ' . $head['logo'] . '
                    <div class="company-name">' . $head['name'] . '</div>
                    <div class="company-details">' . $head['details'] . '</div>
                </td>
                <td style="width: 45%; vertical-align: top;">
                    <table class="meta-wrap"><tr><td class="meta-wrap-cell">
                        <div class="doc-title">' . $esc($doc['title'] ?? 'Note') . '</div>
                        <table class="meta-table">' . $metaRows . '</table>
                    </td></tr></table>
                </td>
            </tr>
        </table>

        ' . ($factRows ? '<table class="facts">' . $factRows . '</table>' : '') . '

        <div class="note-heading">' . $esc($doc['heading'] ?? '') . '</div>
        <div class="note-body">' . $body . '</div>

        <div class="footer">' . $esc($doc['footer'] ?? '') . '</div>
    </div>
</body>
</html>';

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    // The logo arrives as a data URI, so nothing here needs to be
    // fetched over the network — and a renderer that cannot reach
    // the network cannot be talked into fetching something else.
    $options->set('isRemoteEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Anything already buffered would be prepended to the PDF bytes
    // and the reader would see a blank page. This is the same guard
    // the voucher renderer carries, for the same reason.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $dompdf->stream(
        $doc['filename'] ?? 'note.pdf',
        ['Attachment' => !empty($doc['download']) ? 1 : 0]
    );
    exit;
}

/* ════════════════════════════════════════════════════════════
 *  Till receipt
 * ------------------------------------------------------------
 *  The narrow slip a supermarket hands you, for customers who
 *  want one instead of a sheet of A4.
 *
 *  It is the same invoice. Not a summary of it and not a second
 *  document with its own number — every figure on it is read
 *  from the invoice it was asked for, so a receipt and the A4
 *  copy of the same sale cannot disagree.
 * ════════════════════════════════════════════════════════════ */

/** Millimetres to PostScript points, which is what dompdf measures in. */
function receipt_mm_to_pt(float $mm): float
{
    return $mm * 72 / 25.4;
}

/**
 * How wide the roll is, in millimetres.
 *
 * 80mm is what almost every retail thermal printer takes and is
 * the default; 58mm is the small handheld sort. Getting this
 * wrong does not fail loudly — it prints a receipt that is too
 * wide for the paper and loses the right-hand column, which is
 * the amounts — so it is settable two ways: RECEIPT_WIDTH_MM for
 * the printer the shop actually owns, and ?w= on the URL for the
 * one-off.
 */
function receipt_width_mm(): float
{
    $allowed = [58.0, 80.0];

    $asked = $_GET['w'] ?? env('RECEIPT_WIDTH_MM', '80');
    $asked = (float) $asked;

    // Anything else is a typo or a probe. Snap to the nearest real
    // roll rather than printing at a width no printer has.
    if (!in_array($asked, $allowed, true)) {
        $asked = 80.0;
    }
    return $asked;
}

/**
 * Stream an invoice as a till receipt.
 *
 * @param array $doc {
 *   title:      string    e.g. "TAX INVOICE"
 *   filename:   string
 *   download:   bool
 *   meta:       array     [label, value] rows under the title
 *   items:      array     each [name, qty, unit_price, amount]
 *   totals:     array     [label, value] rows
 *   grand:      array     [label, value]
 *   payments:   array     [label, value] rows under the total
 *   balance:    ?array    [label, value] — printed loudly when owed
 *   item_count: string
 *   tax_note:   ?string
 *   footer:     string
 * }
 */
function stream_receipt_pdf(array $doc): void
{
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $widthMm = receipt_width_mm();
    $widthPt = receipt_mm_to_pt($widthMm);
    $pad     = 6.0;
    $narrow  = $widthMm < 70;

    /*  Monospace, and that is not nostalgia.
     *
     *  A receipt is two columns — a description on the left and a
     *  figure on the right — read by somebody checking one against
     *  the other. In a proportional face the decimal points do not
     *  line up down the column and the eye has to do the work. In a
     *  fixed-width one they line up for free.
     *
     *  DejaVu Sans Mono ships with dompdf, so it is embedded rather
     *  than substituted at the printer. At 7.5pt on an 80mm roll
     *  that is about 47 characters across, which is the width real
     *  till receipts have used for forty years. */
    $base = $narrow ? 6.8 : 7.5;

    $s       = company_settings();
    $logoUri = company_logo_data_uri();
    $logo    = $logoUri === ''
        ? ''
        : '<img src="' . $logoUri . '" style="max-height:34px; max-width:'
          . (int) ($widthPt - 2 * $pad - 10) . 'px;">';

    // The company, small and centred. Only what somebody would ring
    // up about: where it is, how to reach it, and the PIN a customer
    // needs to claim the VAT back.
    $companyLines = [];
    foreach (['location', 'mobile'] as $k) {
        if (!empty($s[$k])) {
            $companyLines[] = $esc($s[$k]);
        }
    }
    if ($emails = company_emails()) {
        $companyLines[] = $esc($emails[0]);
    }
    if (!empty($s['tax_pin'])) {
        $companyLines[] = 'PIN: ' . $esc($s['tax_pin']);
    }

    $metaRows = '';
    foreach ($doc['meta'] ?? [] as $row) {
        if (trim((string) ($row[1] ?? '')) === '') {
            continue;
        }
        $metaRows .= '<tr><td class="ml">' . $esc($row[0]) . '</td>'
                   . '<td class="mv">' . $esc($row[1]) . '</td></tr>';
    }

    /*  Two lines per item, the way a till prints them: what it was
     *  on one line, and how the money was arrived at on the next.
     *  One line would mean truncating the name, and the name is the
     *  thing a customer checks. */
    $itemRows = '';
    foreach ($doc['items'] ?? [] as $it) {
        $itemRows .= '
            <tr><td class="iname" colspan="2">' . $esc($it['name']) . '</td></tr>
            <tr>
                <td class="icalc">' . $esc($it['qty']) . ' x ' . $esc($it['unit_price'])
                    . ($it['discount'] !== null ? '  less ' . $esc($it['discount']) : '') . '</td>
                <td class="iamt">' . $esc($it['amount']) . '</td>
            </tr>';
    }

    $totalRows = '';
    foreach ($doc['totals'] ?? [] as $row) {
        // A row flagged false is one that does not apply to this sale
        // — a discount of nothing, tax on a zero-rated item. Printing
        // it as 0.00 invites the question of why it is there.
        if (isset($row[2]) && !$row[2]) {
            continue;
        }
        $totalRows .= '<tr><td class="tl">' . $esc($row[0]) . '</td>'
                    . '<td class="tv">' . $esc($row[1]) . '</td></tr>';
    }

    $payRows = '';
    foreach ($doc['payments'] ?? [] as $row) {
        $payRows .= '<tr><td class="tl">' . $esc($row[0]) . '</td>'
                  . '<td class="tv">' . $esc($row[1]) . '</td></tr>';
    }

    $balanceBlock = '';
    if (!empty($doc['balance'])) {
        $balanceBlock = '
            <table class="w"><tr>
                <td class="bl">' . $esc($doc['balance'][0]) . '</td>
                <td class="bv">' . $esc($doc['balance'][1]) . '</td>
            </tr></table>';
    }

    $html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . $esc($doc['filename'] ?? 'Receipt') . '</title>
    <style>
        @page { margin: 0; }
        body {
            font-family: "DejaVu Sans Mono", monospace;
            font-size: ' . $base . 'pt;
            line-height: 1.35;
            color: #000;
            margin: 0;
            padding: ' . $pad . 'pt;
        }
        .w { width: 100%; border-collapse: collapse; }
        .c { text-align: center; }
        .co-name { font-size: ' . ($base + 3) . 'pt; font-weight: bold; text-transform: uppercase; }
        .co-line { font-size: ' . ($base - 0.5) . 'pt; }

        /* A row of dashes rather than a border: a thermal head prints
           a character reliably and a hairline rule sometimes not at
           all, which leaves the receipt looking like it lost a line. */
        .rule { text-align: center; font-size: ' . $base . 'pt; letter-spacing: 0; }

        .doc-title { font-size: ' . ($base + 1.5) . 'pt; font-weight: bold; text-align: center; text-transform: uppercase; }

        .ml { font-size: ' . ($base - 0.5) . 'pt; }
        .mv { font-size: ' . ($base - 0.5) . 'pt; text-align: right; }

        .iname { font-size: ' . $base . 'pt; padding-top: 2pt; }
        .icalc { font-size: ' . ($base - 0.5) . 'pt; padding-left: 6pt; }
        .iamt  { font-size: ' . $base . 'pt; text-align: right; white-space: nowrap; }

        .tl { font-size: ' . $base . 'pt; }
        .tv { font-size: ' . $base . 'pt; text-align: right; white-space: nowrap; }

        .gl { font-size: ' . ($base + 2) . 'pt; font-weight: bold; }
        .gv { font-size: ' . ($base + 2) . 'pt; font-weight: bold; text-align: right; white-space: nowrap; }

        .bl { font-size: ' . ($base + 0.5) . 'pt; font-weight: bold; }
        .bv { font-size: ' . ($base + 0.5) . 'pt; font-weight: bold; text-align: right; white-space: nowrap; }

        .foot { font-size: ' . ($base - 0.5) . 'pt; text-align: center; }
    </style>
</head>
<body>
    <div class="c">' . $logo . '</div>
    <div class="c co-name">' . $esc($s['company_name'] ?? APP_NAME) . '</div>
    ' . ($companyLines ? '<div class="c co-line">' . implode('<br>', $companyLines) . '</div>' : '') . '

    <div class="rule">' . str_repeat('-', $narrow ? 32 : 46) . '</div>
    <div class="doc-title">' . $esc($doc['title'] ?? 'RECEIPT') . '</div>
    <div class="rule">' . str_repeat('-', $narrow ? 32 : 46) . '</div>

    <table class="w">' . $metaRows . '</table>

    <div class="rule">' . str_repeat('-', $narrow ? 32 : 46) . '</div>

    <table class="w">' . $itemRows . '</table>

    <div class="rule">' . str_repeat('-', $narrow ? 32 : 46) . '</div>

    <table class="w">' . $totalRows . '</table>

    <div class="rule">' . str_repeat('=', $narrow ? 32 : 46) . '</div>
    <table class="w"><tr>
        <td class="gl">' . $esc($doc['grand'][0] ?? 'TOTAL') . '</td>
        <td class="gv">' . $esc($doc['grand'][1] ?? '') . '</td>
    </tr></table>
    <div class="rule">' . str_repeat('=', $narrow ? 32 : 46) . '</div>

    ' . ($payRows ? '<table class="w">' . $payRows . '</table>' : '') . '
    ' . $balanceBlock . '

    <div class="rule">' . str_repeat('-', $narrow ? 32 : 46) . '</div>
    <div class="foot">' . $esc($doc['item_count'] ?? '') . '</div>
    ' . (!empty($doc['tax_note']) ? '<div class="foot">' . $esc($doc['tax_note']) . '</div>' : '') . '
    <div class="foot" style="padding-top:6pt;">' . $esc($doc['footer'] ?? '') . '</div>
</body>
</html>';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans Mono');

    /*  A roll has no page length, but dompdf must be told one.
     *
     *  Estimating it is guesswork — a long product name wraps, a
     *  sale with one payment is shorter than one with three — and
     *  guessing short is the failure that matters: the receipt
     *  breaks onto a second page and the customer is handed two
     *  halves of a slip with the total on the back one.
     *
     *  So the estimate is only a starting point. After rendering,
     *  dompdf will say how many pages it actually used, and if that
     *  is more than one the page is grown and it is rendered again.
     *  Three attempts is plenty: the first is right for an ordinary
     *  sale, and each retry multiplies by the overflow. */
    $lines  = 0;
    $lines += 4 + count($companyLines);                 // logo + name + address block
    $lines += 4;                                        // title and its rules
    $lines += count($doc['meta'] ?? []);
    $lines += 2 * count($doc['items'] ?? []) + 2;
    $lines += count($doc['totals'] ?? []) + 4;
    $lines += count($doc['payments'] ?? []) + (empty($doc['balance']) ? 0 : 2);
    $lines += 6;                                        // footer block

    // A wrapped name costs a line nobody counted, so allow for one
    // in every four items rather than pretend none of them wrap.
    $lines += (int) ceil(count($doc['items'] ?? []) / 4);

    /*  1.72 is measured, not chosen. A line of 7.5pt type in a table
     *  cell occupies about 1.5 times its own size; the rest is the
     *  cell padding and the wraps this count cannot see. At 1.5 the
     *  estimate came out 11-12% short on every receipt tried, which
     *  cost four renders each while the loop below closed the gap.
     *  At 1.72 an ordinary sale fits on the first attempt with a few
     *  millimetres to spare, and the loop is left as the safety net
     *  it was meant to be.                                        */
    $heightPt = max(receipt_mm_to_pt(90), $lines * $base * 1.72 + 2 * $pad + 20);

    /*  Find the shortest page the receipt actually fits on.
     *
     *  Rendering is the only way to know: a long product name wraps
     *  and a short one does not, and the difference is a line of
     *  paper. So the estimate above is a starting point and this
     *  closes in on the truth — grow until it fits on one page, then
     *  halve the gap between the last height that did not fit and
     *  the first that did.
     *
     *  Multiplying by the page count instead, which is the obvious
     *  thing, overshoots badly: two pages does not mean twice the
     *  content, it can mean one line too many. Measured on a
     *  thirty-line sale that way — 825mm of till roll for a receipt
     *  that needs about half of it.
     *
     *  It stops when the gap is under 18pt, about a line, because
     *  paper is cheap and renders are not.                        */
    $tooShort = null;
    $fits     = null;
    $best     = null;

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper([0, 0, $widthPt, $heightPt], 'portrait');
        $dompdf->render();

        if ($dompdf->getCanvas()->get_page_count() <= 1) {
            $fits = $heightPt;
            $best = $dompdf;
        } else {
            $tooShort = $heightPt;
        }

        if ($fits === null) {
            // Still too small, and no upper bound yet. Double it:
            // the estimate is out by more than a trim will fix.
            $heightPt *= 2;
            continue;
        }
        if ($tooShort === null || $fits - $tooShort < 18) {
            break;
        }
        $heightPt = ($tooShort + $fits) / 2;
    }

    // $best is the last render that fitted on one page. It cannot be
    // null: the loop doubles until something fits.
    $dompdf = $best ?? $dompdf;

    $dompdf->stream(
        $doc['filename'] ?? 'receipt.pdf',
        ['Attachment' => !empty($doc['download']) ? 1 : 0]
    );
    exit;
}
