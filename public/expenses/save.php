<?php

/**
 * ============================================================
 *  Expense Management — Save (AJAX endpoint)
 * ------------------------------------------------------------
 *  Creates or updates an expense. POST-only, CSRF-protected,
 *  fully validated server-side, audited. Returns JSON consumed
 *  by the shared data-ajax form handler.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_role(ROLE_MANAGER);

if (!is_post()) {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    json_response(['success' => false, 'message' => 'Security token expired. Reload the page and try again.'], 403);
}

$id        = input_int($_POST, 'expense_id');
$catId     = input_int($_POST, 'category_id');
$date      = input($_POST, 'expense_date');
$amount    = input($_POST, 'amount');
$desc      = input($_POST, 'description');
$reference = input($_POST, 'receipt_reference');
$status    = input($_POST, 'status');
$acctId    = input_int($_POST, 'cash_account_id');
$paidOn    = input($_POST, 'paid_on');
$method    = input($_POST, 'payment_method');
$supplier  = input_int($_POST, 'supplier_id');

// ── Validation ──────────────────────────────────────────────
$errors = [];
if ($catId === null || !db_value("SELECT 1 FROM expense_categories WHERE category_id = :id AND is_active = TRUE", [':id' => $catId])) {
    $errors[] = 'Please choose a valid category.';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors[] = 'Please provide a valid date.';
}
if (!is_numeric($amount) || (float) $amount <= 0) {
    $errors[] = 'Amount must be greater than zero.';
}
if (!in_array($status, ['recorded', 'approved', 'rejected'], true)) {
    $status = 'recorded';
}
// ── Payment: all of it, or none of it ───────────────────────
// An expense can be recorded before it is settled, so the account
// is optional. Once one is chosen the rest has to follow, or the
// books cannot say when the money moved or out of what.
if ($acctId !== null) {
    $account = db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $acctId]);
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        $errors[] = 'Choose the cash, bank or mobile-money account the expense was paid from.';
    }
    if ($paidOn === '') {
        $paidOn = $date;                       // settled the day it was incurred
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) {
        $errors[] = 'Please provide a valid payment date.';
    }
    if (!isset(PAYMENT_METHODS[$method]) || $method === 'salary_deduction') {
        $errors[] = 'Choose how the expense was paid.';
    }
    if ($status === 'rejected') {
        $errors[] = 'A rejected expense cannot be marked as paid.';
    }
} else {
    $paidOn = null;
    $method = null;
}
if ($supplier !== null && !db_value("SELECT 1 FROM suppliers WHERE supplier_id = :id", [':id' => $supplier])) {
    $supplier = null;
}

if ($errors) {
    json_response(['success' => false, 'message' => implode(' ', $errors)]);
}

$me = current_user();

/**
 * Mirror one expense into the cash book.
 *
 * Always clears whatever the expense wrote before, so an edit
 * cannot leave two entries behind and an expense marked unpaid
 * takes its entry away with it. Only a settled expense posts —
 * money that has not moved has no business in a cash book.
 */
function expense_sync_cashbook(int $expenseId): void
{
    cash_unpost('expense', $expenseId);

    $ex = db_one(
        "SELECT e.*, c.name AS category, s.name AS supplier_name
           FROM expenses e
           LEFT JOIN expense_categories c ON c.category_id = e.category_id
           LEFT JOIN suppliers s ON s.supplier_id = e.supplier_id
          WHERE e.expense_id = :id",
        [':id' => $expenseId]
    );
    if (!$ex || $ex['cash_account_id'] === null || $ex['status'] === 'rejected') {
        return;
    }

    cash_post([
        'date'        => $ex['paid_on'],
        'account_id'  => (int) $ex['cash_account_id'],
        'direction'   => 'out',
        'amount'      => (float) $ex['amount'],
        'category'    => 'expense',
        'party'       => $ex['supplier_name'] ?: null,
        'description' => trim(($ex['category'] ?: 'Expense')
                         . ($ex['description'] ? ' · ' . $ex['description'] : '')
                         . ($ex['receipt_reference'] ? ' · ' . $ex['receipt_reference'] : '')),
        'supplier_id' => $ex['supplier_id'] !== null ? (int) $ex['supplier_id'] : null,
        'source_type' => 'expense',
        'source_id'   => $expenseId,
    ]);
}

$pdo = db();
try {
    $pdo->beginTransaction();

    if ($id === null) {
        $newId = (int) db_one(
            "INSERT INTO expenses (category_id, expense_date, amount, description, receipt_reference,
                                   status, cash_account_id, paid_on, payment_method, supplier_id,
                                   created_by, updated_by)
             VALUES (:cat, :date, :amount, :descr, :ref, :status, :acct, :paid, :method, :sup, :uid, :uid)
             RETURNING expense_id",
            [
                ':cat' => $catId, ':date' => $date, ':amount' => $amount,
                ':descr' => $desc !== '' ? $desc : null,
                ':ref' => $reference !== '' ? $reference : null,
                ':status' => $status, ':acct' => $acctId, ':paid' => $paidOn,
                ':method' => $method, ':sup' => $supplier, ':uid' => $me['id'],
            ]
        )['expense_id'];

        expense_sync_cashbook($newId);
        $pdo->commit();

        audit_log('expense.create', 'expenses', $newId, ['amount' => $amount, 'paid' => $acctId !== null]);
        json_response([
            'success' => true,
            'message' => $acctId !== null
                ? 'Expense recorded and posted to the cash book.'
                : 'Expense recorded. Mark it paid once the money leaves an account.',
        ]);
    }

    // Update path
    if (!db_value("SELECT 1 FROM expenses WHERE expense_id = :id", [':id' => $id])) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'That expense no longer exists.'], 404);
    }
    db_run(
        "UPDATE expenses
         SET category_id = :cat, expense_date = :date, amount = :amount,
             description = :descr, receipt_reference = :ref, status = :status,
             cash_account_id = :acct, paid_on = :paid, payment_method = :method,
             supplier_id = :sup, updated_by = :uid, updated_at = NOW()
         WHERE expense_id = :id",
        [
            ':cat' => $catId, ':date' => $date, ':amount' => $amount,
            ':descr' => $desc !== '' ? $desc : null,
            ':ref' => $reference !== '' ? $reference : null,
            ':status' => $status, ':acct' => $acctId, ':paid' => $paidOn,
            ':method' => $method, ':sup' => $supplier,
            ':uid' => $me['id'], ':id' => $id,
        ]
    );

    // The cash book follows the expense, including when an edit
    // takes the payment away again.
    expense_sync_cashbook($id);
    $pdo->commit();

    audit_log('expense.update', 'expenses', $id, ['amount' => $amount, 'status' => $status]);
    json_response(['success' => true, 'message' => 'Expense updated.']);
} catch (PDOException $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[EXPENSES] save failed: ' . $ex->getMessage());
    json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not save the expense.')], 500);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[EXPENSES] save failed: ' . $ex->getMessage());
    json_response(['success' => false, 'message' => 'Could not save the expense. Please try again.'], 500);
}
