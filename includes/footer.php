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
 *  Layout — Footer
 * ------------------------------------------------------------
 *  Closes the main content area and page chrome, and loads the
 *  shared JavaScript. Pages may set:
 *
 *    $pageScripts array  Extra script basenames (assets/js/)
 *    $inlineData  array  Small data payload exposed as window.APP
 *                        (avoids inline <script> logic; data only)
 * ============================================================
 */

$pageScripts = $pageScripts ?? [];
$inlineData  = $inlineData  ?? [];
?>
        </main><!-- /.app-content -->

        <footer class="app-foot">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
            <span class="app-foot-sep">·</span>
            <span>Pharmacy &amp; Dispensary</span>
        </footer>
    </div><!-- /.app-main -->
</div><!-- /.app-shell -->

<!-- Toast container -->
<div class="toast-stack" id="toastStack" aria-live="polite" aria-atomic="true"></div>

<?php if (!empty($inlineData)): ?>
    <!-- Data-only bootstrap: no logic here, consumed by external JS modules -->
    <script type="application/json" id="app-data"><?= json_encode($inlineData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<?php // Idle sign-out. Only for someone actually signed in — there is
      // nothing to protect on the login screen, and no session to end. ?>
<?php if (current_user()): ?>
    <script type="application/json" id="idle-config"><?= json_encode([
        'timeoutSeconds' => SESSION_LIFETIME,
        'warningSeconds' => IDLE_WARNING_SECONDS,
        'pingUrl'        => url('session_ping.php'),
        'logoutUrl'      => url('logout.php'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="<?= e(asset('assets/js/idle.js')) ?>" defer></script>

    <?php /*  Everywhere this person is allowed to go, for the quick
              jump box on Ctrl-K.

              It is built from nav_items(), which is already filtered
              to their roles — so the box cannot offer a page that
              would answer 403, and a new module appears in it the
              moment it appears in the sidebar. Nothing here is a
              permission: it is a list of links, and every one of them
              is still checked by the page it opens.                 */ ?>
    <script type="application/json" id="nav-index"><?= json_encode(
        nav_flat(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?></script>
    <script src="<?= e(asset('assets/js/palette.js')) ?>" defer></script>
<?php endif; ?>

<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php foreach ($pageScripts as $script): ?>
    <script src="<?= e(asset('assets/js/' . $script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
