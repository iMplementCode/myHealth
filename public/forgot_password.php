<?php

/**
 * ============================================================
 *  Forgot your password — ask for a link
 * ------------------------------------------------------------
 *  Takes an email address and, if it belongs to an active
 *  account, emails a single-use link. See includes/password_reset.php
 *  for why every path out of here says the same thing.
 *
 *  Reachable without a session, obviously — somebody who could
 *  sign in would not be here.
 * ============================================================
 */

// Reachable without a session: part of the sign-in flow.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/password_reset.php';

if (auth_check()) {
    redirect('dashboard/index.php');
}

$message   = null;
$isError   = false;
$available = password_reset_available();

if (is_post()) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $message = 'That form had gone stale. Please try again.';
        $isError = true;
    } else {
        $result  = password_reset_request((string) ($_POST['email'] ?? ''));
        $message = $result['message'];
        $isError = !$result['ok'];
    }
}

$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0b10">
    <title>Reset your password — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/forms.css')) ?>">
</head>

<body class="auth-body">
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-brand">
                <span class="brand-mark brand-mark--lg">mH</span>
                <span class="auth-brand-text">
                    <span class="auth-brand-name"><?= e(APP_NAME) ?></span>
                    <span class="auth-brand-sub">Pharmacy &amp; Dispensary</span>
                </span>
            </div>

            <h1 class="auth-title">Reset your password</h1>

            <?php if ($message !== null): ?>
                <div class="alert alert--<?= $isError ? 'error' : 'success' ?>" role="status">
                    <span><?= e($message) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$available): ?>

                <p class="auth-lead">Password reset by email is not switched on for this system.</p>
                <div class="alert alert--info" role="status">
                    <span>Ask an administrator to set a new password for you from
                    <strong>Users &rarr; Manage Users</strong>.</span>
                </div>

            <?php else: ?>

                <p class="auth-lead">
                    Type the email address on your account and we will send you a link
                    to set a new one. The link works once and lasts an hour.
                </p>

                <form method="POST" autocomplete="on">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label class="form-label" for="email">Email address</label>
                        <input type="email" id="email" name="email" class="form-control"
                               autocomplete="username" required autofocus
                               value="<?= e((string) ($_POST['email'] ?? '')) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Send me a link</button>
                </form>

            <?php endif; ?>

            <p class="auth-alt">
                <a href="<?= e(url('login.php')) ?>">Back to sign in</a>
            </p>
        </div>

        <p class="auth-foot">&copy; <?= $year ?> <?= e(APP_NAME) ?> · All rights reserved</p>
    </div>
</body>

</html>
