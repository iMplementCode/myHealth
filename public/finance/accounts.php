<?php

/**
 * ============================================================
 *  Cash Accounts
 * ------------------------------------------------------------
 *  The places money and near-money sits: tills and floats held
 *  by staff, loans made to employees, and goods released to
 *  customers on credit.
 *
 *  The account's type is what decides how it reports:
 *
 *    cash   → counts towards cash at hand
 *    loan   → out of the till but still owed back, so it leaves
 *             cash at hand and stays in operating revenue
 *    credit → never was cash, but is owed to the business, so it
 *             counts in operating revenue only
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_role(ROLE_MANAGER);
require_tables('cash_accounts', 'cash_transactions');

/* ── AJAX: create / update an account ───────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $id      = input_int($_POST, 'cash_account_id');
    $name    = trim(input($_POST, 'name'));
    $type    = input($_POST, 'account_type');
    $holder  = input_int($_POST, 'holder_user_id');
    $opening = (float) ($_POST['opening_balance'] ?? 0);

    if ($name === '') {
        json_response(['success' => false, 'message' => 'Give the account a name.']);
    }
    if (!isset(CASH_ACCOUNT_TYPES[$type])) {
        json_response(['success' => false, 'message' => 'Choose what kind of account this is.']);
    }
    $clash = db_value(
        "SELECT 1 FROM cash_accounts WHERE LOWER(name) = LOWER(:n) AND cash_account_id IS DISTINCT FROM :id",
        [':n' => $name, ':id' => $id]
    );
    if ($clash) {
        json_response(['success' => false, 'message' => 'An account called "' . $name . '" already exists.']);
    }

    try {
        if ($id !== null) {
            $existing = db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $id]);
            if (!$existing) {
                json_response(['success' => false, 'message' => 'That account no longer exists.']);
            }
            // Changing the type of an account that already has
            // movements would silently rewrite history — every past
            // day's cash at hand would shift.
            if ($existing['account_type'] !== $type) {
                $used = (int) db_value(
                    "SELECT COUNT(*) FROM cash_transactions WHERE cash_account_id = :id",
                    [':id' => $id]
                );
                if ($used > 0) {
                    json_response(['success' => false, 'message' => sprintf(
                        '%s already has %d entries, so its type cannot be changed. '
                        . 'Deactivate it and create a new account instead.',
                        $existing['name'], $used
                    )]);
                }
            }
            db_run(
                "UPDATE cash_accounts
                    SET name = :n, account_type = :t, holder_user_id = :h,
                        opening_balance = :ob, is_active = :active,
                        notes = :notes, updated_at = NOW()
                  WHERE cash_account_id = :id",
                [
                    ':n' => $name, ':t' => $type, ':h' => $holder, ':ob' => $opening,
                    ':active' => isset($_POST['is_active']) ? 't' : 'f',
                    ':notes' => input($_POST, 'notes') ?: null, ':id' => $id,
                ]
            );
            $message = 'Account updated.';
        } else {
            db_run(
                "INSERT INTO cash_accounts (name, account_type, holder_user_id, opening_balance, notes)
                 VALUES (:n, :t, :h, :ob, :notes)",
                [
                    ':n' => $name, ':t' => $type, ':h' => $holder, ':ob' => $opening,
                    ':notes' => input($_POST, 'notes') ?: null,
                ]
            );
            $message = 'Account created.';
        }
        audit_log($id !== null ? 'cash_account.update' : 'cash_account.create', 'cash_accounts', $id ?? 0, [
            'name' => $name, 'type' => $type,
        ]);
        json_response(['success' => true, 'message' => $message]);
    } catch (Throwable $ex) {
        error_log('[CASH] account save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not save the account.'], 500);
    }
}

/* ── Data ───────────────────────────────────────────────────── */
$accounts = cash_accounts(false);
$balances = cash_balances_asof(date('Y-m-d'));
$staff    = db_all("SELECT user_id, TRIM(CONCAT(first_name, ' ', last_name)) AS name FROM users WHERE is_active ORDER BY first_name");

$movements = [];
foreach (db_all(
    "SELECT cash_account_id, COUNT(*) AS n, MAX(txn_date) AS last_date
       FROM cash_transactions GROUP BY cash_account_id"
) as $m) {
    $movements[(int) $m['cash_account_id']] = $m;
}

$totals = ['cash' => 0.0, 'loan' => 0.0, 'credit' => 0.0];
foreach ($accounts as $a) {
    $totals[$a['account_type']] += $balances[(int) $a['cash_account_id']] ?? (float) $a['opening_balance'];
}

$pageTitle    = 'Cash Accounts';
$pageSubtitle = 'Tills, staff loans and customer credit';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Cash Accounts']];

require __DIR__ . '/../../includes/header.php';
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Cash at hand</span><span class="mini-value"><?= e(money($totals['cash'])) ?></span></div>
    <div class="mini-card"><span class="mini-label">Lent to staff</span><span class="mini-value"><?= e(money($totals['loan'])) ?></span></div>
    <div class="mini-card"><span class="mini-label">On customer credit</span><span class="mini-value"><?= e(money($totals['credit'])) ?></span></div>
    <div class="mini-card"><span class="mini-label">Operating revenue</span><span class="mini-value"><?= e(money(array_sum($totals))) ?></span></div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('finance/accounts.php')) ?>">
    <div class="toolbar-search">
        <p class="form-hint u-nomargin">
            An account's type decides how it reports. Cash counts towards cash at
            hand; loans and credit are owed back, so they stay in operating
            revenue without being cash.
        </p>
        <?= toolbar_apply('finance/accounts.php') ?>
    </div>
    <button type="button" class="btn btn-primary" data-modal-open="tplAccountForm" data-modal-title="Add Account" data-modal-auto>
        <?= icon('plus') ?> Add Account
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Type</th>
                    <th>Held by</th>
                    <th class="ta-right">Opening</th>
                    <th class="ta-right">Balance now</th>
                    <th class="ta-right">Entries</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$accounts): ?>
                    <tr><td colspan="8" class="table-empty">No accounts yet. Add your first till.</td></tr>
                <?php endif; ?>
                <?php foreach ($accounts as $a):
                    $id      = (int) $a['cash_account_id'];
                    $balance = $balances[$id] ?? (float) $a['opening_balance'];
                    $mv      = $movements[$id] ?? null;
                    $prefill = json_encode([
                        'cash_account_id' => $id,
                        'name'            => $a['name'],
                        'account_type'    => $a['account_type'],
                        'holder_user_id'  => $a['holder_user_id'],
                        'opening_balance' => $a['opening_balance'],
                        'is_active'       => $a['is_active'] ? 1 : '',
                        'notes'           => $a['notes'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td><strong><?= e($a['name']) ?></strong></td>
                        <td><span class="role-pill"><?= e(cash_account_type_label($a['account_type'])) ?></span></td>
                        <td class="cell-muted"><?= e($a['holder_name'] ?: '—') ?></td>
                        <td class="ta-right cell-muted"><?= e(money($a['opening_balance'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($balance)) ?></strong></td>
                        <td class="ta-right cell-muted">
                            <?= $mv ? e(num((int) $mv['n'])) : '0' ?>
                            <?php if ($mv): ?><br><small>last <?= e(fmt_date($mv['last_date'])) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= $a['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $a['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplAccountForm" data-modal-title="Edit Account"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <a class="icon-btn" title="View entries"
                                   href="<?= e(url('finance/cashbook.php?account=' . $id . '&from=')) ?>"><?= icon('file') ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<template id="tplAccountForm">
    <form method="POST" action="<?= e(url('finance/accounts.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="cash_account_id" value="">

        <div class="form-group">
            <label class="form-label">Name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="120"
                   placeholder="e.g. Samuel, Kamala, Samuel (loan), Limeline credit">
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Type <span class="req">*</span></label>
                <select name="account_type" class="form-control" required>
                    <?php foreach (CASH_ACCOUNT_TYPES as $key => $meta): ?>
                        <option value="<?= e($key) ?>"><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint">
                    Cash counts towards cash at hand. Staff loans and customer
                    credit count towards operating revenue only.
                </p>
            </div>
            <div class="form-group">
                <label class="form-label">Held by <span class="opt">(optional)</span></label>
                <select name="holder_user_id" class="form-control" data-searchable="Search people">
                    <option value="">— Nobody in particular —</option>
                    <?php foreach ($staff as $u): ?>
                        <option value="<?= (int) $u['user_id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Opening balance (KES)</label>
            <input type="number" name="opening_balance" class="form-control" step="0.01" value="0">
            <p class="form-hint">
                What the account held before the cash book started. Every balance
                is this plus the entries recorded since.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control">
        </div>

        <div class="form-group form-check">
            <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save account</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
