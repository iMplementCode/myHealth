<?php

/**
 * ============================================================
 *  Reset a shop from the command line
 * ------------------------------------------------------------
 *  Clears everything a shop has done and leaves a database
 *  identical to a freshly provisioned one, with a single
 *  administrator.
 *
 *      php deploy/reset-shop.php --confirm="Alpha Electronics Ltd"
 *
 *  The value of --confirm must be the shop's current company
 *  name, exactly. Typing it is the point: there is no undo, and
 *  the most likely mistake is running this against the wrong
 *  shop's database.
 *
 *  It works on whichever database the environment points at, so
 *  on a container host run it from that shop's own application
 *  terminal, or pass --database explicitly.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

/*  Same escape hatch the migration runner has, and for the same
 *  reason: saying which database out loud beats trusting whatever
 *  .env happens to be lying around. config.php guards every DB_
 *  constant with defined().                                     */
$cli = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $cli[$m[1]] = $m[2];
    }
}
foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'database' => 'DB_NAME', 'user' => 'DB_USER'] as $a => $c) {
    if (isset($cli[$a])) {
        define($c, $cli[$a]);
    }
}
if (isset($cli['password-from-env'])) {
    define('DB_PASS', (string) getenv($cli['password-from-env']));
}

define('APP_BOOTSTRAPPED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/reset.php';

function bail(string $msg): void
{
    fwrite(STDERR, "\n  ✗ " . $msg . "\n\n");
    exit(1);
}

$pdo  = db();
$name = (string) (db_value('SELECT company_name FROM company_settings WHERE id = 1') ?? '');
$dbn  = DB_NAME;

if ($name === '') {
    bail("This database has no company name set, so there is nothing to confirm against.\n"
       . "    Set one in Settings first, or use --force-name=<name> if the shop is half set up.");
}

$confirm = $cli['confirm'] ?? null;
if ($confirm === null) {
    echo "\n  About to reset a shop — this cannot be undone.\n";
    echo "  ────────────────────────────────────────────────────\n";
    echo "  Database : $dbn\n";
    echo "  Shop     : $name\n\n";
    echo "  Everything goes: customers, products, invoices, payments,\n";
    echo "  stock, notes, uploaded files, and every user but one.\n\n";
    echo "  Re-run with --confirm=\"$name\" if that is what you want.\n";
    echo "  Take a backup first:  bash deploy/backup.sh\n\n";
    exit(1);
}

if ($confirm !== $name) {
    bail("--confirm does not match this shop's name.\n"
       . "    Database : $dbn\n"
       . "    Expected : $name\n"
       . "    Given    : $confirm\n"
       . "    Nothing was changed. Check you are pointed at the right shop.");
}

/*  Who survives. The administrator with the lowest id, which on a
 *  provisioned shop is the account new-tenant.php set up. --user
 *  names somebody else by email when a shop has moved on from it. */
if (isset($cli['user'])) {
    $keep = db_one('SELECT user_id, email FROM users WHERE LOWER(email) = LOWER(:e)', [':e' => $cli['user']]);
    if (!$keep) {
        bail('No user with the email ' . $cli['user'] . ' in ' . $dbn . '. Nothing was changed.');
    }
} else {
    $keep = db_one(
        "SELECT u.user_id, u.email FROM users u
           JOIN roles r ON r.role_id = u.role_id
          WHERE r.name = :admin AND u.is_active
          ORDER BY u.user_id LIMIT 1",
        [':admin' => ROLE_ADMIN]
    );
    if (!$keep) {
        bail('No active administrator found in ' . $dbn . '. Nothing was changed.');
    }
}

echo "\n  Resetting \"$name\" ($dbn)\n";
echo "  ────────────────────────────────────────────────────\n";

try {
    $result = reset_shop(
        (int) $keep['user_id'],
        $name,
        null,
        static function (string $line): void { echo $line . "\n"; }
    );
} catch (Throwable $e) {
    bail($e->getMessage());
}

echo "\n  ✓ Done. " . $result['tables'] . " tables cleared, "
   . $result['files'] . " file(s) deleted, "
   . $result['users_removed'] . " other account(s) removed.\n";
echo "    Signed in as: " . $keep['email'] . " (password unchanged)\n\n";
