<?php

/**
 * ============================================================
 *  Is this deployment actually hardened?
 * ------------------------------------------------------------
 *  Run it inside the container, on the box that serves the site:
 *
 *      php deploy/check-hardening.php
 *
 *  The application's own security is not the question here —
 *  that is in the code and it travels with the code. This asks
 *  the other half, which does not travel: whether the settings
 *  around it are right on THIS host.
 *
 *  Every finding below is something that has made a correctly
 *  written application insecure on a correctly configured
 *  server, because the two were configured for different worlds.
 *  The one that matters most is the proxy: behind a load
 *  balancer with TRUSTED_PROXIES unset, every visitor shares one
 *  address, and the login lockout — which counts failures by
 *  address — locks out the whole pharmacy when anybody fails a
 *  few passwords. Nothing in the code is wrong. It is simply
 *  being told that fifty people are one person.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';

$findings = [];

/** Record one finding. Level: fail, warn, ok. */
function finding(string $level, string $title, string $detail, string $fix = ''): void
{
    global $findings;
    $findings[] = ['level' => $level, 'title' => $title, 'detail' => $detail, 'fix' => $fix];
}

/* ── Reverse proxy ──────────────────────────────────────────── */

$proxies = trim((string) env('TRUSTED_PROXIES', ''));
if ($proxies === '') {
    finding(
        'fail',
        'TRUSTED_PROXIES is not set',
        'If anything sits in front of this application — Traefik, nginx, Cloudflare, a '
        . 'load balancer — then every visitor is being recorded as that one address. '
        . 'The login lockout counts failed attempts by address as well as by account, so '
        . 'a few bad passwords from any single person lock out every user at once. Rate '
        . 'limits become one bucket for the whole internet, and the audit log records the '
        . 'proxy against every action.',
        'Set TRUSTED_PROXIES to the proxy\'s address or CIDR. On Dokploy the application '
        . 'container sees Traefik on the Docker network, commonly 172.16.0.0/12. Check the '
        . 'log after a real request: the application now writes a [SECURITY] line naming '
        . 'the exact address it saw.'
    );
} else {
    finding('ok', 'TRUSTED_PROXIES is set', $proxies);
}

/* ── Which hosts this answers to ────────────────────────────── */

$hosts = trim((string) env('APP_HOSTS', ''));
if ($hosts === '') {
    finding(
        'warn',
        'APP_HOSTS is not set',
        'The application answers to any Host header. A forged Host ends up in '
        . 'password-reset links, so a reset email can be made to point at somebody '
        . 'else\'s server with a working token in it.',
        'Set APP_HOSTS to the real domain, comma separated if there are several. '
        . 'Health probes are unaffected: liveness answers before the host check.'
    );
} else {
    finding('ok', 'APP_HOSTS is set', $hosts);
}

/* ── Two-factor sign-in ─────────────────────────────────────── */

$twoFactor = filter_var(env('TWO_FACTOR_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
$smtpHost  = trim((string) env('SMTP_HOST', ''));
$mailFrom  = trim((string) env('MAIL_FROM', ''));

if ($twoFactor && ($smtpHost === '' || $mailFrom === '')) {
    finding(
        'fail',
        'Two-factor is switched on but cannot send anything',
        'TWO_FACTOR_ENABLED is true and ' . ($smtpHost === '' ? 'SMTP_HOST' : 'MAIL_FROM')
        . ' is empty. The application will not refuse every sign-in over this — that would '
        . 'lock the business out — so it signs people in WITHOUT a code and writes a line '
        . 'to the log. The effect is that two-factor looks switched on, reports as switched '
        . 'on, and is not protecting anybody.',
        'Set SMTP_HOST, SMTP_USER, SMTP_PASS and MAIL_FROM, then prove it with '
        . 'php deploy/check-mail.php you@yourdomain before relying on it.'
    );
} elseif ($twoFactor) {
    finding('ok', 'Two-factor sign-in is on, with mail configured', $smtpHost);
} else {
    finding(
        'warn',
        'Two-factor sign-in is off',
        'A password alone opens a system holding patient records and a controlled drugs '
        . 'register. Passwords are reused, written down, and phished.',
        'Configure SMTP, check it with deploy/check-mail.php, then set '
        . 'TWO_FACTOR_ENABLED=true. If the mail server later fails, sign-in stops at the '
        . 'code box rather than at the password, and deploy/two-factor-code.php issues a '
        . 'code from the server so nobody is locked out.'
    );
}

/* ── Environment and debugging ──────────────────────────────── */

$envName = (string) env('APP_ENV', 'production');
if ($envName !== 'production') {
    finding('fail', 'APP_ENV is not production', 'APP_ENV=' . $envName
        . '. Development mode shows errors to whoever is at the browser, '
        . 'including file paths and query fragments.', 'Set APP_ENV=production.');
} else {
    finding('ok', 'APP_ENV is production', '');
}

if (filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN)) {
    finding('fail', 'APP_DEBUG is on', 'Stack traces are being rendered to visitors.',
        'Remove APP_DEBUG, or set it to false.');
}

if ((string) ini_get('display_errors') === '1') {
    finding('fail', 'display_errors is on in PHP',
        'PHP itself will print warnings and notices into the page, which leaks paths '
        . 'and sometimes query text regardless of what the application does.',
        'display_errors = Off in php.ini, or -d display_errors=0 on the command line.');
}

/* ── The database account ───────────────────────────────────── */

try {
    $pdo = Database::connect();

    $super = (bool) $pdo->query(
        "SELECT rolsuper FROM pg_roles WHERE rolname = current_user"
    )->fetchColumn();

    if ($super) {
        finding(
            'fail',
            'The application connects as a database superuser',
            'DB_USER is ' . DB_USER . ', which is a superuser. SQL injection is not the '
            . 'only way this matters: any bug that reaches the database does so with '
            . 'rights to read every other database on the cluster, write files the server '
            . 'can write, and drop anything. A pharmacy\'s application needs none of that.',
            "CREATE ROLE myhealth_app LOGIN PASSWORD '…';\n"
            . "          GRANT CONNECT ON DATABASE " . DB_NAME . " TO myhealth_app;\n"
            . "          GRANT USAGE ON SCHEMA public TO myhealth_app;\n"
            . "          GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO myhealth_app;\n"
            . "          GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO myhealth_app;\n"
            . '          then point DB_USER at it. Migrations may need more and can run as the owner.'
        );
    } else {
        finding('ok', 'The database account is not a superuser', DB_USER);
    }

    //  The seeded administrator. Its password is in the migration,
    //  which is in the repository, which is on somebody's laptop.
    $stillDefault = $pdo->query(
        "SELECT password_hash FROM users WHERE LOWER(username) = 'implement' LIMIT 1"
    )->fetchColumn();

    if ($stillDefault && password_verify('implement@1725', (string) $stillDefault)) {
        finding(
            'fail',
            'The default administrator password is unchanged',
            'The seeded account still has the password published in migration 005, which '
            . 'is in the repository and in this file\'s history. Anybody who can reach the '
            . 'sign-in page can sign in as an administrator.',
            'Sign in and change it, or disable that account once a real one exists.'
        );
    } else {
        finding('ok', 'The default administrator password has been changed', '');
    }

    $sslUsed = $pdo->query("SELECT COALESCE((SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()), false)")->fetchColumn();
    if (!$sslUsed && !in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true) && !str_starts_with((string) DB_HOST, '/')) {
        finding('warn', 'The database connection is not encrypted',
            'DB_HOST is ' . DB_HOST . ', which is not local, and the connection is in clear. '
            . 'On a single Docker network that is usually acceptable; across hosts it is not.',
            'Require TLS on the server, or keep the database on the same private network.');
    }
} catch (Throwable $e) {
    finding('warn', 'Could not check the database', $e->getMessage(), '');
}

/* ── How it is being served ─────────────────────────────────── */

$serverSoftware = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');
$startCmd = @file_get_contents(__DIR__ . '/../nixpacks.toml') ?: '';
if (str_contains($startCmd, 'php -S')) {
    $workers = (int) (getenv('PHP_CLI_SERVER_WORKERS') ?: 1);
    finding(
        'warn',
        'Served by PHP\'s built-in development server',
        'PHP\'s own manual says this server "should not be used on a public network". It has '
        . 'no request timeouts and no connection limits, and it handles '
        . ($workers > 1 ? $workers . ' requests' : 'one request') . ' at a time. Rendering a '
        . 'PDF occupies a worker for a second or two, so a handful of slow requests — or one '
        . 'person opening slow connections on purpose — is enough to make the site stop '
        . 'answering for everybody.',
        'Serve it with nginx and php-fpm: deploy/Dockerfile and deploy/nginx-site.conf in '
        . 'this repository do that. On Dokploy, set the build type to Dockerfile and the '
        . 'path to deploy/Dockerfile — it is not at the repository root precisely so that '
        . 'nothing changes about a live site until somebody decides it should.'
    );
}

/* ── Secrets on disk ────────────────────────────────────────── */

$envFile = BASE_PATH . '/.env';
if (is_file($envFile)) {
    $perms = substr(sprintf('%o', fileperms($envFile)), -4);
    if ((int) substr($perms, -1) !== 0) {
        finding('fail', '.env is readable by every account on the host',
            'Permissions are ' . $perms . '. The database password is in that file.',
            'chmod 640 .env && chown root:www-data .env, or hold the settings as '
            . 'environment variables and ship no .env at all, which is what a container '
            . 'platform is for.');
    } else {
        finding('ok', '.env is not world readable', $perms);
    }
}

/* ── Report ─────────────────────────────────────────────────── */

$order = ['fail' => 0, 'warn' => 1, 'ok' => 2];
usort($findings, static fn(array $a, array $b): int => $order[$a['level']] <=> $order[$b['level']]);

$mark  = ['fail' => '  ✗ FAIL', 'warn' => '  ! WARN', 'ok' => '  ✓ ok  '];
$fails = $warns = 0;

echo "\n  Deployment hardening\n";
echo "  ────────────────────────────────────────────────────────\n";
echo '  host ' . DB_USER . '@' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME
   . '   PHP ' . PHP_VERSION . "\n\n";

foreach ($findings as $f) {
    $fails += $f['level'] === 'fail' ? 1 : 0;
    $warns += $f['level'] === 'warn' ? 1 : 0;

    echo $mark[$f['level']] . '  ' . $f['title'] . "\n";
    if ($f['detail'] !== '' && $f['level'] !== 'ok') {
        echo '          ' . wordwrap($f['detail'], 68, "\n          ") . "\n";
    } elseif ($f['detail'] !== '') {
        echo '          ' . $f['detail'] . "\n";
    }
    if ($f['fix'] !== '') {
        echo "\n          Fix: " . wordwrap($f['fix'], 68, "\n          ") . "\n";
    }
    echo "\n";
}

echo "  ────────────────────────────────────────────────────────\n";
printf("  %d to fix, %d to look at, %d fine\n\n", $fails, $warns, count($findings) - $fails - $warns);

exit($fails > 0 ? 1 : 0);
