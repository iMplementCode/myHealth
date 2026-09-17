<?php

/**
 * ============================================================
 *  Logout
 * ------------------------------------------------------------
 *  Destroys the session and any remember-me token, then sends
 *  the user back to the login page.
 * ============================================================
 */

// Reachable without a session: this is the sign-in flow itself.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

// Why we are here decides what the sign-in page says. An idle
// sign-out is not something the user did, and telling them
// "you have been signed out" for it reads like a fault.
$idle = ($_GET['reason'] ?? '') === 'idle';

auth_logout();

// Start a fresh session so we can show a confirmation flash.
auth_start_session();
if ($idle) {
    flash('info', sprintf(
        'You were signed out after %d minutes without activity.',
        IDLE_TIMEOUT_MINUTES
    ));
} else {
    flash('success', 'You have been signed out.');
}

redirect('login.php');
