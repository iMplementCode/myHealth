<?php

/**
 * ============================================================
 *  Application Entry Point
 * ------------------------------------------------------------
 *  Routes visitors into the app: authenticated users land on
 *  the dashboard, everyone else is sent to sign in. No page is
 *  reachable without authentication.
 * ============================================================
 */

// Reachable without a session: this is the sign-in flow itself.
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';

redirect(auth_check() ? 'dashboard/index.php' : 'login.php');
