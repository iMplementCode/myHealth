<?php

/**
 * ============================================================
 *  Application Configuration
 *  myHealth
 * ------------------------------------------------------------
 *  Central place for environment loading and application-wide
 *  constants. This file performs NO output and starts NO
 *  session — that is handled by includes/bootstrap.php so that
 *  it can be included safely from any context (web or CLI).
 * ============================================================
 */

declare(strict_types=1);

/* ─── Paths ───────────────────────────────────────────────────
 *  Two roots, and the difference between them is the whole of
 *  the deployment's security posture.
 *
 *  BASE_PATH   the application. Code, configuration, the database
 *              password, the vendor tree, the migrations. **Nothing
 *              here is ever served.** On a live host it sits above
 *              the web root, where no request can reach it however
 *              badly the web server is configured.
 *
 *  PUBLIC_PATH the document root. Entry pages, assets, uploads —
 *              the things a browser is *supposed* to fetch, and
 *              nothing else.
 *
 *  It used to be one root, with the whole project inside the web
 *  root and .htaccess rules standing between the internet and
 *  .env. Those rules are still there, but they are now the second
 *  line rather than the only one: a lost AllowOverride, a rule
 *  that does not apply because the request was routed differently,
 *  a server moved to nginx — any of those and a single root leaks
 *  the database password. Two roots cannot.
 * ──────────────────────────────────────────────────────────── */

define('BASE_PATH', __DIR__);

// Where the served files live. `public` inside the project by
// default; on cPanel the document root is ~/public_html and cannot
// be moved for the primary domain, so PUBLIC_PATH is settable.
// See docs/DEPLOYMENT.md.
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', (static function (): string {
        // Read straight out of .env rather than waiting for the
        // loader: the document root has to be known before anything
        // else, and a path is not a secret.
        $fromEnv = static function (): ?string {
            $envFile = __DIR__ . '/.env';
            if (!is_file($envFile)) {
                return null;
            }
            foreach ((array) @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^\s*PUBLIC_PATH\s*=\s*(.*)$/', $line, $m)) {
                    return trim(trim($m[1]), "\"'") ?: null;
                }
            }
            return null;
        };

        $path     = getenv('PUBLIC_PATH') ?: $fromEnv() ?: BASE_PATH . '/public';
        $resolved = realpath($path);

        if ($resolved === false) {
            // Getting this wrong on a live host is a blank page and an
            // afternoon. Say which path was tried.
            http_response_code(500);
            exit('Configuration error: PUBLIC_PATH does not exist (' . $path . ').');
        }
        return $resolved;
    })());
}

// APP_URL is the base URL path the app is served from. Adjust
// this if the project lives in a sub-folder of the web root.
// It is used to build absolute links to assets and modules.
// Computed inside a closure, not at file scope. This file is
// included from CLI tools that have their own variables, and a
// bare $root here lands in theirs: deploy/check-db.php sets
// $root to the project and then went looking for vendor/ under
// the document root, reporting a healthy install as broken. A
// config file must leave no variables behind.
if (!defined('APP_URL')) {
    define('APP_URL', (static function (): string {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

        // Walk back to the document root, however deep the running
        // page sits. The depth is measured on disk — how far the
        // script is below PUBLIC_PATH — rather than matched against a
        // list of known folder names. A list has to be edited every
        // time a directory is added, and forgetting leaves every
        // asset on those pages pointing at a URL that does not
        // exist, with no error to say so.
        $realScript = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $appUrl     = $scriptDir;

        if ($realScript !== false) {
            $docRoot = rtrim(str_replace('\\', '/', PUBLIC_PATH), '/');
            $here    = str_replace('\\', '/', dirname($realScript));

            if ($here === $docRoot) {
                $depth = 0;
            } elseif (str_starts_with($here . '/', $docRoot . '/')) {
                $relative = trim(substr($here, strlen($docRoot)), '/');
                $depth    = $relative === '' ? 0 : substr_count($relative, '/') + 1;
            } else {
                $depth = null;          // served through a symlink or alias
            }

            if ($depth !== null) {
                for ($i = 0; $i < $depth; $i++) {
                    $appUrl = str_replace('\\', '/', dirname($appUrl));
                }
            }
        }

        // dirname() bottoms out at '/' (or '.'); the root of a site is
        // the empty string, so links read '/assets/…' not '//assets/…'.
        return rtrim($appUrl === '/' || $appUrl === '.' ? '' : $appUrl, '/');
    })());
}

// ─── Load environment (.env) ─────────────────────────────────
require_once BASE_PATH . '/vendor/autoload.php';

if (file_exists(BASE_PATH . '/.env')) {
    // Not assigned to a variable: see the note on APP_URL above —
    // this file is included from scripts that have their own.
    Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
}

/*  Small helper so config reads cleanly and provides defaults.
 *
 *  Three sources, in order, because there is no single one that
 *  always works. PHP here runs with variables_order = "GPCS" — no E
 *  — so $_ENV is never filled from the real environment and is
 *  populated only by Dotenv reading .env. On the live host there is
 *  no .env at all and the settings arrive as real environment
 *  variables, which only getenv() can see. Drop either source and
 *  one of the two deployments stops being configurable.
 *
 *  This was written as
 *
 *      return $_ENV[$k] ?? $_SERVER[$k] ?? getenv($k) ?: $default;
 *
 *  which does not do what it reads like. ?? binds *tighter* than ?:,
 *  so the whole coalescing chain resolves first and its result is
 *  then tested for truth — and that means a setting could never be
 *  any falsy value. MIGRATE_DB_WAIT=0 came back as the default 20;
 *  so would IDLE_WARNING_SECONDS=0 or TWO_FACTOR_RESEND_COOLDOWN=0.
 *  Setting something to zero silently did the opposite of nothing.
 *
 *  Written out, "not configured" is now exactly: absent, or blank. A
 *  blank is still the default on purpose — DB_HOST= in a .env is
 *  somebody who has not filled it in, and handing libpq an empty
 *  host is a worse failure than falling back to localhost.         */
function env(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
}

/* ─── Database ────────────────────────────────────────────────
 *
 *  Read from the environment, because the connection string is the
 *  tenant boundary. One image serves every shop; which shop it is
 *  serving is decided entirely by DB_NAME. Hard-coding it here
 *  would mean a branch per customer, and a branch per customer is
 *  ten places to apply every fix.
 *
 *  Guarded with defined() so a CLI tool can say which database it
 *  means before loading this file — that is how the migration
 *  runner points at a tenant other than the one in .env, and it
 *  follows the same pattern PUBLIC_PATH and APP_URL use above.
 *
 *  ── The aliases, and why they are here ──
 *
 *  This application reads DB_NAME / DB_USER / DB_PASS. A hosting
 *  panel's PHP preset fills in DB_DATABASE / DB_USERNAME /
 *  DB_PASSWORD, which are Laravel's names, and nothing here read
 *  them. The result was not an error anybody could see: env()
 *  returned an empty password, libpq was handed nothing, and the
 *  whole site answered "the service is temporarily unavailable"
 *  with `fe_sendauth: no password supplied` in the container log.
 *
 *  Accepting both names costs three lines and removes an outage
 *  whose cause is invisible from the browser. The application's
 *  own names win where both are set.
 * ──────────────────────────────────────────────────────────── */
if (!defined('DB_HOST')) {
    define('DB_HOST', env('DB_HOST', 'localhost'));
}
if (!defined('DB_PORT')) {
    define('DB_PORT', env('DB_PORT', '5432'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', env('DB_NAME', env('DB_DATABASE', 'runai_technologies')));
}
if (!defined('DB_USER')) {
    define('DB_USER', env('DB_USER', env('DB_USERNAME', 'runai_app')));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', env('DB_PASS', env('DB_PASSWORD', '')));
}

// ─── Application ─────────────────────────────────────────────
define('APP_NAME', 'myHealth');
define('APP_ENV', env('APP_ENV', 'production'));   // production | development
define('APP_DEBUG', filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));

// ─── Security / Session ──────────────────────────────────────
define('SESSION_NAME', 'IMPLEMENT_ERP_SESS');

// Idle timeout. A till or a stock desk is often left unattended in
// front of whoever walks past, so the default is deliberately short.
// Set IDLE_TIMEOUT_MINUTES in .env to change it without touching code.
define('IDLE_TIMEOUT_MINUTES', max(1, (int) env('IDLE_TIMEOUT_MINUTES', '30')));
define('SESSION_LIFETIME', IDLE_TIMEOUT_MINUTES * 60);

// How long before the timeout the browser warns, so nobody loses a
// half-typed invoice without being given the chance to keep working.
define('IDLE_WARNING_SECONDS', (int) env('IDLE_WARNING_SECONDS', '60'));

define('SESSION_REGENERATE', 60 * 15);     // rotate session id every 15 min
// Hard ceiling on a session's life, however active it stays. Limits
// how long a stolen session remains useful.
define('SESSION_ABSOLUTE_LIFETIME', 60 * 60 * 12);   // 12 hours
define('CSRF_TOKEN_KEY', 'csrf_token');

/*  ─── Two-factor sign-in ─────────────────────────────────────
 *
 *  Off unless the environment says otherwise, and an environment
 *  variable rather than a constant on purpose: if the mail server
 *  changes its password on a Friday night, somebody needs to be
 *  able to turn this off and get the shop working again without
 *  waiting for a deploy.
 *
 *  It also refuses to switch itself on when mail is not
 *  configured — see two_factor_required(). Requiring a code that
 *  cannot be delivered locks every single person out of the
 *  business at once.
 *
 *  Prove mail works first:  php deploy/check-mail.php you@example.com
 * ──────────────────────────────────────────────────────────── */
define('TWO_FACTOR_ENABLED', filter_var(env('TWO_FACTOR_ENABLED', 'false'), FILTER_VALIDATE_BOOL));

// Six digits: a million combinations against five attempts. Long
// enough that guessing is hopeless, short enough to carry across a
// room in your head.
define('TWO_FACTOR_CODE_DIGITS', 6);

// Long enough to find the email, short enough that a code read off
// a screen somebody walked away from is no longer worth anything.
define('TWO_FACTOR_CODE_TTL', max(60, (int) env('TWO_FACTOR_CODE_TTL', '600')));

// Five wrong codes and it is dead. More than anybody needs to type
// a number they are looking at; far fewer than guessing requires.
define('TWO_FACTOR_MAX_ATTEMPTS', max(1, (int) env('TWO_FACTOR_MAX_ATTEMPTS', '5')));

// A gap between "send it again" presses, so the button cannot be
// used to post email at somebody.
define('TWO_FACTOR_RESEND_COOLDOWN', max(0, (int) env('TWO_FACTOR_RESEND_COOLDOWN', '60')));

// Login throttling
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 15 * 60);     // 15 minutes lockout after max attempts

// ─── Invoicing ───────────────────────────────────────────────
// How long a customer has to pay, in days from the issue date.
// Every invoice gets a due date from this unless one is given, and
// the due date is what the aged receivables report ages from — an
// invoice with none would be counted as late the day after it was
// raised, which is nobody's terms.
define('INVOICE_PAYMENT_TERMS_DAYS', max(0, (int) env('INVOICE_PAYMENT_TERMS_DAYS', '14')));

// ─── Website order intake ────────────────────────────────────
// The most of one product a single web order line may ask for.
// The figure itself is arbitrary; having one is not. Quantity on
// that endpoint is a number a stranger typed into a box on a
// website, and without a ceiling it reaches the database as
// anything JSON can express — including infinity, which NUMERIC
// cannot store and which turned a nonsense order into a 500
// instead of a refusal. Raise it if a real customer ever needs to.
define('ORDER_INTAKE_MAX_QUANTITY', max(1, (int) env('ORDER_INTAKE_MAX_QUANTITY', '10000')));

// ─── Branding ────────────────────────────────────────────────
// Primary brand colour used on printed documents (company name,
// document title, grand total). Matches the app's primary accent.
define('BRAND_COLOR', env('BRAND_COLOR', '#29c5f0'));

// ─── Roles ───────────────────────────────────────────────────
// Canonical role names used for role-based access control.
// These mirror the `roles` table seeded by the migrations.
define('ROLE_ADMIN', 'Administrator');
define('ROLE_MANAGER', 'Manager');
define('ROLE_SALES', 'Salesperson');

// ─── Uploads ─────────────────────────────────────────────────
// Served, so it lives under the document root — but the .htaccess
// beside it turns PHP off, because an uploaded image that happens
// to contain PHP must never become a web shell.
define('UPLOAD_PATH', PUBLIC_PATH . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');

// ─── Error handling ──────────────────────────────────────────
// Never leak internals to the browser in production. Errors are
// always written to the PHP error log for debugging.
error_reporting(E_ALL);
ini_set('log_errors', '1');
if (APP_DEBUG) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}

// Default timezone (East Africa — matches existing Kenya-based data).
date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Nairobi'));
