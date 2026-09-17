<?php

/**
 * ============================================================
 *  Can the web server actually read this?
 * ------------------------------------------------------------
 *  A file PHP cannot open produces a 500 and one line in a log
 *  nobody is watching. There is no clue on the page, nothing in
 *  the application's own error handling — the failure happens
 *  before any of it loads — and the file is right there when you
 *  look, which is what makes it take an hour to find.
 *
 *  It happened four separate times while this application was
 *  being built, always the same way: something wrote a file as
 *  root, and www-data could not read it.
 *
 *      php deploy/check-permissions.php
 *
 *  Run it as the user the web server runs as, which is the only
 *  way to answer the question honestly:
 *
 *      sudo -u www-data php deploy/check-permissions.php
 *
 *  Exit code 0 when everything is reachable, 1 when it is not, so
 *  it can go in a deployment script or a cron.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$me   = function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
    : get_current_user();

echo "\n  Checking as: $me\n";
echo "  Application: $root\n\n";

$problems = [];

/** Every file the application loads on an ordinary request. */
function must_read(string $path, string $why, array &$problems): void
{
    if (!file_exists($path)) {
        $problems[] = [$path, 'is not there at all', $why];
        return;
    }
    if (!is_readable($path)) {
        $problems[] = [$path, 'cannot be read', $why];
        return;
    }
    // is_readable() consults the permission bits; opening it is the
    // only way to find out whether a directory further up the path
    // blocks the way.
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        $problems[] = [$path, 'will not open (a directory above it?)', $why];
        return;
    }
    fclose($fh);
}

function must_write(string $path, string $why, array &$problems): void
{
    if (!is_dir($path)) {
        $problems[] = [$path, 'is not a directory', $why];
        return;
    }
    $probe = $path . '/.write-probe-' . getmypid();
    if (@file_put_contents($probe, 'x') === false) {
        $problems[] = [$path, 'cannot be written to', $why];
        return;
    }
    @unlink($probe);
}

// ── The files every single request opens ────────────────────
must_read("$root/config.php",              'every request reads it first',      $problems);
must_read("$root/.env",                    'the database credentials live in it', $problems);
must_read("$root/vendor/autoload.php",     'composer loads dompdf through it',  $problems);

foreach (glob("$root/includes/*.php") ?: [] as $f) {
    must_read($f, 'bootstrap.php requires it', $problems);
}

// ── Everything reachable by URL ─────────────────────────────
$pages = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/public"));
foreach ($it as $f) {
    if ($f->isFile() && in_array(strtolower($f->getExtension()), ['php', 'css', 'js'], true)) {
        $pages[] = $f->getPathname();
    }
}
sort($pages);
foreach ($pages as $f) {
    must_read($f, 'it is a URL somebody can open', $problems);
}

// ── The places it has to be able to write ───────────────────
must_write("$root/public/uploads",          'logos and proof of payment land here', $problems);
foreach (glob("$root/public/uploads/*", GLOB_ONLYDIR) ?: [] as $d) {
    must_write($d, 'uploads are filed into it', $problems);
}

// ── Say so ──────────────────────────────────────────────────
$checked = count($pages) + count(glob("$root/includes/*.php") ?: []) + 3;

if (!$problems) {
    echo "  ✓ all $checked files readable, uploads writable\n\n";
    exit(0);
}

echo "  ✗ " . count($problems) . " problem(s):\n\n";
foreach ($problems as [$path, $what, $why]) {
    $rel  = str_replace($root . '/', '', $path);
    $mode = file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : '----';
    $own  = '?';
    if (file_exists($path) && function_exists('posix_getpwuid')) {
        $own = (posix_getpwuid(fileowner($path))['name'] ?? '?')
             . ':' . (posix_getgrgid(filegroup($path))['name'] ?? '?');
    }
    printf("    %-52s %s\n", $rel, $what);
    printf("      %-52s %s  %s\n", "($why)", $mode, $own);
}

echo <<<FIX


  The usual cause is a file written by root that the web server
  cannot read. What this application expects:

      chown -R root:www-data /var/www/erp
      find /var/www/erp -type f -exec chmod 640 {} +
      find /var/www/erp -type d -exec chmod 750 {} +
      chmod 750 /var/www/erp/deploy/*.sh
      chmod -R 2775 /var/www/erp/public/uploads
      chown -R www-data:www-data /var/www/erp/public/uploads

  Read by the group, written by nobody but you — except uploads,
  which the application itself writes.


FIX;

exit(1);
