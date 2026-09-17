<?php

/**
 * ============================================================
 *  Request access
 * ------------------------------------------------------------
 *  Placeholder. The link exists on the sign-in page so the
 *  journey is visible, but the flow itself is a later phase —
 *  accounts are created by an administrator rather than
 *  self-registered, since this is internal business software.
 *
 *  Deliberately does NOT reveal whether an account exists, and
 *  performs no action, so nothing here can be probed.
 * ============================================================
 */

// Reachable without a session: part of the sign-in flow.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

if (auth_check()) {{
    redirect('dashboard/index.php');
}}

$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0b10">
    <title>Request access — <?= e(APP_NAME) ?></title>
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

            <h1 class="auth-title">Request access</h1>
            <p class="auth-lead">Accounts for this system are created by an administrator.</p>

            <div class="alert alert--info" role="status">
                This is internal business software, so there is no public
                sign-up. Ask an administrator to create an account for you —
                they can do it from <strong>Users &rarr; Add User</strong>.
            </div>

            <a class="btn btn-primary btn-block" href="<?= e(url('login.php')) ?>">Back to sign in</a>
        </div>

        <p class="auth-foot">&copy; <?= $year ?> <?= e(APP_NAME) ?> · All rights reserved</p>
    </div>
</body>

</html>
