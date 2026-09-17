<?php

/**
 * ============================================================
 *  "I have forgotten my password"
 * ------------------------------------------------------------
 *  A single-use link, emailed, good for an hour.
 *
 *  Four things decide whether a reset flow is safe, and each one
 *  is a way people get this wrong:
 *
 *  1. It must not say whether an address has an account. The
 *     answer is identical either way, or the form becomes a way
 *     to find out who works here.
 *
 *  2. The link must be built from configuration, never from the
 *     Host header. A header is whatever the caller sent, and a
 *     reset link pointing at a host somebody else controls is a
 *     handed-over account.
 *
 *  3. The token is stored so that reading the table gives
 *     nothing. See migration 059 for the split-token shape.
 *
 *  4. Using it ends every other session. Somebody resetting
 *     their password because they think they were compromised
 *     has to actually push the other party out.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/** How long a link is good for. Long enough to find the email. */
const PASSWORD_RESET_TTL = 3600;

/**
 * The same answer whether or not the address has an account.
 *
 * Written once, used on every path out of the request, so no
 * future edit can make one branch chattier than another.
 */
const PASSWORD_RESET_SAME_ANSWER =
    'If that email address has an account here, a link to set a new password is on its way. '
  . 'It is good for one hour. Check the spam folder if it does not arrive.';

/**
 * Is the feature usable at all?
 *
 * Without mail there is nowhere to send the link, and a form that
 * silently does nothing is worse than one that says so.
 */
function password_reset_available(): bool
{
    return mail_is_configured() && table_exists('password_resets');
}

/**
 * Where the link points.
 *
 * From configuration only. APP_PUBLIC_URL is the plain statement
 * of it; failing that, the Host header is accepted ONLY when
 * APP_HOSTS lists it, which is the whole reason that list exists.
 *
 * Returns null when neither is set — and the caller must then
 * refuse to send rather than guess, because guessing here means
 * emailing somebody a link to whatever host the request claimed
 * to be.
 *
 * Note the explicit emptiness test on APP_HOSTS below. It is not
 * redundant: security_safe_host() returns the raw Host header
 * when the list is empty, which is right for its other caller —
 * bouncing a request back to the host it arrived on — and wrong
 * here, because THIS host ends up in an email sent to somebody
 * else. With no list configured there is nothing to check the
 * header against, so there is no safe answer and we give none.
 */
function password_reset_base_url(): ?string
{
    $configured = rtrim((string) env('APP_PUBLIC_URL', ''), '/');
    if ($configured !== '') {
        return $configured;
    }

    $allowList = trim((string) env('APP_HOSTS', ''));
    if ($allowList === '' || !function_exists('security_safe_host')) {
        return null;
    }

    $host = security_safe_host();
    if ($host !== null && $host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    return null;
}

/**
 * Ask for a reset link.
 *
 * Always reports the same thing, whatever happened, unless the
 * server itself cannot do it — a missing mail configuration is the
 * operator's problem and is worth saying out loud, because it
 * reveals nothing about any account.
 *
 * @return array{ok: bool, message: string}
 */
function password_reset_request(string $email): array
{
    $email = strtolower(trim($email));

    if (!password_reset_available()) {
        error_log('[RESET] a password reset was requested but mail is not configured '
                . '(or migration 059 has not run). Nobody can be sent a link.');
        return ['ok' => false, 'message' =>
            'Password reset is not switched on for this system yet. Ask an administrator to reset it for you.'];
    }

    $base = password_reset_base_url();
    if ($base === null) {
        error_log('[RESET] refusing to send: neither APP_PUBLIC_URL nor APP_HOSTS is set, so the '
                . 'link would be built from the Host header, which the caller controls.');
        return ['ok' => false, 'message' =>
            'Password reset is not configured correctly on this system. Ask an administrator.'];
    }

    /*  Two limits, because they stop different things. Per address
     *  stops one mailbox being buried; per source stops the form
     *  being used to send mail at a list of people. Both are
     *  checked before the account is looked up, so a refusal says
     *  nothing about whether the account exists. */
    $ip = function_exists('client_ip') ? client_ip() : '';
    $perEmail = rate_limit('pwreset:e:' . substr(hash('sha256', $email), 0, 32), 3, 900);
    $perIp    = rate_limit('pwreset:i:' . substr(hash('sha256', $ip), 0, 32), 10, 900);

    if (!$perEmail['allowed'] || !$perIp['allowed']) {
        // Still the same answer. "You are being rate limited" would
        // itself be a signal worth harvesting.
        return ['ok' => true, 'message' => PASSWORD_RESET_SAME_ANSWER];
    }

    $user = db_one(
        "SELECT user_id, email, first_name, username
           FROM users
          WHERE LOWER(email) = :e AND is_active
          LIMIT 1",
        [':e' => $email]
    );

    if (!$user) {
        // No account, or a deactivated one. Same words, same code
        // path length, nothing written anywhere a caller can see.
        return ['ok' => true, 'message' => PASSWORD_RESET_SAME_ANSWER];
    }

    /*  Any earlier link stops working now. Two live links means a
     *  request made by an attacker stays usable after the real
     *  owner has quietly requested their own. */
    db_run(
        'UPDATE password_resets SET consumed_at = NOW()
          WHERE user_id = :u AND consumed_at IS NULL',
        [':u' => $user['user_id']]
    );

    $selector = bin2hex(random_bytes(8));      // public, indexed
    $verifier = bin2hex(random_bytes(24));     // secret, hashed

    db_run(
        'INSERT INTO password_resets (user_id, selector, verifier_hash, expires_at, requested_ip)
         VALUES (:u, :s, :h, NOW() + (:ttl || \' seconds\')::INTERVAL, :ip)',
        [
            ':u'   => $user['user_id'],
            ':s'   => $selector,
            ':h'   => password_hash($verifier, PASSWORD_DEFAULT),
            ':ttl' => (string) PASSWORD_RESET_TTL,
            ':ip'  => mb_substr($ip, 0, 45),
        ]
    );

    $link  = $base . '/reset_password.php?t=' . $selector . '.' . $verifier;
    $who   = trim((string) ($user['first_name'] ?? '')) ?: (string) $user['username'];
    $brand = e_company_name();

    $html =
        '<p>Hello ' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8') . ',</p>'
      . '<p>Somebody asked to set a new password for your ' . $brand . ' account.</p>'
      . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Set a new password</a></p>'
      . '<p>The link works once and stops working in one hour.</p>'
      . '<p style="color:#555">If this was not you, nothing has happened and you can ignore this. '
      . 'Your current password still works.</p>';

    $text = "Hello $who,\n\nSomebody asked to set a new password for your $brand account.\n\n"
          . "$link\n\nThe link works once and stops working in one hour.\n\n"
          . "If this was not you, nothing has happened — your current password still works.\n";

    $sent = mail_send((string) $user['email'], 'Set a new password', $html, $text);
    if (!$sent['sent']) {
        error_log('[RESET] could not email a reset link: ' . $sent['error']);
        // Still the same answer to the caller. The failure is ours.
    }

    audit_log('password_reset_requested', 'user', (int) $user['user_id']);

    return ['ok' => true, 'message' => PASSWORD_RESET_SAME_ANSWER];
}

/** The company name, for an email subject line. */
function e_company_name(): string
{
    $s = function_exists('company_settings') ? company_settings() : [];
    return htmlspecialchars((string) ($s['company_name'] ?? APP_NAME), ENT_QUOTES, 'UTF-8');
}

/**
 * Check a link and return whose it is.
 *
 * @return array{user_id:int, reset_id:int}|null
 */
function password_reset_lookup(string $token): ?array
{
    if (!table_exists('password_resets')) {
        return null;
    }

    // "selector.verifier" and nothing else.
    if (!preg_match('/^([a-f0-9]{16})\.([a-f0-9]{48})$/', trim($token), $m)) {
        return null;
    }
    [$whole, $selector, $verifier] = $m;

    $row = db_one(
        'SELECT reset_id, user_id, verifier_hash, expires_at, consumed_at
           FROM password_resets
          WHERE selector = :s',
        [':s' => $selector]
    );
    if (!$row) {
        return null;
    }

    /*  Constant-time against the hash, then the cheap checks. Doing
     *  the cheap ones first would let the response time say whether
     *  a selector existed. */
    $matches = password_verify($verifier, (string) $row['verifier_hash']);
    if (!$matches) {
        return null;
    }
    if ($row['consumed_at'] !== null) {
        return null;
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return null;
    }

    // The account must still be one somebody may sign into.
    $user = db_one('SELECT user_id FROM users WHERE user_id = :u AND is_active',
                   [':u' => $row['user_id']]);
    if (!$user) {
        return null;
    }

    return ['user_id' => (int) $row['user_id'], 'reset_id' => (int) $row['reset_id']];
}

/**
 * Set the new password and burn the link.
 *
 * @return array{ok: bool, message: string}
 */
function password_reset_complete(string $token, string $password, string $confirm): array
{
    $found = password_reset_lookup($token);
    if ($found === null) {
        return ['ok' => false, 'message' =>
            'That link has expired or has already been used. Ask for a new one.'];
    }

    if ($password !== $confirm) {
        return ['ok' => false, 'message' => 'The two passwords do not match.'];
    }

    $user = db_one(
        'SELECT user_id, email, username, first_name, last_name FROM users WHERE user_id = :u',
        [':u' => $found['user_id']]
    );

    // The same policy the rest of the application applies, and the
    // person's own details are fed in so they cannot reuse them.
    $problem = password_policy_check($password, array_filter([
        $user['email'] ?? '', $user['username'] ?? '',
        $user['first_name'] ?? '', $user['last_name'] ?? '',
    ]));
    if ($problem !== null) {
        return ['ok' => false, 'message' => $problem];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_run(
            'UPDATE users SET password_hash = :h WHERE user_id = :u',
            [':h' => password_hash($password, PASSWORD_DEFAULT), ':u' => $found['user_id']]
        );

        // Once. Marked consumed inside the same transaction as the
        // change it authorised, so the two cannot come apart.
        db_run('UPDATE password_resets SET consumed_at = NOW() WHERE reset_id = :r',
               [':r' => $found['reset_id']]);

        /*  Everything else signed in as this person stops now.
         *
         *  The commonest reason to reset a password is believing
         *  somebody else has it. Leaving their session alive would
         *  make the reset theatre. */
        if (table_exists('sessions')) {
            db_run('DELETE FROM sessions WHERE user_id = :u', [':u' => $found['user_id']]);
        }
        if (table_exists('remember_tokens')) {
            db_run('DELETE FROM remember_tokens WHERE user_id = :u', [':u' => $found['user_id']]);
        }
        // And any half-finished sign-in code.
        if (table_exists('login_codes')) {
            db_run('UPDATE login_codes SET consumed_at = NOW()
                     WHERE user_id = :u AND consumed_at IS NULL', [':u' => $found['user_id']]);
        }

        // A lockout from the failed guesses that led to this should
        // not keep them out once they hold the mailbox.
        if (table_exists('login_attempts')) {
            db_run('DELETE FROM login_attempts WHERE identifier = :e', [':e' => $user['email'] ?? '']);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[RESET] could not complete: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The password could not be changed. Please try again.'];
    }

    audit_log('password_reset_completed', 'user', (int) $found['user_id']);

    // Tell them it happened, to the address on the account. If it
    // was not them, this is the warning.
    if (mail_is_configured() && !empty($user['email'])) {
        $brand = e_company_name();
        mail_send(
            (string) $user['email'],
            'Your password was changed',
            '<p>The password on your ' . $brand . ' account has just been changed.</p>'
          . '<p style="color:#555">If that was not you, tell whoever looks after this system now — '
          . 'somebody else has access to your email.</p>',
            "The password on your $brand account has just been changed.\n\n"
          . "If that was not you, tell whoever looks after this system now.\n"
        );
    }

    return ['ok' => true, 'message' => 'Your password has been changed. You can sign in with it now.'];
}
