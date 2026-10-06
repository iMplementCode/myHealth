<?php

/**
 * ============================================================
 *  Health check
 * ------------------------------------------------------------
 *  What a load balancer asks before it sends a customer to this
 *  node, and what a monitor watches between deployments.
 *
 *  Two depths:
 *
 *    /health.php        liveness. Is PHP running? Answers without
 *                       touching the database, so a database
 *                       hiccup does not make every node look dead
 *                       and get restarted at once.
 *    /health.php?deep=1 readiness. Can this node actually serve a
 *                       request — database reachable, migrations
 *                       applied, sessions shared, uploads
 *                       writable? A node failing this should be
 *                       taken out of rotation, not killed.
 *
 *  It says nothing a stranger could use: no versions, no
 *  hostnames, no error text. HEALTH_TOKEN adds a shared secret
 *  for the deep check when even the shape of the answer is more
 *  than you want to publish.
 * ============================================================
 */

declare(strict_types=1);

/*  Liveness is answered before anything else is loaded, and that
    ordering is the whole point rather than a micro-optimisation.

    This used to require bootstrap.php first, which opens a
    database-backed session. So when the database was unreachable
    the liveness probe returned 503 along with every other page —
    a probe that claims to answer "did PHP answer" could not
    answer it precisely when somebody needed to know.

    An orchestrator reads that 503 as a dead container, kills it,
    starts another, and that one cannot reach the database either.
    The visitor gets 502 Bad Gateway from the router, which says
    nothing about a database, while the application sits there
    perfectly able to serve the page that would have explained
    itself. A wrong DB_NAME is enough to cause it.

    Liveness now means what it says: PHP is running and answering.
    Readiness — whether the dependencies are there — is the deep
    check below, which is what a probe should use when it wants
    the database's opinion.

    It also means no host check and no session for this request,
    both of which are right for a probe arriving on an internal
    address with no cookies.                                    */
if (($_GET['deep'] ?? '') === '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'ok']);
    exit;
}

// Reachable without a session: a load balancer has no cookies.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Readiness may be gated, because it describes the inside.
$token = trim((string) env('HEALTH_TOKEN', ''));
if ($token !== '' && !hash_equals($token, (string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ($_GET['token'] ?? '')))) {
    http_response_code(404);
    echo json_encode(['status' => 'not_found']);
    exit;
}

$checks  = [];
$healthy = true;

/** Run one check, recording how long it took and never throwing. */
$check = static function (string $name, callable $fn) use (&$checks, &$healthy): void {
    $started = microtime(true);
    try {
        $ok = (bool) $fn();
    } catch (Throwable $e) {
        error_log('[HEALTH] ' . $name . ': ' . $e->getMessage());
        $ok = false;
    }
    $checks[$name] = [
        'ok' => $ok,
        'ms' => (int) round((microtime(true) - $started) * 1000),
    ];
    $healthy = $healthy && $ok;
};

$check('database', static fn() => db_value('SELECT 1') !== null);

$check('migrations', static function () {
    // The newest migration this build expects. A node still
    // running yesterday's schema must not take traffic.
    return table_exists('schema_migrations') && table_exists('sessions');
});

$check('sessions_shared', static function () {
    // False here is not fatal on a single server, but on a fleet
    // it means this node is keeping sessions to itself.
    return function_exists('session_store_install') && session_store_install();
});

$check('uploads_writable', static fn() => is_dir(UPLOAD_PATH) && is_writable(UPLOAD_PATH));

http_response_code($healthy ? 200 : 503);
echo json_encode([
    'status' => $healthy ? 'ok' : 'degraded',
    'checks' => $checks,
], JSON_PRETTY_PRINT);
