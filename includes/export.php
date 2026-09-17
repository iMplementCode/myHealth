<?php

/**
 * ============================================================
 *  Excel export
 * ------------------------------------------------------------
 *  Writes a genuine .xlsx — a zip of XML parts, which is all an
 *  Excel file is — rather than a CSV renamed.
 *
 *  That distinction is the whole point. A CSV of "KES 12,000.00"
 *  is text: Excel cannot add it up, cannot sort it as a number,
 *  and cannot filter a date range. Here every cell carries its
 *  type, so amounts are numbers, dates are dates, and the file
 *  behaves like something a person made.
 *
 *  No new dependency. PHP already has ZipArchive, and the parts
 *  an Excel file needs are few enough to write out by hand.
 *
 *  Usage:
 *      xlsx_download('expenses-2026-08', [[
 *          'name'    => 'Expenses',
 *          'title'   => 'Expenses — August 2026',
 *          'meta'    => ['Filtered by' => 'Category: Rent'],
 *          'columns' => [
 *              ['label' => 'Date',   'key' => 'expense_date', 'type' => 'date'],
 *              ['label' => 'Amount', 'key' => 'amount',       'type' => 'money'],
 *          ],
 *          'rows'    => $rows,
 *          'total'   => true,
 *      ]]);
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * Cell style indices, matching the order they are written into
 * styles.xml below. Kept as names so the writer reads as intent
 * rather than as a list of magic numbers.
 */
const XLSX_STYLE = [
    'default' => 0,
    'header'  => 1,
    'date'    => 2,
    'money'   => 3,
    'integer' => 4,
    'title'   => 5,
    'total'   => 6,   // bold, money format, ruled above
    'label'   => 7,   // bold, for the meta block
    'percent' => 8,
    'total_i' => 9,   // the same, for a column of whole numbers
];

/** Column types the caller may declare, mapped to a style. */
const XLSX_TYPE_STYLE = [
    'text'    => 'default',
    'date'    => 'date',
    'money'   => 'money',
    'number'  => 'money',
    'integer' => 'integer',
    'percent' => 'percent',
];

/**
 * Types that hold a number: right-aligned, and written to the
 * workbook as a number rather than as text.
 *
 * A `percent` is **already in percent points** — a margin of
 * seven per cent arrives as `7.0`, not `0.07` — because that is
 * what reads correctly under a column headed "Margin %". Anything
 * formatting one must not multiply it again.
 */
const XLSX_NUMERIC_TYPES = ['money', 'number', 'integer', 'percent'];

/**
 * Types that can be totalled.
 *
 * A column of percentages cannot: adding 7% to 22% gives 29%, which
 * is not the margin on anything. The overall figure has to be
 * worked out from the totals of the columns it came from, so a
 * percent column is left without one whether or not it asked.
 */
const XLSX_TOTAL_TYPES = ['money', 'number', 'integer'];

/**
 * Which columns carry a total worked out from two other totals.
 *
 * The overall margin on a sales analysis is a real and wanted
 * figure — total margin over total revenue — it simply is not the
 * sum of the column above it. A column says so declaratively:
 *
 *     ['label' => 'Margin %', 'key' => 'pc', 'type' => 'percent',
 *      'total' => ['of' => 'margin', 'over' => 'revenue', 'scale' => 100]]
 *
 * and both writers produce it: the workbook as a formula over the
 * two total cells, so it stays right when a row is deleted, and
 * the PDF as the figure itself.
 *
 * Returns index => ['of' => index, 'over' => index, 'scale' => float].
 */
function export_ratio_totals(array $columns): array
{
    $byKey = [];
    foreach ($columns as $i => $col) {
        if (isset($col['key'])) {
            $byKey[$col['key']] = $i;
        }
    }

    $out = [];
    foreach ($columns as $i => $col) {
        $spec = $col['total'] ?? null;
        if (!is_array($spec) || !isset($spec['of'], $spec['over'])) {
            continue;
        }
        if (!isset($byKey[$spec['of']], $byKey[$spec['over']])) {
            continue;   // names a column that is not on this sheet
        }
        $out[$i] = [
            'of'    => $byKey[$spec['of']],
            'over'  => $byKey[$spec['over']],
            'scale' => (float) ($spec['scale'] ?? 1),
        ];
    }
    return $out;
}

/**
 * Send one or more sheets to the browser as an .xlsx download.
 *
 * Anything already buffered is discarded first: a stray notice
 * printed before the zip would corrupt the file, and the browser
 * would offer a download that cannot be opened.
 */
function xlsx_download(string $filename, array $sheets): void
{
    $binary = xlsx_build($sheets);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'export';
    $safe = trim($safe, '-') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo $binary;
    exit;
}

/** Build the workbook and return it as a binary string. */
function xlsx_build(array $sheets): string
{
    $sheets = array_values($sheets);
    if (!$sheets) {
        $sheets = [['name' => 'Sheet1', 'columns' => [], 'rows' => []]];
    }

    // ZipArchive works on a file, so the workbook is assembled in
    // a temporary one and read back.
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        throw new RuntimeException('Could not create a temporary file for the export.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open the export file for writing.');
    }

    $names = [];
    foreach ($sheets as $i => $sheet) {
        $names[] = xlsx_sheet_name($sheet['name'] ?? ('Sheet' . ($i + 1)), $names);
    }

    $zip->addFromString('[Content_Types].xml', xlsx_content_types(count($sheets)));
    $zip->addFromString('_rels/.rels', xlsx_root_rels());
    $zip->addFromString('xl/workbook.xml', xlsx_workbook($names));
    $zip->addFromString('xl/_rels/workbook.xml.rels', xlsx_workbook_rels(count($sheets)));
    $zip->addFromString('xl/styles.xml', xlsx_styles());

    foreach ($sheets as $i => $sheet) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsx_sheet($sheet));
    }

    $zip->close();
    $binary = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $binary;
}

/* ─────────────────────────────────────────────────────────────
 *  One worksheet
 * ──────────────────────────────────────────────────────────── */

function xlsx_sheet(array $sheet): string
{
    $columns = array_values($sheet['columns'] ?? []);
    $rows    = $sheet['rows'] ?? [];
    $width   = max(1, count($columns));

    $xml   = [];
    $rowNo = 0;

    // ── Title ──
    if (!empty($sheet['title'])) {
        $rowNo++;
        $xml[] = xlsx_row($rowNo, [xlsx_cell('A' . $rowNo, (string) $sheet['title'], 'text', XLSX_STYLE['title'])]);
        $rowNo++;                       // a blank line under it
    }

    // ── Meta block: what the reader needs to trust the numbers ──
    foreach (($sheet['meta'] ?? []) as $label => $value) {
        $rowNo++;
        $xml[] = xlsx_row($rowNo, [
            xlsx_cell('A' . $rowNo, (string) $label, 'text', XLSX_STYLE['label']),
            xlsx_cell('B' . $rowNo, (string) $value, 'text', XLSX_STYLE['default']),
        ]);
    }
    if (!empty($sheet['meta'])) {
        $rowNo++;
    }

    // ── Header ──
    $headerRow = 0;
    if ($columns) {
        $rowNo++;
        $headerRow = $rowNo;
        $cells = [];
        foreach ($columns as $i => $col) {
            $cells[] = xlsx_cell(xlsx_col_letter($i) . $rowNo, (string) $col['label'], 'text', XLSX_STYLE['header']);
        }
        $xml[] = xlsx_row($rowNo, $cells);
    }

    // ── Body ──
    $firstDataRow = $rowNo + 1;
    foreach ($rows as $row) {
        $rowNo++;
        $cells = [];
        foreach ($columns as $i => $col) {
            $type  = $col['type'] ?? 'text';
            $value = xlsx_value($row, $col);
            $cells[] = xlsx_cell(xlsx_col_letter($i) . $rowNo, $value, $type,
                XLSX_STYLE[XLSX_TYPE_STYLE[$type] ?? 'default']);
        }
        $xml[] = xlsx_row($rowNo, $cells);
    }
    $lastDataRow = $rowNo;

    // ── Totals ──
    // Written as a real SUM() rather than a computed figure, so it
    // stays right when somebody deletes a row in Excel.
    if (!empty($sheet['total']) && $rows && $columns) {
        $rowNo++;
        $cells = [];
        $labelled = false;
        $ratios   = export_ratio_totals($columns);
        foreach ($columns as $i => $col) {
            $letter = xlsx_col_letter($i);
            $type   = $col['type'] ?? 'text';
            $ref    = $letter . $rowNo;

            if (isset($ratios[$i])) {
                // A ratio of two totals, as a formula over their cells
                // so it survives a row being deleted like the sums do.
                $of    = xlsx_col_letter($ratios[$i]['of'])   . $rowNo;
                $over  = xlsx_col_letter($ratios[$i]['over']) . $rowNo;
                $scale = $ratios[$i]['scale'];
                $cells[] = '<c r="' . $ref . '" s="' . XLSX_STYLE['total'] . '">'
                         . '<f>IF(' . $over . '=0,&quot;&quot;,' . $of . '/' . $over
                         . ($scale != 1.0 ? '*' . $scale : '') . ')</f></c>';
            } elseif (in_array($type, XLSX_TOTAL_TYPES, true) && ($col['total'] ?? true)) {
                // A count totalled with two decimal places reads as a
                // mistake, so whole-number columns keep their format.
                $style = $type === 'integer' ? XLSX_STYLE['total_i'] : XLSX_STYLE['total'];
                $cells[] = '<c r="' . $ref . '" s="' . $style . '">'
                         . '<f>SUM(' . $letter . $firstDataRow . ':' . $letter . $lastDataRow . ')</f></c>';
            } elseif (!$labelled) {
                $cells[] = xlsx_cell($ref, 'Total', 'text', XLSX_STYLE['total']);
                $labelled = true;
            } else {
                $cells[] = '<c r="' . $ref . '" s="' . XLSX_STYLE['total'] . '"/>';
            }
        }
        $xml[] = xlsx_row($rowNo, $cells);
    }

    // ── Column widths ──
    $cols = '';
    if ($columns) {
        $cols = '<cols>';
        foreach ($columns as $i => $col) {
            $w = $col['width'] ?? xlsx_guess_width($col, $rows);
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $cols .= '</cols>';
    }

    // Freeze the header and switch on the filter dropdowns, so a
    // long export is usable the moment it opens.
    $pane   = '';
    $filter = '';
    if ($headerRow > 0) {
        $pane = '<pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1)
              . '" activePane="bottomLeft" state="frozen"/>';
        if ($rows) {
            $filter = '<autoFilter ref="A' . $headerRow . ':' . xlsx_col_letter($width - 1) . $lastDataRow . '"/>';
        }
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0">' . $pane . '</sheetView></sheetViews>'
        . $cols
        . '<sheetData>' . implode('', $xml) . '</sheetData>'
        . $filter
        . '</worksheet>';
}

function xlsx_row(int $n, array $cells): string
{
    return '<row r="' . $n . '">' . implode('', $cells) . '</row>';
}

/**
 * One cell, typed.
 *
 * Numbers and dates go in as numbers — that is what makes the
 * file worth more than a CSV. Everything else goes in as an
 * inline string, which avoids maintaining a shared-string table
 * for no gain at these sizes.
 */
function xlsx_cell(string $ref, $value, string $type = 'text', int $style = 0): string
{
    $s = ' s="' . $style . '"';

    if ($value === null || $value === '') {
        return '<c r="' . $ref . '"' . $s . '/>';
    }

    if (in_array($type, XLSX_NUMERIC_TYPES, true)) {
        if (!is_numeric($value)) {
            return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t>'
                 . xlsx_escape((string) $value) . '</t></is></c>';
        }
        return '<c r="' . $ref . '"' . $s . '><v>' . (0 + $value) . '</v></c>';
    }

    if ($type === 'date') {
        $serial = xlsx_date_serial((string) $value);
        if ($serial === null) {
            return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t>'
                 . xlsx_escape((string) $value) . '</t></is></c>';
        }
        return '<c r="' . $ref . '"' . $s . '><v>' . $serial . '</v></c>';
    }

    return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
         . xlsx_escape((string) $value) . '</t></is></c>';
}

/** Pull a column's value out of a row, honouring a callback. */
function xlsx_value(array $row, array $col)
{
    if (isset($col['value']) && is_callable($col['value'])) {
        return ($col['value'])($row);
    }
    return $row[$col['key'] ?? ''] ?? null;
}

/**
 * Excel counts days from 1899-12-30 — the offset that reproduces
 * Lotus 1-2-3's belief that 1900 was a leap year, kept for
 * compatibility ever since.
 */
function xlsx_date_serial(string $value): ?float
{
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    $days = (new DateTimeImmutable('1899-12-30'))
        ->diff(new DateTimeImmutable(date('Y-m-d H:i:s', $ts)));
    $serial = (float) $days->days;
    // Keep the time of day when there is one.
    $serial += ((int) date('H', $ts) * 3600 + (int) date('i', $ts) * 60 + (int) date('s', $ts)) / 86400;
    return round($serial, 6);
}

/** XML-escape, and drop control characters XML cannot carry. */
function xlsx_escape(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** 0 → A, 25 → Z, 26 → AA. */
function xlsx_col_letter(int $index): string
{
    $letter = '';
    $index  = max(0, $index);
    do {
        $letter = chr(65 + ($index % 26)) . $letter;
        $index  = intdiv($index, 26) - 1;
    } while ($index >= 0);
    return $letter;
}

/**
 * A width that fits the header and a sample of the data.
 *
 * Columns sized to the header alone leave amounts showing as
 * ####, which is the first thing anybody notices about a bad
 * export.
 */
function xlsx_guess_width(array $col, array $rows): int
{
    $longest = mb_strlen((string) ($col['label'] ?? ''));
    $type    = $col['type'] ?? 'text';

    foreach (array_slice($rows, 0, 200) as $row) {
        $v = xlsx_value($row, $col);
        if ($v === null) {
            continue;
        }
        $text = in_array($type, XLSX_NUMERIC_TYPES, true)
            ? number_format((float) $v, 2)
            : (string) $v;
        $longest = max($longest, mb_strlen($text));
    }
    if ($type === 'date') {
        $longest = max($longest, 11);
    }
    return (int) min(60, max(10, $longest + 3));
}

/** Excel refuses some characters in a tab name, and 31 is the cap. */
function xlsx_sheet_name(string $name, array $taken): string
{
    $name = preg_replace('#[\\\\/*?:\[\]]#', ' ', $name) ?? $name;
    $name = trim(mb_substr(trim($name), 0, 31));
    if ($name === '') {
        $name = 'Sheet';
    }
    $base = $name;
    for ($n = 2; in_array($name, $taken, true); $n++) {
        $suffix = ' (' . $n . ')';
        $name   = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
    }
    return $name;
}

/* ─────────────────────────────────────────────────────────────
 *  The fixed parts of the package
 * ──────────────────────────────────────────────────────────── */

function xlsx_content_types(int $sheetCount): string
{
    $overrides = '';
    for ($i = 1; $i <= $sheetCount; $i++) {
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml"'
                    . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $overrides
        . '</Types>';
}

function xlsx_root_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
        . ' Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function xlsx_workbook(array $names): string
{
    $sheets = '';
    foreach ($names as $i => $name) {
        $sheets .= '<sheet name="' . xlsx_escape($name) . '" sheetId="' . ($i + 1)
                 . '" r:id="rId' . ($i + 1) . '"/>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheets . '</sheets></workbook>';
}

function xlsx_workbook_rels(int $sheetCount): string
{
    $rels = '';
    for ($i = 1; $i <= $sheetCount; $i++) {
        $rels .= '<Relationship Id="rId' . $i . '"'
               . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
               . ' Target="worksheets/sheet' . $i . '.xml"/>';
    }
    $rels .= '<Relationship Id="rId' . ($sheetCount + 1) . '"'
           . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
           . ' Target="styles.xml"/>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $rels . '</Relationships>';
}

/**
 * The style table.
 *
 * The order here defines the indices in XLSX_STYLE — the two must
 * be changed together.
 */
function xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="3">'
        . '<numFmt numFmtId="164" formatCode="dd\ mmm\ yyyy"/>'
        . '<numFmt numFmtId="165" formatCode="#,##0.00"/>'
        . '<numFmt numFmtId="166" formatCode="#,##0"/>'
        . '</numFmts>'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFEFEFEF"/>'
        . '<bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left/><right/><top style="thin"><color rgb="FF999999"/></top><bottom/><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="10">'
        //  0 default
        . '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'
        //  1 header — bold on a grey fill
        . '<xf numFmtId="0"   fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        //  2 date
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        //  3 money
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        //  4 integer
        . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        //  5 title
        . '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        //  6 total — bold money, ruled above
        . '<xf numFmtId="165" fontId="1" fillId="0" borderId="1" xfId="0"'
        . ' applyNumberFormat="1" applyFont="1" applyBorder="1"/>'
        //  7 label
        . '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        //  8 percent — a plain number; the sign is in the header
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        //  9 total, whole numbers
        . '<xf numFmtId="166" fontId="1" fillId="0" borderId="1" xfId="0"'
        . ' applyNumberFormat="1" applyFont="1" applyBorder="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/* ─────────────────────────────────────────────────────────────
 *  Page helpers
 * ──────────────────────────────────────────────────────────── */

/** True when the current request is asking for a spreadsheet. */
function wants_export(): bool
{
    return export_format() !== '';
}

/**
 * A filename stem carrying today's date, so a folder of exports
 * does not become a pile of "export (3).xlsx".
 */
function export_filename(string $stem): string
{
    return $stem . '-' . date('Y-m-d');
}

/**
 * The link that repeats the current request as a download.
 *
 * Built from the filters already in hand rather than from
 * $_GET, so the export is exactly the list on screen — minus
 * the page number, because an export of page 3 alone is not
 * what anybody means by "export".
 */
function export_url(string $path, array $query = [], string $format = 'xlsx'): string
{
    unset($query['page'], $query['c_page'], $query['po_page'], $query['per_page']);
    $query['export'] = in_array($format, ['xlsx', 'pdf'], true) ? $format : 'xlsx';
    return url($path . '?' . http_build_query($query));
}

/**
 * The provenance block that heads every export.
 *
 * A spreadsheet leaves the system and gets mailed on, argued
 * over, and opened again in six months. By then nobody remembers
 * which filters produced it, so the file has to say — and it has
 * to say who produced it and when, because that is the first
 * question asked of a figure somebody disputes.
 *
 * Empty filters are dropped: "Search: (blank)" is noise.
 */
function export_meta(array $filters): array
{
    $meta = [];
    foreach ($filters as $label => $value) {
        if ($value === null || $value === '' || $value === []) {
            continue;
        }
        $meta[$label] = is_array($value) ? implode(', ', $value) : (string) $value;
    }
    $meta['Produced']    = fmt_date(date('Y-m-d'), 'd F Y') . ' at ' . date('H:i');
    $meta['Produced by'] = current_user()['name'] ?? 'Unknown';
    return $meta;
}

/**
 * The download buttons, so every page offers them the same way.
 *
 * Two formats, because they answer different questions. A
 * spreadsheet is for working with — sorting, filtering, adding a
 * column of your own. A PDF is for sending to somebody who will
 * not edit it: a bank, an auditor, a landlord asking what the
 * business turned over. Both are built from the same definition,
 * so a column added to one appears in the other.
 */
function export_button(string $path, array $query = [], string $label = 'Excel'): string
{
    return '<a class="btn btn-ghost" href="' . e(export_url($path, $query, 'xlsx')) . '">'
         . icon('download') . ' ' . e($label) . '</a>'
         . '<a class="btn btn-ghost" href="' . e(export_url($path, $query, 'pdf')) . '">'
         . icon('report') . ' PDF</a>';
}

/* ═══════════════════════════════════════════════════════════════
 *  The same table, as a PDF
 * ══════════════════════════════════════════════════════════════
 *  A spreadsheet is for working with; a PDF is for sending to
 *  someone who will not edit it — a bank, an auditor, a landlord
 *  asking what the business turned over. Every page that offers
 *  one now offers the other, and neither has to be defined twice:
 *  both read the same `$sheets` structure, so a column added to
 *  the export appears in both or in neither.
 *
 *  dompdf lays out tables and very little else — floated blocks
 *  overlap and flexbox does nothing — so every part of this is a
 *  table, exactly as the invoice renderer is.
 * ══════════════════════════════════════════════════════════════ */

/** Which format the current request is asking for. */
function export_format(): string
{
    $f = strtolower((string) ($_GET['export'] ?? ''));
    return in_array($f, ['xlsx', 'pdf'], true) ? $f : '';
}

/**
 * Send the export in whichever format was asked for.
 *
 * Pages call this instead of choosing a writer themselves, so
 * adding a format is one change here rather than one per page.
 */
function export_deliver(string $filename, array $sheets): void
{
    $format = export_format() === 'pdf' ? 'pdf' : 'xlsx';

    if (($missing = export_missing_requirement($format)) !== null) {
        export_refuse($missing);
    }
    if ($format === 'pdf') {
        pdf_download($filename, $sheets);
    }
    xlsx_download($filename, $sheets);
}

/**
 * What this server is missing before it can write that format.
 *
 * Both writers depend on something that is not part of PHP itself,
 * and when it is absent the failure is a fatal error — which the
 * browser shows as a blank page or the generic "temporarily
 * unavailable", so the button simply appears not to work. Naming
 * the missing piece turns a mystery into a one-line fix.
 */
function export_missing_requirement(string $format): ?string
{
    if ($format === 'pdf') {
        if (!class_exists('\Dompdf\Dompdf')) {
            return 'the PDF library is not installed on this server — run "composer install" in the application folder';
        }
        if (!extension_loaded('dom') || !extension_loaded('mbstring')) {
            return 'PDFs need PHP\'s dom and mbstring extensions, and this server does not have both enabled';
        }
        return null;
    }

    if (!class_exists('ZipArchive')) {
        // An .xlsx is a zip of XML parts; without ext-zip there is
        // nothing to put them in.
        return 'Excel exports need PHP\'s zip extension, which is not enabled on this server '
             . '(enable extension=zip in php.ini and restart the web server)';
    }
    return null;
}

/**
 * Turn the request back with the reason, rather than dying.
 *
 * Shares the size guard's rule about REQUEST_URI: it comes off the
 * wire, so it is checked before it goes into a Location header.
 */
function export_refuse(string $why): void
{
    flash('error', 'That export could not be produced: ' . $why . '.');

    $back = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    $q    = $_GET;
    unset($q['export']);

    if (is_string($back) && preg_match('#^/(?!/)[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#', $back)) {
        header('Location: ' . $back . ($q ? '?' . http_build_query($q) : ''));
        exit;
    }
    redirect('index.php');
}

/**
 * How many rows a PDF is allowed to carry.
 *
 * dompdf lays out every cell itself, and the cost climbs faster
 * than the row count: 500 rows render in under four seconds,
 * 2,000 take twenty-five, and 5,000 never finish inside any
 * sensible time limit. Left alone, a year of invoices would sit
 * there until PHP gave up and the download arrived truncated or
 * not at all.
 */
const PDF_MAX_ROWS = 1200;

/**
 * Refuse a PDF that is too big to render, and say why.
 *
 * Truncating it is the tempting shortcut and the wrong one: a
 * report whose Total does not add up its own rows is worse than
 * no report, because somebody will quote the figure. So the
 * request is turned back to the page it came from with the
 * spreadsheet offered instead — which is the right tool at that
 * size anyway. Nobody emails a five-thousand-row PDF.
 */
function pdf_guard_size(array $sheets): void
{
    $rows = 0;
    foreach ($sheets as $sheet) {
        $rows += count($sheet['rows'] ?? []);
    }
    if ($rows <= PDF_MAX_ROWS) {
        // A legitimate big-but-allowed PDF still needs room to
        // render; the default 30 seconds is not always enough.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }
        return;
    }

    flash('error', 'That is ' . num($rows) . ' rows — too many for a PDF, which is meant '
        . 'for a document you send to somebody. Narrow the dates or filters, or use the '
        . 'Excel export, which handles any size.');

    // Back to the page that asked, carrying its filters but not the
    // export itself — redirecting to ?export=xlsx would push down a
    // spreadsheet nobody asked for.
    //
    // REQUEST_URI comes off the wire, so it is checked before it goes
    // into a Location header: one leading slash and no second one,
    // or "//evil.example" becomes a protocol-relative open redirect.
    $back = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    $q    = $_GET;
    unset($q['export']);

    if (is_string($back) && preg_match('#^/(?!/)[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#', $back)) {
        header('Location: ' . $back . ($q ? '?' . http_build_query($q) : ''));
        exit;
    }
    redirect('index.php');
}

/** One cell, formatted the way a person reads it. */
function pdf_cell_text(array $row, array $col): string
{
    $value = xlsx_value($row, $col);
    $type  = $col['type'] ?? 'text';

    if ($value === null || $value === '') {
        return '';
    }
    return match ($type) {
        'money'   => money($value),
        'number'  => num((float) $value, 2),
        'integer' => num((float) $value),
        // Already in percent points — see XLSX_NUMERIC_TYPES.
        // Multiplying here turned a 7% margin into 700%.
        'percent' => num((float) $value, 1) . '%',
        'date'    => fmt_date((string) $value),
        default   => (string) $value,
    };
}

/**
 * Render the sheets as a PDF and send it.
 *
 * Landscape once a table is wide enough that portrait would
 * squeeze it: eight columns is about where a page stops being
 * readable, and a report nobody can read is not a report.
 */
function pdf_download(string $filename, array $sheets): void
{
    require_once __DIR__ . '/company.php';

    pdf_guard_size($sheets);

    $co     = company_settings();
    $esc    = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $brand  = defined('BRAND_COLOR') ? BRAND_COLOR : '#29c5f0';
    $widest = 0;
    foreach ($sheets as $sheet) {
        $widest = max($widest, count($sheet['columns'] ?? []));
    }
    $landscape = $widest > 7;

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        body  { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #1a1a1a; }
        .co   { font-size: 15pt; font-weight: bold; color: ' . $esc($brand) . '; }
        .co-sub { font-size: 8.5pt; color: #666; }
        h1    { font-size: 13pt; margin: 14px 0 2px; color: ' . $esc($brand) . '; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { font-size: 8.5pt; color: #555; padding: 1px 0; }
        .meta .k { width: 26%; font-weight: bold; color: #333; }
        .grid { margin-top: 8px; }
        .grid th {
            background: #f2f4f7; border-bottom: 1.2px solid #999;
            padding: 5px 6px; font-size: 8.5pt; text-align: left;
            text-transform: uppercase; letter-spacing: 0.03em;
        }
        .grid td { padding: 4px 6px; border-bottom: 0.5px solid #e2e2e2; font-size: 9pt;
                   word-wrap: break-word; }
        .grid tr.alt td { background: #fafbfc; }
        /* A figure must never break across two lines: on a cramped
           sheet "KES -" ended up above "4,000.00", which reads as a
           different number entirely. */
        .num  { text-align: right; white-space: nowrap; }
        .tot td { border-top: 1.2px solid #999; border-bottom: none;
                  font-weight: bold; background: #f2f4f7; }
        .empty { padding: 14px 6px; color: #777; font-style: italic; }
        .foot  { margin-top: 10px; font-size: 7.5pt; color: #888; }
    </style></head><body>';

    // trim() is byte-wise, so a middot must never be in its charlist:
    // build the line from the parts that are actually there instead.
    $subtitle = implode(' · ', array_filter([
        trim((string) ($co['location'] ?? '')),
        trim((string) ($co['mobile'] ?? '')),
        trim((string) ($co['email'] ?? '')),
    ], static fn($v) => $v !== ''));

    $html .= '<table><tr>'
           . '<td><span class="co">' . $esc($co['company_name'] ?? APP_NAME) . '</span><br>'
           . '<span class="co-sub">' . $esc($subtitle) . '</span></td>'
           . '<td style="text-align:right" class="co-sub">' . $esc(fmt_date(date('Y-m-d'), 'd F Y')) . '</td>'
           . '</tr></table>';

    foreach ($sheets as $s => $sheet) {
        $columns = array_values($sheet['columns'] ?? []);
        $rows    = $sheet['rows'] ?? [];

        if ($s > 0) {
            $html .= '<div style="page-break-before: always;"></div>';
        }
        $html .= '<h1>' . $esc($sheet['title'] ?? $sheet['name'] ?? 'Report') . '</h1>';

        if (!empty($sheet['meta'])) {
            $html .= '<table class="meta">';
            foreach ($sheet['meta'] as $k => $v) {
                $html .= '<tr><td class="k">' . $esc($k) . '</td><td>' . $esc($v) . '</td></tr>';
            }
            $html .= '</table>';
        }

        $html .= '<table class="grid"><thead><tr>';
        foreach ($columns as $col) {
            $numeric = in_array($col['type'] ?? 'text', XLSX_NUMERIC_TYPES, true);
            $html .= '<th' . ($numeric ? ' class="num"' : '') . '>' . $esc($col['label']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (!$rows) {
            $html .= '<tr><td class="empty" colspan="' . max(1, count($columns)) . '">'
                   . 'Nothing to show for these filters.</td></tr>';
        }

        // Totals are computed here rather than left as a formula:
        // a PDF has no spreadsheet underneath it to recalculate.
        //
        // Every summable column is added up, whether or not it shows
        // a total, because a ratio total may be built from a column
        // that keeps its own sum off the page.
        $sums = [];
        foreach ($rows as $n => $row) {
            $html .= '<tr' . ($n % 2 ? ' class="alt"' : '') . '>';
            foreach ($columns as $i => $col) {
                $type    = $col['type'] ?? 'text';
                $numeric = in_array($type, XLSX_NUMERIC_TYPES, true);
                if (in_array($type, XLSX_TOTAL_TYPES, true)) {
                    $sums[$i] = ($sums[$i] ?? 0) + (float) xlsx_value($row, $col);
                }
                $html .= '<td' . ($numeric ? ' class="num"' : '') . '>'
                       . $esc(pdf_cell_text($row, $col)) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';

        if (!empty($sheet['total']) && $rows && $columns) {
            $ratios = export_ratio_totals($columns);
            $html  .= '<tfoot><tr class="tot">';
            $labelled = false;
            foreach ($columns as $i => $col) {
                $type  = $col['type'] ?? 'text';
                $shown = null;

                if (isset($ratios[$i])) {
                    $over = $sums[$ratios[$i]['over']] ?? 0.0;
                    if ($over != 0.0) {
                        $shown = ($sums[$ratios[$i]['of']] ?? 0.0) / $over * $ratios[$i]['scale'];
                    }
                } elseif (isset($sums[$i]) && ($col['total'] ?? true) !== false) {
                    $shown = $sums[$i];
                }

                if ($shown !== null) {
                    $html .= '<td class="num">'
                           . $esc(pdf_cell_text([$i => $shown], ['key' => $i, 'type' => $type]))
                           . '</td>';
                } elseif (!$labelled) {
                    $html .= '<td>Total</td>';
                    $labelled = true;
                } else {
                    $html .= '<td></td>';
                }
            }
            $html .= '</tr></tfoot>';
        }
        $html .= '</table>';
    }

    // Who produced it and when is already in the meta block above;
    // repeating it here would just be noise.
    $html .= '<div class="foot">' . $esc($co['company_name'] ?? APP_NAME)
           . ' · generated by ' . $esc(APP_NAME) . '</div>';
    $html .= '</body></html>';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
    $dompdf->render();

    // Page numbers, which a printed report of any length needs.
    $canvas = $dompdf->getCanvas();
    $canvas->page_text(
        $canvas->get_width() - 90, $canvas->get_height() - 28,
        'Page {PAGE_NUM} of {PAGE_COUNT}', null, 8, [0.5, 0.5, 0.5]
    );

    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'report';
    $dompdf->stream(trim($safe, '-') . '.pdf', ['Attachment' => 1]);
    exit;
}
