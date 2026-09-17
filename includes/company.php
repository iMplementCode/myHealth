<?php

/**
 * ============================================================
 *  Company profile + logo helpers
 * ------------------------------------------------------------
 *  Single source for the company profile (company_settings) and
 *  its logo, used on every printable document. Resolves the
 *  configured logo when present and falls back to the bundled
 *  default so documents always carry a logo.
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

// ── How big the logo prints ──────────────────────────────────
//  CSS pixels, which dompdf renders at 0.75pt each, so 100px is
//  75pt — about 26mm tall on A4.
//
//  The width matters as much as the height. The letterhead shares
//  its row with the document title, and a wide logo bounded only by
//  its height would grow sideways until it collided with the word
//  QUOTATION. 300px leaves the title its half of the page.
define('LOGO_MAX_HEIGHT_PX', 100);
define('LOGO_MAX_WIDTH_PX', 300);


/** The single company_settings row (cached), or [] if unavailable. */
function company_settings(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = db_one("SELECT * FROM company_settings WHERE id = 1") ?? [];
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

/**
 * Absolute filesystem path to the best available logo, or null.
 *
 * Worked out once per request. It globs two directories and sorts
 * what it finds by modification time, which is cheap but not free —
 * and now that every page declares the logo as its tab icon, and
 * every PDF asks two or three times while rendering, "cheap" was
 * being paid for rather more often than it used to be.
 */
/**
 * The logo, from the database.
 *
 * The database is the only thing a shop owns that survives a
 * deploy. A container's filesystem does not: public/uploads is
 * rebuilt from the repository every time the code is pushed, which
 * is how every shop came to lose its logo on every release.
 *
 * Read lazily and once per request. It is deliberately NOT a column
 * on company_settings, because that row is fetched with SELECT * on
 * every page load and a two-megabyte image on it would be read two
 * megabytes at a time, forever.
 *
 * @return array{bytes:string, mime:string}|null
 */
function company_logo_blob(): ?array
{
    static $answer = false;
    if ($answer !== false) {
        return $answer;
    }

    // Survives a database that has not run migration 058 yet, like
    // everything else that reads a table the code is ahead of.
    if (!table_exists('company_logo')) {
        return $answer = null;
    }

    try {
        $row = db_one('SELECT mime, bytes FROM company_logo WHERE id = 1');
    } catch (Throwable $e) {
        return $answer = null;
    }
    if (!$row || ($row['bytes'] ?? '') === '') {
        return $answer = null;
    }

    /*  PostgreSQL hands bytea back as a stream through PDO. Read it
     *  out before anything else touches the connection, or the
     *  handle is spent and the logo silently becomes empty. */
    $bytes = $row['bytes'];
    if (is_resource($bytes)) {
        $bytes = stream_get_contents($bytes);
    }
    if (!is_string($bytes) || $bytes === '') {
        return $answer = null;
    }

    return $answer = ['bytes' => $bytes, 'mime' => (string) ($row['mime'] ?: 'image/png')];
}

/** When the stored logo last changed — for cache-busting a URL. */
function company_logo_version(): string
{
    if (!table_exists('company_logo')) {
        return '0';
    }
    try {
        return (string) (db_value('SELECT EXTRACT(EPOCH FROM updated_at)::bigint FROM company_logo WHERE id = 1') ?? '0');
    } catch (Throwable $e) {
        return '0';
    }
}

/**
 * Store the logo where it cannot be lost.
 *
 * @return bool true when it was written
 */
function company_logo_store(string $bytes, string $mime): bool
{
    if ($bytes === '' || !table_exists('company_logo')) {
        return false;
    }
    try {
        $stmt = db()->prepare(
            'INSERT INTO company_logo (id, mime, bytes, updated_at) VALUES (1, :m, :b, CURRENT_TIMESTAMP)
             ON CONFLICT (id) DO UPDATE
                SET mime = EXCLUDED.mime, bytes = EXCLUDED.bytes, updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':m', $mime);
        $stmt->bindValue(':b', $bytes, PDO::PARAM_LOB);
        $stmt->execute();
        return true;
    } catch (Throwable $e) {
        error_log('[LOGO] could not store the logo in the database: ' . $e->getMessage());
        return false;
    }
}

function company_logo_fs_path(): ?string
{
    static $answer = false;          // false = not worked out yet
    if ($answer !== false) {
        return $answer;
    }

    $s   = company_settings();
    $rel = ltrim((string) ($s['logo_path'] ?? ''), '/');

    $candidates = [];

    // What the settings page recorded, when it recorded it correctly.
    if ($rel !== '') {
        $candidates[] = PUBLIC_PATH . '/' . $rel;
        $candidates[] = PUBLIC_PATH . '/uploads/company/' . basename($rel);
    }

    // ── Rescuing a logo the old settings page lost ──────────────
    //  It wrote uploads to public/modules/settings/assets/img/ — a
    //  folder nothing serves and nothing read — and stored the path
    //  as "/uploadscompany_logo.jpg", the two halves glued together
    //  because it re-defined a constant bootstrap.php had already
    //  set. So neither the folder nor the recorded name can be
    //  trusted, and the file is found by looking for it rather than
    //  by being told where it is.
    //
    //  Newest first: a logo changed twice should show the second one.
    foreach ([PUBLIC_PATH . '/uploads/company/',
              PUBLIC_PATH . '/modules/settings/assets/img/'] as $dir) {
        $found = glob($dir . 'company_logo*') ?: [];
        usort($found, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        foreach ($found as $p) {
            $candidates[] = $p;
        }
    }

    /*  There is NO fall-through to the logo that ships with the
     *  application, and that removal is the point of this function.
     *
     *  public/assets/img/company_logo.png is one real company's
     *  logo. While it was the last candidate, any shop whose own
     *  file was missing silently printed that company's brand — on
     *  its dashboard, its invoices, its quotes and its receipts.
     *  On a container host the file went missing on every single
     *  deploy, so ten shops were sending out another company's
     *  letterhead and their owners were re-uploading the logo by
     *  hand afterwards.
     *
     *  A shop with no logo now has no logo. company_letterhead()
     *  prints the company name, which is correct and is nobody
     *  else's. */

    foreach ($candidates as $p) {
        if (is_file($p)) {
            return $answer = $p;
        }
    }
    return $answer = null;
}

/** Browser URL for the logo (for HTML documents), or null. */
function company_logo_url(): ?string
{
    /*  The stored logo wins, and is served by company-logo.php
     *  rather than as a static file — bytes in a table have no URL
     *  of their own. The version is the timestamp it was saved, so
     *  a changed logo is fetched again instead of the browser
     *  showing yesterday's from cache. */
    if (company_logo_blob() !== null) {
        return url('company-logo.php?v=' . company_logo_version());
    }

    // Derived from the file that was actually found, so the browser and
    // the PDF can never disagree about which logo is the current one.
    $path = company_logo_fs_path();
    if ($path === null) {
        return null;
    }
    $rel = ltrim(str_replace('\\', '/', substr($path, strlen(PUBLIC_PATH))), '/');
    return $rel !== '' ? url($rel) : null;
}

/** Base64 data URI for the logo (for dompdf/PDF embedding), or ''. */
function company_logo_data_uri(): string
{
    //  Same order as company_logo_url(), so a document and the page
    //  that links to it can never show different logos.
    if ($blob = company_logo_blob()) {
        return 'data:' . $blob['mime'] . ';base64,' . base64_encode($blob['bytes']);
    }

    $path = company_logo_fs_path();
    if ($path === null) {
        return '';
    }
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
    $mime = $ext === 'jpg' ? 'jpeg' : $ext;
    $data = @file_get_contents($path);
    if ($data === false) {
        return '';
    }
    return 'data:image/' . $mime . ';base64,' . base64_encode($data);
}

/**
 * The <img> for the logo on a printed document, or '' if there is none.
 *
 * The size lives here rather than in each document, because it was
 * written out five times and five copies drift. Both bounds are set
 * so the logo keeps its shape whatever was uploaded: a tall crest is
 * held by the height, a wide banner by the width, and neither can
 * grow into the document title beside it.
 */
function company_logo_img_tag(): string
{
    $logo = company_logo_data_uri();
    return $logo === '' ? '' : '<img src="' . $logo . '" style="max-height:'
        . LOGO_MAX_HEIGHT_PX . 'px; max-width:' . LOGO_MAX_WIDTH_PX
        . 'px; margin-bottom:10px;">';
}

/**
 * The letterhead block every printed document shares: the logo, the
 * company name, and the ways to reach it.
 *
 * A quote and an invoice that disagree about the company address are
 * two companies as far as the customer is concerned. They disagreed
 * because each document built this block itself — which is also how
 * quotes carried on printing the logo that ships with the software
 * long after the real one had been uploaded.
 *
 * @return array{logo:string, name:string, details:string} all HTML-ready
 */
function company_letterhead(): array
{
    $s   = company_settings();
    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $bits = [];
    if (!empty($s['location'])) {
        $bits[] = nl2br($esc($s['location']));
    }
    // Every address the company gave, not just the first. A customer
    // with a payment query should not have to write to whoever
    // happened to prepare the quote.
    if ($emails = company_emails()) {
        $bits[] = '<strong>Email:</strong> ' . $esc(implode(', ', $emails));
    }
    if (!empty($s['mobile'])) {
        $bits[] = '<strong>Mobile:</strong> ' . $esc($s['mobile']);
    }
    if (!empty($s['tax_pin'])) {
        $bits[] = '<strong>PIN:</strong> ' . $esc($s['tax_pin']);
    }

    return [
        'logo'    => company_logo_img_tag(),
        'name'    => $esc($s['company_name'] ?? APP_NAME),
        'details' => implode('<br>', $bits),
    ];
}

/**
 * How a customer is supposed to pay, ready for a document.
 *
 * Returns [label, value] rows, or an empty array when nothing has
 * been filled in — a document then prints no payment block at all
 * rather than an empty heading, which reads as something the system
 * failed to finish.
 *
 * Guarded with column_exists() because the columns arrive in
 * migration 045 and this is called from the PDF renderers, which
 * must keep working on a database that is one migration behind.
 *
 * @param ?string $reference  the document's own number, used when no
 *                            fixed account number is configured
 */
function company_payment_details(?string $reference = null): array
{
    if (!table_exists('company_settings')
        || !column_exists('company_settings', 'mpesa_paybill')) {
        return [];
    }

    $s    = company_settings();
    $rows = [];

    if (!empty($s['mpesa_paybill'])) {
        $rows[] = ['M-Pesa Paybill', $s['mpesa_paybill']];

        // Most small businesses have no fixed account number and tell
        // the customer to quote the invoice number. Saying that on the
        // document is the difference between a payment that reconciles
        // and one that arrives as an unidentified deposit.
        $rows[] = ['Account', !empty($s['mpesa_account'])
            ? $s['mpesa_account']
            : ($reference !== null && $reference !== ''
                ? $reference . '  (this document number)'
                : 'Quote this document number')];
    }

    if (!empty($s['bank_details'])) {
        $rows[] = ['Bank', $s['bank_details']];
    }

    return $rows;
}

/**
 * Every company email address, main one first.
 *
 * A business has sales@ for quotes, accounts@ for invoices, info@
 * on the website. Printing one of them on every document sends a
 * payment query to whoever wrote the quote.
 *
 * Anything that is not an address is dropped rather than printed:
 * a letterhead is the wrong place to discover a typo. Duplicates
 * go too — the same address twice on a document reads as a mistake,
 * because it is one.
 *
 * @return string[]
 */
function company_emails(): array
{
    $s   = company_settings();
    $out = [];

    foreach (array_merge(
        [(string) ($s['email'] ?? '')],
        preg_split('/[\r\n,;]+/', (string) ($s['extra_emails'] ?? '')) ?: []
    ) as $candidate) {
        $ok = valid_email((string) $candidate);
        if ($ok !== null && !in_array($ok, $out, true)) {
            $out[] = $ok;
        }
    }
    return $out;
}
