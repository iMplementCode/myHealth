<?php

/**
 * ============================================================
 *  Settings — Start this shop again
 * ------------------------------------------------------------
 *  Clears everything the shop has done and leaves it as it was
 *  the day it was created: no customers, no products, no
 *  invoices, no uploaded files, and one administrator.
 *
 *  It exists for the handover — a shop is practised on for a
 *  week and then has to open its books clean.
 *
 *  ── There is no undo ──
 *
 *  So three things stand in front of it, and each one blocks a
 *  different mistake:
 *
 *    the company name, typed   — a wrong tab, the wrong shop
 *    the password, re-entered  — a machine left unlocked
 *    administrators only       — everybody else
 *
 *  The typed name is not only a confirmation: it is what the
 *  shop is re-branded with afterwards, so the one thing the
 *  person had to prove they knew is the one thing that survives.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/reset.php';
require_role(ROLE_ADMIN);

$me          = current_user();
$companyName = (string) (db_value('SELECT company_name FROM company_settings WHERE id = 1') ?? '');
$done        = null;
$errors      = [];

/*  What is about to be lost, counted rather than described. "All
 *  your data" is easy to click past; "4,812 invoices" is not. */
$tally = [];
foreach ([
    'customers' => 'Customers',
    'products'  => 'Products',
    'invoices'  => 'Invoices',
    'quotes'    => 'Quotes',
    'expenses'  => 'Expenses',
    'notes'     => 'Notes',
] as $table => $label) {
    if (table_exists($table)) {
        $tally[$label] = (int) db_value("SELECT COUNT(*) FROM \"$table\"");
    }
}
$otherUsers = (int) db_value('SELECT COUNT(*) FROM users WHERE user_id <> :id', [':id' => $me['id']]);

if (is_post()) {
    csrf_check();

    $typed    = input($_POST, 'company_name');
    $password = (string) ($_POST['password'] ?? '');

    $row = db_one('SELECT password_hash FROM users WHERE user_id = :id', [':id' => $me['id']]);

    if ($companyName === '') {
        $errors[] = 'This shop has no company name set, so there is nothing to confirm against. '
                  . 'Set one in Company Details first.';
    } elseif ($typed !== $companyName) {
        $errors[] = 'That is not this shop\'s name. Nothing has been changed.';
    } elseif (!$row || !password_verify($password, (string) $row['password_hash'])) {
        // Recorded: somebody with an administrator's screen but not
        // their password is the case this step exists for.
        audit_log('shop_reset_refused', 'company', 1, ['reason' => 'password']);
        $errors[] = 'That password is not correct. Nothing has been changed.';
    } else {
        try {
            $done = reset_shop((int) $me['id'], $companyName, session_id());
        } catch (Throwable $e) {
            $errors[] = 'The reset did not finish: ' . $e->getMessage();
            error_log('[RESET] ' . $e->getMessage());
        }
    }
}

$pageTitle    = 'Start this shop again';
$pageSubtitle = $companyName !== '' ? $companyName : 'This shop';
$breadcrumbs  = [['label' => 'Settings'], ['label' => 'Start again']];
$pageStyles   = ['forms.css'];

require __DIR__ . '/../../../includes/header.php';
?>

<?php if ($done): ?>

    <div class="alert alert--success" role="status">
        <span>
            <strong>The shop has been reset.</strong>
            <?= (int) $done['tables'] ?> tables cleared,
            <?= (int) $done['files'] ?> uploaded file<?= $done['files'] === 1 ? '' : 's' ?> deleted,
            and <?= (int) $done['users_removed'] ?> other account<?= $done['users_removed'] === 1 ? '' : 's' ?>
            removed. You are still signed in and your password has not changed.
        </span>
    </div>

    <div class="panel u-pad">
        <p>This shop is now exactly as it was the day it was created. The next
           invoice will be number one.</p>
        <p class="u-mt">
            <a href="<?= e(url('dashboard/index.php')) ?>" class="btn btn-primary">
                <?= icon('grid') ?> Go to the dashboard
            </a>
        </p>
    </div>

<?php else: ?>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert--error" role="alert"><span><?= e($err) ?></span></div>
    <?php endforeach; ?>

    <div class="alert alert--warning" role="alert" style="align-items:flex-start;">
        <span>
            <strong>This cannot be undone.</strong>
            Everything below is deleted permanently — including the uploaded
            payment slips, refund proofs and product images. There is no undo
            and no recycle bin. If this shop has traded for real, take a backup
            before you continue.
        </span>
    </div>

    <div class="panel">
        <div class="panel-head"><h2 class="panel-title">What will be deleted</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Records</th><th class="ta-right">Count</th></tr></thead>
                <tbody>
                    <?php foreach ($tally as $label => $n): ?>
                        <tr>
                            <td><?= e($label) ?></td>
                            <td class="ta-right"><?= e(num($n)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td>Other user accounts</td>
                        <td class="ta-right"><?= e(num($otherUsers)) ?></td>
                    </tr>
                    <tr>
                        <td class="cell-muted">Everything else — payments, stock, deliveries,
                            purchase orders, the audit history and every
                            uploaded file</td>
                        <td class="ta-right cell-muted">all</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel u-pad u-mt">
        <h2 class="panel-title">What will be kept</h2>
        <p class="cell-muted">Your own account and password, this shop's name,
           and the starter lists a new shop begins with — categories, brands,
           units and cash accounts. Everyone else is
           signed out.</p>
    </div>

    <form method="POST" class="form-card u-mt" autocomplete="off">
        <?= csrf_field() ?>

        <div class="form-group">
            <label class="form-label" for="company_name">
                Type this shop's name to confirm <span class="req">*</span>
            </label>
            <input type="text" id="company_name" name="company_name" class="form-control"
                   placeholder="<?= e($companyName !== '' ? $companyName : 'the company name') ?>"
                   autocomplete="off" required>
            <span class="hint">Exactly as it appears on your invoices.</span>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Your password <span class="req">*</span></label>
            <input type="password" id="password" name="password" class="form-control"
                   autocomplete="current-password" required>
            <span class="hint">So that an unlocked screen is not enough.</span>
        </div>

        <div class="form-actions">
            <a href="<?= e(url('dashboard/index.php')) ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger"
                    data-confirm="Delete everything in this shop? This cannot be undone."
                    data-confirm-label="Delete everything">
                <?= icon('trash') ?> Delete everything and start again
            </button>
        </div>
    </form>

<?php endif; ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
