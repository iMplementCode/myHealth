<?php

/**
 * ============================================================
 *  Invoices — Change Status
 * ------------------------------------------------------------
 *  POST endpoint for invoice status transitions across the
 *  lifecycle defined in includes/workflow.php:
 *    draft · issued · partially_paid · paid · overdue · cancelled
 *
 *  Rules:
 *    • only transitions permitted by the workflow are accepted
 *    • paid   → amount_paid is set to the invoice total
 *    • issued → amount_paid resets to 0
 *    • cancelled → managers/administrators only
 *    • delivery statuses are owned by the delivery notes and can
 *      never be set by hand here
 *  CSRF-protected and audited.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/workflow.php';
require_tables('invoices');

if (!is_post()) {
    redirect('invoices/index.php');
}
csrf_check();

$id     = input_int($_POST, 'invoice_id');
$status = input($_POST, 'status');
$back   = input($_POST, 'back') === 'view' ? 'invoices/view.php?id=' . (int) $id : 'invoices/index.php';

// Delivery statuses are derived from posted delivery notes, so they
// are deliberately absent from the set a user may choose.
$allowed = ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'];
if ($id === null || !in_array($status, $allowed, true)) {
    flash('error', 'Invalid status request.');
    redirect('invoices/index.php');
}

$inv = db_one(
    "SELECT invoice_id, invoice_number, status, total_amount, amount_paid, credit_status
       FROM invoices WHERE invoice_id = :id",
    [':id' => $id]
);
if (!$inv) {
    flash('error', 'That invoice no longer exists.');
    redirect('invoices/index.php');
}

if ($status === $inv['status']) {
    flash('info', $inv['invoice_number'] . ' is already ' . status_label($status) . '.');
    redirect($back);
}

// Refuse moves the lifecycle does not allow. Administrators get a
// wider set (see admin_override_transitions) so a paid invoice can be
// put back when a payment was allocated in error.
$isOverride = is_override_transition('invoice', $inv['status'], $status);
if (!can_transition('invoice', $inv['status'], $status)) {
    flash('error', sprintf(
        '%s cannot go from %s to %s.%s',
        $inv['invoice_number'], status_label($inv['status']), status_label($status),
        in_array($status, admin_override_transitions('invoice', $inv['status']), true)
            ? '' : ' An administrator can override this.'
    ));
    redirect($back);
}

// Cancelling is a bigger decision — managers and admins only.
if ($status === 'cancelled' && !is_admin() && !user_has_role(ROLE_MANAGER)) {
    flash('error', 'Only managers or administrators can cancel an invoice.');
    redirect($back);
}

// Receipts are the source of truth for what has been received, so
// where any exist the status must not overwrite the figure they add
// up to — a trigger keeps amount_paid equal to their sum.
$hasReceipts = table_exists('invoice_payments') && (bool) db_value(
    "SELECT 1 FROM invoice_payments WHERE invoice_id = :id", [':id' => $id]
);

// Goods that have gone out do not come back because a status
// changed. Cancelling such an invoice leaves it owing a credit note,
// and that is recorded rather than assumed.
$delivered = table_exists('delivery_notes') && (bool) db_value(
    "SELECT 1 FROM delivery_notes WHERE invoice_id = :id AND status = 'posted'",
    [':id' => $id]
);
$needsCredit = $status === 'cancelled' && $delivered;

try {
    $paid = match ($status) {
        'paid'   => $inv['total_amount'],
        'issued', 'draft' => 0,
        default  => null, // partially_paid/overdue/cancelled: leave as-is
    };
    // Reverting away from paid must not leave the invoice recorded as
    // settled in full, or the balance due would read zero.
    if ($inv['status'] === 'paid' && $status !== 'paid' && $paid === null) {
        $paid = 0;
    }
    if ($hasReceipts) {
        $paid = null;   // the receipts already say what was received
    }

    if ($paid !== null) {
        db_run(
            "UPDATE invoices SET status = :s, amount_paid = :p, updated_at = NOW() WHERE invoice_id = :id",
            [':s' => $status, ':p' => $paid, ':id' => $id]
        );
    } else {
        db_run(
            "UPDATE invoices SET status = :s, updated_at = NOW() WHERE invoice_id = :id",
            [':s' => $status, ':id' => $id]
        );
    }

    if ($needsCredit && $inv['credit_status'] === 'none') {
        db_run(
            "UPDATE invoices SET credit_status = 'awaiting_credit', updated_at = NOW()
              WHERE invoice_id = :id AND credit_status = 'none'",
            [':id' => $id]
        );
    }

    audit_log($isOverride ? 'invoice.status_override' : 'invoice.status', 'invoices', $id, [
        'number' => $inv['invoice_number'], 'from' => $inv['status'], 'to' => $status,
        'override' => $isOverride, 'awaiting_credit' => $needsCredit,
    ]);

    if ($needsCredit) {
        // Cancelling is not enough on its own: the goods are with the
        // customer and, if they paid, so is the business's obligation
        // to give the money back.
        flash('warning', sprintf(
            '%s cancelled, but its goods were already delivered. Raise a credit note to '
            . 'reverse the sale, bring the stock back and%s settle with the customer.',
            $inv['invoice_number'],
            (float) $inv['amount_paid'] > 0
                ? ' refund the ' . money($inv['amount_paid']) . ' received to'
                : ''
        ));
    } else {
        flash('success', $inv['invoice_number'] . ' marked as ' . status_label($status) . '.'
            . ($isOverride ? ' (administrator override)' : ''));
    }

    if ($status === 'paid' && !$hasReceipts) {
        flash('info', 'No receipt is recorded against ' . $inv['invoice_number']
            . '. Use Record payment so the money is traceable and has proof attached.');
    }
} catch (PDOException $ex) {
    error_log('[INVOICES] status change failed: ' . $ex->getMessage());
    flash('error', 'Could not update the invoice status.');
}

redirect($back);
