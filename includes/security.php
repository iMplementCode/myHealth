<?php

/**
 * ============================================================
 *  Security layer
 * ------------------------------------------------------------
 *  The things that have to be true of every request before the
 *  page it asks for is allowed to think about answering it:
 *
 *    - who is asking, honestly, from behind a proxy or CDN
 *    - that the connection is encrypted, and stays that way
 *    - that the response tells the browser what it may run
 *    - that nobody is asking too often
 *    - that the request is not an obvious attack
 *
 *  ── On the layers above this one ────────────────────────────
 *  A web application firewall, a CDN and DDoS scrubbing live in
 *  front of the origin, not inside it — see docs/DEPLOYMENT.md
 *  and the configuration in deploy/. What is here is the last
 *  layer, the one that still works when a request reaches the
 *  origin directly, and the one that cannot be bypassed by
 *  finding the origin's IP address.
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/* ─────────────────────────────────────────────────────────────
 *  Who is asking
 * ──────────────────────────────────────────────────────────── */

/**
 * The client's real IP address.
 *
 * Behind a CDN or a load balancer, REMOTE_ADDR is the proxy, and
 * every visitor in the world shares it. Rate limits keyed on it
 * would then either lock out everybody at once or nobody at all.
 *
 * X-Forwarded-For fixes that — and is trivially forged, so it is
 * only believed when the request genuinely arrived from a proxy
 * the operator has listed in TRUSTED_PROXIES. With no list, the
 * header is ignored entirely, which is the safe default for a
 * server exposed directly.
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    if (!security_from_trusted_proxy($remote)) {
        return $remote;
    }

    // Cloudflare states the origin IP directly and does not let a
    // client set it, so it is preferred where present.
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }

    /*  Walk the chain from the RIGHT, and this direction is the whole
     *  point.
     *
     *  X-Forwarded-For reads "client, proxy1, proxy2": each hop
     *  APPENDS. So the right-hand end was written by our own proxy and
     *  can be believed, while the left-hand end is whatever the
     *  original request arrived carrying — which a client is free to
     *  invent. Reading left-to-right, an attacker sending
     *
     *      X-Forwarded-For: 1.2.3.4
     *
     *  becomes 1.2.3.4 the moment our proxy appends their real
     *  address, and every limit keyed on client_ip() falls over:
     *  unlimited password guessing by rotating the header, and the
     *  ability to push somebody else's address into lockout. The
     *  audit log records the address they chose, too.
     *
     *  So: take the last hop that is not one of ours. */
    $chain = array_reverse(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    foreach ($chain as $hop) {
        $hop = trim($hop);
        if ($hop === '' || !filter_var($hop, FILTER_VALIDATE_IP)) {
            // A malformed entry means the chain cannot be trusted past
            // this point — anything further left is behind a value we
            // could not verify. Stop rather than keep walking.
            break;
        }
        if (!security_from_trusted_proxy($hop)) {
            return $hop;
        }
    }

    // Every hop was one of ours, or the header was absent: the peer is
    // the closest thing to a client we have.
    return $remote;
}

/** True when an address is one of the proxies we put in front of us. */
function security_from_trusted_proxy(string $ip): bool
{
    static $list = null;
    if ($list === null) {
        $raw  = (string) env('TRUSTED_PROXIES', '');
        $list = array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
    if (!$list || $ip === '') {
        return false;
    }
    foreach ($list as $entry) {
        if ($entry === $ip) {
            return true;
        }
        if (str_contains($entry, '/') && security_ip_in_cidr($ip, $entry)) {
            return true;
        }
    }
    return false;
}

/** Whether an address falls inside a CIDR block (IPv4 and IPv6). */
function security_ip_in_cidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipBin  = @inet_pton($ip);
    $netBin = @inet_pton((string) $subnet);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $bits = (int) $bits;
    $max  = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $max) {
        return false;
    }

    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;
    if ($whole > 0 && substr($ipBin, 0, $whole) !== substr($netBin, 0, $whole)) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $rest)) & 0xFF);
    return (($ipBin[$whole] & $mask) === ($netBin[$whole] & $mask));
}

/** True when the request reached us over TLS, proxies included. */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    /*  Only something we are behind may claim this on the client's
     *  own behalf.
     *
     *  TRUSTED_PROXIES is the explicit answer and the one to use.
     *  Failing that, a request that reached us from a private or
     *  loopback address arrived through whatever sits in front of
     *  us — on a container platform that is the ingress proxy, and
     *  nothing on the public internet can present a private peer
     *  address, because it could not complete the handshake.
     *
     *  This second rule exists because without it a containerised
     *  deployment cannot work at all: the proxy terminates TLS and
     *  forwards plain HTTP, so request_is_https() says no,
     *  security_force_https() redirects to https://, the proxy
     *  forwards it back as HTTP, and the browser gives up after
     *  twenty round trips. The deployment met exactly that and
     *  answered it by trusting X-Forwarded-Proto from ANYBODY,
     *  which hands any client on the internet the power to switch
     *  the HTTPS redirect off for itself. This is the same fix
     *  without that.                                              */
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (security_from_trusted_proxy($peer) || security_peer_is_private($peer)) {
        $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
    }
    return false;
}

/**
 * Did this request reach us from an address on our own side of the
 * internet — loopback, or one of the private ranges?
 *
 * Used only to decide whether a forwarding header can be believed.
 * It is deliberately NOT used for client_ip(): knowing a request
 * came through some proxy is not the same as knowing which proxy,
 * and rate limits keyed on the wrong address are worse than useless.
 * Set TRUSTED_PROXIES for that.
 */
function security_peer_is_private(string $ip): bool
{
    if ($ip === '' || $ip === '::1' || str_starts_with($ip, '127.')) {
        return true;
    }
    foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7', 'fe80::/10'] as $range) {
        if (security_ip_in_cidr($ip, $range)) {
            return true;
        }
    }
    return false;
}

/* ─────────────────────────────────────────────────────────────
 *  Transport
 * ──────────────────────────────────────────────────────────── */

/**
 * Insist on HTTPS.
 *
 * A password sent once over http:// is a password an attacker on
 * the same café Wi-Fi already has, and no amount of care
 * afterwards takes it back. The redirect closes the first
 * request; HSTS closes every one after it, by telling the browser
 * never to try http:// for this host again.
 *
 * Disabled when FORCE_HTTPS is off, so a developer on localhost
 * is not redirected into a connection that does not exist.
 */
function security_force_https(): void
{
    if (PHP_SAPI === 'cli' || !filter_var(env('FORCE_HTTPS', 'true'), FILTER_VALIDATE_BOOL)) {
        return;
    }
    if (security_is_local_request()) {
        return;
    }

    if (!request_is_https()) {
        $host = security_safe_host();
        if ($host === null) {
            http_response_code(400);
            exit('Bad host.');
        }
        header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    // Only ever sent over a connection that is already secure —
    // over http:// the header is meaningless and browsers ignore it.
    if (!headers_sent()) {
        $age = (int) env('HSTS_MAX_AGE', '31536000');   // one year
        $hsts = 'max-age=' . $age . '; includeSubDomains';
        if (filter_var(env('HSTS_PRELOAD', 'false'), FILTER_VALIDATE_BOOL)) {
            $hsts .= '; preload';
        }
        header('Strict-Transport-Security: ' . $hsts);
    }
}

/** Loopback and private addresses, where TLS is usually absent. */
function security_is_local_request(): bool
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '' || $ip === '::1' || str_starts_with($ip, '127.')) {
        return true;
    }
    $host = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
    return $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test');
}

/**
 * The Host header, but only if it is one of ours.
 *
 * Host is client-supplied. Left unchecked it ends up in redirects
 * and password-reset links, which is how a password reset arrives
 * pointing at someone else's server. APP_HOSTS lists what is
 * acceptable; with no list, whatever the server itself was
 * configured with is used instead of the header.
 */
function security_safe_host(): ?string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    $allowed = array_values(array_filter(array_map(
        'trim',
        explode(',', strtolower((string) env('APP_HOSTS', '')))
    )));

    if (!$allowed) {
        return $host !== '' ? $host : null;
    }
    foreach ($allowed as $ok) {
        if ($host === $ok || $host === $ok . ':443' || $host === $ok . ':80') {
            return $ok;
        }
    }
    return null;
}

/* ─────────────────────────────────────────────────────────────
 *  Response headers
 * ──────────────────────────────────────────────────────────── */

/**
 * A per-response nonce for inline scripts.
 *
 * A content security policy is only worth having if it can say
 * "run this script and no other". Inline blocks are how injected
 * script normally runs, so each legitimate one carries a random
 * value the attacker cannot predict, and the policy names it.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/**
 * Every security header, in one place.
 *
 * The policy is strict about scripts and relaxed about styles:
 * the app carries inline style attributes throughout, and an
 * injected style cannot exfiltrate a session the way an injected
 * script can. Scripts get 'self' and a nonce, and nothing else.
 *
 * CSP_REPORT_ONLY sends the policy without enforcing it, which is
 * how you roll one out on a live system: watch the reports for a
 * week, fix what breaks, then turn it on.
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header_remove('X-Powered-By');

    // Chart.js is loaded from a CDN when it has not been vendored
    // locally. Naming the host is the price of that; running
    // deploy/vendor-assets.sh removes both the host and the
    // dependency. See docs/DEPLOYMENT.md.
    $scriptHosts = is_file(PUBLIC_PATH . '/assets/vendor/chart.umd.min.js')
        ? ''
        : ' https://cdn.jsdelivr.net';

    $policy = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-" . csp_nonce() . "'" . $scriptHosts,
        // Legacy pages carry their own <style> blocks and a Google
        // Fonts link. Styles cannot read a cookie or call an API.
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
        "font-src 'self' https://fonts.gstatic.com data:",
        "img-src 'self' data: blob:",
        "connect-src 'self'",
        // The modern replacement for X-Frame-Options, and the one
        // browsers actually consult now.
        "frame-ancestors 'none'",
        // A form on this site may only post back to this site,
        // which stops an injected form from harvesting a password.
        "form-action 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ]);

    $header = filter_var(env('CSP_REPORT_ONLY', 'false'), FILTER_VALIDATE_BOOL)
        ? 'Content-Security-Policy-Report-Only'
        : 'Content-Security-Policy';

    $reportTo = trim((string) env('CSP_REPORT_URI', ''));
    if ($reportTo !== '') {
        $policy .= '; report-uri ' . $reportTo;
    }

    header($header . ': ' . $policy);
}

/**
 * Mark a response as private.
 *
 * An invoice must never be cached by a CDN or a shared proxy: the
 * next person through that edge node would be served someone
 * else's document. Every authenticated page says so.
 */
function security_no_store(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }
}

/* ─────────────────────────────────────────────────────────────
 *  Rate limiting
 * ──────────────────────────────────────────────────────────── */

/**
 * Count one hit against a bucket and say whether it is over.
 *
 * A fixed window rather than a sliding one: cruder, but it is one
 * statement, it is shared by every node, and the failure mode of
 * the crude version is that an attacker gets at most twice the
 * quota across a window boundary — which is not the difference
 * between safe and unsafe.
 *
 * @return array{allowed:bool, hits:int, limit:int, retry_after:int}
 */
function rate_limit(string $key, int $limit, int $windowSeconds): array
{
    $bucket = substr('rl:' . $key, 0, 190);

    try {
        // One round trip: insert the bucket, or bump it — resetting
        // when the previous window has already expired.
        $row = db_one(
            "INSERT INTO rate_limits (bucket, hits, window_started_at, expires_at)
             VALUES (:b, 1, NOW(), NOW() + (:w || ' seconds')::INTERVAL)
             ON CONFLICT (bucket) DO UPDATE
                SET hits = CASE WHEN rate_limits.expires_at < NOW() THEN 1
                                ELSE rate_limits.hits + 1 END,
                    window_started_at = CASE WHEN rate_limits.expires_at < NOW() THEN NOW()
                                             ELSE rate_limits.window_started_at END,
                    expires_at = CASE WHEN rate_limits.expires_at < NOW()
                                      THEN NOW() + (:w || ' seconds')::INTERVAL
                                      ELSE rate_limits.expires_at END
             RETURNING hits, GREATEST(CEIL(EXTRACT(EPOCH FROM (expires_at - NOW()))), 0) AS retry_after",
            [':b' => $bucket, ':w' => (string) $windowSeconds]
        );
    } catch (Throwable $e) {
        // A limiter that cannot reach the database must not be the
        // reason the business stops trading. It fails open, loudly.
        error_log('[RATE] ' . $bucket . ' not counted: ' . $e->getMessage());
        return ['allowed' => true, 'hits' => 0, 'limit' => $limit, 'retry_after' => 0];
    }

    $hits = (int) ($row['hits'] ?? 1);
    return [
        'allowed'     => $hits <= $limit,
        'hits'        => $hits,
        'limit'       => $limit,
        'retry_after' => (int) ($row['retry_after'] ?? $windowSeconds),
    ];
}

/**
 * Apply a limit and stop the request when it is exceeded.
 *
 * Answers in the shape the caller expects — JSON to a fetch or an
 * API client, a plain page to a browser — because a limiter that
 * returns something unparseable just looks like a broken app.
 */
function rate_limit_or_stop(string $key, int $limit, int $windowSeconds, string $what = 'requests'): void
{
    $result = rate_limit($key, $limit, $windowSeconds);
    if ($result['allowed']) {
        return;
    }

    if (!headers_sent()) {
        header('Retry-After: ' . $result['retry_after']);
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: 0');
    }
    audit_log('security.rate_limited', 'rate_limits', null, [
        'bucket' => $key, 'hits' => $result['hits'], 'limit' => $limit,
    ]);

    $message = 'Too many ' . $what . '. Please wait '
             . max(1, (int) ceil($result['retry_after'] / 60)) . ' minute(s) and try again.';

    if (function_exists('wants_json') && wants_json()) {
        json_response(['success' => false, 'message' => $message], 429);
    }
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

/* ─────────────────────────────────────────────────────────────
 *  Request firewall
 * ──────────────────────────────────────────────────────────── */

/**
 * The last-resort filter, in front of everything.
 *
 * This is deliberately *not* a pattern-matching WAF. An ERP is
 * full of text that looks like an attack — a product called
 * "1/2\" union", a note reading "SELECT the blue one" — and a
 * filter that guesses will eventually refuse a real invoice,
 * which is worse than useless because people then turn it off.
 * Signature matching belongs at the edge, where it can be tuned
 * and watched: deploy/modsecurity-erp.conf does that job.
 *
 * What is enforced here is only what is never legitimate:
 *
 *   - a request method the application does not implement
 *   - a Host header that is not ours (cache poisoning, poisoned
 *     password-reset links)
 *   - null bytes anywhere in the input, which exist only to cut a
 *     string short inside something that later opens a file
 *   - traversal sequences in a parameter that names a file
 *   - a body or a header set far larger than any real form
 *
 * Everything else is left to the layers that can judge it: bound
 * parameters for SQL, escaping on output for HTML.
 */
function security_firewall(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD', 'POST', 'OPTIONS'], true)) {
        security_block(405, 'Method not allowed.');
    }

    // A Host we do not answer to. Checked before anything reads it.
    if (env('APP_HOSTS', '') !== '' && security_safe_host() === null) {
        security_block(400, 'Unrecognised host.');
    }

    // Null bytes: never typed by anyone, and the classic way to
    // truncate a path inside a function that opens files.
    if (security_has_null_byte($_GET) || security_has_null_byte($_POST) || security_has_null_byte($_COOKIE)) {
        security_block(400, 'Malformed request.');
    }

    // Traversal in anything that names a file or a path.
    foreach (['file', 'path', 'template', 'page_file', 'download', 'doc', 'include'] as $key) {
        $value = $_GET[$key] ?? $_POST[$key] ?? null;
        if (is_string($value) && (str_contains($value, '../') || str_contains($value, '..\\'))) {
            security_block(400, 'Malformed request.');
        }
    }

    // Absurd headers. Real browsers do not send 16 KB of cookie.
    $headerBytes = 0;
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with((string) $k, 'HTTP_') && is_string($v)) {
            $headerBytes += strlen($v);
        }
    }
    if ($headerBytes > 32768) {
        security_block(431, 'Request headers too large.');
    }

    // An oversized body, where the web server has not already
    // stopped it. Uploads are excluded: they are checked properly,
    // by type and size, in includes/uploads.php.
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $isUpload = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
    if (!$isUpload && $length > 1048576) {
        security_block(413, 'Request too large.');
    }
}

/** Recursively look for a null byte in submitted input. */
function security_has_null_byte($value): bool
{
    if (is_string($value)) {
        return str_contains($value, "\0");
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            if ((is_string($k) && str_contains($k, "\0")) || security_has_null_byte($v)) {
                return true;
            }
        }
    }
    return false;
}

/** Refuse a request, recording why. */
function security_block(int $status, string $message): void
{
    if (function_exists('audit_log')) {
        audit_log('security.blocked', '', null, [
            'status' => $status,
            'reason' => $message,
            'path'   => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
            'ip'     => client_ip(),
        ]);
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

/* ─────────────────────────────────────────────────────────────
 *  Housekeeping
 * ──────────────────────────────────────────────────────────── */

/**
 * Expire what has expired.
 *
 * Run on roughly one request in two hundred, which on any real
 * traffic is often enough and costs nothing noticeable. A cron
 * entry calling `SELECT security_gc();` is better, and this is
 * what keeps a system without one from growing forever.
 */
function security_gc_maybe(): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }
    try {
        db_run('SELECT security_gc(:idle)', [
            ':idle' => (SESSION_ABSOLUTE_LIFETIME + 3600) . ' seconds',
        ]);
    } catch (Throwable $e) {
        error_log('[GC] housekeeping skipped: ' . $e->getMessage());
    }
}
