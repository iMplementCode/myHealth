<?php

/**
 * ============================================================
 *  Investor Capital
 * ------------------------------------------------------------
 *  Money put into the business by investors. Which of two kinds
 *  it is decides where it lands, so it is recorded rather than
 *  assumed:
 *
 *    equity — bought into the business. Never repaid, so it is
 *             capital. It raises cash and raises the owners'
 *             stake; it is not income and never appears in
 *             sales.
 *
 *    loan   — borrowed. It raises cash but the business owes it
 *             back, so it sits under liabilities and leaves net
 *             asset value unchanged on the day it arrives.
 *
 *  Only a loan can be repaid. Returning money to an equity
 *  investor is a drawing or a dividend, not a repayment, and
 *  the database refuses to confuse the two.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_role(ROLE_MANAGER);
require_tables('investments', 'investment_repayments', 'cash_accounts');

/* ── AJAX: record an investment ─────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'record') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $investor  = trim(input($_POST, 'investor_name'));
    $type      = input($_POST, 'investment_type');
    $amount    = (float) ($_POST['amount'] ?? 0);
    $date      = input($_POST, 'received_on') ?: date('Y-m-d');
    $method    = input($_POST, 'method');
    $accountId = input_int($_POST, 'cash_account_id');

    if ($investor === '') {
        json_response(['success' => false, 'message' => 'Give the investor a name.']);
    }
    if (!in_array($type, ['equity', 'loan'], true)) {
        json_response(['success' => false, 'message' => 'Say whether this is equity or a loan.']);
    }
    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'The amount must be more than zero.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'Please provide a valid date, not in the future.']);
    }
    if (!isset(PAYMENT_METHODS[$method]) || in_array($method, ['salary_deduction', 'card'], true)) {
        json_response(['success' => false, 'message' => 'Choose how the money was received.']);
    }
    $account = $accountId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
        : null;
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        json_response(['success' => false, 'message' => 'Choose the account the money was received into.']);
    }

    // Equity is not repayable, so it carries no loan terms. The
    // database enforces this too; catching it here gives a better
    // message than a constraint violation would.
    $isLoan   = $type === 'loan';
    $rate     = $isLoan && input($_POST, 'interest_rate') !== '' ? (float) $_POST['interest_rate'] : null;
    $dueDate  = $isLoan ? (input($_POST, 'due_date') ?: null) : null;
    $equityPc = !$isLoan && input($_POST, 'equity_percent') !== '' ? (float) $_POST['equity_percent'] : null;

    if ($rate !== null && $rate < 0) {
        json_response(['success' => false, 'message' => 'An interest rate cannot be negative.']);
    }
    if ($equityPc !== null && ($equityPc < 0 || $equityPc > 100)) {
        json_response(['success' => false, 'message' => 'An equity share must be between 0 and 100 per cent.']);
    }
    if ($dueDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        json_response(['success' => false, 'message' => 'Please provide a valid due date.']);
    }

    $agreement = ['file' => null, 'error' => null];
    if (!empty($_FILES['agreement']['name'])) {
        $agreement = store_uploaded_document($_FILES['agreement'], 'investments');
        if ($agreement['error']) {
            json_response(['success' => false, 'message' => $agreement['error']]);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $ref = next_document_number('investments', 'reference_no', 'INV');
        $stmt = $pdo->prepare(
            "INSERT INTO investments
                (reference_no, investor_name, investment_type, received_on, amount,
                 cash_account_id, method, reference, equity_percent, interest_rate, due_date,
                 agreement_url, agreement_filename, notes, recorded_by)
             VALUES (:ref, :who, :type, :d, :amt, :acct, :m, :extref,
                     :pc, :rate, :due, :aurl, :aname, :notes, :by)
             RETURNING investment_id"
        );
        $stmt->execute([
            ':ref' => $ref, ':who' => $investor, ':type' => $type, ':d' => $date, ':amt' => $amount,
            ':acct' => $accountId, ':m' => $method,
            ':extref' => input($_POST, 'reference') ?: null,
            ':pc' => $equityPc, ':rate' => $rate, ':due' => $dueDate,
            ':aurl' => $agreement['file']['url'] ?? null,
            ':aname' => $agreement['file']['filename'] ?? null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by' => current_user()['id'],
        ]);
        $investmentId = (int) $stmt->fetchColumn();

        cash_post([
            'date'        => $date,
            'account_id'  => $accountId,
            'direction'   => 'in',
            'amount'      => $amount,
            'category'    => 'investment',
            'party'       => $investor,
            'description' => ($isLoan ? 'Investor loan ' : 'Equity investment ') . $ref . ' from ' . $investor,
            'source_type' => 'investment',
            'source_id'   => $investmentId,
        ]);

        $pdo->commit();
        audit_log('investment.record', 'investments', $investmentId, [
            'reference' => $ref, 'investor' => $investor, 'type' => $type, 'amount' => $amount,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s received from %s as %s. %s',
            money($amount), $investor, $ref,
            $isLoan
                ? 'It is held under liabilities until repaid.'
                : 'It is capital, so it raises the net asset value.'
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[INVEST] record failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the investment.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[INVEST] record failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the investment.'], 500);
    }
}

/* ── AJAX: repay an investor loan ───────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'repay') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $id  = input_int($_POST, 'investment_id');
    $inv = $id !== null ? db_one(
        "SELECT v.*, COALESCE(r.repaid, 0) AS repaid
           FROM investments v
           LEFT JOIN (SELECT investment_id, SUM(amount) AS repaid FROM investment_repayments GROUP BY investment_id) r
                  ON r.investment_id = v.investment_id
          WHERE v.investment_id = :id",
        [':id' => $id]
    ) : null;

    if (!$inv) {
        json_response(['success' => false, 'message' => 'That investment no longer exists.']);
    }
    if ($inv['investment_type'] !== 'loan') {
        json_response(['success' => false, 'message' =>
            $inv['reference_no'] . ' is equity, not a loan. Returning money to an equity '
            . 'investor is a drawing or a dividend — record it in the cash book.']);
    }

    $amount    = (float) ($_POST['amount'] ?? 0);
    $date      = input($_POST, 'paid_on') ?: date('Y-m-d');
    $accountId = input_int($_POST, 'from_account_id');
    $account   = $accountId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
        : null;

    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'The repayment must be more than zero.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid repayment date.']);
    }
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        json_response(['success' => false, 'message' => 'Choose the account the repayment is paid from.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO investment_repayments
                (investment_id, paid_on, amount, from_account_id, reference, notes, recorded_by)
             VALUES (:v, :d, :amt, :acct, :ref, :notes, :by)
             RETURNING repayment_id"
        );
        $stmt->execute([
            ':v' => $id, ':d' => $date, ':amt' => $amount, ':acct' => $accountId,
            ':ref' => input($_POST, 'reference') ?: null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by' => current_user()['id'],
        ]);
        $repaymentId = (int) $stmt->fetchColumn();

        cash_post([
            'date'        => $date,
            'account_id'  => $accountId,
            'direction'   => 'out',
            'amount'      => $amount,
            'category'    => 'transfer',
            'party'       => $inv['investor_name'],
            'description' => 'Repayment of investor loan ' . $inv['reference_no'],
            'source_type' => 'investment_repayment',
            'source_id'   => $repaymentId,
        ]);

        $pdo->commit();
        $left = (float) $inv['amount'] - (float) $inv['repaid'] - $amount;
        audit_log('investment.repay', 'investment_repayments', $repaymentId, [
            'reference' => $inv['reference_no'], 'amount' => $amount,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s repaid to %s. %s',
            money($amount), $inv['investor_name'],
            $left > 0.005 ? money($left) . ' still owed.' : 'The loan is now fully repaid.'
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[INVEST] repay failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the repayment.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[INVEST] repay failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the repayment.'], 500);
    }
}

/* ── Data ───────────────────────────────────────────────────── */
$typeFilter = input($_GET, 'type');
$search     = input($_GET, 'q');
$focus      = input_int($_GET, 'focus');
$perPage    = per_page();

$where  = [];
$params = [];
// "Show me this one" — how the cash book links back to the
// investment behind a line. Clear gets out of it.
if ($focus !== null) {
    $where[] = "v.investment_id = :focus";
    $params[':focus'] = $focus;
}
if (in_array($typeFilter, ['equity', 'loan'], true)) {
    $where[] = "v.investment_type = :t";
    $params[':t'] = $typeFilter;
}
if ($search !== '') {
    $where[] = "(LOWER(v.investor_name) LIKE :q OR LOWER(v.reference_no) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM investments v $whereSql", $params);
$pg    = paginate($total, $perPage);

$investments = db_all(
    "SELECT v.*, COALESCE(r.repaid, 0) AS repaid, a.name AS account_name
       FROM investments v
       LEFT JOIN cash_accounts a ON a.cash_account_id = v.cash_account_id
       LEFT JOIN (SELECT investment_id, SUM(amount) AS repaid FROM investment_repayments GROUP BY investment_id) r
              ON r.investment_id = v.investment_id
     $whereSql
     ORDER BY v.received_on DESC, v.investment_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$today       = date('Y-m-d');
$capital     = finance_invested_capital($today);
$loansOwed   = finance_investor_loans($today);
$raisedTotal = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM investments");

$accounts = cash_liquid_accounts();

$pageTitle    = 'Investor Capital';
$pageSubtitle = 'Equity put into the business, and loans it must repay';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js', 'investment-form.js'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Investor Capital']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter(['type' => $typeFilter, 'q' => $search, 'focus' => $focus], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Equity capital</span><span class="mini-value tone-in"><?= e(money($capital)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Investor loans owed</span><span class="mini-value tone-out"><?= e(money($loansOwed)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Raised in total</span><span class="mini-value"><?= e(money($raisedTotal)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Investors on record</span><span class="mini-value"><?= e(num($total)) ?></span></div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('finance/investors.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search investor or reference…">
        <select name="type" class="form-control toolbar-select" data-autosubmit>
            <option value="">Equity and loans</option>
            <option value="equity" <?= $typeFilter === 'equity' ? 'selected' : '' ?>>Equity only</option>
            <option value="loan"   <?= $typeFilter === 'loan' ? 'selected' : '' ?>>Loans only</option>
        </select>
        <?= toolbar_apply('finance/investors.php') ?>
    </div>
    <button type="button" class="btn btn-primary" data-modal-open="tplInvestmentForm" data-modal-title="Record Investment" data-modal-auto>
        <?= icon('plus') ?> Record Investment
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Investor</th>
                    <th>Kind</th>
                    <th>Received</th>
                    <th>Into</th>
                    <th class="ta-right">Amount</th>
                    <th class="ta-right">Outstanding</th>
                    <th>Terms</th>
                    <th>Agreement</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$investments): ?>
                    <tr><td colspan="10" class="table-empty">
                        No investments recorded yet.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($investments as $v):
                    $isLoan = $v['investment_type'] === 'loan';
                    $left   = (float) $v['amount'] - (float) $v['repaid'];
                    $repayFill = json_encode([
                        'investment_id' => $v['investment_id'],
                        'paid_on'       => date('Y-m-d'),
                        'amount'        => number_format(max($left, 0), 2, '.', ''),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($v['reference_no']) ?></span></td>
                        <td><?= e($v['investor_name']) ?></td>
                        <td>
                            <span class="badge badge--<?= $isLoan ? 'pending' : 'active' ?>">
                                <?= $isLoan ? 'Investor loan' : 'Equity' ?>
                            </span>
                        </td>
                        <td class="cell-muted"><?= e(fmt_date($v['received_on'])) ?></td>
                        <td class="cell-muted"><?= e($v['account_name'] ?: '—') ?></td>
                        <td class="ta-right"><strong><?= e(money($v['amount'])) ?></strong></td>
                        <td class="ta-right">
                            <?php if (!$isLoan): ?>
                                <span class="cell-muted">Not repayable</span>
                            <?php elseif ($left > 0.005): ?>
                                <strong class="tone-out"><?= e(money($left)) ?></strong>
                            <?php else: ?>
                                <span class="cell-muted">Repaid</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted">
                            <?php if ($isLoan): ?>
                                <?= $v['interest_rate'] !== null ? e(num($v['interest_rate'], 2)) . '%' : 'No interest' ?>
                                <?php if ($v['due_date']): ?><br><small>due <?= e(fmt_date($v['due_date'])) ?></small><?php endif; ?>
                            <?php else: ?>
                                <?= $v['equity_percent'] !== null ? e(num($v['equity_percent'], 2)) . '% share' : '—' ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['agreement_url']): ?>
                                <a href="<?= e(private_document_url($v['agreement_url'])) ?>" target="_blank" rel="noopener"
                                   class="icon-btn" title="View agreement"><?= icon('file') ?></a>
                            <?php else: ?>
                                <span class="cell-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <?php if ($isLoan && $left > 0.005): ?>
                                <button type="button" class="btn btn-ghost btn--sm"
                                        data-modal-open="tplInvestmentRepay"
                                        data-modal-title="Repay <?= e($v['reference_no']) ?>"
                                        data-prefill="<?= e($repayFill) ?>">Repay</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'investment'); ?>
</div>

<template id="tplInvestmentForm">
    <form method="POST" action="<?= e(url('finance/investors.php')) ?>" data-ajax
          enctype="multipart/form-data" data-investment-form>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="record">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Investor <span class="req">*</span></label>
                <input type="text" name="investor_name" class="form-control" required maxlength="160">
            </div>
            <div class="form-group">
                <label class="form-label">Kind <span class="req">*</span></label>
                <select name="investment_type" class="form-control" required data-investment-type>
                    <option value="equity">Equity — bought into the business</option>
                    <option value="loan">Loan — must be repaid</option>
                </select>
                <p class="form-hint" data-investment-help>
                    Equity is capital: never repaid, and it raises the net asset value.
                </p>
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
            </div>
            <div class="form-group">
                <label class="form-label">Date received <span class="req">*</span></label>
                <input type="date" name="received_on" class="form-control" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Received into <span class="req">*</span></label>
                <select name="cash_account_id" class="form-control" required data-searchable="Search accounts">
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $ac): ?>
                        <option value="<?= (int) $ac['cash_account_id'] ?>">
                            <?= e($ac['name']) ?> · <?= e(cash_account_type_label($ac['account_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group" data-investment-equity>
            <label class="form-label">Share of the business <span class="opt">(per cent, if agreed)</span></label>
            <input type="number" name="equity_percent" class="form-control" min="0" max="100" step="0.001">
        </div>

        <div class="form-grid-2" data-investment-loan hidden>
            <div class="form-group">
                <label class="form-label">Interest rate <span class="opt">(per cent)</span></label>
                <input type="number" name="interest_rate" class="form-control" min="0" step="0.001">
            </div>
            <div class="form-group">
                <label class="form-label">Repayable by</label>
                <input type="date" name="due_date" class="form-control">
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Method <span class="req">*</span></label>
                <select name="method" class="form-control" required>
                    <?php foreach (['bank_transfer', 'cash', 'mobile_money', 'cheque', 'other'] as $m): ?>
                        <option value="<?= e($m) ?>"><?= e(payment_method_label($m)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Reference</label>
                <input type="text" name="reference" class="form-control" maxlength="160">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Signed agreement <span class="opt">(image or PDF)</span></label>
            <input type="file" name="agreement" class="form-control" accept="image/*,application/pdf">
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record investment</button>
        </div>
    </form>
</template>

<template id="tplInvestmentRepay">
    <form method="POST" action="<?= e(url('finance/investors.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="repay">
        <input type="hidden" name="investment_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
            </div>
            <div class="form-group">
                <label class="form-label">Date <span class="req">*</span></label>
                <input type="date" name="paid_on" class="form-control" required>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Paid from <span class="req">*</span></label>
            <select name="from_account_id" class="form-control" required data-searchable="Search accounts">
                <option value="">— Select account —</option>
                <?php foreach ($accounts as $ac): ?>
                    <option value="<?= (int) $ac['cash_account_id'] ?>">
                        <?= e($ac['name']) ?> · <?= e(cash_account_type_label($ac['account_type'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Reference</label>
            <input type="text" name="reference" class="form-control" maxlength="160">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record repayment</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
