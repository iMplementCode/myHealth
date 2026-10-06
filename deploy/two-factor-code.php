<?php

/**
 * ============================================================
 *  A sign-in code, issued from the server
 * ------------------------------------------------------------
 *      php deploy/two-factor-code.php pharmacist@example.com
 *
 *  Two-factor sign-in emails a code, and this application has no
 *  backup codes. So when the mail server stops working — an
 *  expired password, a provider blocking the host, a DNS change
 *  made on a Friday — nobody can sign in at all. Not the
 *  pharmacist, not the manager, not the administrator who would
 *  fix it. A shop that cannot dispense because of an SMTP
 *  password is a worse outcome than the one two-factor prevents.
 *
 *  This is the way back in. It issues a real code and prints it
 *  here instead of sending it, for somebody who already has a
 *  shell on the server — which is to say somebody who already
 *  has the database and could do anything they liked without
 *  this file. It gives away nothing that was being protected.
 *
 *  It is deliberately not a way to turn two-factor off: the code
 *  expires on the usual schedule, is single use, and every issue
 *  is written to the audit log with a note saying it came from
 *  the console. If somebody is doing this routinely rather than
 *  in an emergency, the log says so and the mail server is what
 *  needs fixing.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/two_factor.php';

//  client_ip() and audit_log() expect a request. There is none.
if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return 'console';
    }
}

$identifier = trim((string) ($argv[1] ?? ''));

if ($identifier === '' || in_array($identifier, ['-h', '--help'], true)) {
    fwrite(STDERR, "\n  Usage: php deploy/two-factor-code.php <email or username>\n\n"
                 . "  Issues a sign-in code and prints it, for when the mail server\n"
                 . "  cannot. The code behaves exactly as an emailed one: single use,\n"
                 . "  expires in " . (int) ceil(TWO_FACTOR_CODE_TTL / 60) . " minutes.\n\n");
    exit(1);
}

if (!TWO_FACTOR_ENABLED) {
    fwrite(STDERR, "\n  Two-factor sign-in is switched off, so no code is needed.\n"
                 . "  Sign in with the password alone.\n\n");
    exit(1);
}

try {
    $pdo = Database::connect();
} catch (PDOException $e) {
    fwrite(STDERR, "\n  ✗ the database is not reachable: " . $e->getMessage() . "\n\n");
    exit(1);
}

$user = db_one(
    "SELECT user_id, first_name, last_name, email, username, is_active
       FROM users
      WHERE LOWER(email) = LOWER(:id) OR LOWER(username) = LOWER(:id)",
    [':id' => $identifier]
);

if (!$user) {
    //  Named plainly. This is a console tool for an administrator
    //  holding a shell, not a login form: refusing to say whether
    //  the account exists would only waste their time.
    fwrite(STDERR, "\n  No account matches " . $identifier . ".\n\n");
    exit(1);
}

if (!$user['is_active']) {
    fwrite(STDERR, "\n  " . $identifier . " is not an active account.\n\n");
    exit(1);
}

$code = two_factor_new_code();

//  Retire anything still outstanding, exactly as two_factor_begin
//  does: two live codes at once doubles what a guess is worth.
db_run(
    "UPDATE login_codes SET consumed_at = NOW()
      WHERE user_id = :u AND consumed_at IS NULL",
    [':u' => (int) $user['user_id']]
);

db_run(
    "INSERT INTO login_codes (user_id, code_hash, expires_at, sent_to, ip_address)
     VALUES (:u, :h, NOW() + (:ttl || ' seconds')::interval, :to, :ip)",
    [
        ':u'   => (int) $user['user_id'],
        ':h'   => password_hash($code, PASSWORD_DEFAULT),
        ':ttl' => (string) TWO_FACTOR_CODE_TTL,
        ':to'  => 'console',
        ':ip'  => 'console',
    ]
);

//  The audit log is the whole justification for this being an
//  acceptable tool rather than a back door, so a failure to write
//  it is reported rather than swallowed.
try {
    if (function_exists('audit_log')) {
        audit_log('auth.2fa_issued_console', 'users', (int) $user['user_id'],
                  ['by' => 'console', 'reason' => 'issued from the server']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "  ! the audit entry could not be written: " . $e->getMessage() . "\n");
}

$name = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);

echo "\n  Sign-in code for " . ($name !== '' ? $name : $identifier) . "\n";
echo "  ────────────────────────────────────────────\n";
echo "      " . $code . "\n";
echo "  ────────────────────────────────────────────\n";
echo "  Valid for " . (int) ceil(TWO_FACTOR_CODE_TTL / 60) . " minutes, once.\n";
echo "  Recorded in the audit log as issued from the console.\n\n";
echo "  If you are here because email is broken, fix that next:\n";
echo "      php deploy/check-mail.php you@yourdomain\n\n";
