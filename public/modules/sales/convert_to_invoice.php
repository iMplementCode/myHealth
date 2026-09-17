<?php

/**
 * ============================================================
 *  Sales Order → Invoice (one click)
 * ------------------------------------------------------------
 *  Converts a confirmed sales order into an invoice in a single
 *  atomic transaction, preserving customer, product lines,
 *  quantities, pricing, taxes, discounts, payment terms and
 *  notes exactly as ordered.
 *
 *  Duplicate conversions are prevented three ways:
 *    1. row lock + status re-check inside the transaction
 *    2. unique index invoices(sales_order_id)
 *    3. converted_invoice_id back-reference on the sales order
 *
 *  Records the originating order, the converting user and the
 *  conversion timestamp, and writes the document-graph edge so
 *  the full Quote → Order → Invoice trail stays navigable.
 * ============================================================
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../../includes/documents.php';
require_once __DIR__ . '/../../../includes/workflow.php';

if (!is_post()) {
    redirect('modules/sales/list_sales_orders.php');
}
csrf_check();

$soId = input_int($_POST, 'sales_order_id');
if ($soId === null) {
    flash('error', 'No sales order specified.');
    redirect('modules/sales/list_sales_orders.php');
}

$me  = current_user();
$pdo = db();

try {
    $pdo->beginTransaction();

    // Lock the order: concurrent conversions serialise here.
    $so = db_one("SELECT * FROM sales_orders WHERE sales_order_id = :id FOR UPDATE", [':id' => $soId]);
    if (!$so) {
        $pdo->rollBack();
        flash('error', 'That sales order no longer exists.');
        redirect('modules/sales/list_sales_orders.php');
    }
    if (!empty($so['converted_invoice_id']) || $so['status'] === 'invoiced') {
        $pdo->rollBack();
        flash('error', $so['order_number'] . ' has already been invoiced.');
        redirect('modules/sales/list_sales_orders.php');
    }
    if (in_array($so['status'], ['draft', 'cancelled'], true)) {
        $pdo->rollBack();
        flash('error', $so['status'] === 'draft'
            ? 'Confirm the sales order before invoicing it.'
            : 'A cancelled sales order cannot be invoiced.');
        redirect('modules/sales/list_sales_orders.php');
    }

    $lineCount = (int) db_value(
        "SELECT COUNT(*) FROM sales_order_items WHERE sales_order_id = :id",
        [':id' => $soId]
    );
    if ($lineCount === 0) {
        $pdo->rollBack();
        flash('error', 'That sales order has no line items to invoice.');
        redirect('modules/sales/list_sales_orders.php');
    }

    $invNumber = next_document_number('invoices', 'invoice_number', 'INV');

    // Payment terms, from the one place they are set, so this route
    // and the proforma route state the same thing.
    $dueDate = invoice_due_date();

    $stmt = $pdo->prepare(
        "INSERT INTO invoices
            (invoice_number, customer_id, sales_order_id, quote_id, issued_by,
             issue_date, due_date, currency_id,
             subtotal, discount_amount, tax_amount, tax_rate, total_amount,
             notes, terms, tax_note, status, converted_at, converted_by)
         VALUES
            (:num, :customer, :so, :quote, :user,
             CURRENT_DATE, :due, :currency,
             :subtotal, :discount, :tax, :rate, :total,
             :notes, :terms, :taxnote, 'issued', NOW(), :cby)
         RETURNING invoice_id"
    );
    $stmt->execute([
        ':num'      => $invNumber,
        ':customer' => $so['customer_id'],
        ':so'       => $soId,
        ':quote'    => $so['quote_id'],
        ':user'     => $me['id'],
        ':due'      => $dueDate,
        ':currency' => $so['currency_id'],
        ':subtotal' => $so['subtotal'],
        ':discount' => $so['discount_amount'],
        ':tax'      => $so['tax_amount'],
        ':rate'     => $so['tax_rate'] ?? 0,
        ':total'    => $so['total_amount'],
        ':notes'    => $so['notes'] ?? null,
        ':terms'    => $so['terms'] ?? null,
        // The sentence about tax belongs to the sale, not to the
        // document that happened to state it first.
        ':taxnote'  => $so['tax_note'] ?? null,
        ':cby'      => $me['id'],
    ]);
    $invoiceId = (int) $stmt->fetchColumn();

    // Copy every line verbatim: product, quantity, price, discount,
    // and the note written on that line. Guarded on both columns
    // because they arrive in migration 056.
    $noteCol = (column_exists('sales_order_items', 'note')
             && column_exists('invoice_items', 'note')) ? ', note' : '';
    $pdo->prepare(
        "INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, discount$noteCol)
         SELECT :inv, product_id, quantity, unit_price, discount$noteCol
         FROM sales_order_items WHERE sales_order_id = :so"
    )->execute([':inv' => $invoiceId, ':so' => $soId]);

    // Close the loop on the order.
    $pdo->prepare(
        "UPDATE sales_orders
            SET status = 'invoiced', converted_invoice_id = :inv,
                invoiced_at = NOW(), invoiced_by = :user
          WHERE sales_order_id = :so"
    )->execute([':inv' => $invoiceId, ':user' => $me['id'], ':so' => $soId]);

    // Point any delivery notes raised against the order at the invoice
    // so the Invoice ↔ Delivery Note relationship holds (brief §6).
    $pdo->prepare(
        "UPDATE delivery_notes SET invoice_id = :inv
          WHERE sales_order_id = :so AND invoice_id IS NULL"
    )->execute([':inv' => $invoiceId, ':so' => $soId]);

    // Carry across whatever has already gone out — per line, not just
    // the headline status — so an invoice raised after a delivery
    // opens life reporting it. Runs last, once the notes above point
    // at the invoice.
    $pdo->prepare("SELECT refresh_invoice_delivery(:inv)")->execute([':inv' => $invoiceId]);

    $pdo->commit();

    // Document graph — recorded after commit; never blocks the sale.
    link_documents('sales_order', $soId, 'invoice', $invoiceId);
    if (!empty($so['quote_id'])) {
        link_documents('quote', (int) $so['quote_id'], 'invoice', $invoiceId);
    }

    audit_log('sales_order.invoice', 'sales_orders', $soId, [
        'sales_order' => $so['order_number'],
        'invoice'     => $invNumber,
        'total'       => $so['total_amount'],
    ]);
    flash('success', "{$so['order_number']} invoiced as {$invNumber}.");
    redirect('invoices/view.php?id=' . $invoiceId);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[SO] convert failed: ' . $ex->getMessage());
    flash('error', 'Conversion failed. Please try again.');
    redirect('modules/sales/list_sales_orders.php');
}
