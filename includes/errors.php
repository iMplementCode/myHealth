<?php

/**
 * ============================================================
 *  When something goes wrong
 * ------------------------------------------------------------
 *  PHP's default answer to an uncaught error, with display_errors
 *  off, is an empty HTTP 500. The browser renders that as
 *
 *      "This page isn't working"
 *
 *  and the person looking at it learns nothing: not what broke,
 *  not whether it was their fault, not what to tell anybody.
 *  Meanwhile the only record is a line in a server log they have
 *  no way to reach.
 *
 *  That is how three purchasing documents were "broken" for days.
 *  The cause was one renamed column and the fix was one command,
 *  but nothing on screen pointed at either.
 *
 *  ── What this does ──────────────────────────────────────────
 *  Every uncaught throwable and every fatal now produces:
 *
 *    • a full entry in the error log, with a short reference
 *    • a readable page — or JSON, for a fetch — carrying that
 *      same reference, so a report can be matched to a log line
 *    • the actual message, but ONLY when APP_DEBUG is on
 *
 *  ── Why the reference ───────────────────────────────────────
 *  "It said error 4F2A1B" is a bug report somebody can act on.
 *  "It said this page isn't working" is not.
 *
 *  ── What it deliberately does not do ────────────────────────
 *  It does not show the message, file or stack trace to a
 *  visitor by default. A database error names tables and columns,
 *  and a stack trace names paths; both are reconnaissance. In
 *  development set APP_DEBUG=true in .env and everything is on
 *  screen instead.
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


/** True when the application should show the real cause on screen. */
function errors_debug(): bool
{
    $flag = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG');
    return in_array(strtolower((string) $flag), ['1', 'true', 'on', 'yes'], true);
}

/**
 * Install the handlers. Called once, from bootstrap.
 *
 * Registered late enough that config and the helpers exist, and
 * early enough to catch anything a page then does.
 */
function errors_install(): void
{
    // Never print a raw error into the middle of a half-built page:
    // it leaks paths, and it corrupts a PDF or a JSON reply.
    if (!errors_debug()) {
        @ini_set('display_errors', '0');
    }
    @ini_set('log_errors', '1');

    set_exception_handler(static function (Throwable $e): void {
        errors_render($e);
    });

    // A fatal (a call to an undefined function, a memory exhaustion)
    // never reaches the exception handler, so it is caught on the way
    // out instead.
    register_shutdown_function(static function (): void {
        $last = error_get_last();
        if ($last === null || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        errors_render(new ErrorException(
            $last['message'], 0, $last['type'], $last['file'], $last['line']
        ));
    });
}

/**
 * Log it, then say so in whatever language the caller is speaking.
 */
function errors_render(Throwable $e): void
{
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    error_log(sprintf(
        '[ERR %s] %s: %s in %s:%d | url=%s | user=%s%s%s',
        $ref,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $_SERVER['REQUEST_URI'] ?? 'cli',
        $_SESSION['user_id'] ?? '-',
        PHP_EOL,
        $e->getTraceAsString()
    ));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "[ERR $ref] " . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    // Anything already sent is a half-written page. Throw it away so
    // the reply is only the error — a PDF with an HTML error stapled
    // to the front is a corrupt file, not a message.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header_remove('Content-Disposition');
    }

    $debug   = errors_debug();
    $detail  = $debug ? $e->getMessage() : null;
    $where   = $debug ? basename($e->getFile()) . ':' . $e->getLine() : null;

    if (function_exists('wants_json') && wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_filter([
            'success'   => false,
            'message'   => 'Something went wrong at our end. Reference ' . $ref . '.',
            'reference' => $ref,
            'detail'    => $detail,
            'where'     => $where,
        ], static fn($v) => $v !== null));
        exit;
    }

    errors_page($ref, $detail, $where);
    exit;
}

/**
 * The page a person sees.
 *
 * Deliberately self-contained: the failure may well be in the
 * database, the session or the layout, so an error page that needs
 * any of those to render is an error page that will not render on
 * the day it is needed.
 */
function errors_page(string $ref, ?string $detail, ?string $where): void
{
    $esc  = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $home = defined('APP_URL') ? APP_URL . '/dashboard/index.php' : '/';

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Something went wrong</title>
<style>
  :root { color-scheme: light dark; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background:#0f1115; color:#e6e8eb; padding:24px; }
  @media (prefers-color-scheme: light) { body { background:#f5f6f8; color:#1c1f24; } }
  .card { max-width:560px; width:100%; background:rgba(127,127,127,.08);
          border:1px solid rgba(127,127,127,.22); border-radius:14px; padding:32px; }
  h1 { margin:0 0 10px; font-size:21px; }
  p { margin:0 0 14px; line-height:1.6; font-size:14.5px; opacity:.9; }
  .ref { display:inline-block; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
         font-size:15px; letter-spacing:.08em; background:rgba(41,197,240,.14);
         color:#29c5f0; border-radius:6px; padding:5px 12px; margin-bottom:16px; }
  pre { white-space:pre-wrap; word-break:break-word; font-size:12.5px; line-height:1.5;
        background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.35);
        color:#fca5a5; border-radius:8px; padding:12px 14px; margin:0 0 16px; }
  a { display:inline-block; margin-top:6px; background:#29c5f0; color:#08131a;
      text-decoration:none; font-weight:600; padding:10px 18px; border-radius:8px; font-size:14px; }
</style>
</head>
<body>
  <div class="card">
    <h1>Something went wrong at our end</h1>
    <p>This page could not be built. Nothing you did caused it, and nothing
       has been saved incorrectly.</p>
    <div class="ref">Reference ' . $esc($ref) . '</div>';

    if ($detail !== null) {
        echo '
    <pre>' . $esc($detail) . ($where !== null ? "\n\n" . $esc($where) : '') . '</pre>';
    } else {
        echo '
    <p>Quote that reference when reporting it — it matches this exact
       failure in the server log.</p>
    <p><strong>If this started after an update</strong>, the database may be
       behind the application. Running the outstanding migrations is the
       usual cure.</p>';
    }

    echo '
    <a href="' . $esc($home) . '">Back to the dashboard</a>
  </div>
</body>
</html>';
}
