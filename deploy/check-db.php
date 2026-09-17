<?php

/**
 * ============================================================
 *  Why can't it reach the database?
 * ------------------------------------------------------------
 *  "The service is temporarily unavailable. Please try again
 *  later." means one thing and one thing only: PHP could not
 *  open a connection to PostgreSQL. The page is deliberately
 *  vague — it is shown to whoever is at the browser, and the
 *  host, user and database name are none of their business —
 *  which unfortunately makes it just as uninformative to you.
 *
 *  This checks the same things the application checks, in the
 *  order it checks them, and says which one failed and what to
 *  do about it.
 *
 *      php deploy/check-db.php
 *
 *  Run it as the *same user the web server runs as* if the
 *  browser fails while the command line works:
 *
 *      sudo -u www-data php deploy/check-db.php
 *
 *  It never prints the password.
 * ============================================================
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;

function say(string $state, string $what, string $detail = ''): void
{
    $mark = ['ok' => '  ok  ', 'no' => ' FAIL ', 'hm' => ' note '][$state] ?? '      ';
    echo $mark . $what . ($detail !== '' ? "\n         " . str_replace("\n", "\n         ", $detail) : '') . "\n";
}

echo "\nChecking what the application checks, in the same order.\n\n";

/* ── 1. The PHP that is actually running ─────────────────────── */
echo "── This PHP ──\n";
say('ok', 'PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ')');

if (!extension_loaded('pdo_pgsql')) {
    $fail++;
    say('no', 'The pdo_pgsql extension is missing.',
        "Nothing can reach PostgreSQL without it.\n"
        . "  Debian/Ubuntu:  sudo apt install php" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "-pgsql\n"
        . "  then restart the web server.\n"
        . "IMPORTANT: if the browser fails but the command line works, this is\n"
        . "the usual reason — XAMPP and the system PHP are different binaries\n"
        . "with different php.ini files. Check the one the browser uses:\n"
        . "  /opt/lampp/bin/php -m | grep pdo_pgsql");
} else {
    say('ok', 'pdo_pgsql is loaded');
}

/* ── 2. The .env file ────────────────────────────────────────── */
echo "\n── Configuration ──\n";
$envPath = $root . '/.env';
if (!is_file($envPath)) {
    $fail++;
    say('no', 'There is no .env file.',
        "Without it every setting falls back to its default, and DB_PASS\n"
        . "defaults to empty — which PostgreSQL refuses with\n"
        . "\"fe_sendauth: no password supplied\".\n"
        . "  cp .env.example .env      then fill in DB_NAME, DB_USER, DB_PASS");
} else {
    say('ok', '.env found');

    $perms = substr(sprintf('%o', fileperms($envPath)), -3);
    if (PHP_OS_FAMILY !== 'Windows' && (int) $perms > 640) {
        say('hm', '.env is mode ' . $perms . ' — it holds the database password.',
            'chown root:www-data .env && chmod 640 .env');
    }
    if (!is_readable($envPath)) {
        $fail++;
        say('no', '.env exists but this user cannot read it.',
            'Running as ' . (function_exists('posix_getpwuid')
                ? posix_getpwuid(posix_geteuid())['name'] : get_current_user())
            . '. The web server user needs read access.');
    }
}

require_once $root . '/config.php';

say('ok', 'Reading: host ' . DB_HOST . ', port ' . DB_PORT . ', database ' . DB_NAME . ', user ' . DB_USER);
if (DB_PASS === '') {
    say('hm', 'DB_PASS is empty.',
        "Fine for a trust/peer setup; PostgreSQL's default md5 or scram\n"
        . "authentication will refuse it with \"no password supplied\".");
}

/* ── 3. Is anything listening? ───────────────────────────────── */
echo "\n── The server ──\n";
$sock = @fsockopen(DB_HOST, (int) DB_PORT, $errno, $errstr, 3);
if ($sock === false) {
    $fail++;
    say('no', 'Nothing is accepting connections on ' . DB_HOST . ':' . DB_PORT . '.',
        trim($errstr) . "\n"
        . "  Is it running?      sudo systemctl status postgresql\n"
        . "  Start it:           sudo systemctl start postgresql\n"
        . "  Which port?         sudo ss -lntp | grep postgres\n"
        . "  On another host, check listen_addresses in postgresql.conf\n"
        . "  and that a firewall is not in the way.");
} else {
    fclose($sock);
    say('ok', 'Something is listening on ' . DB_HOST . ':' . DB_PORT);
}

/* ── 4. The connection the application actually makes ────────── */
echo "\n── The connection ──\n";
try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    say('ok', 'Connected as ' . DB_USER . ' to ' . DB_NAME);

    $v = $pdo->query('SHOW server_version')->fetchColumn();
    say('ok', 'PostgreSQL ' . $v);

    /* ── 5. Is the schema there? ─────────────────────────────── */
    echo "\n── The schema ──\n";
    $applied = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = 'public' AND table_name = 'schema_migrations'"
    )->fetchColumn();

    if ($applied === 0) {
        $fail++;
        say('no', 'The database is empty — no migrations have been applied.',
            'php database_structure/migrate.php');
    } else {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        $last  = (string) $pdo->query('SELECT MAX(filename) FROM schema_migrations')->fetchColumn();
        say('ok', $count . ' migrations applied, latest ' . $last);

        $onDisk = glob($root . '/database_structure/migrations/*.sql') ?: [];
        $newest = $onDisk ? basename(end($onDisk)) : '';
        if ($newest !== '' && $newest !== $last) {
            say('hm', 'The code ships ' . $newest . ', which has not been applied.',
                'php database_structure/migrate.php');
        }

        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        say($users > 0 ? 'ok' : 'hm', $users . ' user account(s)',
            $users === 0 ? 'Nobody can sign in. Re-run the migrations to seed the administrator.' : '');
    }

    /* ── 6. Connection headroom ──────────────────────────────── */
    echo "\n── Capacity ──\n";
    $max  = (int) $pdo->query('SHOW max_connections')->fetchColumn();
    $used = (int) $pdo->query('SELECT COUNT(*) FROM pg_stat_activity')->fetchColumn();
    $pct  = $max > 0 ? (int) round($used / $max * 100) : 0;
    say($pct < 80 ? 'ok' : 'hm', $used . ' of ' . $max . ' connections in use (' . $pct . '%)',
        $pct >= 80
            ? "Near the limit. A full pool is the other thing that produces\n"
              . "\"temporarily unavailable\" — intermittently, under load."
            : '');
} catch (PDOException $e) {
    $fail++;
    $msg = $e->getMessage();
    echo " FAIL Could not connect.\n         " . $msg . "\n\n";

    $hint = match (true) {
        str_contains($msg, 'no password supplied') =>
            "PostgreSQL wants a password and DB_PASS is empty.\n"
            . "  Put the real one in .env, or set the role's password:\n"
            . "  sudo -u postgres psql -c \"ALTER ROLE " . DB_USER . " PASSWORD 'something'\"",
        str_contains($msg, 'password authentication failed') =>
            "The password in .env is wrong for user " . DB_USER . ".\n"
            . "  sudo -u postgres psql -c \"ALTER ROLE " . DB_USER . " PASSWORD 'something'\"\n"
            . "  then put the same value in .env",
        str_contains($msg, 'does not exist') && str_contains($msg, 'database') =>
            "There is no database called " . DB_NAME . ".\n"
            . "  sudo -u postgres createdb -O " . DB_USER . ' ' . DB_NAME,
        str_contains($msg, 'role') && str_contains($msg, 'does not exist') =>
            "There is no role called " . DB_USER . ".\n"
            . "  sudo -u postgres createuser --pwprompt " . DB_USER,
        str_contains($msg, 'no pg_hba.conf entry') =>
            "PostgreSQL is running and refuses this connection method.\n"
            . "  Add a line to pg_hba.conf for this host and user, then\n"
            . "  sudo systemctl reload postgresql",
        str_contains($msg, 'Connection refused') =>
            "Nothing is listening. Is PostgreSQL running, and on port " . DB_PORT . "?\n"
            . "  sudo systemctl status postgresql",
        str_contains($msg, 'too many clients') =>
            "The connection pool is full. This is the cause when the error is\n"
            . "  intermittent and only under load. Raise max_connections, or put\n"
            . "  PgBouncer in front.",
        default => "Read the message above — it is the exact reason the\n"
            . "  application writes to its error log and then hides.",
    };
    echo "         " . str_replace("\n", "\n         ", $hint) . "\n";
}

/* ── 5. What the downloads need ──────────────────────────────── */
//  A missing extension here is not a broken database, so it does
//  not stop the app — it stops one button, silently, because the
//  failure is a fatal error and a production server shows those as
//  a blank page. That is how "the Excel button does nothing" starts.
echo "\n── Downloads ──\n";

if (!class_exists('ZipArchive')) {
    $fail++;
    say('no', 'The zip extension is missing, so Excel exports cannot be written.',
        "An .xlsx is a zip of XML parts; without it every Excel button fails\n"
        . "while PDF keeps working, which makes it look like one broken page.\n"
        . "  Debian/Ubuntu:  sudo apt install php" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "-zip\n"
        . "  XAMPP:          uncomment extension=zip in /opt/lampp/etc/php.ini\n"
        . "  then restart the web server.");
} else {
    say('ok', 'zip is loaded — Excel exports can be written');
}

$missing = array_values(array_filter(['dom', 'mbstring'], fn($e) => !extension_loaded($e)));
if (!is_file($root . '/vendor/autoload.php')) {
    $fail++;
    say('no', 'vendor/ is missing, so no PDF can be produced.',
        'Run "composer install" in ' . $root);
} elseif ($missing) {
    $fail++;
    say('no', 'PDFs need ' . implode(' and ', $missing) . ', which ' . (count($missing) > 1 ? 'are' : 'is') . ' not loaded.',
        "  Debian/Ubuntu:  sudo apt install php" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-'
        . implode(" php" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-', $missing) . "\n"
        . "  then restart the web server.");
} else {
    say('ok', 'dom, mbstring and vendor/ are present — PDFs can be produced');
}

// gd is how dompdf puts a raster image on the page. Without it the
// document still renders and still says the right things — it just
// comes out with no logo on it, which reads as "the logo upload is
// broken" and sends you looking in entirely the wrong place.
if (!extension_loaded('gd')) {
    $fail++;
    say('no', 'gd is missing, so documents will print with the logo missing.',
        "Everything else about the PDF is fine, which is what makes this\n"
        . "one hard to place: it looks like a broken logo upload.\n"
        . "  Debian/Ubuntu:  sudo apt install php" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "-gd\n"
        . "  then restart the web server.");
} else {
    say('ok', 'gd is loaded — the logo can be drawn on documents');
}

// finfo reads the real type of an uploaded file rather than trusting
// the name it arrived under. It is compiled in on Debian and Ubuntu,
// so this only ever fires on a hand-built PHP.
if (!class_exists('finfo')) {
    $fail++;
    say('no', 'fileinfo is missing, so every upload will be refused.',
        "Uploads are checked by real MIME type, not by file extension.\n"
        . "Without fileinfo there is nothing to check with and the answer\n"
        . "is always no.\n"
        . "  Enable extension=fileinfo in php.ini, then restart the web server.");
} else {
    say('ok', 'fileinfo is loaded — uploads can be type-checked');
}

/* ── Somewhere to put an uploaded file ───────────────────────────
 *  A logo, a product photo, a proof of payment. Each fails the
 *  same way — move_uploaded_file() returns false — and the folder
 *  is nearly always the reason: it belongs to whoever ran the
 *  deployment rather than to the web server that has to write
 *  into it. Worth checking here, because "Failed to save logo to
 *  server" is not a sentence anybody can act on.
 */
echo "\n── Uploads ──\n";

$uploadRoot = $root . '/public/uploads';
foreach (['' => 'uploads/', 'products' => 'uploads/products/',
          'company' => 'uploads/company/', 'payments' => 'uploads/payments/'] as $sub => $label) {
    $dir = $uploadRoot . ($sub !== '' ? '/' . $sub : '');

    if (!is_dir($dir)) {
        // Only the root has to exist; the rest are made on first use.
        if ($sub === '') {
            $fail++;
            say('no', $label . ' does not exist.', 'mkdir -p ' . $dir . ' && chmod 775 ' . $dir);
        } else {
            say('ok', $label . ' not created yet — it is made on the first upload');
        }
        continue;
    }

    if (!is_writable($dir)) {
        $fail++;
        $owner = function_exists('posix_getpwuid') && ($st = @stat($dir))
            ? (@posix_getpwuid($st['uid'])['name'] ?? $st['uid']) : 'another user';
        say('no', $label . ' is not writable by the web server (it belongs to ' . $owner . ').',
            "Every upload into it fails.\n"
            . '  chmod 775 ' . $dir . "\n"
            . '  # or, if the web server runs as another user:' . "\n"
            . '  chown -R www-data ' . $dir);
    } else {
        say('ok', $label . ' is writable');
    }
}

/* ── Where the real error goes ───────────────────────────────── */
echo "\n── The log ──\n";
$log = ini_get('error_log');
say('ok', 'PHP writes [DB] lines to: ' . ($log ?: 'the web server error log (error_log is unset)'),
    $log ? 'tail -50 ' . $log : "Apache:  tail -50 /var/log/apache2/error.log\n"
        . "XAMPP:   tail -50 /opt/lampp/logs/error_log\n"
        . "nginx:   tail -50 /var/log/nginx/error.log");

echo "\n" . ($fail === 0
    ? "Nothing wrong here — the application can reach its database.\n\n"
    : $fail . " problem(s) found. Fix the FAIL lines above, top to bottom.\n\n");

exit($fail === 0 ? 0 : 1);
