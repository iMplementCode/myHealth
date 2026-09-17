<?php

/**
 * ============================================================
 *  Look for things worth telling somebody about
 * ------------------------------------------------------------
 *  The application also runs this by itself, on a page load, at
 *  most once every ten minutes — so the bell is never far out of
 *  date even if nothing else is set up.
 *
 *  Running it from cron as well is better, and on a VPS it is the
 *  right answer:
 *
 *      every 10 min, from root's crontab — the schedule field is
 *      an asterisk-slash-ten, which cannot be written literally in
 *      here because it would close this comment:
 *
 *          <every 10 min> php /var/www/erp/deploy/notify-scan.php \
 *              >> /var/log/erp-notify.log 2>&1
 *
 *      The exact line is in docs/NOTIFICATIONS.md, ready to paste.
 *
 *  Two reasons. The first person through the door in the morning
 *  stops paying for the scan in page latency. And when email and
 *  SMS are added, this is the process that will send them — a
 *  notification nobody has opened the site to trigger is exactly
 *  the one that needed to reach a phone.
 *
 *  Exits non-zero if the scan could not run, so cron's MAILTO does
 *  something useful.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/notifications.php';

$quiet = in_array('--quiet', $argv, true);

$stamp = date('Y-m-d H:i:s');

try {
    $result = notifications_scan();
} catch (Throwable $e) {
    fwrite(STDERR, "[$stamp] notify-scan failed: " . $e->getMessage() . "\n");
    exit(1);
}

if (!($result['ok'] ?? false)) {
    fwrite(STDERR, "[$stamp] notify-scan did not run: "
        . ($result['reason'] ?? 'unknown reason') . "\n");
    fwrite(STDERR, "         If this is a fresh deployment, run:\n");
    fwrite(STDERR, "         php database_structure/migrate.php\n");
    exit(1);
}

foreach ($result['skipped'] ?? [] as $why) {
    // A rule that could not run is worth saying out loud even in
    // quiet mode: it means an alert nobody is getting.
    fwrite(STDERR, "[$stamp] rule skipped — $why\n");
}

if (!$quiet) {
    printf(
        "[%s] %d rules, %d open, %d resolved, %dms%s\n",
        $stamp,
        $result['rules'],
        $result['opened'],
        $result['resolved'],
        $result['ms'],
        $result['skipped'] ? ' (' . count($result['skipped']) . ' skipped)' : ''
    );
}

exit(0);
