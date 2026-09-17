<?php

/**
 * ============================================================
 *  Migration Runner
 * ------------------------------------------------------------
 *  Applies every ./migrations/*.sql file in numeric order,
 *  exactly once, tracking applied files in a schema_migrations
 *  table. Idempotent and safe to re-run.
 *
 *  Usage (from the project root):
 *      php database_structure/migrate.php
 *
 *  It reuses the application's database configuration, so make
 *  sure your .env is set up first.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

/*  Which database, said out loud.
 *
 *  Provisioning a new shop means migrating a database that is NOT
 *  the one in .env, and that has to be explicit. A provisioning
 *  script that quietly migrated the wrong tenant because a .env
 *  happened to be sitting in the directory would be the kind of
 *  disaster nobody notices until two shops disagree about their
 *  own stock.
 *
 *  These are defined BEFORE config.php, which guards every DB_
 *  constant with defined() for exactly this purpose.
 *
 *  The password is deliberately awkward to pass on the command
 *  line: anything in argv is visible to every other user on the
 *  box through `ps`. --password-from-env names a variable to read
 *  instead, which is what deploy/new-tenant.php uses.            */
$cliDb = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--(database|user|host|port|password|password-from-env)=(.*)$/', $arg, $m)) {
        $cliDb[$m[1]] = $m[2];
    }
}

if (isset($cliDb['host'])) {
    define('DB_HOST', $cliDb['host']);
}
if (isset($cliDb['port'])) {
    define('DB_PORT', $cliDb['port']);
}
if (isset($cliDb['database'])) {
    define('DB_NAME', $cliDb['database']);
}
if (isset($cliDb['user'])) {
    define('DB_USER', $cliDb['user']);
}
if (isset($cliDb['password-from-env'])) {
    // getenv() and not env(): this must read the real environment
    // and must not be overridden by a .env file, which is the
    // whole point of saying it on the command line.
    define('DB_PASS', (string) getenv($cliDb['password-from-env']));
} elseif (isset($cliDb['password'])) {
    define('DB_PASS', $cliDb['password']);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';

if ($cliDb) {
    echo '  target ' . DB_USER . '@' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME . "\n";
}

/*  Wait a little for the database, then give up.
 *
 *  This runs from the container's start command, and on a fresh
 *  deploy the application container is often listening before the
 *  database container is. Connecting once and dying would mean the
 *  migrations are skipped on exactly the deploy that most likely
 *  needs them, and — because the application is written to survive a
 *  database one migration behind — nothing would look broken. That
 *  is precisely how the two-factor table came to be missing.
 *
 *  So: retry, but on a budget. Every second here is a second before
 *  the web server starts listening, and a database that is genuinely
 *  down is not going to be fixed by waiting. Twenty seconds covers a
 *  container starting up and costs nothing at all when the database
 *  is already there — the first attempt succeeds.                  */
$waitUntil = time() + max(0, (int) env('MIGRATE_DB_WAIT', '20'));
$pdo       = null;

while (true) {
    try {
        $pdo = Database::connect();
        break;
    } catch (PDOException $e) {
        if (time() >= $waitUntil) {
            fwrite(STDERR, "\n  ✗ FAIL  the database is not reachable: " . $e->getMessage() . "\n"
                         . "          No migrations applied. The web server still starts;\n"
                         . "          it will serve once the database comes back.\n");
            exit(1);
        }
        echo "  wait   database not answering yet\n";
        sleep(2);
    }
}

/*  Only one of these at a time.
 *
 *  This now runs from the start command, so it runs whenever a
 *  container starts — and a rolling deploy starts the new container
 *  before it stops the old one. For a moment there are two, both
 *  reading schema_migrations, both seeing the same file unapplied,
 *  both running it. The lucky one wins the primary key and the
 *  other reports a failure for work that was done correctly.
 *
 *  Worse, a migration that is not written to be repeatable could be
 *  half-applied twice.
 *
 *  A Postgres advisory lock settles it. It is held on this
 *  connection and released when the script ends, however it ends —
 *  including being killed, which a lock table would not survive.
 *  The number is arbitrary and only has to be the same in every
 *  copy of this script.
 *
 *  Not acquiring it is not a failure: it means another process is
 *  already doing exactly this, so the right thing is to say so and
 *  exit cleanly rather than make a deploy look broken.            */
const MIGRATION_LOCK_KEY = 8074231;

$deadline = time() + max(0, (int) env('MIGRATE_LOCK_WAIT', '30'));
$locked   = false;
while (time() < $deadline) {
    $locked = (bool) $pdo->query(
        'SELECT pg_try_advisory_lock(' . MIGRATION_LOCK_KEY . ')'
    )->fetchColumn();
    if ($locked) {
        break;
    }
    echo "  wait   another process is applying migrations\n";
    sleep(2);
}
if (!$locked) {
    echo "\n  Another process holds the migration lock and has not finished.\n"
       . "  Nothing applied here; it is doing the work. Exiting cleanly.\n";
    exit(0);
}

// Track which migrations have run.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
    )
");

$applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        echo "  skip   $name (already applied)\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        echo "  warn   $name (empty, skipped)\n";
        continue;
    }

    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (:f)");
        $stmt->execute([':f' => $name]);
        echo "  ✓ ran  $name\n";
        $ran++;
    } catch (PDOException $e) {
        fwrite(STDERR, "  ✗ FAIL $name: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo $ran === 0
    ? "\nEverything already up to date.\n"
    : "\nDone. Applied $ran migration(s).\n";
