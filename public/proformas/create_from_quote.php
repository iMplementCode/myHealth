<?php

/**
 * ============================================================
 *  Proforma Invoices — Create from Quote
 * ------------------------------------------------------------
 *  One-click quote → proforma. Copies customer, items,
 *  quantities, pricing, discounts, taxes, notes and terms in a
 *  single transaction. Duplicate-guarded by the unique index
 *  on proforma_invoices(quote_id).
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/documents.php';

if (!is_post()) {
    redirect('proformas/index.php');
}
csrf_check();

$quoteId = input_int($_POST, 'quote_id');
if ($quoteId === null) {
    flash('error', 'No quote selected.');
    redirect('proformas/index.php');
}

$me  = current_user();
$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock the quote row so two clicks can't race.
    $quote = db_one("SELECT * FROM quotes WHERE quote_id = :id FOR UPDATE", [':id' => $quoteId]);
    if (!$quote) {
        $pdo->rollBack();
        flash('error', 'That quote no longer exists.');
        redirect('proformas/index.php');
    }
    if (db_value("SELECT 1 FROM proforma_invoices WHERE quote_id = :id", [':id' => $quoteId])) {
        $pdo->rollBack();
        flash('error', 'A proforma already exists for ' . $quote['quote_number'] . '.');
        redirect('proformas/index.php');
    }

    $piNumber = next_document_number('proforma_invoices', 'pi_number', 'PI');

    $stmt = $pdo->prepare(
        "INSERT INTO proforma_invoices
            (pi_number, quote_id, customer_id, prepared_by, issue_date, expiry_date, currency_id,
             subtotal, discount_amount, tax_amount, tax_rate, total_amount, notes, terms, status)
         VALUES
            (:num, :quote, :customer, :user, CURRENT_DATE, :expiry, :currency,
             :subtotal, :discount, :tax, :rate, :total, :notes, :terms, 'draft')
         RETURNING pi_id"
    );
    $stmt->execute([
        ':num' => $piNumber, ':quote' => $quoteId, ':customer' => $quote['customer_id'],
        ':user' => $me['id'], ':expiry' => $quote['expiry_date'], ':currency' => $quote['currency_id'],
        ':subtotal' => $quote['subtotal'], ':discount' => $quote['discount_amount'],
        ':tax' => $quote['tax_amount'], ':rate' => $quote['tax_rate'] ?? 0, ':total' => $quote['total_amount'],
        ':notes' => $quote['notes'], ':terms' => $quote['terms'],
    ]);
    $piId = (int) $stmt->fetchColumn();

    // Copy line items verbatim — including the note on each line,
    // which is the whole point of putting it on a line. Guarded on
    // both columns because they arrive in migration 056.
    $noteCol = (column_exists('quote_items', 'note')
             && column_exists('proforma_invoice_items', 'note')) ? ', note' : '';
    $pdo->prepare(
        "INSERT INTO proforma_invoice_items (pi_id, product_id, quantity, unit_price, discount$noteCol)
         SELECT :pi, product_id, quantity, unit_price, discount$noteCol
         FROM quote_items WHERE quote_id = :quote"
    )->execute([':pi' => $piId, ':quote' => $quoteId]);

    // Mark the quote converted (status value exists in the quotes CHECK).
    $pdo->prepare("UPDATE quotes SET status = 'converted', updated_at = NOW() WHERE quote_id = :id")
        ->execute([':id' => $quoteId]);

    $pdo->commit();
    audit_log('proforma.create_from_quote', 'proforma_invoices', $piId, [
        'quote' => $quote['quote_number'], 'number' => $piNumber,
    ]);
    flash('success', "Proforma {$piNumber} created from {$quote['quote_number']}.");
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[PROFORMA] create from quote failed: ' . $ex->getMessage());
    flash('error', 'Could not create the proforma. Please try again.');
}

redirect('proformas/index.php');
