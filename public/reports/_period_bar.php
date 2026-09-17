<?php

/**
 * ============================================================
 *  The period picker every report shares
 * ------------------------------------------------------------
 *  Included after the header. Expects $from and $to to be set,
 *  and reads the current script's own URL so one file serves
 *  every report.
 * ============================================================
 */

if (!defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

$selfPath = 'reports/' . basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
$today    = date('Y-m-d');

/** Query string for a preset range, keeping any extra filters. */
$presetUrl = static function (string $f, string $t) use ($selfPath): string {
    $q = $_GET;
    $q['from'] = $f;
    $q['to']   = $t;
    return url($selfPath . '?' . http_build_query($q));
};
?>

<form class="filter-bar" method="GET" action="<?= e(url($selfPath)) ?>">
    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
        </label>
        <?php // Carry any filter the report added of its own. ?>
        <?php foreach ($_GET as $k => $v): ?>
            <?php if (in_array($k, ['from', 'to', 'page'], true) || is_array($v)) { continue; } ?>
            <input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
        <?php endforeach; ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Apply</button>
            <a class="btn btn-ghost" href="<?= e($presetUrl(date('Y-m-01'), $today)) ?>">This month</a>
            <a class="btn btn-ghost" href="<?= e($presetUrl(
                date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month')))) ?>">Last month</a>
            <a class="btn btn-ghost" href="<?= e($presetUrl(date('Y-01-01'), $today)) ?>">Year to date</a>
            <?php // Every report on this bar exports; the link repeats the
                  // request the page is already answering. ?>
            <?= export_button($selfPath, array_diff_key($_GET, ['export' => 1])) ?>
            <button type="button" class="btn btn-ghost" data-print><?= icon('file') ?> Print</button>
            <a class="btn btn-ghost" href="<?= e(url('reports/index.php')) ?>"><?= icon('grid') ?> All reports</a>
        </div>
    </div>
</form>
