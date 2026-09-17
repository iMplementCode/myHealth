<?php

/**
 * ============================================================
 *  Staff Loans
 * ------------------------------------------------------------
 *  Money lent to an employee, through the stages it actually
 *  goes through:
 *
 *    requested → approved → disbursed → settled
 *                    ↘ rejected
 *
 *  Nothing reaches the books on approval. Approving a loan is a
 *  decision; it is **disbursement** that moves money, so that is
 *  when the cash book is written — cash out of the paying
 *  account and into a receivable account carrying that
 *  employee's balance. The loan is an asset from that moment:
 *  out of cash and bank, still owed to the business.
 *
 *  Repayments move it back, and a loan repaid in full settles
 *  itself.
 *
 *  Administrators and Managers only; approval is administrators.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/documents.php';
require_role(ROLE_MANAGER);
require_tables('employee_loans', 'employee_loan_repayments', 'cash_accounts');

/** Load a loan with the names and totals every handler needs. */
function loan_row(?int $id): ?array
{
    if ($id === null) {
        return null;
    }
    return db_one(
        "SELECT l.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS employee_name,
                COALESCE((SELECT SUM(amount) FROM employee_loan_repayments r
                           WHERE r.loan_id = l.loan_id), 0) AS repaid
           FROM employee_loans l
           JOIN users u ON u.user_id = l.user_id
          WHERE l.loan_id = :id",
        [':id' => $id]
    );
}

/* ── AJAX: request a loan ───────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'request') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $userId    = input_int($_POST, 'user_id');
    $principal = (float) ($_POST['principal'] ?? 0);
    $date      = input($_POST, 'requested_on') ?: date('Y-m-d');

    $employee = $userId !== null ? db_one(
        "SELECT user_id, TRIM(CONCAT(first_name, ' ', last_name)) AS name, is_active
           FROM users WHERE user_id = :id",
        [':id' => $userId]
    ) : null;

    if (!$employee) {
        json_response(['success' => false, 'message' => 'Choose the employee the loan is for.']);
    }
    if (!$employee['is_active']) {
        json_response(['success' => false, 'message' => $employee['name'] . ' is no longer active.']);
    }
    if ($principal <= 0) {
        json_response(['success' => false, 'message' => 'The loan amount must be more than zero.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid request date.']);
    }

    try {
        $number = next_document_number('employee_loans', 'loan_number', 'LN');
        db_run(
            "INSERT INTO employee_loans
                (loan_number, user_id, principal, purpose, requested_on, repayment_terms, created_by)
             VALUES (:num, :u, :p, :purpose, :d, :terms, :by)",
            [
                ':num' => $number, ':u' => $userId, ':p' => $principal,
                ':purpose' => input($_POST, 'purpose') ?: null, ':d' => $date,
                ':terms' => input($_POST, 'repayment_terms') ?: null,
                ':by' => current_user()['id'],
            ]
        );
        audit_log('employee_loan.request', 'employee_loans', 0, [
            'number' => $number, 'employee' => $employee['name'], 'principal' => $principal,
        ]);
        json_response(['success' => true, 'message' =>
            "$number recorded for {$employee['name']}. It awaits approval — no money has moved."]);
    } catch (Throwable $ex) {
        error_log('[LOAN] request failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the loan request.'], 500);
    }
}

/* ── AJAX: approve or reject ────────────────────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['approve', 'reject'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    // Lending company money is an administrator's decision.
    if (!is_admin()) {
        json_response(['success' => false, 'message' => 'Only an administrator can approve or reject a loan.'], 403);
    }

    $loan = loan_row(input_int($_POST, 'loan_id'));
    if (!$loan) {
        json_response(['success' => false, 'message' => 'That loan no longer exists.']);
    }
    if ($loan['status'] !== 'requested') {
        json_response(['success' => false, 'message' =>
            "{$loan['loan_number']} is already {$loan['status']} and cannot be decided again."]);
    }

    $approve = ($_POST['_action'] === 'approve');
    db_run(
        "UPDATE employee_loans
            SET status = :st, approved_by = :by, approved_on = CURRENT_DATE,
                decision_note = :note, updated_at = NOW()
          WHERE loan_id = :id",
        [
            ':st' => $approve ? 'approved' : 'rejected',
            ':by' => current_user()['id'],
            ':note' => input($_POST, 'decision_note') ?: null,
            ':id' => $loan['loan_id'],
        ]
    );
    audit_log($approve ? 'employee_loan.approve' : 'employee_loan.reject',
        'employee_loans', (int) $loan['loan_id'], ['number' => $loan['loan_number']]);

    json_response(['success' => true, 'message' => $approve
        ? "{$loan['loan_number']} approved. Disburse it to move the money."
        : "{$loan['loan_number']} rejected. No money has moved."]);
}

/* ── AJAX: disburse — this is where money moves ─────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'disburse') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $loan = loan_row(input_int($_POST, 'loan_id'));
    if (!$loan) {
        json_response(['success' => false, 'message' => 'That loan no longer exists.']);
    }
    if ($loan['status'] !== 'approved') {
        json_response(['success' => false, 'message' => $loan['status'] === 'disbursed'
            ? "{$loan['loan_number']} has already been disbursed."
            : "{$loan['loan_number']} must be approved before it can be disbursed."]);
    }

    $date      = input($_POST, 'disbursed_on') ?: date('Y-m-d');
    $accountId = input_int($_POST, 'paid_from_account_id');
    $account   = $accountId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
        : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid disbursement date.']);
    }
    if ($date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'A disbursement cannot be dated in the future.']);
    }
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        json_response(['success' => false, 'message' => 'Choose the account the money is paid from.']);
    }

    // Refuse to pay out money the account does not hold. An
    // overdrawn till is a counting error, not a business event.
    $balances = cash_balances_asof($date);
    $available = $balances[$accountId] ?? (float) $account['opening_balance'];
    if ($available + 0.005 < (float) $loan['principal']) {
        json_response(['success' => false, 'message' => sprintf(
            '%s held %s on %s, which is less than the %s being disbursed.',
            $account['name'], money($available), fmt_date($date), money($loan['principal'])
        )]);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // The receivable account that will carry the balance.
        $loanAccountId = cash_staff_loan_account((int) $loan['user_id'], $loan['employee_name']);

        cash_post_transfer([
            'date'            => $date,
            'from_account_id' => $accountId,
            'to_account_id'   => $loanAccountId,
            'amount'          => (float) $loan['principal'],
            'party'           => $loan['employee_name'],
            'description'     => 'Staff loan ' . $loan['loan_number'] . ' disbursed',
            'source_type'     => 'employee_loan',
            'source_id'       => (int) $loan['loan_id'],
        ]);

        db_run(
            "UPDATE employee_loans
                SET status = 'disbursed', disbursed_on = :d,
                    paid_from_account_id = :from, loan_account_id = :loan, updated_at = NOW()
              WHERE loan_id = :id",
            [':d' => $date, ':from' => $accountId, ':loan' => $loanAccountId, ':id' => $loan['loan_id']]
        );

        $pdo->commit();
        audit_log('employee_loan.disburse', 'employee_loans', (int) $loan['loan_id'], [
            'number' => $loan['loan_number'], 'amount' => $loan['principal'], 'from' => $account['name'],
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s disbursed to %s from %s. It now sits under staff loans, not cash.',
            money($loan['principal']), $loan['employee_name'], $account['name']
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LOAN] disburse failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not disburse the loan.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LOAN] disburse failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not disburse the loan.'], 500);
    }
}

/* ── AJAX: record a repayment ───────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'repay') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $loan = loan_row(input_int($_POST, 'loan_id'));
    if (!$loan) {
        json_response(['success' => false, 'message' => 'That loan no longer exists.']);
    }
    if (!in_array($loan['status'], ['disbursed', 'settled'], true)) {
        json_response(['success' => false, 'message' =>
            "{$loan['loan_number']} has not been disbursed, so there is nothing to repay."]);
    }

    $date   = input($_POST, 'paid_on') ?: date('Y-m-d');
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = input($_POST, 'method');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid repayment date.']);
    }
    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'The repayment must be more than zero.']);
    }
    if (!isset(PAYMENT_METHODS[$method])) {
        json_response(['success' => false, 'message' => 'Choose how the repayment was made.']);
    }

    // A salary deduction settles the loan without cash arriving —
    // the money never reaches an account, it is simply not paid
    // out. Anything else lands somewhere and must say where.
    $bySalary  = $method === 'salary_deduction';
    $accountId = $bySalary ? null : input_int($_POST, 'into_account_id');
    if (!$bySalary) {
        $account = $accountId !== null
            ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
            : null;
        if (!$account || !cash_type_is_liquid($account['account_type'])) {
            json_response(['success' => false, 'message' => 'Choose the account the repayment was paid into.']);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO employee_loan_repayments
                (loan_id, paid_on, amount, into_account_id, method, reference, notes, recorded_by)
             VALUES (:l, :d, :amt, :acct, :m, :ref, :notes, :by)
             RETURNING repayment_id"
        );
        $stmt->execute([
            ':l' => $loan['loan_id'], ':d' => $date, ':amt' => $amount,
            ':acct' => $accountId, ':m' => $method,
            ':ref' => input($_POST, 'reference') ?: null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by' => current_user()['id'],
        ]);
        $repaymentId = (int) $stmt->fetchColumn();

        $loanAccountId = (int) ($loan['loan_account_id']
            ?: cash_staff_loan_account((int) $loan['user_id'], $loan['employee_name']));

        if ($accountId !== null) {
            // Cash comes back in, and the receivable shrinks.
            cash_post_transfer([
                'date'            => $date,
                'from_account_id' => $loanAccountId,
                'to_account_id'   => $accountId,
                'amount'          => $amount,
                'party'           => $loan['employee_name'],
                'description'     => 'Repayment of staff loan ' . $loan['loan_number'],
                'source_type'     => 'employee_loan_repayment',
                'source_id'       => $repaymentId,
            ]);
        } else {
            // Salary deduction: the receivable shrinks, but no cash
            // account moves, so this is a single-sided reduction.
            cash_post([
                'date'        => $date,
                'account_id'  => $loanAccountId,
                'direction'   => 'out',
                'amount'      => $amount,
                'category'    => 'adjustment',
                'party'       => $loan['employee_name'],
                'description' => 'Salary deduction against staff loan ' . $loan['loan_number'],
                'source_type' => 'employee_loan_repayment',
                'source_id'   => $repaymentId,
            ]);
        }

        $pdo->commit();

        $after = loan_row((int) $loan['loan_id']);
        $left  = (float) $after['principal'] - (float) $after['repaid'];

        audit_log('employee_loan.repay', 'employee_loan_repayments', $repaymentId, [
            'number' => $loan['loan_number'], 'amount' => $amount, 'method' => $method,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s recorded against %s. %s',
            money($amount), $loan['loan_number'],
            $left > 0.005 ? money($left) . ' still outstanding.' : 'The loan is now settled in full.'
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LOAN] repay failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the repayment.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[LOAN] repay failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the repayment.'], 500);
    }
}

/* ── POST: cancel an undisbursed loan ───────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'cancel') {
    csrf_check();
    $loan = loan_row(input_int($_POST, 'loan_id'));
    if (!$loan) {
        flash('error', 'That loan no longer exists.');
    } elseif (in_array($loan['status'], ['disbursed', 'settled'], true)) {
        // Money has moved; cancelling would leave the ledger
        // describing a loan that no longer exists.
        flash('error', $loan['loan_number'] . ' has been disbursed. Record repayments instead of cancelling it.');
    } else {
        db_run("UPDATE employee_loans SET status = 'cancelled', updated_at = NOW() WHERE loan_id = :id",
            [':id' => $loan['loan_id']]);
        audit_log('employee_loan.cancel', 'employee_loans', (int) $loan['loan_id'], ['number' => $loan['loan_number']]);
        flash('success', $loan['loan_number'] . ' cancelled.');
    }
    redirect('finance/loans.php');
}

/* ── Data ───────────────────────────────────────────────────── */
$statusFilter = input($_GET, 'status');
$search       = input($_GET, 'q');
$focus        = input_int($_GET, 'focus');
$perPage      = per_page();

$where  = [];
$params = [];
// "Show me this one" — how the cash book links back to the staff
// loan behind a line. Clear gets out of it.
if ($focus !== null) {
    $where[] = "l.loan_id = :focus";
    $params[':focus'] = $focus;
}
if (in_array($statusFilter, ['requested', 'approved', 'rejected', 'disbursed', 'settled', 'cancelled'], true)) {
    $where[] = "l.status = :st";
    $params[':st'] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(LOWER(l.loan_number) LIKE :q OR LOWER(TRIM(CONCAT(u.first_name,' ',u.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM employee_loans l JOIN users u ON u.user_id = l.user_id $whereSql", $params);
$pg    = paginate($total, $perPage);

$loans = db_all(
    "SELECT l.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS employee_name,
            TRIM(CONCAT(ap.first_name, ' ', ap.last_name)) AS approved_by_name,
            fa.name AS paid_from_name,
            COALESCE(r.repaid, 0) AS repaid
       FROM employee_loans l
       JOIN users u ON u.user_id = l.user_id
       LEFT JOIN users ap ON ap.user_id = l.approved_by
       LEFT JOIN cash_accounts fa ON fa.cash_account_id = l.paid_from_account_id
       LEFT JOIN (SELECT loan_id, SUM(amount) AS repaid FROM employee_loan_repayments GROUP BY loan_id) r
              ON r.loan_id = l.loan_id
     $whereSql
     ORDER BY l.requested_on DESC, l.loan_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$outstanding = (float) db_value(
    "SELECT COALESCE(SUM(l.principal - COALESCE(r.repaid, 0)), 0)
       FROM employee_loans l
       LEFT JOIN (SELECT loan_id, SUM(amount) AS repaid FROM employee_loan_repayments GROUP BY loan_id) r
              ON r.loan_id = l.loan_id
      WHERE l.status IN ('disbursed', 'settled')"
);
$awaiting = (float) db_value(
    "SELECT COALESCE(SUM(principal), 0) FROM employee_loans WHERE status = 'requested'"
);
$approvedNotPaid = (float) db_value(
    "SELECT COALESCE(SUM(principal), 0) FROM employee_loans WHERE status = 'approved'"
);

$staff    = db_all("SELECT user_id, TRIM(CONCAT(first_name, ' ', last_name)) AS name FROM users WHERE is_active ORDER BY first_name");
$accounts = cash_liquid_accounts();

$statusTone = [
    'requested' => 'pending', 'approved' => 'confirmed', 'rejected' => 'inactive',
    'disbursed' => 'active', 'settled' => 'completed', 'cancelled' => 'inactive',
];

$pageTitle    = 'Staff Loans';
$pageSubtitle = 'Requested, approved, disbursed and repaid';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js', 'loan-form.js'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Staff Loans']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter(['status' => $statusFilter, 'q' => $search, 'focus' => $focus], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Outstanding with staff</span><span class="mini-value"><?= e(money($outstanding)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Awaiting approval</span><span class="mini-value"><?= e(money($awaiting)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Approved, not disbursed</span><span class="mini-value"><?= e(money($approvedNotPaid)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Loans on record</span><span class="mini-value"><?= e(num($total)) ?></span></div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('finance/loans.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search loan number or employee…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach (['requested', 'approved', 'disbursed', 'settled', 'rejected', 'cancelled'] as $st): ?>
                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
            <?php endforeach; ?>
        </select>
        <?= toolbar_apply('finance/loans.php') ?>
    </div>
    <button type="button" class="btn btn-primary" data-modal-open="tplLoanRequest" data-modal-title="New Loan Request" data-modal-auto>
        <?= icon('plus') ?> New Loan Request
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Loan</th>
                    <th>Employee</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th class="ta-right">Principal</th>
                    <th class="ta-right">Repaid</th>
                    <th class="ta-right">Outstanding</th>
                    <th>Paid from</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$loans): ?>
                    <tr><td colspan="9" class="table-empty">
                        No staff loans yet. A request records the intention; nothing reaches
                        the books until it is approved and disbursed.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($loans as $l):
                    $left = (float) $l['principal'] - (float) $l['repaid'];
                    $ids  = json_encode(['loan_id' => $l['loan_id']], JSON_HEX_APOS | JSON_HEX_QUOT);
                    $disburseFill = json_encode([
                        'loan_id'      => $l['loan_id'],
                        'disbursed_on' => date('Y-m-d'),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    $repayFill = json_encode([
                        'loan_id' => $l['loan_id'],
                        'paid_on' => date('Y-m-d'),
                        'amount'  => number_format($left, 2, '.', ''),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($l['loan_number']) ?></span></td>
                        <td><?= e($l['employee_name']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($l['requested_on'])) ?></td>
                        <td>
                            <span class="badge badge--<?= e($statusTone[$l['status']] ?? 'pending') ?>">
                                <?= e(ucfirst($l['status'])) ?>
                            </span>
                            <?php if ($l['status'] === 'disbursed' && $l['disbursed_on']): ?>
                                <br><small class="cell-muted"><?= e(fmt_date($l['disbursed_on'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><strong><?= e(money($l['principal'])) ?></strong></td>
                        <td class="ta-right <?= $l['repaid'] > 0 ? 'tone-in' : 'cell-muted' ?>"><?= e(money($l['repaid'])) ?></td>
                        <td class="ta-right">
                            <?php if (in_array($l['status'], ['disbursed', 'settled'], true)): ?>
                                <?= $left > 0.005 ? '<strong>' . e(money($left)) . '</strong>' : '<span class="cell-muted">Settled</span>' ?>
                            <?php else: ?>
                                <span class="cell-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= e($l['paid_from_name'] ?: '—') ?></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($l['status'] === 'requested' && is_admin()): ?>
                                    <button type="button" class="btn btn-ghost btn--sm"
                                            data-modal-open="tplLoanDecision" data-modal-title="Approve <?= e($l['loan_number']) ?>"
                                            data-prefill="<?= e($ids) ?>">Decide</button>
                                <?php endif; ?>
                                <?php if ($l['status'] === 'approved'): ?>
                                    <button type="button" class="btn btn-primary btn--sm"
                                            data-modal-open="tplLoanDisburse" data-modal-title="Disburse <?= e($l['loan_number']) ?>"
                                            data-prefill="<?= e($disburseFill) ?>">Disburse</button>
                                <?php endif; ?>
                                <?php if ($l['status'] === 'disbursed'): ?>
                                    <button type="button" class="btn btn-ghost btn--sm"
                                            data-modal-open="tplLoanRepay" data-modal-title="Repayment · <?= e($l['loan_number']) ?>"
                                            data-prefill="<?= e($repayFill) ?>">Record repayment</button>
                                <?php endif; ?>
                                <?php if (in_array($l['status'], ['requested', 'approved', 'rejected'], true)): ?>
                                    <form method="POST" action="<?= e(url('finance/loans.php')) ?>" class="inline-form"
                                          data-confirm="Cancel <?= e($l['loan_number']) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="cancel">
                                        <input type="hidden" name="loan_id" value="<?= (int) $l['loan_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Cancel"><?= icon('x') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'loan'); ?>
</div>

<!-- Request -->
<template id="tplLoanRequest">
    <form method="POST" action="<?= e(url('finance/loans.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="request">

        <p class="form-hint">
            A request records the intention only. No money moves and nothing
            reaches the books until the loan is approved and then disbursed.
        </p>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Employee <span class="req">*</span></label>
                <select name="user_id" class="form-control" required data-searchable="Search people">
                    <option value="">— Select employee —</option>
                    <?php foreach ($staff as $u): ?>
                        <option value="<?= (int) $u['user_id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="principal" class="form-control" min="0.01" step="0.01" required>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Date requested</label>
            <input type="date" name="requested_on" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Purpose</label>
            <input type="text" name="purpose" class="form-control" placeholder="What the loan is for">
        </div>

        <div class="form-group">
            <label class="form-label">Repayment terms</label>
            <textarea name="repayment_terms" class="form-control" rows="2"
                      placeholder="e.g. KES 5,000 deducted from salary each month"></textarea>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record request</button>
        </div>
    </form>
</template>

<!-- Approve / reject -->
<template id="tplLoanDecision">
    <form method="POST" action="<?= e(url('finance/loans.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="approve" data-loan-decision>
        <input type="hidden" name="loan_id" value="">

        <p class="form-hint">
            Approving does not move money — disburse the loan when the employee
            is actually paid.
        </p>

        <div class="form-group">
            <label class="form-label">Note <span class="opt">(recorded against the decision)</span></label>
            <textarea name="decision_note" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-ghost" data-loan-reject>Reject</button>
            <button type="submit" class="btn btn-primary">Approve</button>
        </div>
    </form>
</template>

<!-- Disburse -->
<template id="tplLoanDisburse">
    <form method="POST" action="<?= e(url('finance/loans.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="disburse">
        <input type="hidden" name="loan_id" value="">

        <p class="form-hint">
            This is the step that moves money. Cash leaves the account you choose
            and the loan becomes an amount owed to the business.
        </p>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Paid from <span class="req">*</span></label>
                <select name="paid_from_account_id" class="form-control" required data-searchable="Search accounts">
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int) $a['cash_account_id'] ?>">
                            <?= e($a['name']) ?> · <?= e(cash_account_type_label($a['account_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date paid <span class="req">*</span></label>
                <input type="date" name="disbursed_on" class="form-control" required max="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Disburse loan</button>
        </div>
    </form>
</template>

<!-- Repayment -->
<template id="tplLoanRepay">
    <form method="POST" action="<?= e(url('finance/loans.php')) ?>" data-ajax data-loan-repay>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="repay">
        <input type="hidden" name="loan_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
                <p class="form-hint">Pre-filled with the full outstanding balance.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Date <span class="req">*</span></label>
                <input type="date" name="paid_on" class="form-control" required>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Method <span class="req">*</span></label>
            <select name="method" class="form-control" required data-repay-method>
                <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" data-repay-account>
            <label class="form-label">Paid into <span class="req">*</span></label>
            <select name="into_account_id" class="form-control" data-searchable="Search accounts">
                <option value="">— Select account —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int) $a['cash_account_id'] ?>">
                        <?= e($a['name']) ?> · <?= e(cash_account_type_label($a['account_type'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">
                A salary deduction needs no account: the money was never paid out,
                so only the amount owed changes.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Reference</label>
            <input type="text" name="reference" class="form-control" maxlength="160">
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record repayment</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
