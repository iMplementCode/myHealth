<?php

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


/**
 * 403 — Forbidden. Rendered when a signed-in user lacks the role
 * required for a page. Kept dependency-light so it can be included
 * from within require_role().
 */
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/bootstrap.php';
}
$pageTitle = 'Access Denied';
require __DIR__ . '/header.php';
?>
<div class="state-block">
    <div class="state-icon state-icon--danger">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
            <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
        </svg>
    </div>
    <h1 class="state-title">Access denied</h1>
    <p class="state-text">
        You don't have permission to view this page.
        <?php if ($r = current_role()): ?>
            Your role (<strong><?= e($r) ?></strong>) doesn't include access to this area.
        <?php endif; ?>
    </p>
    <a href="<?= e(url('dashboard/index.php')) ?>" class="btn btn-primary">Back to dashboard</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
