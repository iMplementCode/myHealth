<?php

/**
 * ============================================================
 *  Set a new password, from an emailed link
 * ------------------------------------------------------------
 *  The other half of forgot_password.php. Everything that decides
 *  whether this is safe is in includes/password_reset.php; this
 *  page only shows the form and reports what happened.
 *
 *  The token is checked on the way IN as well as on submit, so
 *  somebody following a dead link is told so immediately rather
 *  than after typing a new password twice.
 * ============================================================
 */

// Reachable without a session: whoever is here cannot sign in.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/password_reset.php';

if (auth_check()) {
    redirect('dashboard/index.php');
}

/*  Kept in the form rather than the session. A session started for
 *  somebody who is not signed in is a thing to look after, and it
 *  would break the common case of opening the link on the phone
 *  the email arrived on and finishing on the desktop. */
$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');

$message = null;
$isError = false;
$done    = false;
$valid   = password_reset_lookup($token) !== null;

if (is_post() && $valid) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $message = 'That form had gone stale. Please try again.';
        $isError = true;
    } else {
        $result  = password_reset_complete(
            $token,
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['password_confirm'] ?? '')
        );
        $message = $result['message'];
        $isError = !$result['ok'];
        $done    = $result['ok'];
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
    <title>Set a new password — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/forms.css')) ?>">
    <?php /* A reset link must never travel to another site in a
             Referer header, and must not be indexed if it somehow
             ends up somewhere public. */ ?>
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
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

            <h1 class="auth-title">Set a new password</h1>

            <?php if ($message !== null): ?>
                <div class="alert alert--<?= $isError ? 'error' : 'success' ?>" role="status">
                    <span><?= e($message) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($done): ?>

                <a class="btn btn-primary btn-block" href="<?= e(url('login.php')) ?>">Sign in</a>

            <?php elseif (!$valid): ?>

                <p class="auth-lead">
                    This link has expired, has already been used, or was not complete.
                    Links last one hour and work once.
                </p>
                <a class="btn btn-primary btn-block" href="<?= e(url('forgot_password.php')) ?>">
                    Ask for a new link
                </a>

            <?php else: ?>

                <p class="auth-lead">
                    Choose a password you will remember. Everything else signed in as
                    you will be signed out.
                </p>

                <form method="POST" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="t" value="<?= e($token) ?>">

                    <div class="form-group">
                        <label class="form-label" for="password">New password</label>
                        <div class="input-affix">
                            <input type="password" id="password" name="password" class="form-control"
                                   autocomplete="new-password" required autofocus minlength="<?= (int) PASSWORD_MIN_LENGTH ?>">
                            <button type="button" class="affix-btn" data-toggle-password="password">Show</button>
                        </div>
                        <span class="hint">At least <?= (int) PASSWORD_MIN_LENGTH ?> characters.</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password_confirm">Type it again</label>
                        <input type="password" id="password_confirm" name="password_confirm"
                               class="form-control" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">Set the password</button>
                </form>

            <?php endif; ?>

            <p class="auth-alt">
                <a href="<?= e(url('login.php')) ?>">Back to sign in</a>
            </p>
        </div>

        <p class="auth-foot">&copy; <?= $year ?> <?= e(APP_NAME) ?> · All rights reserved</p>
    </div>

    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>

</html>
