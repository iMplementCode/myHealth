<?php

/**
 * ============================================================
 *  Two-factor sign-in, by email
 * ------------------------------------------------------------
 *  The password is checked as it always was. When it is right,
 *  the session is NOT started. Instead a six-digit code goes to
 *  the address on the account, and the browser is left holding
 *  a pending state that can do exactly one thing: present that
 *  code.
 *
 *  The distinction matters more than it looks. "Signed in, but
 *  we will ask for a code before letting you do anything" is a
 *  session an attacker already holds, and every page then has
 *  to remember to check. "Not signed in, and here is a ticket
 *  that buys nothing but a code check" cannot be forgotten by a
 *  page that does not know it exists.
 *
 *  ── What stops the obvious attacks ──────────────────────────
 *  guessing        five attempts per code, then it is dead
 *  reusing         consumed on first success, checked before use
 *  waiting         ten minutes, then it is dead
 *  reading the DB  codes are hashed, like passwords
 *  someone else's  the code is looked up by the pending user id,
 *                  never by the code, so a code cannot be
 *                  presented against another account
 *  flooding        a resend cooldown, and the sign-in rate limit
 *                  in front of all of it
 *
 *  ── Being locked out is the real risk ───────────────────────
 *  A shop that cannot invoice because a mail server changed its
 *  password is worse off than one running on passwords alone.
 *  So: this is off unless TWO_FACTOR_ENABLED says otherwise, it
 *  refuses to switch itself on when mail is not configured, and
 *  the flag is an environment variable precisely so it can be
 *  turned off at two in the morning without a deploy.
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

/** Where the half-finished sign-in lives. */
const TWO_FACTOR_SESSION_KEY = 'pending_2fa';

/**
 * Should this sign-in ask for a code?
 *
 * Two conditions, and the second is not a formality. Turning the
 * flag on without a mail server configured would refuse every
 * sign-in in the building, so that combination is treated as "not
 * enabled" and says so in the log — loudly, because a security
 * control that silently did not apply is worse than one that was
 * never switched on.
 */
function two_factor_required(): bool
{
    if (!filter_var(env('TWO_FACTOR_ENABLED', 'false'), FILTER_VALIDATE_BOOL)) {
        return false;
    }
    if (!table_exists('login_codes')) {
        error_log('[2FA] TWO_FACTOR_ENABLED is on but the login_codes table does not '
                . 'exist — run the migrations. Sign-in is proceeding WITHOUT a code.');
        return false;
    }
    if (!mail_is_configured()) {
        error_log('[2FA] TWO_FACTOR_ENABLED is on but mail is not configured ('
                . implode('; ', mail_config_problems()) . '). Sign-in is proceeding '
                . 'WITHOUT a code, because refusing every sign-in would lock the '
                . 'business out. Configure SMTP and check with deploy/check-mail.php.');
        return false;
    }
    return true;
}

/** A six-digit code, from the generator used for keys and tokens. */
function two_factor_new_code(): string
{
    return str_pad((string) random_int(0, 999999), TWO_FACTOR_CODE_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Issue a code for a user whose password has already been checked,
 * email it, and leave the browser in the pending state.
 *
 * @return array{sent: bool, message: string}
 */
function two_factor_begin(array $user): array
{
    $email = trim((string) ($user['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('[2FA] user ' . (int) $user['user_id'] . ' has no usable email address');
        return ['sent' => false,
                'message' => 'Your account has no email address to send a sign-in code to. '
                           . 'Please ask an administrator.'];
    }

    $code = two_factor_new_code();

    /*  Any code still live for this person is retired first. Two
     *  valid codes at once doubles what a guess is worth, and the
     *  second email arriving makes the first one look expired to
     *  whoever is reading it. */
    db_run("UPDATE login_codes SET consumed_at = NOW()
             WHERE user_id = :u AND consumed_at IS NULL",
           [':u' => (int) $user['user_id']]);

    db_run(
        "INSERT INTO login_codes (user_id, code_hash, expires_at, sent_to, ip_address)
         VALUES (:u, :h, NOW() + (:ttl || ' seconds')::interval, :to, :ip)",
        [
            ':u'   => (int) $user['user_id'],
            ':h'   => password_hash($code, PASSWORD_DEFAULT),
            ':ttl' => (string) TWO_FACTOR_CODE_TTL,
            ':to'  => $email,
            ':ip'  => client_ip(),
        ]
    );

    $sent = two_factor_send($user, $email, $code);

    // The code is gone from memory either way; nothing below ever
    // sees it again, which is the point of hashing it above.
    unset($code);

    if (!$sent['sent']) {
        /*  Undo the pending state rather than leaving somebody at a
         *  code box for a code that is not coming. The reason goes
         *  to the log, not to the screen. */
        db_run("UPDATE login_codes SET consumed_at = NOW()
                 WHERE user_id = :u AND consumed_at IS NULL",
               [':u' => (int) $user['user_id']]);
        audit_log('auth.2fa_send_failed', 'users', (int) $user['user_id'],
                  ['ip' => client_ip(), 'error' => $sent['error']]);
        return ['sent' => false,
                'message' => 'We could not send your sign-in code. Please try again, '
                           . 'or contact an administrator if it keeps happening.'];
    }

    $_SESSION[TWO_FACTOR_SESSION_KEY] = [
        'user_id'  => (int) $user['user_id'],
        'started'  => time(),
        'resent'   => time(),
        'remember' => false,
    ];

    audit_log('auth.2fa_issued', 'users', (int) $user['user_id'],
              ['ip' => client_ip(), 'to' => two_factor_mask($email)]);

    return ['sent' => true, 'message' => 'We have emailed you a sign-in code.'];
}

/** The email itself. */
function two_factor_send(array $user, string $email, string $code): array
{
    $name    = trim((string) ($user['first_name'] ?? ''));
    $minutes = (int) ceil(TWO_FACTOR_CODE_TTL / 60);
    $company = company_settings()['company_name'] ?? APP_NAME;

    $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    /*  Table-based and inline-styled, because an email client is
     *  not a browser: half of them strip <style> blocks and most
     *  ignore anything clever about layout. The code is large and
     *  spaced because it is going to be read off one screen and
     *  typed into another. */
    $html = '
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5fa;padding:24px 0;font-family:Helvetica,Arial,sans-serif;">
  <tr><td align="center">
    <table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #dcdfe9;border-radius:8px;">
      <tr><td style="padding:26px 30px 8px;font-size:18px;font-weight:bold;color:#191c28;">
        ' . $esc($company) . '
      </td></tr>
      <tr><td style="padding:0 30px;font-size:14px;color:#3a3f55;line-height:1.6;">
        ' . ($name !== '' ? 'Hello ' . $esc($name) . ',<br><br>' : '') . '
        Here is the code to finish signing in.
      </td></tr>
      <tr><td align="center" style="padding:22px 30px;">
        <div style="font-size:34px;font-weight:bold;letter-spacing:10px;color:#191c28;
                    background:#f4f5fa;border:1px solid #dcdfe9;border-radius:6px;
                    padding:16px 10px;">' . $esc($code) . '</div>
      </td></tr>
      <tr><td style="padding:0 30px 8px;font-size:13px;color:#5a5f76;line-height:1.6;">
        It expires in ' . $minutes . ' minutes and can be used once.
      </td></tr>
      <tr><td style="padding:14px 30px 28px;font-size:13px;color:#5a5f76;line-height:1.6;
                     border-top:1px solid #e7e9f1;">
        <strong>If you did not just try to sign in,</strong> somebody else has your
        password. Change it as soon as you can, and tell whoever looks after this
        system.
      </td></tr>
    </table>
  </td></tr>
</table>';

    $text = "Your sign-in code is: $code\n\n"
          . "It expires in $minutes minutes and can be used once.\n\n"
          . "If you did not just try to sign in, somebody else has your password. "
          . "Change it as soon as you can, and tell whoever looks after this system.\n";

    return mail_send($email, 'Your sign-in code: ' . $code, $html, $text);
}

/** The half-finished sign-in, or null. */
function two_factor_pending(): ?array
{
    $pending = $_SESSION[TWO_FACTOR_SESSION_KEY] ?? null;
    if (!is_array($pending) || empty($pending['user_id'])) {
        return null;
    }
    // A pending sign-in nobody finished is not left lying about.
    if (time() - (int) ($pending['started'] ?? 0) > TWO_FACTOR_CODE_TTL + 60) {
        two_factor_abandon();
        return null;
    }
    return $pending;
}

/** Forget the half-finished sign-in. */
function two_factor_abandon(): void
{
    unset($_SESSION[TWO_FACTOR_SESSION_KEY]);
}

/**
 * Check a code and, if it is right, finish signing the person in.
 *
 * @return array{success: bool, message: string}
 */
function two_factor_verify(string $given): array
{
    $pending = two_factor_pending();
    if (!$pending) {
        return ['success' => false,
                'message' => 'That sign-in timed out. Please enter your password again.'];
    }
    $userId = (int) $pending['user_id'];

    /*  Looked up by the pending user, never by the code. Searching
     *  for a row that matches the digits would let somebody present
     *  a code that was issued to a different account entirely. */
    $row = db_one(
        "SELECT code_id, code_hash, attempts, expires_at, consumed_at
           FROM login_codes
          WHERE user_id = :u AND consumed_at IS NULL AND expires_at > NOW()
          ORDER BY code_id DESC LIMIT 1",
        [':u' => $userId]
    );

    $given = preg_replace('/\D+/', '', $given) ?? '';

    if (!$row) {
        audit_log('auth.2fa_failed', 'users', $userId,
                  ['ip' => client_ip(), 'why' => 'no live code']);
        return ['success' => false,
                'message' => 'That code has expired. Ask for a new one below.'];
    }

    if ((int) $row['attempts'] >= TWO_FACTOR_MAX_ATTEMPTS) {
        db_run("UPDATE login_codes SET consumed_at = NOW() WHERE code_id = :c",
               [':c' => (int) $row['code_id']]);
        two_factor_abandon();
        audit_log('auth.2fa_burned', 'users', $userId, ['ip' => client_ip()]);
        return ['success' => false,
                'message' => 'Too many wrong codes. Please enter your password again.'];
    }

    // Counted before it is checked. A process killed mid-verify
    // must not hand back a free guess.
    db_run("UPDATE login_codes SET attempts = attempts + 1 WHERE code_id = :c",
           [':c' => (int) $row['code_id']]);

    if ($given === '' || !password_verify($given, $row['code_hash'])) {
        $left = TWO_FACTOR_MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
        audit_log('auth.2fa_failed', 'users', $userId,
                  ['ip' => client_ip(), 'why' => 'wrong code', 'left' => $left]);
        return ['success' => false, 'message' => $left > 0
            ? 'That code is not right. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.'
            : 'That code is not right, and that was the last attempt.'];
    }

    // Right. Spend it before the session exists, so a failure from
    // here on cannot leave a code that still works.
    db_run("UPDATE login_codes SET consumed_at = NOW() WHERE code_id = :c",
           [':c' => (int) $row['code_id']]);

    $user = db_one(
        "SELECT u.user_id, u.first_name, u.last_name, u.email, u.username,
                u.is_active, u.role_id, r.name AS role_name
           FROM users u LEFT JOIN roles r ON r.role_id = u.role_id
          WHERE u.user_id = :id",
        [':id' => $userId]
    );
    // Deactivated between the password and the code. Rare, and
    // exactly the case where it must not be ignored.
    if (!$user || !$user['is_active']) {
        two_factor_abandon();
        return ['success' => false,
                'message' => 'This account is no longer active. Contact an administrator.'];
    }

    $remember = !empty($pending['remember']);
    two_factor_abandon();
    auth_establish_session($user);
    audit_log('auth.login', 'users', $userId, ['ip' => client_ip(), 'second_factor' => 'email']);

    /*  "Remember me" is honoured here and not at the password step.
     *  A remember-token issued before the code would be a token that
     *  skips the code on the next visit — which is the whole control
     *  handed away to whoever had the password. */
    if ($remember) {
        auth_issue_remember_token($userId);
    }

    try {
        db_run("UPDATE users SET last_login = NOW() WHERE user_id = :id", [':id' => $userId]);
    } catch (Throwable $e) {
        // Never block a sign-in on a bookkeeping column.
    }

    return ['success' => true, 'message' => 'Welcome back!'];
}

/**
 * Send another code for the same pending sign-in.
 *
 * @return array{sent: bool, message: string}
 */
function two_factor_resend(): array
{
    $pending = two_factor_pending();
    if (!$pending) {
        return ['sent' => false,
                'message' => 'That sign-in timed out. Please enter your password again.'];
    }
    $since = time() - (int) ($pending['resent'] ?? 0);
    if ($since < TWO_FACTOR_RESEND_COOLDOWN) {
        $wait = TWO_FACTOR_RESEND_COOLDOWN - $since;
        return ['sent' => false,
                'message' => 'Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's')
                           . ' before asking for another code.'];
    }

    $user = db_one(
        "SELECT user_id, first_name, email, is_active FROM users WHERE user_id = :id",
        [':id' => (int) $pending['user_id']]
    );
    if (!$user || !$user['is_active']) {
        two_factor_abandon();
        return ['sent' => false, 'message' => 'Please enter your password again.'];
    }

    $result = two_factor_begin($user);
    return ['sent' => $result['sent'], 'message' => $result['sent']
        ? 'A new code is on its way.' : $result['message']];
}

/**
 * An address with its middle removed — s****l@example.com.
 *
 * Shown on the code page so somebody who has two addresses knows
 * which inbox to open, and written to the audit log so the record
 * says where a code went without repeating the address in full.
 */
function two_factor_mask(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return '***';
    }
    $len = strlen($name);
    $shown = $len <= 2 ? substr($name, 0, 1) : substr($name, 0, 1) . str_repeat('*', min(4, $len - 2)) . substr($name, -1);
    return $shown . '@' . $domain;
}
