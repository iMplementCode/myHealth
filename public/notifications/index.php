<?php

/**
 * ============================================================
 *  Notifications
 * ------------------------------------------------------------
 *  Everything the scanner currently believes needs attention,
 *  worst first. The bell in the top bar shows the unread few;
 *  this is the whole list, and the place to clear it.
 *
 *  ?go=<id> marks one read and forwards to the thing it is about,
 *  which is what the bell's items link to — reading a notification
 *  and acting on it are the same gesture.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

if (!notifications_ready()) {
    $pageTitle = 'Notifications';
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="panel"><p>Notifications need a database update that has not '
       . 'been applied yet. Run <code>php database_structure/migrate.php</code>.</p></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

/* ── Open one ────────────────────────────────────────────────
 *  Mark it read, then send the reader to the invoice or the
 *  product rather than leaving them to find it.               */
if (isset($_GET['go'])) {
    $id = (int) $_GET['go'];
    $n  = db_one('SELECT url FROM notifications WHERE notification_id = :id', [':id' => $id]);
    notification_mark_read($id);

    // Only ever a path inside this application. The column is written
    // by the scanner, not by a user, but a redirect that will follow
    // whatever it is handed is worth closing anyway.
    // redirect() runs the path through url() itself, so what it wants
    // is the bare path — "invoices/view.php?id=4", not url() output.
    // Passing url() output prefixes APP_URL twice, which is invisible
    // when the application is served from the root of a host (APP_URL
    // is empty, so twice is the same as once) and a 404 the moment it
    // sits in a subdirectory, as it does under XAMPP.
    $target = (string) ($n['url'] ?? '');
    if ($target === '' || preg_match('#^[a-z]+:|^//#i', $target)) {
        $target = 'notifications/index.php';
    }
    redirect($target);
}

/* ── Clear them ──────────────────────────────────────────────  */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // csrf_check() is the one that enforces; csrf_verify() only
    // answers the question and needs the token passing in.
    csrf_check();

    switch ($_POST['_action'] ?? '') {
        case 'read_all':
            $n = notifications_mark_all_read();
            flash('success', $n === 0
                ? 'Everything was already read.'
                : $n . ' notification' . ($n === 1 ? '' : 's') . ' marked as read.');
            break;

        case 'read_one':
            notification_mark_read((int) ($_POST['id'] ?? 0));
            break;

        case 'rescan':
            // Deliberately available by hand: somebody who has just
            // received stock wants the alert gone now, not in ten
            // minutes.
            $r = notifications_scan();
            flash('success', ($r['ok'] ?? false)
                ? "Checked {$r['rules']} rules in {$r['ms']}ms — {$r['opened']} open, {$r['resolved']} cleared."
                : 'Could not run the check.');
            break;
    }

    // Always back to this page, and deliberately not to wherever the
    // Referer header claims. Two reasons: redirect() prefixes what it
    // is given, so an absolute URL comes out mangled; and a redirect
    // that follows a header the caller controls is an open redirect,
    // which is a phishing link with this application's domain on it.
    redirect('notifications/index.php');
}

$showRead = ($_GET['show'] ?? '') === 'all';

// Clicking a summary card filters to that severity. A count you
// cannot click is a number you have to go and look for by hand.
$severity = $_GET['severity'] ?? '';
$severity = in_array($severity, ['critical', 'warning', 'info'], true) ? $severity : null;

// Ten to a page, the same as every other list in the application.
// A business that has let two hundred invoices go overdue does not
// want two hundred rows in one scroll; it wants the worst ten and a
// Next button.
$perPage  = per_page();
$total    = notifications_count(!$showRead, $severity);
$pg       = paginate($total, $perPage);
$items    = notifications_list($pg['per_page'], !$showRead, $pg['offset'], $severity);
$summary  = notifications_summary();

// Carried onto every page link and every card, so paging keeps the
// filter and filtering keeps the read/unread choice.
$baseQuery = array_filter([
    'show'     => $showRead ? 'all' : null,
    'severity' => $severity,
]);

/** A summary card's URL: click to filter, click again to clear. */
$cardUrl = static function (?string $sev) use ($showRead): string {
    $q = array_filter(['show' => $showRead ? 'all' : null, 'severity' => $sev]);
    return url('notifications/index.php' . ($q ? '?' . http_build_query($q) : ''));
};
$lastScan = table_exists('notification_scan_state')
    ? db_value('SELECT last_run_at FROM notification_scan_state')
    : null;

$pageStyles   = ['dashboard.css'];   // mini-grid summary cards
$pageTitle    = 'Notifications';
$pageSubtitle = $summary['unread']
    ? $summary['unread'] . ' unread'
    : 'Nothing needs your attention';
$breadcrumbs  = [['label' => 'Notifications']];

require_once __DIR__ . '/../../includes/header.php';
?>

<section class="mini-grid">
    <?php foreach ([
        ['critical', 'Critical',        $summary['critical'], 'mini-card--danger'],
        ['warning',  'Warnings',        $summary['warning'],  'mini-card--warn'],
        ['info',     'For information', $summary['info'],     ''],
    ] as [$sev, $label, $count, $tone]):
        $on = $severity === $sev;
    ?>
        <a class="mini-card <?= $count ? e($tone) : '' ?><?= $on ? ' is-selected' : '' ?>"
           href="<?= e($cardUrl($on ? null : $sev)) ?>"
           title="<?= $on ? 'Show everything again' : 'Show only ' . e($label) ?>">
            <span class="mini-label"><?= e($label) ?></span>
            <span class="mini-value"><?= (int) $count ?></span>
        </a>
    <?php endforeach; ?>
    <a class="mini-card<?= $severity === null && !$showRead ? ' is-selected' : '' ?>"
       href="<?= e(url('notifications/index.php')) ?>" title="Everything unread">
        <span class="mini-label">Unread</span>
        <span class="mini-value"><?= (int) $summary['unread'] ?></span>
    </a>
</section>

<form class="filter-bar" method="get">
    <?php if ($severity !== null): ?>
        <input type="hidden" name="severity" value="<?= e($severity) ?>">
    <?php endif; ?>
    <div class="filter-row filter-row--fields">
        <label class="check">
            <input type="checkbox" name="show" value="all"
                   <?= $showRead ? 'checked' : '' ?> data-autosubmit>
            Include the ones I have read
        </label>
    </div>
</form>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">
            <?= $showRead ? 'Everything open' : 'Unread' ?><?php
                if ($severity !== null) { echo ' &middot; ' . e(ucfirst($severity)); } ?>
            <?php if ($total > count($items)): ?>
                <span class="panel-sub"><?= (int) $total ?> in total</span>
            <?php endif; ?>
        </h2>
        <div class="panel-actions">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="rescan">
                <button type="submit" class="btn btn--ghost"><?= icon('undo') ?> Check now</button>
            </form>
            <?php if ($summary['unread']): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="read_all">
                    <button type="submit" class="btn btn--ghost"><?= icon('check') ?> Mark all read</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$items): ?>
        <p class="table-empty">
            <?php if ($severity !== null): ?>
                Nothing <?= e($severity) ?> <?= $showRead ? 'is open' : 'is unread' ?>.
                <a href="<?= e($cardUrl(null)) ?>">Show everything</a>
            <?php elseif ($showRead): ?>
                Nothing is open. The system is not worried about anything.
            <?php else: ?>
                Nothing unread. Tick the box above to see what you have already read.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <ul class="notif-list">
            <?php foreach ($items as $n): ?>
                <li class="notif-row notif-row--<?= e(notification_tone($n['severity'])) ?><?= $n['is_read'] ? ' is-read' : '' ?>">
                    <span class="notif-row-dot" aria-hidden="true"></span>
                    <div class="notif-row-main">
                        <a class="notif-row-title"
                           href="<?= e(url('notifications/index.php?go=' . (int) $n['notification_id'])) ?>">
                            <?= e($n['title']) ?>
                        </a>
                        <?php if ($n['body']): ?>
                            <p class="notif-row-body"><?= e($n['body']) ?></p>
                        <?php endif; ?>
                        <span class="notif-row-meta">
                            <span class="badge badge--<?= e($n['severity']) ?>"><?= e($n['severity']) ?></span>
                            <?= e(str_replace('_', ' ', $n['kind'])) ?>
                            &middot; <?= e(notification_when($n['created_at'])) ?>
                        </span>
                    </div>
                    <?php if (!$n['is_read']): ?>
                        <form method="post" class="notif-row-action">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="read_one">
                            <input type="hidden" name="id" value="<?= (int) $n['notification_id'] ?>">
                            <button type="submit" class="icon-btn" title="Mark as read"><?= icon('check') ?></button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($total > 0) { pagination_nav($pg, $baseQuery, 'notification'); } ?>

    <?php if ($lastScan): ?>
        <p class="notif-foot">
            Last checked <?= e(notification_when($lastScan)) ?>. The system looks again
            every few minutes, and clears an alert by itself once the thing it is
            about is dealt with.
        </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
