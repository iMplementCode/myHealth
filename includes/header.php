<?php

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


/**
 * ============================================================
 *  Layout — Header / Page Chrome
 * ------------------------------------------------------------
 *  Renders the <head>, sidebar and top bar, and opens the main
 *  content area. Pages set optional variables before including:
 *
 *    $pageTitle    string  Browser + page title
 *    $pageSubtitle string  Small text under the title
 *    $breadcrumbs  array   [ ['label'=>..,'href'=>..], .. ]
 *    $pageStyles   array   Extra stylesheet basenames (assets/css/)
 *    $bodyClass    string  Extra class on <body>
 * ============================================================
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/bootstrap.php';
}
require_once __DIR__ . '/icons.php';
// For the tab icon below. company_settings() and the logo lookup are
// each worked out once per request, so this costs one query on the
// pages that were not already asking.
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/navigation.php';

$pageTitle    = $pageTitle    ?? APP_NAME;
$pageSubtitle = $pageSubtitle ?? '';
$breadcrumbs  = $breadcrumbs  ?? [];
$pageStyles   = $pageStyles   ?? [];
$bodyClass    = $bodyClass    ?? '';
$user         = current_user();
$flashes      = flash_pull();

// Look for anything worth telling somebody about — but at most once
// every ten minutes across the whole installation, and never for a
// visitor who is not signed in.
//
// This is what makes the bell work with nothing else configured:
// open the site and it is current. On a server with cron, add
// deploy/notify-scan.php and the first person through the door in
// the morning stops paying for it in page latency.
if ($user && function_exists('notifications_scan_if_due')) {
    notifications_scan_if_due();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a0b10">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>

    <?php /*  The tab icon, which was the company's own logo all
              along and simply never declared. With no <link> a
              browser asks for /favicon.ico by itself, gets a 404 on
              every single page load, and shows the blank sheet of
              paper it uses for a site with no identity.

              company_logo_url() is derived from the same file the
              printed documents draw, so the tab and the invoice
              cannot end up showing different logos.               */
       $favicon = function_exists('company_logo_url') ? company_logo_url() : null; ?>
    <?php if ($favicon): ?>
        <link rel="icon" href="<?= e($favicon) ?>">
        <link rel="apple-touch-icon" href="<?= e($favicon) ?>">
    <?php endif; ?>
    <!-- Theme bootstrap: must run before first paint to avoid a
         flash of the wrong theme. Data-only, no page logic. -->
    <script nonce="<?= csp_nonce() ?>">(function(){try{var t=localStorage.getItem('erp-theme');if(t==='light'){document.documentElement.setAttribute('data-theme','light');}}catch(e){}})();</script>
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <?php foreach ($pageStyles as $style): ?>
        <link rel="stylesheet" href="<?= e(asset('assets/css/' . $style)) ?>">
    <?php endforeach; ?>
</head>

<body class="<?= e($bodyClass) ?>">
<div class="app-shell">

    <!-- ══════════ Sidebar ══════════ -->
    <?php require __DIR__ . '/sidebar.php'; ?>

    <!-- ══════════ Main ══════════ -->
    <div class="app-main">

        <!-- Top navigation bar -->
        <?php require __DIR__ . '/navbar.php'; ?>

        <?php if ($breadcrumbs): ?>
            <nav class="breadcrumbs" aria-label="Breadcrumb">
                <a href="<?= e(url('dashboard/index.php')) ?>">Home</a>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <span class="crumb-sep">/</span>
                    <?php if (!empty($crumb['href'])): ?>
                        <a href="<?= e(url($crumb['href'])) ?>"><?= e($crumb['label']) ?></a>
                    <?php else: ?>
                        <span class="crumb-current"><?= e($crumb['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php
        // ── The database is behind the code ──────────────────────
        //  Shown to administrators only, and only when there is
        //  something to say. Four separate faults — "Could not save
        //  changes" on a supplier, a blank 500 on every purchasing
        //  document, a logo that silently would not change — were all
        //  one unapplied migration, and the application knew and said
        //  nothing.
        $pendingMigrations = is_admin() ? pending_migrations() : [];
        ?>
        <?php if ($pendingMigrations): ?>
            <div class="alert alert--warning" role="alert" style="align-items:flex-start;">
                <span>
                    <strong><?= count($pendingMigrations) ?>
                    database update<?= count($pendingMigrations) === 1 ? '' : 's' ?> not applied.</strong>
                    Until they are, parts of the system will fail in ways that do not explain
                    themselves — a form that says "could not save", a document that will not
                    print, a setting that will not stick.
                    <br>
                    Run <code>php database_structure/migrate.php</code> from the application
                    folder. It is safe to run at any time and applies only what is missing.
                    <br>
                    <small style="opacity:.75;">Outstanding:
                        <?= e(implode(', ', array_slice($pendingMigrations, 0, 4))) ?><?=
                        count($pendingMigrations) > 4 ? ', and ' . (count($pendingMigrations) - 4) . ' more' : '' ?></small>
                </span>
            </div>
        <?php endif; ?>

        <!-- Flash messages (server-rendered; JS toasts handle client-side) -->
        <?php if ($flashes): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $f): ?>
                    <div class="alert alert--<?= e($f['type']) ?>" role="alert">
                        <span><?= e($f['message']) ?></span>
                        <button type="button" class="alert-close" data-alert-close aria-label="Dismiss">&times;</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <main class="app-content">
