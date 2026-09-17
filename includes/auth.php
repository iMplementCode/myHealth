<?php

/**
 * ============================================================
 *  Authentication & Authorization
 * ------------------------------------------------------------
 *  Secure session handling, login/logout, login-attempt
 *  throttling, "remember me", the currently authenticated
 *  user, and role-based access control (RBAC).
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


/* ─────────────────────────────────────────────────────────────
 *  Secure session start
 * ──────────────────────────────────────────────────────────── */

/** Start a hardened session and enforce idle timeout + id rotation. */
function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    /*  Refuse a session id the browser invented.
     *
     *  Without strict mode PHP will happily adopt any id it is handed
     *  and create a session under it — so an attacker can fix a known
     *  id in a victim's browser (a link, an XSS, a shared machine) and
     *  then use that same id once the victim signs in. Logging in
     *  regenerates the id here, which closes most of it, but this is
     *  the setting that actually makes the id unguessable-or-nothing.
     *
     *  Set before session_start(), which is the only time it counts. */
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // The id never belongs in a URL, where it would leak through
    // Referer headers, browser history and server logs.
    ini_set('session.use_trans_sid', '0');
    // sid_length and sid_bits_per_character are deprecated as of PHP
    // 8.4 and setting them only fills the error log; the defaults
    // already give 128 bits of entropy.

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,            // session cookie (cleared on browser close)
        'path'     => '/',
        'httponly' => true,         // not readable by JavaScript
        'secure'   => $secure,      // HTTPS-only when available
        'samesite' => 'Lax',        // CSRF hardening for top-level navigations
    ]);
    session_start();

    // Bind the session to the client that created it. A cookie lifted
    // from one browser and replayed in another no longer works, which
    // is the main thing a stolen session id buys an attacker.
    if (!empty($_SESSION['user_id']) && !auth_fingerprint_matches()) {
        auth_logout();
        auth_start_session();
        flash('error', 'Your session could not be verified. Please sign in again.');
        return;
    }

    // Try to restore a "remember me" session before timeout checks.
    if (empty($_SESSION['user_id'])) {
        auth_try_remember();
    }

    $now = time();

    // Absolute lifetime: a session cannot be kept alive indefinitely
    // by staying busy, so a compromised one has a hard expiry.
    if (!empty($_SESSION['started_at']) && ($now - $_SESSION['started_at']) > SESSION_ABSOLUTE_LIFETIME) {
        auth_logout();
        auth_start_session();
        flash('info', 'Please sign in again to continue.');
        return;
    }

    // Idle timeout.
    if (!empty($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        auth_logout();
        auth_start_session(); // fresh session for the flash message
        flash('info', 'Your session expired due to inactivity. Please sign in again.');
        return;
    }
    $_SESSION['last_activity'] = $now;

    // Periodically rotate the session id to limit fixation risk.
    if (empty($_SESSION['created'])) {
        $_SESSION['created'] = $now;
    } elseif (($now - $_SESSION['created']) > SESSION_REGENERATE) {
        session_regenerate_id(true);
        $_SESSION['created'] = $now;
    }
}

/**
 * A stable fingerprint of the client, used to bind a session to the
 * browser that signed in.
 *
 * Only the user agent and the network prefix are used: a full IP match
 * would sign people out every time a phone moves between cell towers
 * or Wi-Fi, while the /24 (or IPv6 /48) still rules out replay from an
 * unrelated network.
 */
function auth_fingerprint(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (str_contains($ip, ':')) {
        $parts = explode(':', $ip);
        $net = implode(':', array_slice($parts, 0, 3));      // IPv6 /48
    } else {
        $parts = explode('.', $ip);
        $net = implode('.', array_slice($parts, 0, 3));      // IPv4 /24
    }

    return hash('sha256', $ua . '|' . $net);
}

/** True when the current request matches the session's fingerprint. */
function auth_fingerprint_matches(): bool
{
    if (empty($_SESSION['fingerprint'])) {
        // Session predates fingerprinting — adopt the current one
        // rather than signing everybody out on deploy.
        $_SESSION['fingerprint'] = auth_fingerprint();
        return true;
    }
    return hash_equals($_SESSION['fingerprint'], auth_fingerprint());
}

/* ─────────────────────────────────────────────────────────────
 *  Login / logout
 * ──────────────────────────────────────────────────────────── */

/**
 * Attempt to authenticate with an identifier (email OR username)
 * and a password. Returns ['success' => bool, 'message' => string].
 */
function auth_attempt(string $identifier, string $password, bool $remember = false): array
{
    $identifier = strtolower(trim($identifier));

    // Two locks, because they stop two different attacks: one
    // password tried against a thousand accounts, and a thousand
    // passwords tried against one. Both are counted in the
    // database, where the person guessing cannot reach them.
    if (auth_is_locked_out($identifier)) {
        audit_log('auth.locked_out', 'users', null, [
            'identifier' => $identifier, 'ip' => client_ip(),
        ]);
        return [
            'success' => false,
            'message' => 'Too many failed sign-in attempts. Please wait '
                       . (int) ceil(LOGIN_LOCKOUT_TIME / 60) . ' minutes and try again.',
        ];
    }

    $user = db_one(
        "SELECT u.user_id, u.first_name, u.last_name, u.email, u.username, u.password_hash,
                u.is_active, u.role_id, r.name AS role_name
         FROM users u
         LEFT JOIN roles r ON r.role_id = u.role_id
         WHERE LOWER(u.email) = :id OR LOWER(u.username) = :id",
        [':id' => $identifier]
    );

    // Uniform failure message — never reveal whether the email exists.
    $genericFail = ['success' => false, 'message' => 'Invalid email or password.'];

    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Verify against a dummy hash when the account does not
        // exist, so a missing user and a wrong password take the
        // same time to answer. Otherwise the response time alone
        // tells an attacker which email addresses are real.
        if (!$user) {
            password_verify($password, '$2y$12$usesomesillystringfoeswvUDcx7kOOxLhCFRThJEyd3PpZ4KTNGq');
        }
        auth_register_failure($identifier);
        return $genericFail;
    }

    if (!$user['is_active']) {
        auth_register_failure($identifier);
        return ['success' => false, 'message' => 'This account has been deactivated. Contact an administrator.'];
    }

    // Transparently upgrade legacy hashes when the algorithm changes.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        db_run("UPDATE users SET password_hash = :h WHERE user_id = :id", [':h' => $newHash, ':id' => $user['user_id']]);
    }

    // The password was right, so the lockout counter is cleared here
    // and not after the second factor: those counters exist to stop
    // password guessing, and the guessing has stopped.
    auth_reset_failures($identifier);

    /*  The second factor, when it is switched on.
     *
     *  Note what does NOT happen: auth_establish_session() is not
     *  called. There is no session, no signed-in user, and nothing
     *  for a page to have to remember to check. What the browser
     *  gets is a pending state that buys one thing — the right to
     *  present a code — and every other page in the application
     *  goes on treating this person as a stranger, without knowing
     *  that two-factor exists.
     *
     *  "remember me" is carried across rather than honoured now; it
     *  is issued on the other side of the code, in two_factor.php,
     *  because a remember-token handed out here would be a way past
     *  the code on the next visit.                                */
    if (two_factor_required()) {
        $started = two_factor_begin($user);
        if (!$started['sent']) {
            return ['success' => false, 'message' => $started['message']];
        }
        $_SESSION[TWO_FACTOR_SESSION_KEY]['remember'] = $remember;
        return [
            'success'     => false,
            'two_factor'  => true,
            'message'     => $started['message'],
        ];
    }

    auth_establish_session($user);
    audit_log('auth.login', 'users', $user['user_id'], ['ip' => client_ip()]);

    // Record last login (best effort — never block login on failure).
    try {
        db_run("UPDATE users SET last_login = NOW() WHERE user_id = :id", [':id' => $user['user_id']]);
    } catch (Throwable $e) {
        error_log('[AUTH] last_login update failed: ' . $e->getMessage());
    }

    if ($remember) {
        auth_issue_remember_token((int) $user['user_id']);
    }

    return ['success' => true, 'message' => 'Signed in successfully.'];
}

/** Populate the session for an authenticated user and rotate the id. */
function auth_establish_session(array $user): void
{
    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user_id']    = (int) $user['user_id'];
    $_SESSION['user_name']  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['role_id']    = $user['role_id'] !== null ? (int) $user['role_id'] : null;
    $_SESSION['role_name']  = $user['role_name'] ?? null;
    $_SESSION['created']     = time();
    $_SESSION['started_at']  = time();          // drives the absolute lifetime
    $_SESSION['last_activity'] = time();
    // Bind this session to the browser it was created in.
    $_SESSION['fingerprint'] = auth_fingerprint();
}

/** Destroy the current session and any remember-me token/cookie. */
function auth_logout(): void
{
    if (!empty($_SESSION['user_id'])) {
        audit_log('auth.logout', 'users', $_SESSION['user_id']);
    }
    auth_clear_remember_token();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    // Forget the incoming cookie too. A session started after this
    // one — the sign-out page starts one to carry its message —
    // otherwise adopts the id still sitting in $_COOKIE, sends no
    // Set-Cookie of its own, and the deletion queued above wins.
    // The message is then written to a session the browser has just
    // been told to throw away, and nobody ever sees it.
    unset($_COOKIE[session_name()]);
}

/* ─────────────────────────────────────────────────────────────
 *  Login attempt throttling
 * ────────────────────────────────────────────────────────────
 *  This used to be counted in the session — that is, in a cookie
 *  the attacker holds. Discarding the cookie between attempts
 *  reset the counter, so the lockout stopped nobody at all and
 *  only ever inconvenienced the person who had genuinely
 *  forgotten their password.
 *
 *  Attempts are now recorded in the database and counted two
 *  ways, because there are two attacks:
 *
 *    per account   many passwords against one name
 *    per address   one password against many names, which is
 *                  what credential-stuffing actually looks like
 *
 *  The per-address limit is deliberately looser: a whole office
 *  behind one router shares an address, and locking out the
 *  building because one person fat-fingered their password five
 *  times would be its own kind of outage.
 * ──────────────────────────────────────────────────────────── */

/*  Attempts from one address before it is held off, per window.
 *
 *  Zero means there is no per-address ceiling — not "no attempts
 *  allowed". Read the other way round, a variable somebody set to 0
 *  meaning "turn this off" would bar every person in the company
 *  from signing in, from the first attempt, with a lockout message
 *  and no way through it. That is the worst failure this file can
 *  produce, and it must not be reachable by a typo.                */
define('LOGIN_MAX_ATTEMPTS_PER_IP', max(0, (int) env('LOGIN_MAX_ATTEMPTS_PER_IP', '20')));

function auth_register_failure(string $identifier = ''): void
{
    try {
        db_run(
            "INSERT INTO login_attempts (identifier, ip_address, successful, user_agent)
             VALUES (:id, :ip, FALSE, :ua)",
            [
                ':id' => $identifier !== '' ? mb_substr($identifier, 0, 190) : null,
                ':ip' => mb_substr(client_ip(), 0, 45),
                ':ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
            ]
        );
    } catch (Throwable $e) {
        // Pre-migration, or the database is unwell. Fall back to the
        // session counter so there is still *some* brake on guessing.
        error_log('[AUTH] attempt not recorded: ' . $e->getMessage());
        $_SESSION['login_attempts']  = ($_SESSION['login_attempts'] ?? 0) + 1;
        $_SESSION['login_last_fail'] = time();
    }
}

function auth_reset_failures(string $identifier = ''): void
{
    unset($_SESSION['login_attempts'], $_SESSION['login_last_fail']);
    try {
        // A successful sign-in clears the slate for that account
        // from this address. Failures against it from elsewhere are
        // left standing — they are somebody else's attempts.
        db_run(
            "DELETE FROM login_attempts
              WHERE ip_address = :ip AND (identifier = :id OR :id IS NULL) AND NOT successful",
            [':ip' => mb_substr(client_ip(), 0, 45), ':id' => $identifier !== '' ? $identifier : null]
        );
        db_run(
            "INSERT INTO login_attempts (identifier, ip_address, successful, user_agent)
             VALUES (:id, :ip, TRUE, :ua)",
            [
                ':id' => $identifier !== '' ? mb_substr($identifier, 0, 190) : null,
                ':ip' => mb_substr(client_ip(), 0, 45),
                ':ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
            ]
        );
    } catch (Throwable $e) {
        error_log('[AUTH] attempts not cleared: ' . $e->getMessage());
    }
}

/**
 * Whether sign-in is currently barred for this account or this
 * address.
 *
 * Fails *open* when the database cannot answer: a business must
 * not be locked out of its own books because a table is missing.
 * The session counter still applies in that case.
 */
function auth_is_locked_out(string $identifier = ''): bool
{
    try {
        $row = db_one(
            "SELECT
                COUNT(*) FILTER (WHERE identifier = :id) AS by_account,
                COUNT(*) FILTER (WHERE ip_address = :ip) AS by_address
               FROM login_attempts
              WHERE NOT successful
                AND attempted_at > NOW() - (:win || ' seconds')::INTERVAL
                AND (identifier = :id OR ip_address = :ip)",
            [
                ':id'  => $identifier !== '' ? $identifier : null,
                ':ip'  => mb_substr(client_ip(), 0, 45),
                ':win' => (string) LOGIN_LOCKOUT_TIME,
            ]
        );
    } catch (Throwable $e) {
        error_log('[AUTH] lockout check unavailable: ' . $e->getMessage());
        // Session fallback, as it was before.
        $attempts = $_SESSION['login_attempts'] ?? 0;
        return $attempts >= LOGIN_MAX_ATTEMPTS
            && (time() - ($_SESSION['login_last_fail'] ?? 0)) < LOGIN_LOCKOUT_TIME;
    }

    return (int) ($row['by_account'] ?? 0) >= LOGIN_MAX_ATTEMPTS
        || (LOGIN_MAX_ATTEMPTS_PER_IP > 0
            && (int) ($row['by_address'] ?? 0) >= LOGIN_MAX_ATTEMPTS_PER_IP);
}

/* ─────────────────────────────────────────────────────────────
 *  Password policy
 * ────────────────────────────────────────────────────────────
 *  Length does more for a password than any composition rule,
 *  which is why the floor is twelve characters and the only
 *  other requirements are the ones that catch a genuinely bad
 *  choice: something in every breach list, or the person's own
 *  name and email typed back at them.
 *
 *  Deliberately no forced expiry and no "must contain a symbol".
 *  Both push people towards Passw0rd!1, Passw0rd!2, written on a
 *  sticky note — guidance every serious body now advises
 *  against, NIST included.
 * ──────────────────────────────────────────────────────────── */

define('PASSWORD_MIN_LENGTH', (int) env('PASSWORD_MIN_LENGTH', '12'));

/** The passwords that get tried first, every time. */
const PASSWORD_DENYLIST = [
    'password', 'password1', 'password123', 'passw0rd', '12345678', '123456789',
    '1234567890', 'qwertyuiop', 'qwerty123', 'letmein', 'welcome', 'welcome123',
    'admin', 'admin123', 'administrator', 'iloveyou', 'monkey', 'dragon',
    'football', 'baseball', 'sunshine', 'princess', 'changeme', 'secret',
    'implement', 'implement123', 'runai', 'runai123', 'nairobi', 'kenya123',
];

/**
 * Check a proposed password.
 *
 * @return string|null The reason it was refused, or null if fine.
 */
function password_policy_check(string $password, array $personal = []): ?string
{
    $length = mb_strlen($password);

    if ($length < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters. '
             . 'A short phrase you will remember beats a short word you will not.';
    }
    if ($length > 200) {
        return 'Password must be 200 characters or fewer.';
    }

    $flat = strtolower(preg_replace('/[^a-z0-9]/i', '', $password) ?? '');
    foreach (PASSWORD_DENYLIST as $bad) {
        if ($flat === $bad || str_starts_with($flat, $bad)) {
            return 'That password is among the first an attacker tries. Choose something else.';
        }
    }

    // The user's own details, which are public knowledge to anyone
    // who has seen an email from them.
    foreach ($personal as $hint) {
        $hint = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $hint) ?? '');
        if ($hint !== '' && mb_strlen($hint) >= 4 && str_contains($flat, $hint)) {
            return 'Password must not contain your name or email address.';
        }
    }

    // One character repeated, or a straight run off the keyboard.
    if (preg_match('/^(.)\1+$/u', $password)) {
        return 'Password cannot be a single character repeated.';
    }
    if (str_contains('abcdefghijklmnopqrstuvwxyz', $flat) || str_contains('01234567890', $flat)) {
        return 'Password cannot be a straight run of letters or numbers.';
    }

    return null;
}

/* ─────────────────────────────────────────────────────────────
 *  Remember me (DB-backed selector/validator tokens)
 * ──────────────────────────────────────────────────────────── */

define('REMEMBER_COOKIE', 'implement_erp_remember');
define('REMEMBER_LIFETIME', 60 * 60 * 24 * 30); // 30 days

function auth_issue_remember_token(int $userId, ?string $selector = null): void
{
    try {
        $selector  = $selector ?? bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $validator);
        $expires   = date('Y-m-d H:i:s', time() + REMEMBER_LIFETIME);

        // Reusing the selector rotates the token in place, which is
        // what makes replay detectable: one selector, one validator,
        // and a mismatch means two browsers hold the same cookie.
        db_run(
            "INSERT INTO remember_tokens (selector, validator_hash, user_id, expires_at, last_used_at, ip_address)
             VALUES (:s, :v, :u, :e, NOW(), :ip)
             ON CONFLICT (selector) DO UPDATE
                SET validator_hash = EXCLUDED.validator_hash,
                    expires_at     = EXCLUDED.expires_at,
                    last_used_at   = NOW(),
                    ip_address     = EXCLUDED.ip_address",
            [
                ':s' => $selector, ':v' => $hash, ':u' => $userId, ':e' => $expires,
                ':ip' => mb_substr(client_ip(), 0, 45),
            ]
        );

        setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + REMEMBER_LIFETIME,
            'path'     => '/',
            'httponly' => true,
            // Behind a load balancer the connection to PHP is plain
            // HTTP even though the visitor is on HTTPS. Asking the
            // superglobal directly got this wrong and sent a
            // thirty-day credential without the Secure flag.
            'secure'   => request_is_https(),
            'samesite' => 'Lax',
        ]);
    } catch (Throwable $e) {
        // Remember-me is optional; never let it break login.
        error_log('[AUTH] remember token issue failed: ' . $e->getMessage());
    }
}

/**
 * Sign in from a remember-me cookie, and rotate it.
 *
 * The cookie is a thirty-day credential sitting on a laptop, so it
 * is treated as one:
 *
 *   - it is replaced on every use. A copy taken yesterday stops
 *     working the moment the real owner opens the app.
 *   - a selector that exists with the wrong validator means
 *     exactly that copy being used. There is no innocent
 *     explanation, so every token that user holds is destroyed
 *     and both of them have to sign in again — the legitimate one
 *     with their password, the thief with a password they do not
 *     have.
 */
function auth_try_remember(): void
{
    if (empty($_COOKIE[REMEMBER_COOKIE]) || !str_contains($_COOKIE[REMEMBER_COOKIE], ':')) {
        return;
    }
    [$selector, $validator] = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);

    try {
        $token = db_one(
            "SELECT t.*, u.user_id, u.first_name, u.last_name, u.email, u.is_active, u.role_id, r.name AS role_name
             FROM remember_tokens t
             JOIN users u ON u.user_id = t.user_id
             LEFT JOIN roles r ON r.role_id = u.role_id
             WHERE t.selector = :s AND t.expires_at > NOW()",
            [':s' => $selector]
        );
    } catch (Throwable $e) {
        return; // table may not exist yet (pre-migration) — fail silently
    }

    if (!$token) {
        auth_clear_remember_token();
        return;
    }

    if (!hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        // A known selector with a stale validator: the cookie has
        // been copied. Burn the whole family.
        try {
            db_run("DELETE FROM remember_tokens WHERE user_id = :u", [':u' => $token['user_id']]);
        } catch (Throwable $e) {
            error_log('[AUTH] token family not revoked: ' . $e->getMessage());
        }
        audit_log('auth.remember_token_reuse', 'users', $token['user_id'], ['ip' => client_ip()]);
        auth_clear_remember_token();
        return;
    }

    if (!$token['is_active']) {
        auth_clear_remember_token();
        return;
    }

    auth_establish_session($token);
    auth_issue_remember_token((int) $token['user_id'], $selector);
    audit_log('auth.login_remembered', 'users', $token['user_id'], ['ip' => client_ip()]);
}

function auth_clear_remember_token(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE]) && str_contains($_COOKIE[REMEMBER_COOKIE], ':')) {
        [$selector] = explode(':', $_COOKIE[REMEMBER_COOKIE], 2);
        try {
            db_run("DELETE FROM remember_tokens WHERE selector = :s", [':s' => $selector]);
        } catch (Throwable $e) {
            // ignore
        }
    }
    setcookie(REMEMBER_COOKIE, '', time() - 42000, '/');
}

/* ─────────────────────────────────────────────────────────────
 *  Current user / access control
 * ──────────────────────────────────────────────────────────── */

/** True when a user is authenticated. */
function auth_check(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Return a lightweight array describing the current user, or null. */
function current_user(): ?array
{
    if (!auth_check()) {
        return null;
    }
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'role_id'   => $_SESSION['role_id'] ?? null,
        'role'  => $_SESSION['role_name'] ?? null,
    ];
}

/** The current user's role name, or null. */
function current_role(): ?string
{
    return $_SESSION['role_name'] ?? null;
}

/** True when the current user holds any of the given role names. */
function user_has_role(string ...$roles): bool
{
    $role = current_role();
    return $role !== null && in_array($role, $roles, true);
}

/** Administrators implicitly have every permission. */
function is_admin(): bool
{
    return current_role() === ROLE_ADMIN;
}

/**
 * Guard: require an authenticated user. Redirects to the login
 * page (remembering the intended destination) when not signed in.
 */
function require_login(): void
{
    if (auth_check()) {
        return;
    }
    // A fetch cannot follow a redirect to the sign-in form usefully:
    // it gets the login page as its "reply", fails to parse it as
    // JSON, and the user is told "Request failed" when what really
    // happened is that their session timed out.
    if (wants_json()) {
        json_response([
            'success' => false,
            'message' => 'Your session has expired. Reload the page and sign in again.',
        ], 401);
    }
    $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? url('dashboard/index.php');
    flash('info', 'Please sign in to continue.');
    redirect('login.php');
}

/**
 * Guard: require one of the given roles. Administrators always pass.
 *
 * Sends a 403 page to a browser and a 403 JSON body to a fetch, for
 * the same reason require_login() does: an AJAX caller handed an
 * HTML page has no way to tell the user what went wrong.
 */
function require_role(string ...$roles): void
{
    require_login();
    if (is_admin() || user_has_role(...$roles)) {
        return;
    }
    if (wants_json()) {
        json_response([
            'success' => false,
            'message' => 'You do not have permission to do that. Ask an administrator for the '
                         . implode(' or ', $roles) . ' role.',
        ], 403);
    }
    http_response_code(403);
    include BASE_PATH . '/includes/403.php';
    exit;
}
