<?php

/**
 * ============================================================
 *  Which configuration actually reached PHP
 * ------------------------------------------------------------
 *  Run it on the server, inside the container:
 *
 *      php deploy/check-env.php
 *
 *  It answers one question: for each setting the application
 *  needs, is the value visible to PHP, and through which of the
 *  three channels env() looks in?
 *
 *  That question is not idle. PHP fills $_ENV only when
 *  variables_order contains "E", and the default is "GPCS" —
 *  no E. So a variable set in a hosting panel is invisible to
 *  $_ENV and perfectly visible to getenv(), and code that reads
 *  one rather than the other is code that works on a laptop with
 *  a .env file and fails on the server with nothing to show for
 *  it. That is exactly how the quote and sales-order PDFs came
 *  to answer "Database connection failed" while every other page
 *  was fine.
 *
 *  It prints LENGTHS, never values. A password that shows up in
 *  a terminal ends up in a scrollback, a screenshot and a
 *  support ticket.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config.php';

/** Where a value was found, and how big it is — never what it is. */
function channels(string $key): array
{
    $found = [];
    if (array_key_exists($key, $_ENV)) {
        $found['$_ENV'] = (string) $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        $found['$_SERVER'] = (string) $_SERVER[$key];
    }
    $g = getenv($key);
    if ($g !== false) {
        $found['getenv()'] = (string) $g;
    }
    return $found;
}

echo "\n  Configuration as PHP sees it\n";
echo "  ────────────────────────────────────────────────────────\n";
echo '  PHP ' . PHP_VERSION . '   SAPI ' . PHP_SAPI . "\n";
echo '  variables_order = "' . ini_get('variables_order') . '"';
echo str_contains((string) ini_get('variables_order'), 'E')
    ? "  ($_ENV is populated from the environment)\n"
    : "  (no E — \$_ENV is NOT populated from the environment;\n"
      . "                              getenv() is the one that sees it)\n";
echo '  .env file: ' . (is_file($root . '/.env') ? 'present' : 'not present')
   . "  (a container usually has none — that is fine)\n\n";

$required = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];
$optional = ['APP_ENV', 'APP_DEBUG', 'FORCE_HTTPS', 'TRUSTED_PROXIES', 'APP_HOSTS',
             'ORDERS_API_KEY', 'CATALOG_API_KEY'];

$problems = [];

foreach ([['Required', $required], ['Optional', $optional]] as [$heading, $keys]) {
    echo "  $heading\n";
    foreach ($keys as $key) {
        $found = channels($key);
        $via   = $found ? implode(', ', array_keys($found)) : '—';
        $value = $found ? reset($found) : '';
        $len   = strlen($value);

        if (!$found) {
            $state = 'NOT SET';
        } elseif ($value === '') {
            $state = 'SET BUT EMPTY';
        } else {
            $state = $len . ' chars';
        }

        printf("    %-16s %-16s via %s\n", $key, $state, $via);

        /*  Not being in the environment is not by itself a fault:
         *  config.php may define the value some other way, and what
         *  matters is what the application ends up using. That is
         *  reported below, and the connection attempt settles it.
         *  Flagging every absent variable here told the operator
         *  DB_PASS was missing on a system where it was present and
         *  working, which is worse than saying nothing. */

        // A value that still carries its quotes was pasted with them.
        if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
            $problems[] = $key . ' (looks quoted — the quotes are part of the value)';
        }
        // Trailing whitespace is invisible in a form field and fatal in a password.
        if ($value !== '' && trim($value) !== $value) {
            $problems[] = $key . ' (has leading or trailing whitespace)';
        }
    }
    echo "\n";
}

echo "  What the application will actually use\n";
printf("    host %s   port %s   database %s   user %s   password %s\n\n",
    DB_HOST, DB_PORT, DB_NAME, DB_USER,
    DB_PASS === '' ? 'EMPTY — this is "fe_sendauth: no password supplied"'
                   : strlen((string) DB_PASS) . ' chars');

foreach (['DB_HOST' => DB_HOST, 'DB_NAME' => DB_NAME,
          'DB_USER' => DB_USER, 'DB_PASS' => DB_PASS] as $k => $v) {
    if ((string) $v === '') {
        $problems[] = $k . ' is empty — the application has no value for it at all';
    }
}

echo "  Can it connect?\n";
try {
    $pdo = new PDO(
        'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    echo "    yes — connected, server says "
       . $pdo->query('SELECT version()')->fetchColumn() . "\n\n";
} catch (Throwable $e) {
    echo '    no — ' . explode("\n", $e->getMessage())[0] . "\n\n";
    $problems[] = 'the connection itself';
}

if ($problems) {
    echo "  Problems\n";
    foreach (array_unique($problems) as $p) {
        echo "    · $p\n";
    }
    echo "\n  Set the missing ones in the hosting panel's environment\n"
       . "  settings and redeploy. Paste the value on its own — no\n"
       . "  surrounding quotes, no trailing space.\n\n";
    exit(1);
}

echo "  Everything the application needs is present and it connects.\n\n";
exit(0);
