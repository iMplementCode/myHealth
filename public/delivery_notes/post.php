<?php

/**
 * ============================================================
 *  Delivery Notes — Post / Cancel
 * ------------------------------------------------------------
 *  Posting is the single moment stock leaves the business.
 *  Cancelling a posted note returns it. Both run inside one
 *  transaction; the triggers from migration 010 do the final
 *  enforcement (stock floor, over-delivery, progress updates)
 *  so a failure here rolls the whole thing back untouched.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/workflow.php';

if (!is_post()) {
    redirect('delivery_notes/index.php');
}
csrf_check();

$dnId   = input_int($_POST, 'dn_id');
$action = input($_POST, 'action');

if ($dnId === null || !in_array($action, ['post', 'cancel', 'fulfilment'], true)) {
    flash('error', 'Nothing to do.');
    redirect('delivery_notes/index.php');
}

/* ── Mark fulfilment by hand ──────────────────────────────── */
// The computed value follows the quantities, which cannot know that a
// customer accepted a short shipment as complete. Marking overrides
// it; clearing the mark hands control back to the quantities.
if ($action === 'fulfilment') {
    $dn = db_one("SELECT dn_number, delivery_status FROM delivery_notes WHERE dn_id = :id", [':id' => $dnId]);
    if (!$dn) {
        flash('error', 'That delivery note no longer exists.');
        redirect('delivery_notes/index.php');
    }
    if (!(is_admin() || user_has_role(ROLE_MANAGER))) {
        flash('error', 'Only a manager or administrator can mark fulfilment by hand.');
        redirect('delivery_notes/view.php?id=' . $dnId);
    }

    $mark = input($_POST, 'delivery_status');
    $note = input($_POST, 'fulfilment_note');
    if ($mark !== '' && !in_array($mark, DELIVERY_STATES, true)) {
        flash('error', 'That is not a valid fulfilment state.');
        redirect('delivery_notes/view.php?id=' . $dnId);
    }

    // Decide the values in PHP rather than with a SQL CASE: PDO sends
    // every parameter as text, so a CASE choosing between NULL and an
    // int parameter resolves to text and the integer column rejects it.
    $clearing = $mark === '';

    try {
        db_run(
            "UPDATE delivery_notes
                SET delivery_status_override = :mark,
                    fulfilment_note      = :note,
                    fulfilment_marked_at = :at,
                    fulfilment_marked_by = :user
              WHERE dn_id = :id",
            [
                ':mark' => $clearing ? null : $mark,
                ':note' => $clearing || $note === '' ? null : $note,
                ':at'   => $clearing ? null : date('c'),
                ':user' => $clearing ? null : current_user()['id'],
                ':id'   => $dnId,
            ]
        );
        audit_log('delivery_note.fulfilment', 'delivery_notes', $dnId, [
            'number' => $dn['dn_number'],
            'marked' => $mark !== '' ? $mark : null,
            'computed' => $dn['delivery_status'],
            'note' => $note ?: null,
        ]);
        flash('success', $mark !== ''
            ? "{$dn['dn_number']} marked as " . status_label($mark) . '.'
            : "{$dn['dn_number']} now follows the delivered quantities again.");
    } catch (Throwable $ex) {
        error_log('[DN] fulfilment mark failed: ' . $ex->getMessage());
        flash('error', 'Could not update the fulfilment state.');
    }
    redirect('delivery_notes/view.php?id=' . $dnId);
}

$me  = current_user();
$pdo = db();

try {
    $pdo->beginTransaction();

    $dn = db_one("SELECT * FROM delivery_notes WHERE dn_id = :id FOR UPDATE", [':id' => $dnId]);
    if (!$dn) {
        $pdo->rollBack();
        flash('error', 'That delivery note no longer exists.');
        redirect('delivery_notes/index.php');
    }

    $target = $action === 'post' ? 'posted' : 'cancelled';
    if (!can_transition('delivery_note', $dn['status'], $target)) {
        $pdo->rollBack();
        flash('error', sprintf(
            'A %s delivery note cannot be %s.',
            status_label($dn['status']),
            $target === 'posted' ? 'posted' : 'cancelled'
        ));
        redirect('delivery_notes/view.php?id=' . $dnId);
    }

    // Once the goods are on an invoice the customer has been billed
    // for them, so reversing the delivery is a credit-note matter
    // rather than an unwind. The database refuses this too.
    if ($action === 'cancel' && $dn['status'] === 'posted') {
        $inv = delivery_note_invoice($dn);
        if ($inv) {
            $pdo->rollBack();
            flash('error', sprintf(
                'These goods are invoiced on %s, so this delivery note can no longer be reversed. Raise a credit note against the invoice instead.',
                $inv['invoice_number']
            ));
            redirect('delivery_notes/view.php?id=' . $dnId);
        }
    }

    // Cancelling a posted note is a stock reversal — restrict it to
    // users who can be trusted with inventory corrections.
    if ($action === 'cancel' && $dn['status'] === 'posted'
        && !(is_admin() || user_has_role(ROLE_MANAGER))) {
        $pdo->rollBack();
        flash('error', 'Only a manager or administrator can cancel a posted delivery note.');
        redirect('delivery_notes/view.php?id=' . $dnId);
    }

    if ($action === 'post') {
        $lines = (int) db_value("SELECT COUNT(*) FROM delivery_note_items WHERE dn_id = :id", [':id' => $dnId]);
        if ($lines === 0) {
            $pdo->rollBack();
            flash('error', 'Add at least one line before posting.');
            redirect('delivery_notes/view.php?id=' . $dnId);
        }
        $pdo->prepare(
            "UPDATE delivery_notes SET status='posted', posted_at=NOW(), posted_by=:by WHERE dn_id=:id"
        )->execute([':by' => $me['id'], ':id' => $dnId]);
    } else {
        $pdo->prepare(
            "UPDATE delivery_notes SET status='cancelled', cancelled_at=NOW(), cancelled_by=:by WHERE dn_id=:id"
        )->execute([':by' => $me['id'], ':id' => $dnId]);
    }

    $pdo->commit();

    audit_log('delivery_note.' . $action, 'delivery_notes', $dnId, [
        'number' => $dn['dn_number'], 'from' => $dn['status'], 'to' => $target,
    ]);
    flash('success', $action === 'post'
        ? "{$dn['dn_number']} posted — stock released and delivery progress updated."
        : "{$dn['dn_number']} cancelled" . ($dn['status'] === 'posted' ? ' and stock returned.' : '.'));
    redirect('delivery_notes/view.php?id=' . $dnId);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[DN] ' . $action . ' failed: ' . $ex->getMessage());
    // Trigger exceptions carry a message written for the user
    // (insufficient stock, over-delivery) — surface it rather than
    // hiding the reason behind a generic failure.
    $raw = $ex->getMessage();
    $msg = str_contains($raw, 'ERROR:')
        ? trim(preg_replace('/^.*ERROR:\s*/s', '', explode("\n", $raw)[0]))
        : 'Could not update the delivery note. Please try again.';
    flash('error', $msg);
    redirect('delivery_notes/view.php?id=' . $dnId);
}
