<?php

/**
 * ============================================================
 *  Router for PHP's built-in server
 * ------------------------------------------------------------
 *  The deployment runs `php -S 0.0.0.0:3000 -t public`, which
 *  reads neither deploy/nginx.conf nor public/uploads/.htaccess.
 *  Several rules that were only ever written in those two files
 *  therefore stopped applying the moment the application moved
 *  to this server, silently and with nothing to show for it.
 *
 *  The ones that matter:
 *
 *    /uploads/payments/    payment slips
 *    /uploads/refunds/     refund proofs
 *    /uploads/investments/ investor agreements
 *    /uploads/advances/    staff advance receipts
 *
 *  Those four are private. They are meant to be fetched through
 *  documents/view.php, which checks for a session first. Served
 *  straight off the disk they are readable by anyone holding the
 *  URL — and a URL leaks through a browser history, a proxy log,
 *  a Referer header, or a forwarded email, none of which is an
 *  attack.
 *
 *  A router script runs for every request the built-in server
 *  handles. Returning false means "serve it normally", which is
 *  what all but a handful of requests get.
 *
 *  This file is not a substitute for the web-server rules. It is
 *  the same rules in the one place that is true of every
 *  deployment: if the application is ever put back behind nginx,
 *  both sets apply and agree.
 * ============================================================
 */

declare(strict_types=1);

/** The requested path, decoded, with traversal collapsed. */
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = urldecode($path);
$path = str_replace('\\', '/', $path);

// Collapse "." and ".." so a path cannot be dressed up to slip past
// the checks below. The built-in server refuses to serve outside the
// document root by itself; this is about the prefix match, not the
// file lookup.
$parts = [];
foreach (explode('/', $path) as $segment) {
    if ($segment === '' || $segment === '.') {
        continue;
    }
    if ($segment === '..') {
        array_pop($parts);
        continue;
    }
    $parts[] = $segment;
}
$clean = '/' . implode('/', $parts);
$lower = strtolower($clean);

/** Refuse, the same way a missing file is refused. */
$refuse = static function (): bool {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Not found.\n";
    return true;
};

// ── Private document folders ────────────────────────────────
foreach (['payments', 'refunds', 'investments', 'advances'] as $private) {
    if (str_starts_with($lower, '/uploads/' . $private . '/')) {
        return $refuse();
    }
}

/*  Nothing under uploads/ is ever executed. Uploads are already
 *  checked by real MIME type and stored under a generated name with
 *  an extension taken from that type, so a .php cannot be written
 *  there in the first place — this is the second lock on the same
 *  door, and it is the lock that does not depend on the first one
 *  being perfect. */
if (str_starts_with($lower, '/uploads/')
    && preg_match('/\.(php\d*|phtml|phar|inc)$/i', $lower)) {
    return $refuse();
}

// ── Dotfiles ────────────────────────────────────────────────
// .htaccess, and anything else beginning with a dot that finds its
// way under the document root. The real secrets live above it and
// are unreachable either way.
foreach ($parts as $segment) {
    if ($segment !== '' && $segment[0] === '.') {
        return $refuse();
    }
}

// ── Everything else is served as it would be without a router ──
return false;
