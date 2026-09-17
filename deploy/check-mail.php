<?php

/**
 * ============================================================
 *  Can this server actually send an email?
 * ------------------------------------------------------------
 *      php deploy/check-mail.php you@yourdomain.co.ke
 *
 *  Run it before switching two-factor sign-in on, and run it
 *  again whenever the mailbox password changes.
 *
 *  The reason it exists: if a sign-in code cannot be delivered,
 *  nobody can sign in. Not one person — everybody, at once, with
 *  the only way back being an environment variable somebody has
 *  to remember exists. "It should work" is not good enough for a
 *  control that can close the business, so this proves it does
 *  by sending a real message to a real address and reporting
 *  exactly what the mail server said.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
define('APP_BOOTSTRAPPED', true);
require_once $root . '/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/mailer.php';

$to = $argv[1] ?? '';

echo "\n  Mail\n";
echo "  ────────────────────────────────────────────────────────\n";

$settings = [
    'SMTP_HOST'      => (string) env('SMTP_HOST', ''),
    'SMTP_PORT'      => (string) env('SMTP_PORT', '587'),
    'SMTP_USER'      => (string) env('SMTP_USER', ''),
    'SMTP_PASS'      => (string) env('SMTP_PASS', ''),
    'SMTP_SECURE'    => (string) env('SMTP_SECURE', 'tls'),
    'MAIL_FROM'      => (string) env('MAIL_FROM', ''),
    'MAIL_FROM_NAME' => (string) env('MAIL_FROM_NAME', APP_NAME),
];

foreach ($settings as $key => $value) {
    // The password is the one thing never printed. Everything else
    // is shown, because a typo in a hostname is the usual fault and
    // it is invisible if you only report its length.
    $shown = $key === 'SMTP_PASS'
        ? ($value === '' ? 'NOT SET' : strlen($value) . ' chars')
        : ($value === '' ? 'NOT SET' : $value);
    printf("    %-16s %s\n", $key, $shown);
}
echo "\n";

if (!mail_is_configured()) {
    echo "  Not enough to send anything:\n";
    foreach (mail_config_problems() as $p) {
        echo "    · $p\n";
    }
    echo "\n  Set these in the hosting panel's environment settings.\n";
    echo "  Until they are set, two-factor sign-in stays off — see\n";
    echo "  two_factor_required() — so nobody is locked out by this.\n\n";
    exit(1);
}

if ($to === '') {
    echo "  Configuration looks complete. To prove it end to end, give\n";
    echo "  this an address to send to:\n\n";
    echo "      php deploy/check-mail.php you@yourdomain.co.ke\n\n";
    exit(0);
}

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "  '$to' is not an email address.\n\n";
    exit(1);
}

echo "  Sending a test message to $to ...\n";
$started = microtime(true);

$result = mail_send(
    $to,
    'Test message from ' . APP_NAME,
    '<p>This is a test from <strong>' . htmlspecialchars(APP_NAME, ENT_QUOTES) . '</strong>.</p>'
    . '<p>If you are reading it, sign-in codes will arrive too, and '
    . 'two-factor sign-in can be switched on safely.</p>',
    "This is a test from " . APP_NAME . ".\n\n"
    . "If you are reading it, sign-in codes will arrive too, and "
    . "two-factor sign-in can be switched on safely.\n"
);

$took = round(microtime(true) - $started, 2);

if ($result['sent']) {
    echo "    sent in {$took}s\n\n";
    echo "  Check the inbox — and the spam folder, because the first\n";
    echo "  message from a new sender often lands there. A code that\n";
    echo "  goes to spam is a person who cannot sign in.\n\n";
    echo "  When it has arrived, switch two-factor on by setting\n";
    echo "  TWO_FACTOR_ENABLED=true and redeploying.\n\n";
    exit(0);
}

echo "    failed after {$took}s\n\n";
echo "  The mail server said:\n    " . $result['error'] . "\n\n";
echo "  Common causes:\n";
echo "    · wrong port for the encryption — 587 goes with tls,\n";
echo "      465 with ssl; crossing them hangs until it times out\n";
echo "    · the mailbox password, not the account password\n";
echo "    · MAIL_FROM is an address the mailbox is not allowed to\n";
echo "      send as, which most providers refuse outright\n";
echo "    · outbound port 587 blocked from the container\n\n";
echo "  Two-factor sign-in stays off while this fails, so nobody is\n";
echo "  locked out by it.\n\n";
exit(1);
