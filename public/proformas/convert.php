<?php

/**
 * ============================================================
 *  Proforma Invoices — Convert to Invoice (one click)
 * ------------------------------------------------------------
 *  Converts a proforma into a proper invoice in a single
 *  transaction, preserving customer, items, quantities,
 *  pricing, discounts, taxes, notes and payment terms.
 *
 *  Duplicate conversions are prevented three ways:
 *    1. row lock + status check inside the transaction
 *    2. unique index invoices(pi_id)
 *    3. converted_invoice_id back-reference on the proforma
 *
 *  Records the conversion date and the converting user.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/workflow.php';

if (!is_post()) {
    redirect('proformas/index.php');
}
csrf_check();

$piId = input_int($_POST, 'pi_id');
if ($piId === null) {
    flash('error', 'No proforma specified.');
    redirect('proformas/index.php');
}

$me  = current_user();
$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock the proforma row: concurrent conversions serialize here.
    $pi = db_one("SELECT * FROM proforma_invoices WHERE pi_id = :id FOR UPDATE", [':id' => $piId]);
    if (!$pi) {
        $pdo->rollBack();
        flash('error', 'That proforma no longer exists.');
        redirect('proformas/index.php');
    }
    if ($pi['status'] === 'converted' || $pi['converted_invoice_id']) {
        $pdo->rollBack();
        flash('error', $pi['pi_number'] . ' has already been converted.');
        redirect('proformas/index.php');
    }
    if ($pi['status'] === 'cancelled') {
        $pdo->rollBack();
        flash('error', 'A cancelled proforma cannot be converted.');
        redirect('proformas/index.php');
    }

    $invNumber = next_document_number('invoices', 'invoice_number', 'INV');

    // When it falls due. This used to be the proforma's expiry date,
    // which is a different thing entirely: expiry is the last day the
    // quoted price stands, not the day payment is expected. A
    // proforma converted after it expired produced an invoice already
    // past its due date, and one with no expiry produced an invoice
    // with no due date at all — which the ageing report then aged
    // from the issue date, calling it overdue the following morning.
    $dueDate = invoice_due_date();

    // Create the invoice, preserving every commercial field.
    $stmt = $pdo->prepare(
        "INSERT INTO invoices
            (invoice_number, customer_id, pi_id, quote_id, issued_by, issue_date, due_date, currency_id,
             subtotal, discount_amount, tax_amount, tax_rate, total_amount, notes, terms, tax_note, status)
         VALUES
            (:num, :customer, :pi, :quote, :user, CURRENT_DATE, :due, :currency,
             :subtotal, :discount, :tax, :rate, :total, :notes, :terms, :taxnote, 'issued')
         RETURNING invoice_id"
    );
    $stmt->execute([
        ':num' => $invNumber, ':customer' => $pi['customer_id'], ':pi' => $piId,
        ':quote' => $pi['quote_id'], ':user' => $me['id'], ':due' => $dueDate,
        ':currency' => $pi['currency_id'], ':subtotal' => $pi['subtotal'],
        ':discount' => $pi['discount_amount'], ':tax' => $pi['tax_amount'], ':rate' => $pi['tax_rate'] ?? 0,
        ':total' => $pi['total_amount'], ':notes' => $pi['notes'], ':terms' => $pi['terms'],
        // The sentence about tax belongs to the sale, not to the
        // document that happened to state it first.
        ':taxnote' => $pi['tax_note'] ?? null,
    ]);
    $invoiceId = (int) $stmt->fetchColumn();

    // Copy line items verbatim (products, quantities, prices,
    // discounts) and the note on each line with them — a line note
    // that survived the quote only to be dropped here would be worse
    // than never having offered the field. Guarded on both columns
    // because they arrive in migration 056.
    $noteCol = (column_exists('proforma_invoice_items', 'note')
             && column_exists('invoice_items', 'note')) ? ', note' : '';
    $pdo->prepare(
        "INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, discount$noteCol)
         SELECT :inv, product_id, quantity, unit_price, discount$noteCol
         FROM proforma_invoice_items WHERE pi_id = :pi"
    )->execute([':inv' => $invoiceId, ':pi' => $piId]);

    // Carry across whatever has already gone out. Notes raised on the
    // proforma count towards the invoice too, so an invoice raised
    // after delivery must not open life saying "not delivered".
    db_run("SELECT refresh_invoice_delivery(:inv)", [':inv' => $invoiceId]);

    // Close the loop on the proforma: status, reference, date, user.
    $pdo->prepare(
        "UPDATE proforma_invoices
         SET status = 'converted', converted_invoice_id = :inv,
             converted_at = NOW(), converted_by = :user, updated_at = NOW()
         WHERE pi_id = :pi"
    )->execute([':inv' => $invoiceId, ':user' => $me['id'], ':pi' => $piId]);

    $pdo->commit();

    // Document graph — recorded after commit; never blocks the sale.
    link_documents('proforma', $piId, 'invoice', $invoiceId);
    if (!empty($pi['quote_id'])) {
        link_documents('quote', (int) $pi['quote_id'], 'invoice', $invoiceId);
    }

    audit_log('proforma.convert', 'proforma_invoices', $piId, [
        'proforma' => $pi['pi_number'], 'invoice' => $invNumber, 'total' => $pi['total_amount'],
    ]);
    flash('success', "{$pi['pi_number']} converted to invoice {$invNumber}.");
    redirect('invoices/view.php?id=' . $invoiceId);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[PROFORMA] convert failed: ' . $ex->getMessage());
    flash('error', 'Conversion failed. Please try again.');
    redirect('proformas/index.php');
}
