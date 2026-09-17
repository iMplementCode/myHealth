<?php

/**
 * ============================================================
 *  A very small Word (.docx) writer
 * ------------------------------------------------------------
 *  A .docx is a ZIP of a handful of XML files. That is the whole
 *  secret, and it means a document with headings, paragraphs,
 *  bullets and tables needs no library at all — just ZipArchive,
 *  which PHP already has.
 *
 *  ── Why not PHPWord ─────────────────────────────────────────
 *  PHPWord is a fine library and roughly forty times the size of
 *  this file. It would have to be installed on the VPS, kept
 *  patched, and would pull in its own dependencies — for a
 *  feature that emits one document shape. When the ERP needs
 *  mail merge and change tracking, swap this out. Until then the
 *  dependency costs more than it saves.
 *
 *  ── What it does NOT do ─────────────────────────────────────
 *  Images, styles beyond the handful below, headers and footers,
 *  page numbering, tracked changes. If you need those, this is
 *  the wrong file to extend — reach for PHPWord instead.
 *
 *  Usage:
 *      $d = new DocxWriter();
 *      $d->heading('Equipment Warranty', 1);
 *      $d->para('Thank you for purchasing from us.');
 *      $d->bullets(['one', 'two']);
 *      $d->table([['A','B']], [['1','2']], [4000, 5000]);
 *      $d->download('Warranty_WTY-2026-0001.docx');
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

class DocxWriter
{
    /** Word measures in twips: 1440 to the inch, 15 to the pixel-ish. */
    public const PAGE_WIDTH  = 11906;   // A4 portrait
    public const PAGE_HEIGHT = 16838;
    public const MARGIN      = 720;     // half an inch

    /** Usable width between the margins, for sizing tables. */
    public const CONTENT_WIDTH = self::PAGE_WIDTH - (2 * self::MARGIN);

    private string $body = '';
    private string $accent;

    /**
     * @param string $accent Heading colour as RRGGBB, no hash. Defaults
     *                       to the house brand so a Word download and a
     *                       PDF of the same document look related.
     */
    public function __construct(string $accent = '29C5F0')
    {
        $this->accent = strtoupper(ltrim($accent, '#'));
    }

    /* ─────────────────────────────────────────────────────────
     *  Content
     * ──────────────────────────────────────────────────────── */

    /** A heading. Level 1 is the document title; 2 and 3 are sections. */
    public function heading(string $text, int $level = 2): static
    {
        $size = match ($level) { 1 => 32, 2 => 24, 3 => 22, default => 20 };
        $rule = $level <= 2
            ? '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="2" w:color="' . $this->accent . '"/></w:pBdr>'
            : '';

        /*  The order of these elements is schema-enforced, not
         *  cosmetic: pBdr, then spacing, then outlineLvl. Word does
         *  not warn about a wrong order, it refuses the file with
         *  "source file could not be loaded" and no other clue. */
        $this->body .=
            '<w:p><w:pPr>'
            . $rule
            . '<w:spacing w:before="' . ($level === 1 ? 0 : 200) . '" w:after="80"/>'
            . '<w:outlineLvl w:val="' . max(0, $level - 1) . '"/>'
            . '</w:pPr>'
            . $this->run($text, [
                'b'     => true,
                'size'  => $size,
                'color' => $level === 3 ? '333333' : $this->accent,
                'caps'  => $level <= 2,
            ])
            . '</w:p>';
        return $this;
    }

    /** A paragraph of body text. */
    public function para(string $text, array $opts = []): static
    {
        if (trim($text) === '') {
            return $this;
        }
        $this->body .=
            '<w:p><w:pPr><w:spacing w:after="' . ($opts['after'] ?? 100) . '"/>'
            . (!empty($opts['justify']) ? '<w:jc w:val="both"/>' : '')
            . '</w:pPr>'
            . $this->run($text, $opts) . '</w:p>';
        return $this;
    }

    /**
     * A bulleted list.
     *
     * The bullet is a literal character with a hanging indent rather
     * than Word's numbering definitions: numbering.xml would double
     * the size of this file to render the same dot.
     */
    public function bullets(array $items): static
    {
        foreach ($items as $item) {
            $this->body .=
                '<w:p><w:pPr>'
                . '<w:spacing w:after="40"/>'
                . '<w:ind w:left="360" w:hanging="180"/>'
                . '</w:pPr>'
                . $this->run('•  ' . $item)
                . '</w:p>';
        }
        return $this;
    }

    /**
     * A table with a shaded header row.
     *
     * @param string[]   $headers
     * @param string[][] $rows
     * @param int[]      $widths Column widths in twips; must sum to the
     *                           table width or Word lays it out itself.
     */
    public function table(array $headers, array $rows, array $widths = []): static
    {
        $cols = max(count($headers), $rows ? count($rows[0]) : 0);
        if ($cols === 0) {
            return $this;
        }
        if (count($widths) !== $cols) {
            $widths = array_fill(0, $cols, intdiv(self::CONTENT_WIDTH, $cols));
        }

        $grid = '<w:tblGrid>';
        foreach ($widths as $wpx) {
            $grid .= '<w:gridCol w:w="' . (int) $wpx . '"/>';
        }
        $grid .= '</w:tblGrid>';

        $border = static fn(string $side) =>
            '<w:' . $side . ' w:val="single" w:sz="4" w:space="0" w:color="DDDDDD"/>';

        $xml = '<w:tbl><w:tblPr>'
             . '<w:tblW w:w="' . array_sum($widths) . '" w:type="dxa"/>'
             . '<w:tblBorders>'
             . $border('top') . $border('left') . $border('bottom')
             . $border('right') . $border('insideH') . $border('insideV')
             . '</w:tblBorders>'
             . '<w:tblCellMar>'
             . '<w:top w:w="60" w:type="dxa"/><w:left w:w="80" w:type="dxa"/>'
             . '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="80" w:type="dxa"/>'
             . '</w:tblCellMar>'
             . '</w:tblPr>' . $grid;

        if ($headers) {
            $xml .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
            foreach ($headers as $i => $h) {
                $xml .= $this->cell((string) $h, $widths[$i] ?? 1000, [
                    'b' => true, 'color' => 'FFFFFF', 'size' => 16,
                    'shade' => $this->accent, 'caps' => true,
                ]);
            }
            $xml .= '</w:tr>';
        }

        foreach ($rows as $row) {
            $xml .= '<w:tr>';
            foreach (array_values($row) as $i => $c) {
                $xml .= $this->cell((string) $c, $widths[$i] ?? 1000, ['size' => 17]);
            }
            $xml .= '</w:tr>';
        }

        $this->body .= $xml . '</w:tbl>' . $this->spacerXml();
        return $this;
    }

    /**
     * A two-column label/value table with no outer borders — for
     * summary boxes and identification blocks.
     */
    public function definitions(array $pairs, int $labelWidth = 2600): static
    {
        if (!$pairs) {
            return $this;
        }
        $valueWidth = self::CONTENT_WIDTH - $labelWidth;

        $xml = '<w:tbl><w:tblPr>'
             . '<w:tblW w:w="' . self::CONTENT_WIDTH . '" w:type="dxa"/>'
             . '<w:tblBorders>'
             . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="EEEEEE"/>'
             . '<w:top w:val="single" w:sz="4" w:space="0" w:color="DDDDDD"/>'
             . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="DDDDDD"/>'
             . '</w:tblBorders>'
             . '<w:tblCellMar>'
             . '<w:top w:w="70" w:type="dxa"/><w:left w:w="90" w:type="dxa"/>'
             . '<w:bottom w:w="70" w:type="dxa"/><w:right w:w="90" w:type="dxa"/>'
             . '</w:tblCellMar></w:tblPr>'
             . '<w:tblGrid><w:gridCol w:w="' . $labelWidth . '"/>'
             . '<w:gridCol w:w="' . $valueWidth . '"/></w:tblGrid>';

        foreach ($pairs as [$label, $value]) {
            $xml .= '<w:tr>'
                  . $this->cell((string) $label, $labelWidth,
                        ['b' => true, 'size' => 18, 'color' => $this->accent, 'shade' => 'F8F9FA'])
                  . $this->cell((string) $value, $valueWidth, ['size' => 18])
                  . '</w:tr>';
        }

        $this->body .= $xml . '</w:tbl>' . $this->spacerXml();
        return $this;
    }

    /**
     * Blank vertical space. Word needs an empty paragraph for it, and
     * one directly after a table is not optional: two adjacent tables
     * with nothing between them are merged into one on open.
     */
    public function spacer(int $after = 120): static
    {
        $this->body .= $this->spacerXml($after);
        return $this;
    }

    private function spacerXml(int $after = 120): string
    {
        return '<w:p><w:pPr><w:spacing w:after="' . $after . '"/></w:pPr></w:p>';
    }

    /** A signature line: an underscore rule with a caption beneath. */
    public function signatureRow(array $left, array $right): static
    {
        $half = intdiv(self::CONTENT_WIDTH, 2);
        $col = function (array $lines) use ($half) {
            $inner = '';
            foreach ($lines as $l) {
                [$text, $kind] = is_array($l) ? $l : [$l, 'label'];
                $inner .= match ($kind) {
                    'rule'  => '<w:p><w:pPr><w:spacing w:before="200" w:after="0"/>'
                             . '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="999999"/></w:pBdr>'
                             . '</w:pPr></w:p>',
                    'label' => '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>'
                             . $this->run($text, ['size' => 15, 'color' => '888888', 'caps' => true]) . '</w:p>',
                    default => '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>'
                             . $this->run($text, ['size' => 17, 'color' => '555555']) . '</w:p>',
                };
            }
            return '<w:tc><w:tcPr><w:tcW w:w="' . $half . '" w:type="dxa"/></w:tcPr>'
                 . $inner . '</w:tc>';
        };

        $this->body .=
            '<w:tbl><w:tblPr><w:tblW w:w="' . self::CONTENT_WIDTH . '" w:type="dxa"/>'
            . '<w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="240" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="' . $half . '"/><w:gridCol w:w="' . $half . '"/></w:tblGrid>'
            . '<w:tr>' . $col($left) . $col($right) . '</w:tr></w:tbl>'
            . $this->spacerXml();
        return $this;
    }

    /* ─────────────────────────────────────────────────────────
     *  Output
     * ──────────────────────────────────────────────────────── */

    /** The finished .docx as a string. */
    public function render(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file for the document.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not open the document archive for writing.');
        }

        foreach ($this->parts() as $name => $xml) {
            $zip->addFromString($name, $xml);
        }
        $zip->close();

        $out = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }

    /** Send it to the browser as a download. */
    public function download(string $filename): void
    {
        $out = $this->render();

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // The filename is quoted and stripped of anything that could
        // break out of the header — it comes from a document number,
        // but a header injection is not worth trusting that.
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'document.docx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . strlen($out));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        echo $out;
    }

    /* ─────────────────────────────────────────────────────────
     *  The XML underneath
     * ──────────────────────────────────────────────────────── */

    /** One formatted run of text. */
    private function run(string $text, array $o = []): string
    {
        // Schema order again: b, i, caps, color, sz. Emitting sz
        // before color is enough for Word to reject the document.
        $props = '';
        if (!empty($o['b']))     $props .= '<w:b/>';
        if (!empty($o['i']))     $props .= '<w:i/>';
        if (!empty($o['caps']))  $props .= '<w:caps/>';
        if (!empty($o['color'])) $props .= '<w:color w:val="' . $this->hex($o['color']) . '"/>';
        if (!empty($o['size']))  $props .= '<w:sz w:val="' . (int) $o['size'] . '"/>';

        // Word collapses leading and trailing space unless told not to,
        // which turns "•  Item" into "•Item".
        return '<w:r>' . ($props ? '<w:rPr>' . $props . '</w:rPr>' : '')
             . '<w:t xml:space="preserve">' . $this->esc($text) . '</w:t></w:r>';
    }

    /** One table cell. */
    private function cell(string $text, int $width, array $o = []): string
    {
        $shade = !empty($o['shade'])
            ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $this->hex($o['shade']) . '"/>'
            : '';

        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>'
             . $shade
             . '<w:vAlign w:val="top"/></w:tcPr>'
             . '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr>'
             . $this->run($text, $o) . '</w:p></w:tc>';
    }

    private function hex(string $c): string
    {
        $c = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $c) ?? '');
        return strlen($c) === 6 ? $c : '333333';
    }

    private function esc(string $s): string
    {
        // Control characters are illegal in XML 1.0 and make Word
        // refuse the file outright rather than skip them.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return array<string,string> path => contents */
    private function parts(): array
    {
        $sect = '<w:sectPr>'
              . '<w:pgSz w:w="' . self::PAGE_WIDTH . '" w:h="' . self::PAGE_HEIGHT . '"/>'
              . '<w:pgMar w:top="' . self::MARGIN . '" w:right="' . self::MARGIN . '"'
              . ' w:bottom="' . self::MARGIN . '" w:left="' . self::MARGIN . '"'
              . ' w:header="0" w:footer="0" w:gutter="0"/>'
              . '</w:sectPr>';

        return [
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                . '</Types>',

            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',

            'word/_rels/document.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',

            // Only the default paragraph and run properties — every
            // other bit of formatting is applied directly, which keeps
            // this file honest about what it actually supports.
            'word/styles.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:docDefaults><w:rPrDefault><w:rPr>'
                . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
                . '<w:sz w:val="19"/><w:szCs w:val="19"/><w:color w:val="333333"/>'
                . '</w:rPr></w:rPrDefault>'
                . '<w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
                . '</w:docDefaults></w:styles>',

            'word/document.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:body>' . $this->body . $sect . '</w:body></w:document>',
        ];
    }
}
