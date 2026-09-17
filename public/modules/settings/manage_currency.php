<?php

/**
 * ============================================================
 *  Currencies — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page into the shared
 *  layout. Add and edit both use the shared modal + AJAX
 *  pattern, so managing currencies never leaves this page.
 *
 *  KES is the business base currency (see migration 008) and is
 *  protected from deletion — documents default to it.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_role(ROLE_ADMIN, ROLE_MANAGER);

/* ── AJAX: create or update a currency ────────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $isCreate = $_POST['_action'] === 'create';
    $id       = input_int($_POST, 'currency_id');
    $name     = input($_POST, 'name');
    $code     = strtoupper(input($_POST, 'code'));
    $symbol   = input($_POST, 'symbol');
    $rate     = input($_POST, 'exchange_rate');
    $active   = isset($_POST['is_active']) ? 't' : 'f';

    $errors = [];
    if ($name === '')                       $errors[] = 'Currency name is required.';
    if (!preg_match('/^[A-Z]{3}$/', $code)) $errors[] = 'Code must be exactly three letters (e.g. KES).';
    if ($symbol === '')                     $errors[] = 'Symbol is required.';
    if (!is_numeric($rate) || (float) $rate <= 0) $errors[] = 'Exchange rate must be greater than zero.';
    if (!$isCreate && ($id === null || !db_value("SELECT 1 FROM currencies WHERE currency_id = :id", [':id' => $id]))) {
        $errors[] = 'That currency no longer exists.';
    }
    // Say which currency holds the code, rather than leaving the
    // user searching for a duplicate that is not there. Symbols are
    // deliberately not checked: ¥ is both the yen and the yuan, and
    // $ belongs to half a dozen dollars.
    $clash = db_one(
        "SELECT code, name FROM currencies
          WHERE UPPER(code) = UPPER(:c) AND currency_id IS DISTINCT FROM :id",
        [':c' => $code, ':id' => $isCreate ? null : $id]
    );
    if ($clash) {
        $errors[] = sprintf('%s is already used by "%s".', $clash['code'], $clash['name']);
    }

    if ($errors) {
        json_response(['success' => false, 'message' => implode(' ', $errors)]);
    }

    try {
        if ($isCreate) {
            $id = (int) db_value(
                "INSERT INTO currencies (name, code, symbol, exchange_rate, is_active)
                 VALUES (:name, :code, :symbol, :rate, :active)
                 RETURNING currency_id",
                [':name' => $name, ':code' => $code, ':symbol' => $symbol, ':rate' => $rate, ':active' => $active]
            );
        } else {
            db_run(
                "UPDATE currencies
                    SET name = :name, code = :code, symbol = :symbol,
                        exchange_rate = :rate, is_active = :active, updated_at = NOW()
                  WHERE currency_id = :id",
                [':name' => $name, ':code' => $code, ':symbol' => $symbol,
                 ':rate' => $rate, ':active' => $active, ':id' => $id]
            );
        }
        audit_log($isCreate ? 'currency.create' : 'currency.update', 'currencies', $id, ['code' => $code]);
        json_response(['success' => true, 'message' => $isCreate ? 'Currency added.' : 'Currency updated.']);
    } catch (PDOException $ex) {
        error_log('[CURRENCY] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getCode() === '23505'
            ? 'That currency code is already in use.'
            : db_rule_message($ex, 'Could not save the currency. Please try again.')], 500);
    }
}

/* ── POST: delete a currency ──────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    $id  = input_int($_POST, 'currency_id');
    $cur = $id !== null ? db_one("SELECT code, name FROM currencies WHERE currency_id = :id", [':id' => $id]) : null;
    if (!$cur) {
        flash('error', 'That currency no longer exists.');
    } elseif (strtoupper($cur['code']) === 'KES') {
        flash('error', 'KES is the base currency and cannot be deleted. Deactivate it instead.');
    } else {
        // Any document already priced in this currency keeps it, so
        // refuse rather than orphan the reference.
        $used = 0;
        foreach (['quotes', 'sales_orders', 'invoices', 'proforma_invoices', 'purchase_orders'] as $t) {
            try {
                $used += (int) db_value("SELECT COUNT(*) FROM $t WHERE currency_id = :id", [':id' => $id]);
            } catch (PDOException) {
                // Table absent on this database — nothing to protect.
            }
        }
        if ($used > 0) {
            flash('error', sprintf(
                '%s is used by %d document%s and cannot be deleted. Deactivate it instead.',
                $cur['code'], $used, $used === 1 ? '' : 's'
            ));
        } else {
            try {
                db_run("DELETE FROM currencies WHERE currency_id = :id", [':id' => $id]);
                audit_log('currency.delete', 'currencies', $id, ['code' => $cur['code']]);
                flash('success', $cur['code'] . ' deleted.');
            } catch (PDOException $ex) {
                error_log('[CURRENCY] delete failed: ' . $ex->getMessage());
                flash('error', 'Could not delete that currency.');
            }
        }
    }
    redirect('modules/settings/manage_currency.php');
}

/* ── Data ─────────────────────────────────────────────────── */
$currencies = db_all(
    "SELECT * FROM currencies
     ORDER BY (LOWER(code) = 'kes') DESC, is_active DESC, code"
);

$pageTitle    = 'Currencies';
$pageSubtitle = 'Currencies available on documents';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Settings'], ['label' => 'Currencies']];

require __DIR__ . '/../../../includes/header.php';
?>

<div class="toolbar">
    <div></div>
    <button type="button" class="btn btn-primary"
            data-modal-open="tplCurrencyForm" data-modal-title="Add Currency" data-modal-auto
            data-prefill='<?= e(json_encode([
                '_action' => 'create', 'currency_id' => '', 'exchange_rate' => '1.0000', 'is_active' => 1,
            ], JSON_UNESCAPED_SLASHES)) ?>'>
        <?= icon('plus') ?> Add Currency
    </button>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Symbol</th>
                    <th class="ta-right">Rate</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$currencies): ?>
                    <tr><td colspan="6" class="table-empty">No currencies configured.</td></tr>
                <?php endif; ?>
                <?php foreach ($currencies as $c): ?>
                    <?php
                    $isBase  = strtoupper($c['code']) === 'KES';
                    $prefill = json_encode([
                        'currency_id'   => $c['currency_id'],
                        'name'          => $c['name'],
                        'code'          => $c['code'],
                        'symbol'        => $c['symbol'],
                        'exchange_rate' => $c['exchange_rate'],
                        'is_active'     => $c['is_active'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($c['code']) ?></span>
                            <?php if ($isBase): ?><span class="cell-sub">Base currency</span><?php endif; ?>
                        </td>
                        <td><?= e($c['name']) ?></td>
                        <td class="cell-muted"><?= e($c['symbol']) ?></td>
                        <td class="ta-right"><?= e(num($c['exchange_rate'], 4)) ?></td>
                        <td>
                            <span class="badge badge--<?= $c['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplCurrencyForm" data-modal-title="Edit Currency"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <?php if (!$isBase): ?>
                                    <form method="POST" action="<?= e(url('modules/settings/manage_currency.php')) ?>" class="inline-form"
                                          data-confirm="Delete <?= e($c['code']) ?>? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="currency_id" value="<?= (int) $c['currency_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<template id="tplCurrencyForm">
    <form method="POST" action="<?= e(url('modules/settings/manage_currency.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="currency_id" value="">

        <div class="form-group">
            <label class="form-label">Currency name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="50" placeholder="Kenyan Shilling">
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Code <span class="req">*</span></label>
                <input type="text" name="code" class="form-control" required maxlength="3"
                       style="text-transform:uppercase;" placeholder="KES">
            </div>
            <div class="form-group">
                <label class="form-label">Symbol <span class="req">*</span></label>
                <input type="text" name="symbol" class="form-control" required maxlength="5" placeholder="KSh">
                <p class="form-hint">May be shared — ¥ is both the yen and the yuan.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Exchange rate <span class="req">*</span></label>
                <input type="number" name="exchange_rate" class="form-control" required min="0.0001" step="0.0001">
            </div>
        </div>

        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="is_active">
                <span>Active (selectable on new documents)</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save currency</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
