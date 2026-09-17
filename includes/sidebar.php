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
 *  Layout — Sidebar
 * ------------------------------------------------------------
 *  Data-driven from includes/navigation.php. Included by
 *  header.php. Never included directly.
 * ============================================================
 */
if (!defined('BASE_PATH')) {
    exit;
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <a href="<?= e(url('dashboard/index.php')) ?>" class="brand-link">
            <span class="brand-mark">mH</span>
            <?php /*  APP_NAME rather than a second copy of the product's
                      name. The mark beside it had already been changed to
                      "mH" and this had not, so a pharmacy called myHealth
                      introduced itself as iMplementERP on every page it
                      served. Split on the first capital so the accent
                      still lands the way it was drawn: my|Health.      */
                  $brandParts = preg_match('/^(.+?)([A-Z].*)$/', APP_NAME, $m)
                      ? [$m[1], $m[2]]
                      : [APP_NAME, '']; ?>
            <span class="brand-text"><?= e($brandParts[0]) ?><span class="brand-accent"><?= e($brandParts[1]) ?></span></span>
        </a>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php foreach (nav_items() as $item): ?>
            <?php if (empty($item['children'])): ?>
                <a href="<?= e(url($item['href'])) ?>"
                   class="nav-link <?= nav_is_active($item['href']) ? 'is-active' : '' ?>">
                    <?= icon($item['icon'], 'nav-ic') ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php else: ?>
                <div class="nav-group <?= nav_group_active($item) ? 'is-open' : '' ?>" data-nav-group>
                    <button type="button" class="nav-link nav-toggle" data-nav-toggle>
                        <?= icon($item['icon'], 'nav-ic') ?>
                        <span><?= e($item['label']) ?></span>
                        <?= icon('chevron', 'nav-caret') ?>
                    </button>
                    <div class="nav-sub">
                        <div class="nav-sub-inner">
                            <?php // Children carry their own roles too. A group can be
                                  // open to managers while one entry inside it is not,
                                  // and the menu should say so rather than offering a
                                  // link that answers 403. ?>
                            <?php foreach ($item['children'] as $child): ?>
                                <?php if (!nav_can_see($child)) { continue; } ?>
                                <a href="<?= e(url($child['href'])) ?>"
                                   class="nav-sub-link <?= nav_is_active($child['href']) ? 'is-active' : '' ?>">
                                    <?php // Nine text links in a column read as a wall;
                                          // an icon gives each one something to aim at.
                                          // 'box' is icon()'s own fallback, so a child
                                          // added without one still lines up. ?>
                                    <?= icon($child['icon'] ?? 'box', 'nav-sub-ic') ?>
                                    <span><?= e($child['label']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <a href="<?= e(url('logout.php')) ?>" class="nav-link nav-link--muted">
            <?= icon('logout', 'nav-ic') ?>
            <span>Sign out</span>
        </a>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>
