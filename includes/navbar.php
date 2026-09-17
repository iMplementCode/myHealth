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
 *  Layout — Top Navigation Bar
 * ------------------------------------------------------------
 *  Page title, sidebar toggle (mobile) and the user menu.
 *  Included by header.php. Expects $pageTitle, $pageSubtitle
 *  and $user to be in scope.
 * ============================================================
 */
if (!defined('BASE_PATH')) {
    exit;
}
?>
<header class="topbar">
    <div class="topbar-left">
        <button type="button" class="icon-btn sidebar-open" data-sidebar-toggle aria-label="Toggle menu">
            <?= icon('menu') ?>
        </button>
        <div class="topbar-heading">
            <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
            <?php if (!empty($pageSubtitle)): ?>
                <p class="topbar-sub"><?= e($pageSubtitle) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="topbar-right">
        <?php if ($user && function_exists('notifications_ready') && notifications_ready()):
            $noteUnread = notifications_unread_count();
            $noteList   = notifications_list(8, true);
        ?>
            <div class="notif" data-dropdown>
                <button type="button" class="icon-btn notif-btn" data-dropdown-toggle
                        aria-label="<?= $noteUnread ? e($noteUnread . ' unread notifications') : 'Notifications' ?>"
                        title="Notifications">
                    <?= icon('bell') ?>
                    <?php if ($noteUnread): ?>
                        <span class="notif-count"><?= $noteUnread > 99 ? '99+' : (int) $noteUnread ?></span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu notif-menu" data-dropdown-menu>
                    <div class="notif-head">
                        <span><?= $noteUnread ? e($noteUnread) . ' unread' : 'Notifications' ?></span>
                        <?php if ($noteUnread): ?>
                            <form method="post" action="<?= e(url('notifications/index.php')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="_action" value="read_all">
                                <input type="hidden" name="back" value="1">
                                <button type="submit" class="linkish">Mark all read</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!$noteList): ?>
                        <p class="notif-empty">Nothing needs your attention.</p>
                    <?php else: foreach ($noteList as $n): ?>
                        <a class="notif-item notif-item--<?= e(notification_tone($n['severity'])) ?>"
                           href="<?= e(url('notifications/index.php?go=' . (int) $n['notification_id'])) ?>">
                            <span class="notif-item-title"><?= e($n['title']) ?></span>
                            <?php if ($n['body']): ?>
                                <span class="notif-item-body"><?= e($n['body']) ?></span>
                            <?php endif; ?>
                            <span class="notif-item-when"><?= e(notification_when($n['created_at'])) ?></span>
                        </a>
                    <?php endforeach; endif; ?>

                    <a class="notif-all" href="<?= e(url('notifications/index.php')) ?>">
                        See all notifications
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($user): ?>
            <?php /*  The same box Ctrl-K opens. A keyboard shortcut
                      nobody is told about is a feature for one person,
                      and on a phone there is no Ctrl to press.       */ ?>
            <button type="button" class="icon-btn" data-palette-open
                    aria-label="Jump to a page" title="Jump to a page (Ctrl+K)">
                <?= icon('search') ?>
            </button>
        <?php endif; ?>

        <button type="button" class="icon-btn" data-theme-toggle aria-label="Switch between dark and light mode" title="Toggle theme">
            <span class="theme-ic-dark"><?= icon('moon') ?></span>
            <span class="theme-ic-light"><?= icon('sun') ?></span>
        </button>
        <?php if ($user): ?>
            <div class="user-chip" data-dropdown>
                <button type="button" class="user-chip-btn" data-dropdown-toggle>
                    <span class="avatar"><?= e(initials($user['name'] ?: $user['email'])) ?></span>
                    <span class="user-chip-meta">
                        <span class="user-chip-name"><?= e($user['name'] ?: $user['email']) ?></span>
                        <span class="user-chip-role"><?= e($user['role'] ?? 'User') ?></span>
                    </span>
                </button>
                <div class="dropdown-menu" data-dropdown-menu>
                    <div class="dropdown-head"><?= e($user['email']) ?></div>
                    <a href="<?= e(url('users/edit.php?id=' . (int) $user['id'])) ?>" class="dropdown-item">
                        <?= icon('user') ?> My profile
                    </a>
                    <a href="<?= e(url('logout.php')) ?>" class="dropdown-item dropdown-item--danger">
                        <?= icon('logout') ?> Sign out
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>
