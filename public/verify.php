<?php

/**
 * ============================================================
 *  Sign-in code
 * ------------------------------------------------------------
 *  The second half of signing in. Reachable without a session
 *  because there is no session yet — the password was accepted
 *  and nothing was granted for it.
 *
 *  What a visitor holds here is a pending state naming one user
 *  id. Without it this page is a redirect back to the sign-in
 *  form and nothing else: no code can be requested, none can be
 *  checked, and no account can be named from outside.
 * ============================================================
 */

// Reachable without a session: this is the sign-in flow itself.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

// Already through? Nothing to do here.
if (auth_check()) {
    redirect('dashboard/index.php');
}

$pending = two_factor_pending();
if (!$pending) {
    flash('error', 'Please sign in again.');
    redirect('login.php');
}

$errors = [];
$notice = '';

if (is_post()) {
    csrf_check();

    /*  The same ceiling the sign-in form has. The per-code attempt
     *  limit stops one code being guessed; this stops a machine
     *  cycling password, code, password, code to get fresh codes to
     *  guess at. */
    rate_limit_or_stop('verify:' . client_ip(), 30, 300, 'code attempts');

    if (($_POST['_action'] ?? '') === 'resend') {
        $again = two_factor_resend();
        if ($again['sent']) {
            $notice = $again['message'];
            $pending = two_factor_pending();
        } else {
            $errors[] = $again['message'];
            if (!two_factor_pending()) {
                flash('error', $again['message']);
                redirect('login.php');
            }
        }
    } else {
        $result = two_factor_verify((string) ($_POST['code'] ?? ''));
        if ($result['success']) {
            $target = $_SESSION['intended'] ?? 'dashboard/index.php';
            unset($_SESSION['intended']);
            flash('success', $result['message']);
            redirect(login_safe_target_2fa($target));
        }
        $errors[] = $result['message'];
        // The pending state may have been torn up by that attempt.
        if (!two_factor_pending()) {
            flash('error', $result['message']);
            redirect('login.php');
        }
    }
}

/**
 * Where it is safe to send somebody after the code.
 *
 * The same rule the sign-in form applies, and for the same reason:
 * "return to where you were" is the one place an application
 * redirects to a URL somebody else chose, and a phishing page
 * reached from the real domain is far more convincing than one
 * that is not. Nothing but a path on this site is accepted.
 */
function login_safe_target_2fa(string $target): string
{
    $fallback = 'dashboard/index.php';
    if ($target === '' || str_contains($target, "\n") || str_contains($target, "\r")) {
        return $fallback;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) || str_starts_with($target, '//')) {
        return $fallback;
    }
    if (str_contains($target, '..')) {
        return $fallback;
    }
    $path = ltrim((string) parse_url($target, PHP_URL_PATH), '/');
    if (APP_URL !== '' && str_starts_with('/' . $path, APP_URL . '/')) {
        $path = ltrim(substr('/' . $path, strlen(APP_URL)), '/');
    }
    if ($path === '' || !str_ends_with($path, '.php')) {
        return $fallback;
    }
    $query = parse_url($target, PHP_URL_QUERY);
    return $path . ($query ? '?' . $query : '');
}

// Which inbox to open, without printing the address in full on a
// page anybody who has the password can reach.
$sentTo = two_factor_mask((string) db_value(
    "SELECT email FROM users WHERE user_id = :id", [':id' => (int) $pending['user_id']]
));
$minutes = (int) ceil(TWO_FACTOR_CODE_TTL / 60);
$flashes = flash_pull();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0b10">
    <title>Sign-in code · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/forms.css')) ?>">
</head>

<body class="auth-body">
    <div class="auth-wrap">
        <div class="auth-card">
            <div class="auth-brand">
                <span class="brand-mark brand-mark--lg">mH</span>
                <div class="auth-brand-text">
                    <span class="auth-brand-name">iMplement<span class="brand-accent">ERP</span></span>
                    <span class="auth-brand-sub">Pharmacy & Dispensary</span>
                </div>
            </div>

            <h1 class="auth-title">Check your email</h1>
            <p class="auth-lead">
                We sent a <?= (int) TWO_FACTOR_CODE_DIGITS ?>-digit code to
                <strong><?= e($sentTo) ?></strong>. It lasts <?= $minutes ?> minutes.
            </p>

            <?php foreach ($flashes as $f): ?>
                <div class="alert alert--<?= e($f['type']) ?>"><span><?= e($f['message']) ?></span></div>
            <?php endforeach; ?>

            <?php if ($notice !== ''): ?>
                <div class="alert alert--success"><span><?= e($notice) ?></span></div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert--error"><span><?= e(implode(' ', $errors)) ?></span></div>
            <?php endif; ?>

            <form method="POST" action="<?= e(url('verify.php')) ?>" novalidate class="auth-form">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="code" class="form-label">Sign-in code</label>
                    <?php /*  inputmode numeric brings up the number pad on a
                              phone; autocomplete one-time-code lets iOS and
                              Android offer the code straight from the
                              notification, which is the difference between
                              this being a nuisance and being nothing.     */ ?>
                    <input type="text" id="code" name="code" class="form-control code-input"
                           required autofocus autocomplete="one-time-code"
                           inputmode="numeric" pattern="[0-9]*"
                           maxlength="<?= (int) TWO_FACTOR_CODE_DIGITS ?>"
                           placeholder="<?= str_repeat('0', (int) TWO_FACTOR_CODE_DIGITS) ?>">
                </div>

                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>

            <form method="POST" action="<?= e(url('verify.php')) ?>" class="auth-form">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="resend">
                <button type="submit" class="btn btn-ghost btn-block">Send another code</button>
            </form>

            <p class="auth-alt">
                Wrong account?
                <a class="auth-link" href="<?= e(url('logout.php')) ?>">Start again</a>
            </p>

            <p class="auth-foot">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></p>
        </div>
    </div>

    <script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</body>

</html>
