<?php

/**
 * ============================================================
 *  Sending email
 * ------------------------------------------------------------
 *  The application had no way to send an email at all until the
 *  sign-in code needed one. That is worth stating plainly,
 *  because it is the reason two-factor sign-in is off until
 *  somebody turns it on: a login that depends on a message
 *  nobody has ever proved can be delivered is a login that can
 *  lock the whole company out of its own books.
 *
 *  PHP's mail() is not an option here. It shells out to
 *  sendmail, and the container this runs in has no sendmail —
 *  no MTA of any kind. mail() would return false forever and
 *  say nothing about why. So this speaks SMTP to a real mail
 *  server, using the mailbox the business already has.
 *
 *  Configure it in the environment (Dokploy → Environment, or
 *  .env on a plain VPS):
 *
 *      SMTP_HOST      smtp.hostinger.com
 *      SMTP_PORT      587
 *      SMTP_USER      no-reply@yourdomain.co.ke
 *      SMTP_PASS      the mailbox password
 *      SMTP_SECURE    tls   (or ssl on port 465)
 *      MAIL_FROM      no-reply@yourdomain.co.ke
 *      MAIL_FROM_NAME Run AI Technologies
 *
 *  Prove it before relying on it:
 *
 *      php deploy/check-mail.php you@yourdomain.co.ke
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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Is there enough configuration to send anything at all?
 *
 * Asked before a feature offers to email somebody, so the offer is
 * never made when it cannot be kept.
 */
function mail_is_configured(): bool
{
    return trim((string) env('SMTP_HOST', '')) !== ''
        && trim((string) env('MAIL_FROM', '')) !== '';
}

/** What is missing, in words, for an operator reading a diagnostic. */
function mail_config_problems(): array
{
    $missing = [];
    foreach (['SMTP_HOST' => 'the mail server to send through',
              'MAIL_FROM' => 'the address messages come from'] as $key => $what) {
        if (trim((string) env($key, '')) === '') {
            $missing[] = $key . ' — ' . $what;
        }
    }
    // A host without credentials is legitimate on an internal relay,
    // so this is a note rather than a fault.
    if (trim((string) env('SMTP_USER', '')) === '' && !$missing) {
        $missing[] = 'note: SMTP_USER is empty, so the server is being '
                   . 'asked to relay without authenticating';
    }
    return $missing;
}

/**
 * Send one message. Returns [sent, error].
 *
 * Never throws: a caller in the middle of a sign-in wants a yes or
 * no and a reason for the log, not an exception to catch. The
 * reason is deliberately not shown to the person signing in —
 * "Connection refused to smtp.example.com:587" tells an attacker
 * about the infrastructure and tells the user nothing they can act
 * on.
 *
 * @return array{sent: bool, error: string}
 */
function mail_send(string $to, string $subject, string $html, string $text = ''): array
{
    if (!mail_is_configured()) {
        return ['sent' => false, 'error' => 'mail is not configured (SMTP_HOST / MAIL_FROM)'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'not a valid recipient address'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host    = (string) env('SMTP_HOST', '');
        $mail->Port    = (int) env('SMTP_PORT', '587');
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $user = (string) env('SMTP_USER', '');
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = (string) env('SMTP_PASS', '');
        }

        /*  tls means STARTTLS on 587; ssl means implicit TLS on 465.
         *  'none' is accepted and deliberately awkward to write,
         *  because sending a one-time code over a cleartext hop is
         *  handing it to anybody on the path. */
        $secure = strtolower(trim((string) env('SMTP_SECURE', 'tls')));
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'none') {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        // Certificates are verified. PHPMailer lets this be turned
        // off and the internet is full of advice to do so; that
        // advice converts "encrypted" into "encrypted to whoever
        // answered", which for a sign-in code is no protection.
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]];

        // A sign-in is a person waiting at a form. Fail in seconds.
        // Never zero: PHPMailer reads that as "wait forever", and a
        // sign-in code that never comes back is a person who cannot
        // get in rather than a person who gets an error.
        $mail->Timeout = max(1, (int) env('SMTP_TIMEOUT', '10'));
        $mail->SMTPDebug = SMTP::DEBUG_OFF;

        $from = (string) env('MAIL_FROM', '');
        $mail->setFrom($from, (string) env('MAIL_FROM_NAME', APP_NAME));
        $mail->addAddress($to);

        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));

        $mail->send();
        return ['sent' => true, 'error' => ''];
    } catch (MailException $e) {
        $why = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        error_log('[MAIL] send to ' . $to . ' failed: ' . $why);
        return ['sent' => false, 'error' => $why];
    } catch (Throwable $e) {
        error_log('[MAIL] send to ' . $to . ' failed: ' . $e->getMessage());
        return ['sent' => false, 'error' => $e->getMessage()];
    }
}
