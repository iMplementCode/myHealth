<?php

/**
 * ============================================================
 *  Expense Management — Delete
 * ------------------------------------------------------------
 *  POST-only, CSRF-protected, administrators only, audited.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_role(ROLE_ADMIN);

if (!is_post()) {
    redirect('expenses/index.php');
}
csrf_check();

$id = input_int($_POST, 'id');
if ($id === null) {
    flash('error', 'No expense specified.');
    redirect('expenses/index.php');
}

$expense = db_one("SELECT expense_id, amount, expense_date FROM expenses WHERE expense_id = :id", [':id' => $id]);
if (!$expense) {
    flash('error', 'That expense no longer exists.');
    redirect('expenses/index.php');
}

try {
    // The ledger entry goes with it, or the cash book keeps showing
    // money leaving for an expense that no longer exists.
    cash_unpost('expense', $id);
    db_run("DELETE FROM expenses WHERE expense_id = :id", [':id' => $id]);
    audit_log('expense.delete', 'expenses', $id, ['amount' => $expense['amount'], 'date' => $expense['expense_date']]);
    flash('success', 'Expense of ' . money($expense['amount']) . ' deleted.');
} catch (PDOException $ex) {
    error_log('[EXPENSES] delete failed: ' . $ex->getMessage());
    flash('error', 'Could not delete the expense.');
}

redirect('expenses/index.php');
