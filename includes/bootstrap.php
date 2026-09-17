<?php


/**
 * ============================================================
 *  Application Bootstrap
 * ------------------------------------------------------------
 *  The single entry point every page includes. It wires up
 *  configuration, the database layer, helper functions and
 *  authentication, then starts a secure session.
 *
 *  Protected pages should additionally call require_login()
 *  (and optionally require_role(...)) after including this.
 *
 *  Example (from a module two levels deep):
 *      require_once __DIR__ . '/../../includes/bootstrap.php';
 *      require_login();
 * ============================================================
 */

declare(strict_types=1);

/*  Running behind a proxy that terminates TLS is handled in
 *  request_is_https(), which weighs X-Forwarded-Proto against who
 *  sent it. It used to be handled here instead, by believing that
 *  header from anybody — see the note in includes/security.php for
 *  why that could not stay.
 *
 *  The database credentials were also repeated here, as a fallback
 *  "for isolated files like PDF downloads". They were never read:
 *  config.php is required on the line below and defines them all,
 *  so the guard around them was always false. What the PDF pages
 *  actually needed was to stop opening their own connection, which
 *  they now do. */

// Marks a request as having come through the front door. Files under
// includes/ check for it and refuse to run when requested directly,
// so a stray URL like /includes/header.php cannot execute anything.
define('APP_BOOTSTRAPPED', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/session_store.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/two_factor.php';
require_once __DIR__ . '/lookup.php';
require_once __DIR__ . '/notifications.php';

/*
 * The order below is the order it has to be in.
 *
 *  1. refuse the request outright if it is malformed or aimed at a
 *     host that is not ours — before anything reads a parameter
 *  2. get it onto TLS, before a cookie or a password crosses the
 *     wire in the clear
 *  3. say what the browser may run, before a byte of body is sent
 *  4. put sessions in the database, before one is started
 *  5. start the session
 */
// Before anything that can fail. PHP's own answer to an uncaught
// error is an empty 500 — "This page isn't working" — which tells
// the person nothing and leaves the only record in a log they
// cannot reach. See includes/errors.php.
errors_install();

security_firewall();
security_force_https();
security_headers();
session_store_install();

auth_start_session();

// An authenticated page is nobody else's to cache. Static assets
// are served by the web server and are unaffected — they are the
// part a CDN should be holding.
security_no_store();

// Expire old sessions, spent rate-limit windows and stale cache
// rows, occasionally, so a system with no cron still tidies up.
security_gc_maybe();

/*
 * Default deny.
 * ------------------------------------------------------------
 * Authentication used to depend on each page remembering to call
 * require_login(). One forgotten call left a page open to anyone who
 * knew its URL. Now every page that includes this file is protected
 * unless it declares itself public *before* including:
 *
 *     define('APP_PUBLIC_PAGE', true);
 *     require_once __DIR__ . '/includes/bootstrap.php';
 *
 * Only the sign-in flow and the storefront API do that, so a new page
 * added later is closed by default and fails safe rather than open.
 */
if (!defined('APP_PUBLIC_PAGE')) {
    require_login();
}
