<?php

/**
 * ============================================================
 *  Login
 * ------------------------------------------------------------
 *  Public page. Authenticates a user and starts a secure
 *  session. Already-authenticated users are redirected to the
 *  dashboard. CSRF-protected; login attempts are throttled.
 * ============================================================
 */

// Reachable without a session: this is the sign-in flow itself.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

// Already signed in? Go straight to the dashboard.
if (auth_check()) {
    redirect('dashboard/index.php');
}

$errors = [];
$email  = '';

if (is_post()) {
    csrf_check();

    // A ceiling on how fast this form can be submitted at all,
    // whatever is typed into it. The account lockout in
    // auth_attempt() counts wrong passwords; this counts requests,
    // and so also covers someone hammering the form to make the
    // server hash passwords until it falls over.
    rate_limit_or_stop('login:' . client_ip(), 30, 300, 'sign-in attempts');

    $email    = input($_POST, 'email');
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both your email and password.';
    } else {
        $result = auth_attempt($email, $password, $remember);

        /*  The password was right and a code is on its way. There is
         *  no session yet — see auth_attempt() — so this is not a
         *  signed-in person being sent somewhere, it is a stranger
         *  being sent to the one page their pending state can use. */
        if (!empty($result['two_factor'])) {
            redirect('verify.php');
        }

        if ($result['success']) {
            $target = $_SESSION['intended'] ?? 'dashboard/index.php';
            unset($_SESSION['intended']);
            $target = login_safe_target($target);
            flash('success', 'Welcome back!');
            redirect($target);
        }
        $errors[] = $result['message'];
    }
}

/**
 * Where it is safe to send someone after they sign in.
 *
 * "Return to where you were" is the one place an application
 * cheerfully redirects to a URL somebody else chose. A phishing
 * page reached through a link on the real sign-in domain is far
 * more convincing than one that is not, so nothing but a path on
 * this site is accepted — no scheme, no host, and in particular
 * not //evil.example, which is a URL to another site even though
 * it looks like a path.
 */
function login_safe_target(string $target): string
{
    $fallback = 'dashboard/index.php';

    if ($target === '' || str_contains($target, "\n") || str_contains($target, "\r")) {
        return $fallback;
    }
    // Anything with a scheme, or a protocol-relative //host.
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) || str_starts_with($target, '//')) {
        return $fallback;
    }
    if (str_contains($target, '..')) {
        return $fallback;
    }

    // The stored value is a REQUEST_URI, so it carries the base
    // path the app is served from; url() adds that back.
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

$flashes = flash_pull();
$year    = date('Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0b10">
    <title>Sign in · <?= e(APP_NAME) ?></title>
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

            <h1 class="auth-title">Sign in to your account</h1>
            <p class="auth-lead">Enter your credentials to access the dashboard.</p>

            <?php foreach ($flashes as $f): ?>
                <div class="alert alert--<?= e($f['type']) ?>"><span><?= e($f['message']) ?></span></div>
            <?php endforeach; ?>

            <?php if ($errors): ?>
                <div class="alert alert--error">
                    <span><?= e(implode(' ', $errors)) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= e(url('login.php')) ?>" novalidate class="auth-form" autocomplete="on">
                <?= csrf_field() ?>

                <div class="form-group">
                    <label for="email" class="form-label">Email or username</label>
                    <input type="text" id="email" name="email" class="form-control"
                           value="<?= e($email) ?>" required autofocus autocomplete="username"
                           placeholder="you@company.com or username">
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-affix">
                        <input type="password" id="password" name="password" class="form-control"
                               required autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="affix-btn" data-toggle-password="password" aria-label="Show password">Show</button>
                    </div>
                </div>

                <div class="form-inline-between">
                    <label class="checkbox">
                        <input type="checkbox" name="remember" value="1">
                        <span>Remember me</span>
                    </label>
                    <a class="auth-link" href="<?= e(url('forgot_password.php')) ?>">Forgot password?</a>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Sign in</button>
            </form>

            <p class="auth-alt">
                Don't have an account?
                <a class="auth-link" href="<?= e(url('signup.php')) ?>">Request access</a>
            </p>
        </div>

        <p class="auth-foot">&copy; <?= $year ?> <?= e(APP_NAME) ?> · All rights reserved</p>
    </div>

    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</body>

</html>
