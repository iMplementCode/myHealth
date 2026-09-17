<?php

/**
 * ============================================================
 *  Purchase Orders — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page. Preserves the
 *  legacy actions — safe edit (delivery/notes/terms) and
 *  strict cancel (blocked when goods have been received) —
 *  and adds pagination, search, status filter and a create
 *  button.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/documents.php';
require_once __DIR__ . '/../../../includes/purchasing.php';
require_once __DIR__ . '/../../../includes/export.php';
require_once __DIR__ . '/../../../includes/uploads.php';

// Purchasing is a Manager's module: the menu has always hidden it
// from everyone else, but hiding a link is not access control —
// the page was reachable by anyone who knew the URL.
require_role(ROLE_MANAGER);

/* ── AJAX: create a purchase order (document builder) ─────── */
// Totals are recalculated server-side; the browser's numbers are
// never trusted.
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $supplierId = input_int($_POST, 'supplier_id');
    $currencyId = input_int($_POST, 'currency_id') ?? default_currency_id();
    $issueDate  = input($_POST, 'issue_date') ?: date('Y-m-d');
    $expected   = input($_POST, 'expected_delivery_date');
    $taxRate    = (float) ($_POST['tax_rate'] ?? 0);

    if ($supplierId === null || !db_value("SELECT 1 FROM suppliers WHERE supplier_id = :id", [':id' => $supplierId])) {
        json_response(['success' => false, 'message' => 'Please choose a valid supplier.']);
    }
    if ($currencyId === null) {
        json_response(['success' => false, 'message' => 'No currency is configured.']);
    }
    foreach ([['issue date', $issueDate], ['expected delivery date', $expected]] as [$label, $d]) {
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            json_response(['success' => false, 'message' => "Please provide a valid $label."]);
        }
    }
    if ($taxRate < 0 || $taxRate > 1) {
        json_response(['success' => false, 'message' => 'Invalid tax rate.']);
    }

    // Line items — one row per product, quantities and prices positive.
    $prodIds = $_POST['product_id'] ?? [];
    $qtys    = $_POST['quantity'] ?? [];
    $prices  = $_POST['unit_price'] ?? [];
    $items   = [];
    $subtotal = 0.0;
    foreach ((array) $prodIds as $i => $pid) {
        $pid   = (int) $pid;
        $qty   = (float) ($qtys[$i] ?? 0);
        $price = (float) ($prices[$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        if ($price < 0) {
            json_response(['success' => false, 'message' => 'Unit prices must be 0 or more.']);
        }
        if (isset($items[$pid])) {
            json_response(['success' => false, 'message' => 'Each product may appear only once — combine the quantities.']);
        }
        // Goods AND services. The business buys somebody else's labour
        // — logo design, subcontracted cabling — and that gets an order
        // and a bill like anything else. Only the receipt differs:
        // nothing arrives, so a service line is accepted rather than
        // received (migration 042).
        if (!db_value("SELECT 1 FROM products p WHERE p.product_id = :id AND p.is_active", [':id' => $pid])) {
            json_response(['success' => false, 'message' => 'A selected product no longer exists.']);
        }
        $items[$pid] = ['qty' => $qty, 'price' => $price];
        $subtotal   += $qty * $price;
    }
    if (!$items) {
        json_response(['success' => false, 'message' => 'Add at least one product line.']);
    }

    $tax   = $subtotal * $taxRate;
    $total = $subtotal + $tax;
    $pdo   = db();

    try {
        $pdo->beginTransaction();
        $poNumber = next_document_number('purchase_orders', 'po_number', 'PO');
        $stmt = $pdo->prepare(
            "INSERT INTO purchase_orders
                (po_number, supplier_id, issued_by, issue_date, expected_delivery_date,
                 currency_id, subtotal, tax_amount, tax_rate, total_amount, delivery_address, notes, terms, status)
             VALUES
                (:num, :sup, :by, :issue, :expected,
                 :cur, :sub, :tax, :rate, :total, :addr, :notes, :terms, 'draft')
             RETURNING po_id"
        );
        $stmt->execute([
            ':num' => $poNumber, ':sup' => $supplierId, ':by' => current_user()['id'],
            ':issue' => $issueDate, ':expected' => $expected ?: null,
            ':cur' => $currencyId, ':sub' => $subtotal, ':tax' => $tax, ':rate' => $taxRate, ':total' => $total,
            ':addr' => input($_POST, 'delivery_address') ?: null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':terms' => input($_POST, 'terms') ?: null,
        ]);
        $poId = (int) $stmt->fetchColumn();

        $itemStmt = $pdo->prepare(
            "INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_price)
             VALUES (:po, :pid, :qty, :price)"
        );
        foreach ($items as $pid => $it) {
            $itemStmt->execute([':po' => $poId, ':pid' => $pid, ':qty' => $it['qty'], ':price' => $it['price']]);
        }

        $pdo->commit();
        audit_log('purchase_order.create', 'purchase_orders', $poId, ['number' => $poNumber, 'total' => $total]);
        json_response(['success' => true, 'message' => "Purchase order $poNumber created."]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PO] create failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not create the purchase order.'], 500);
    }
}

/* ── AJAX: edit PO details (modal) ────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'edit') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id = input_int($_POST, 'po_id');
    $po = $id !== null
        ? db_one("SELECT po_id, po_number, status FROM purchase_orders WHERE po_id = :id", [':id' => $id])
        : null;
    if (!$po) {
        json_response(['success' => false, 'message' => 'That purchase order no longer exists.']);
    }
    if (in_array($po['status'], ['cancelled', 'received'], true)) {
        json_response(['success' => false, 'message' =>
            'A ' . str_replace('_', ' ', $po['status']) . ' purchase order can no longer be edited.']);
    }

    $expDate = input($_POST, 'expected_delivery_date');
    if ($expDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expDate)) {
        json_response(['success' => false, 'message' => 'Please provide a valid delivery date.']);
    }
    $taxRate = (float) ($_POST['tax_rate'] ?? 0);
    if ($taxRate < 0 || $taxRate > 1) {
        json_response(['success' => false, 'message' => 'Invalid tax rate.']);
    }

    // What has already been received against each line. Those
    // quantities are history — the edit may not contradict them.
    $received = [];
    foreach (db_all(
        "SELECT poi.product_id, poi.quantity_fulfilled, p.name
           FROM purchase_order_items poi
           JOIN products p ON p.product_id = poi.product_id
          WHERE poi.po_id = :id",
        [':id' => $id]
    ) as $row) {
        $received[(int) $row['product_id']] = $row;
    }

    // Submitted lines, deduplicated and validated.
    $prodIds = $_POST['product_id'] ?? [];
    $qtys    = $_POST['quantity'] ?? [];
    $prices  = $_POST['unit_price'] ?? [];
    $items    = [];
    $subtotal = 0.0;
    foreach ((array) $prodIds as $i => $pid) {
        $pid   = (int) $pid;
        $qty   = (float) ($qtys[$i] ?? 0);
        $price = (float) ($prices[$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        if ($price < 0) {
            json_response(['success' => false, 'message' => 'Unit prices must be 0 or more.']);
        }
        if (isset($items[$pid])) {
            json_response(['success' => false, 'message' => 'Each product may appear only once — combine the quantities.']);
        }
        // Goods AND services. The business buys somebody else's labour
        // — logo design, subcontracted cabling — and that gets an order
        // and a bill like anything else. Only the receipt differs:
        // nothing arrives, so a service line is accepted rather than
        // received (migration 042).
        if (!db_value("SELECT 1 FROM products p WHERE p.product_id = :id AND p.is_active", [':id' => $pid])) {
            json_response(['success' => false, 'message' => 'A selected product no longer exists.']);
        }
        // Cannot order less than has already arrived.
        $already = (float) ($received[$pid]['quantity_fulfilled'] ?? 0);
        if ($qty < $already - 1e-9) {
            json_response(['success' => false, 'message' => sprintf(
                '%s: %s already received, so the order cannot be reduced to %s.',
                $received[$pid]['name'], num($already, 2), num($qty, 2)
            )]);
        }
        $items[$pid] = ['qty' => $qty, 'price' => $price];
        $subtotal   += $qty * $price;
    }
    if (!$items) {
        json_response(['success' => false, 'message' => 'A purchase order needs at least one product line.']);
    }
    // A line with receipts against it cannot simply disappear.
    foreach ($received as $pid => $row) {
        if ((float) $row['quantity_fulfilled'] > 0 && !isset($items[$pid])) {
            json_response(['success' => false, 'message' => sprintf(
                '%s has %s already received and cannot be removed from the order.',
                $row['name'], num($row['quantity_fulfilled'], 2)
            )]);
        }
    }

    $tax   = $subtotal * $taxRate;
    $total = $subtotal + $tax;
    $pdo   = db();

    try {
        $pdo->beginTransaction();

        // Re-check under a row lock so a concurrent receipt cannot slip
        // in between the validation above and the write below.
        $locked = db_one("SELECT status FROM purchase_orders WHERE po_id = :id FOR UPDATE", [':id' => $id]);
        if (!$locked || in_array($locked['status'], ['cancelled', 'received'], true)) {
            throw new RuntimeException('This purchase order can no longer be edited.');
        }

        $pdo->prepare(
            "UPDATE purchase_orders
                SET expected_delivery_date = :exp, delivery_address = :addr,
                    notes = :notes, terms = :terms,
                    subtotal = :sub, tax_amount = :tax, tax_rate = :rate, total_amount = :total,
                    updated_at = NOW()
              WHERE po_id = :id"
        )->execute([
            ':exp'   => $expDate !== '' ? $expDate : null,
            ':addr'  => input($_POST, 'delivery_address') ?: null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':terms' => input($_POST, 'terms') ?: null,
            ':sub'   => $subtotal, ':tax' => $tax, ':rate' => $taxRate, ':total' => $total,
            ':id'    => $id,
        ]);

        // Update in place rather than delete-and-reinsert, so
        // quantity_fulfilled — the record of what has already arrived —
        // survives the edit.
        $upd = $pdo->prepare(
            "UPDATE purchase_order_items
                SET quantity_ordered = :qty, unit_price = :price
              WHERE po_id = :po AND product_id = :pid"
        );
        $ins = $pdo->prepare(
            "INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_price)
             VALUES (:po, :pid, :qty, :price)"
        );
        foreach ($items as $pid => $it) {
            if (isset($received[$pid])) {
                $upd->execute([':qty' => $it['qty'], ':price' => $it['price'], ':po' => $id, ':pid' => $pid]);
            } else {
                $ins->execute([':po' => $id, ':pid' => $pid, ':qty' => $it['qty'], ':price' => $it['price']]);
            }
        }
        // Drop lines the user removed (only ever unfulfilled ones —
        // the guard above already refused the rest).
        $keep = implode(',', array_map('intval', array_keys($items)));
        $pdo->prepare(
            "DELETE FROM purchase_order_items
              WHERE po_id = :po AND product_id NOT IN ($keep) AND quantity_fulfilled = 0"
        )->execute([':po' => $id]);

        $pdo->commit();
        audit_log('po.update', 'purchase_orders', $id, [
            'number' => $po['po_number'], 'lines' => count($items), 'total' => $total,
        ]);
        json_response(['success' => true, 'message' => "Purchase order {$po['po_number']} updated."]);
    } catch (PDOException $ex) {
        // Must be caught before RuntimeException, which PDOException
        // extends — otherwise a raw SQL error reaches the user.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PO] edit failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not save changes.')], 500);
    } catch (RuntimeException $ex) {
        // Raised by this handler, with a message written for the user.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PO] edit failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not save changes.'], 500);
    }
}

/* ── AJAX: record what the supplier said ──────────────────── */
// One handler for every manual move — accepted, rejected,
// cancelled, sent, closed. Each is written with who decided and
// why, so a rejection can still be explained months later.
if (is_post() && ($_POST['_action'] ?? '') === 'status') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id = input_int($_POST, 'po_id');
    $po = $id !== null ? db_one(
        "SELECT po_id, po_number, status, amount_paid, total_amount
           FROM purchase_orders WHERE po_id = :id",
        [':id' => $id]
    ) : null;
    if (!$po) {
        json_response(['success' => false, 'message' => 'That purchase order no longer exists.']);
    }

    $to  = input($_POST, 'status');
    $err = po_status_change_error($po, $to);
    if ($err !== null) {
        json_response(['success' => false, 'message' => $err]);
    }

    $note = input($_POST, 'status_note');
    // A refusal with no reason recorded is the one everybody asks
    // about later, so this is the single case where a note is required.
    if ($to === 'rejected' && $note === '') {
        json_response(['success' => false, 'message' => 'Please say why the supplier rejected the order.']);
    }

    try {
        db_run(
            "UPDATE purchase_orders
                SET status = :s, status_note = :note,
                    status_changed_at = NOW(), status_changed_by = :by, updated_at = NOW()
              WHERE po_id = :id",
            [':s' => $to, ':note' => $note ?: null, ':by' => current_user()['id'], ':id' => $id]
        );
    } catch (PDOException $ex) {
        error_log('[PO] status change failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not update the status.')], 500);
    }

    audit_log('po.status', 'purchase_orders', $id, [
        'number' => $po['po_number'], 'from' => $po['status'], 'to' => $to,
    ]);
    json_response([
        'success' => true,
        'message' => $po['po_number'] . ' marked as ' . strtolower(po_status_label($to)) . '.',
    ]);
}

/* ── AJAX: pay a supplier against this order ──────────────── */
// Money out of a real account, with its proof, and the cash book
// entry written in the same transaction. amount_paid and
// payment_status are left to the database trigger — neither is
// ever a figure anyone types.
if (is_post() && ($_POST['_action'] ?? '') === 'pay') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = input($_POST, 'method');

    $proof = ['file' => null, 'error' => null];
    if (!empty($_FILES['proof']['name'])) {
        $proof = store_uploaded_document($_FILES['proof'], 'payments');
        if ($proof['error']) {
            json_response(['success' => false, 'message' => $proof['error']]);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // The rules about what may be paid, and how much, live with
        // the ledger — so a payment entered here and one entered on
        // the cash book are the same event recorded the same way.
        $result = cash_record_supplier_payment([
            'po_id'      => input_int($_POST, 'po_id'),
            'date'       => input($_POST, 'payment_date'),
            'amount'     => $amount,
            'method'     => $method,
            'reference'  => input($_POST, 'reference'),
            'account_id' => input_int($_POST, 'cash_account_id'),
            'notes'      => input($_POST, 'notes'),
            'proof_url'      => $proof['file']['url'] ?? null,
            'proof_filename' => $proof['file']['filename'] ?? null,
        ]);

        $pdo->commit();

        audit_log('purchase_payment.record', 'purchase_payments', $result['payment_id'], [
            'po' => $result['order']['po_number'], 'amount' => $amount, 'method' => $method,
        ]);
        json_response([
            'success' => true,
            'message' => sprintf(
                '%s paid against %s. %s',
                money($amount),
                $result['order']['po_number'],
                $result['outstanding'] > 0.005
                    ? money($result['outstanding']) . ' still to pay.'
                    : 'The order is now paid in full.'
            ),
        ]);
    } catch (PDOException $ex) {
        // Caught before RuntimeException, which PDOException extends.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PO] payment failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the payment.')], 500);
    } catch (RuntimeException $ex) {
        // Raised by the ledger, with a message written for the user.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PO] payment failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the payment.'], 500);
    }
}

/* ── POST: cancel PO (strict — blocked once goods received) ─ */
/* ── AJAX: the service work has been done ─────────────────── */
//  A service never arrives, so it never gets a goods received note.
//  This is its equivalent: the day the work was accepted is the day
//  it becomes payable, and it is owed AS OF that date so an aged
//  payables report run for last month is not moved by work accepted
//  this month. See migration 042.
if (is_post() && ($_POST['_action'] ?? '') === 'accept_service') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    if (!column_exists('purchase_order_items', 'accepted_at')) {
        json_response(['success' => false, 'message' =>
            'Accepting service work needs migration 042. Run the outstanding migrations first.']);
    }

    $id   = input_int($_POST, 'po_id');
    $date = input($_POST, 'accepted_at') ?: date('Y-m-d');
    $po   = $id !== null ? db_one(
        "SELECT po_id, po_number, status FROM purchase_orders WHERE po_id = :id", [':id' => $id]
    ) : null;

    if (!$po) {
        json_response(['success' => false, 'message' => 'That purchase order no longer exists.']);
    }
    if ($po['status'] === 'cancelled') {
        json_response(['success' => false, 'message' => $po['po_number'] . ' is cancelled.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'Please provide a valid date, not in the future.']);
    }

    // ── The supplier's own bill ──────────────────────────────
    //  Optional: the work is often accepted before the invoice
    //  turns up. What it must not be is half-entered — a VAT
    //  figure with no invoice number behind it is a claim that
    //  cannot be defended, so the two travel together.
    $hasBill  = column_exists('purchase_order_items', 'supplier_invoice_no');
    $billNo   = $hasBill ? trim((string) input($_POST, 'supplier_invoice_no')) : '';
    $billDate = $hasBill ? trim((string) input($_POST, 'supplier_invoice_date')) : '';
    $billTax  = $hasBill ? trim((string) input($_POST, 'tax_amount')) : '';

    if ($billDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $billDate) || $billDate > date('Y-m-d'))) {
        json_response(['success' => false, 'message' =>
            'The invoice date is not a real date, or it is in the future.']);
    }
    if ($billTax !== '') {
        if (!is_numeric($billTax) || (float) $billTax < 0) {
            json_response(['success' => false, 'message' =>
                'The VAT charged has to be a number, and cannot be negative.']);
        }
        if ($billNo === '') {
            json_response(['success' => false, 'message' =>
                'Add the supplier\'s invoice number as well. VAT can only be '
                . 'claimed against the tax invoice it came from, so a figure '
                . 'with no invoice behind it would not survive an audit.']);
        }
    }

    $pending = db_all(
        "SELECT poi.po_item_id, poi.quantity_ordered, poi.unit_price, p.name
           FROM purchase_order_items poi
           JOIN products p ON p.product_id = poi.product_id
          WHERE poi.po_id = :id AND p.product_type = 'service' AND poi.accepted_at IS NULL",
        [':id' => $id]
    );
    if (!$pending) {
        json_response(['success' => false, 'message' =>
            'There is no service work outstanding on ' . $po['po_number'] . '.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $value = 0.0;
        $pendingNet = 0.0;
        foreach ($pending as $line) {
            $pendingNet += (float) $line['quantity_ordered'] * (float) $line['unit_price'];
        }
        $taxAllocated = 0.0;
        $lastIndex    = count($pending) - 1;
        foreach ($pending as $index => $line) {
            $lineNet = (float) $line['quantity_ordered'] * (float) $line['unit_price'];

            // One bill can cover several service lines, so its VAT is
            // split across them in proportion to their value. The last
            // line takes the rounding remainder, or the parts would not
            // add back up to what the supplier actually charged.
            $lineTax = null;
            if ($billTax !== '' && $pendingNet > 0) {
                $lineTax = $index === $lastIndex
                    ? round((float) $billTax - $taxAllocated, 2)
                    : round((float) $billTax * ($lineNet / $pendingNet), 2);
                $taxAllocated += $lineTax;
            }

            db_run(
                "UPDATE purchase_order_items
                    SET accepted_at = :d,
                        -- Fulfilled, so the order can complete rather
                        -- than waiting for goods that never come.
                        quantity_fulfilled = quantity_ordered"
                . ($hasBill ? ",
                        supplier_invoice_no   = :bn,
                        supplier_invoice_date = :bd,
                        tax_amount            = :tx" : '') . "
                  WHERE po_item_id = :i",
                array_merge(
                    [':d' => $date, ':i' => $line['po_item_id']],
                    $hasBill ? [
                        ':bn' => $billNo   !== '' ? $billNo   : null,
                        ':bd' => $billDate !== '' ? $billDate : null,
                        ':tx' => $lineTax,
                    ] : []
                )
            );
            $value += $lineNet;
        }

        // An order with nothing left outstanding is done, whether what
        // it was waiting for was goods, work, or both.
        $stillOwed = (int) db_value(
            "SELECT COUNT(*) FROM purchase_order_items
              WHERE po_id = :id AND quantity_fulfilled < quantity_ordered",
            [':id' => $id]
        );
        db_run(
            "UPDATE purchase_orders SET status = :s, updated_at = NOW() WHERE po_id = :id",
            [':s' => $stillOwed > 0 ? 'partially_received' : 'received', ':id' => $id]
        );
        $pdo->commit();

        audit_log('po.accept_service', 'purchase_orders', (int) $id, [
            'number' => $po['po_number'], 'lines' => count($pending), 'value' => $value,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s of service work accepted on %s. It is now owed to the supplier and can be paid.',
            money($value), $po['po_number']
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[PO] accept service failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not accept that work.')], 500);
    }
}

if (is_post() && ($_POST['_action'] ?? '') === 'cancel') {
    csrf_check();
    $id = input_int($_POST, 'po_id');
    $po = $id !== null ? db_one(
        "SELECT po_id, po_number, status, amount_paid, total_amount
           FROM purchase_orders WHERE po_id = :id",
        [':id' => $id]
    ) : null;
    if (!$po) {
        flash('error', 'That purchase order no longer exists.');
    } elseif ($po['status'] === 'cancelled') {
        flash('info', $po['po_number'] . ' is already cancelled.');
    } elseif (($err = po_status_change_error($po, 'cancelled')) !== null) {
        flash('error', $err);
    } else {
        db_run(
            "UPDATE purchase_orders
                SET status = 'cancelled', status_changed_at = NOW(),
                    status_changed_by = :by, updated_at = NOW()
              WHERE po_id = :id",
            [':by' => current_user()['id'], ':id' => $id]
        );
        audit_log('po.cancel', 'purchase_orders', $id, ['number' => $po['po_number']]);
        flash('success', $po['po_number'] . ' cancelled.');
    }
    redirect('modules/purchasing/manage_pos.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search     = input($_GET, 'q');
$status     = input($_GET, 'status');
$payState   = input($_GET, 'payment');
$supplierId = input_int($_GET, 'supplier_id');
$dateField  = input($_GET, 'date_field');
$dateFrom   = input($_GET, 'from');
$dateTo     = input($_GET, 'to');
$perPage    = per_page();

$isDate   = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$dateFrom = $isDate($dateFrom) ? $dateFrom : '';
$dateTo   = $isDate($dateTo)   ? $dateTo   : '';
// A range entered backwards is a slip, not a request for nothing.
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

// Which date the range applies to. An order is looked for by when it
// was raised, by when it was promised, or by when it was paid —
// three different questions, and only the buyer knows which one.
const PO_DATE_FIELDS = [
    'issue_date'             => ['label' => 'Ordered',  'sql' => 'po.issue_date'],
    'expected_delivery_date' => ['label' => 'Expected', 'sql' => 'po.expected_delivery_date'],
    'received'               => ['label' => 'Goods received',
                                 'sql'   => '(SELECT MAX(g.receipt_date) FROM grns g
                                                WHERE g.po_id = po.po_id AND g.status <> \'cancelled\')'],
    'paid'                   => ['label' => 'Last paid',
                                 'sql'   => '(SELECT MAX(pp.payment_date) FROM purchase_payments pp
                                                WHERE pp.po_id = po.po_id)'],
];
if (!isset(PO_DATE_FIELDS[$dateField])) {
    $dateField = 'issue_date';
}

$where  = [];
$params = [];
if ($search !== '') {
    // The three things anyone searches an order by: its number, the
    // supplier, and a note left on it.
    $where[] = "(LOWER(po.po_number) LIKE :q
                 OR LOWER(COALESCE(s.name, '')) LIKE :q
                 OR LOWER(COALESCE(po.notes, '')) LIKE :q
                 OR LOWER(COALESCE(po.status_note, '')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($supplierId !== null) {
    $where[] = "po.supplier_id = :sup";
    $params[':sup'] = $supplierId;
}
if (isset(PO_STATUSES[$status])) {
    $where[] = "po.status = :status";
    $params[':status'] = $status;
} elseif ($status === 'open') {
    // Everything still live: not finished, not withdrawn, not refused.
    $where[] = "po.status NOT IN ('received', 'closed', 'cancelled', 'rejected')";
} else {
    $status = '';
}
if (isset(PO_PAYMENT_STATUSES[$payState])) {
    $where[] = "po.payment_status = :pay";
    $params[':pay'] = $payState;
} elseif ($payState === 'outstanding') {
    // The question a buyer actually asks: what still owes money?
    $where[] = "po.payment_status <> 'paid' AND po.status NOT IN ('cancelled', 'rejected')";
} else {
    $payState = '';
}
$dateSql = PO_DATE_FIELDS[$dateField]['sql'];
if ($dateFrom !== '') {
    $where[] = "$dateSql >= :from";
    $params[':from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "$dateSql <= :to";
    $params[':to'] = $dateTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$filtersActive = $search !== '' || $supplierId !== null || $status !== ''
    || $payState !== '' || $dateFrom !== '' || $dateTo !== '';

if (wants_export()) {
    export_deliver(export_filename('purchase-orders'), [[
        'name'  => 'Purchase orders',
        'title' => 'Purchase orders',
        'meta'  => export_meta([
            'Supplier' => $supplierId !== null
                ? (string) db_value("SELECT name FROM suppliers WHERE supplier_id = :s", [':s' => $supplierId]) : 'all',
            'Dates'    => ($dateFrom !== '' || $dateTo !== '')
                ? (PO_DATE_FIELDS[$dateField]['label'] . ': ' . ($dateFrom ?: 'the beginning')
                   . ' to ' . ($dateTo ?: 'today')) : 'all dates',
            'Status'   => $status !== '' ? ($status === 'open' ? 'Still open' : po_status_label($status)) : 'any',
            'Payment'  => $payState !== '' ? ($payState === 'outstanding' ? 'Still owing' : po_payment_label($payState)) : 'any',
            'Search'   => $search,
        ]),
        'columns' => [
            ['label' => 'PO number', 'key' => 'po_number'],
            ['label' => 'Supplier',  'key' => 'supplier_name'],
            ['label' => 'Ordered',   'key' => 'issue_date',   'type' => 'date'],
            ['label' => 'Expected',  'key' => 'expected_delivery_date', 'type' => 'date'],
            ['label' => 'Status',    'key' => 'status', 'value' => fn($r) => po_status_label($r['status'])],
            ['label' => 'Status note','key' => 'status_note'],
            ['label' => 'Payment',   'key' => 'payment_status', 'value' => fn($r) => po_payment_label($r['payment_status'])],
            ['label' => 'GRNs',      'key' => 'grn_count',      'type' => 'integer'],
            ['label' => 'Total',     'key' => 'total_amount',   'type' => 'money'],
            ['label' => 'Received',  'key' => 'received_value', 'type' => 'money'],
            ['label' => 'Paid',      'key' => 'amount_paid',    'type' => 'money'],
            ['label' => 'Balance',   'key' => 'balance',        'type' => 'money',
             'value' => fn($r) => max((float) $r['total_amount'] - (float) $r['amount_paid'], 0)],
            ['label' => 'Raised by', 'key' => 'issued_by_name'],
        ],
        'rows'  => db_all(
            "SELECT po.*, s.name AS supplier_name,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name,
                    (SELECT COUNT(*) FROM grns g WHERE g.po_id = po.po_id AND g.status != 'cancelled') AS grn_count,
                    (SELECT COALESCE(SUM(g.total_amount), 0) FROM grns g
                      WHERE g.po_id = po.po_id AND g.status != 'cancelled') AS received_value
               FROM purchase_orders po
               LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
               LEFT JOIN users u ON u.user_id = po.issued_by
             $whereSql
             ORDER BY po.issue_date DESC, po.po_id DESC",
            $params
        ),
        'total' => true,
    ]]);
}

$total = (int) db_value("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id $whereSql", $params);
$pg    = paginate($total, $perPage);

// Service work that has been ordered but not yet accepted. A service
// never gets a goods received note, so this is what tells the row
// whether there is anything left to accept (migration 042).
$serviceWaiting = (column_exists('purchase_order_items', 'accepted_at') && product_types_available())
    ? "(SELECT COUNT(*) FROM purchase_order_items poi
          JOIN products p ON p.product_id = poi.product_id
         WHERE poi.po_id = po.po_id AND p.product_type = 'service'
           AND poi.accepted_at IS NULL)"
    : '0';

$pos = db_all(
    "SELECT po.*, s.name AS supplier_name,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS issued_by_name,
            (SELECT COUNT(*) FROM grns g WHERE g.po_id = po.po_id AND g.status != 'cancelled') AS grn_count,
            (SELECT COALESCE(SUM(g.total_amount), 0) FROM grns g
              WHERE g.po_id = po.po_id AND g.status != 'cancelled')                            AS received_value,
            $serviceWaiting AS services_pending
     FROM purchase_orders po
     LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
     LEFT JOIN users u ON u.user_id = po.issued_by
     $whereSql
     ORDER BY po.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Headline figures for the filtered set, so the page answers
// "how much do we still owe on these?" without any adding up.
$sums = db_one(
    "SELECT COALESCE(SUM(po.total_amount), 0) AS ordered,
            COALESCE(SUM(po.amount_paid), 0)  AS paid
       FROM purchase_orders po
       LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
     $whereSql",
    $params
) ?: ['ordered' => 0, 'paid' => 0];
$outstandingNow = finance_trade_payables(date('Y-m-d'));

// Accounts money can be paid from. With none set up, the pay
// button is hidden rather than opening a form that cannot submit.
$payAccounts = cash_liquid_accounts();

// Only suppliers that have actually been ordered from. A dropdown
// listing every supplier ever created is a list of dead ends.
$supplierFilterOptions = db_all(
    "SELECT DISTINCT s.supplier_id, s.name
       FROM purchase_orders po
       JOIN suppliers s ON s.supplier_id = po.supplier_id
      ORDER BY s.name"
);

// Line items for the POs on this page, so the edit modal can open
// already populated instead of asking the user to retype the order.
$itemsByPo = [];
if ($pos) {
    $ids = implode(',', array_map(fn($p) => (int) $p['po_id'], $pos));
    // The product NAME travels with the line: the picker holds a
    // page of products, not all of them, so without this an edit
    // could open with the line's own product missing from its
    // <select> and silently change what was ordered.
    foreach (db_all(
        "SELECT poi.po_id, poi.product_id, poi.quantity_ordered, poi.unit_price,
                p.name || COALESCE(' (' || NULLIF(p.sku, '') || ')', '') AS name
           FROM purchase_order_items poi
           JOIN products p ON p.product_id = poi.product_id
          WHERE poi.po_id IN ($ids)
          ORDER BY poi.po_item_id"
    ) as $it) {
        $itemsByPo[(int) $it['po_id']][] = [
            'product_id' => (int) $it['product_id'],
            'name'       => $it['name'],
            'quantity'   => (float) $it['quantity_ordered'],
            'unit_price' => (float) $it['unit_price'],
            'discount'   => 0,
        ];
    }
}

$pageTitle    = 'Purchase Orders';
$pageSubtitle = num($total) . ' purchase order' . ($total === 1 ? '' : 's');
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['docbuilder.js'];

// Options for the create modal.
$supplierOptions = db_all("SELECT supplier_id, name FROM suppliers WHERE is_active = TRUE ORDER BY name");
$currencyOptions = db_all("SELECT currency_id, code FROM currencies WHERE is_active = TRUE ORDER BY (LOWER(code)='kes') DESC, code");
// Goods and services both. A service is bought the same way as a
// product and billed the same way; it simply never arrives, so it
// is accepted instead of received. The picker says which is which,
// because ordering a service by mistake and then waiting for a
// delivery is the confusion this is meant to avoid. That marking
// now lives in the 'purchase_products' lookup list.
$breadcrumbs  = [['label' => 'Purchasing'], ['label' => 'Purchase Orders']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter([
    'q' => $search, 'status' => $status, 'payment' => $payState,
    'supplier_id' => $supplierId,
    'date_field' => $dateField !== 'issue_date' ? $dateField : null,
    'from' => $dateFrom, 'to' => $dateTo,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Ordered (filtered)</span><span class="mini-value"><?= e(money($sums['ordered'])) ?></span></div>
    <div class="mini-card"><span class="mini-label">Paid (filtered)</span><span class="mini-value tone-out"><?= e(money($sums['paid'])) ?></span></div>
    <div class="mini-card"><span class="mini-label">Still to pay (filtered)</span><span class="mini-value"><?= e(money(max((float) $sums['ordered'] - (float) $sums['paid'], 0))) ?></span></div>
    <div class="mini-card">
        <span class="mini-label">Trade payables now</span>
        <span class="mini-value"><?= e(money($outstandingNow)) ?></span>
        <span class="mini-note">goods received, not yet paid</span>
    </div>
</section>

<form class="filter-bar" method="GET" action="<?= e(url('modules/purchasing/manage_pos.php')) ?>">
    <div class="filter-row">
        <div class="toolbar-search filter-grow">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="PO number, supplier, or a note on the order…">
        </div>
        <div class="toolbar-actions">
            <?= export_button('modules/purchasing/manage_pos.php', $baseQuery) ?>
            <a class="btn btn-ghost" href="<?= e(url('modules/purchasing/payments.php')) ?>"><?= icon('money') ?> Supplier Payments</a>
            <button type="button" class="btn btn-primary"
                    data-modal-open="tplPoCreate" data-modal-title="Create Purchase Order" data-modal-xl data-modal-auto>
                <?= icon('plus') ?> Create Purchase Order
            </button>
        </div>
    </div>

    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">Supplier</span>
            <select name="supplier_id" class="form-control" data-searchable="Search suppliers">
                <option value="">All suppliers</option>
                <?php foreach ($supplierFilterOptions as $sp): ?>
                    <option value="<?= (int) $sp['supplier_id'] ?>" <?= $supplierId === (int) $sp['supplier_id'] ? 'selected' : '' ?>>
                        <?= e($sp['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php // Which date the range applies to. Looking for what was
              // ordered in March is a different question from what
              // arrived in March, and both get asked. ?>
        <label class="filter-field">
            <span class="filter-label">Date</span>
            <select name="date_field" class="form-control">
                <?php foreach (PO_DATE_FIELDS as $key => $meta): ?>
                    <option value="<?= e($key) ?>" <?= $dateField === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">Status</span>
            <select name="status" class="form-control">
                <option value="">Any status</option>
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Still open</option>
                <?php foreach (PO_STATUSES as $key => $meta): ?>
                    <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">Payment</span>
            <select name="payment" class="form-control">
                <option value="">Any payment state</option>
                <option value="outstanding" <?= $payState === 'outstanding' ? 'selected' : '' ?>>Still owing</option>
                <?php foreach (PO_PAYMENT_STATUSES as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $payState === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Filter</button>
            <?php if ($filtersActive): ?>
                <a href="<?= e(url('modules/purchasing/manage_pos.php')) ?>" class="btn btn-ghost"><?= icon('x') ?> Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>Issued</th>
                    <th>Expected</th>
                    <th>GRNs</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th class="ta-right">Total</th>
                    <th class="ta-right">Paid</th>
                    <th class="ta-right">Balance</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pos): ?>
                    <tr><td colspan="11" class="table-empty">No purchase orders match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($pos as $po):
                    // The order carries the rate it was priced at
                    // (migration 026). It used to be worked back out of
                    // the amounts and snapped to 16% or nothing, which
                    // reopened an 8% order as a 16% one.
                    $poRate = vat_rate_value($po['tax_rate'] ?? 0);
                    $prefill = json_encode([
                        'po_id'                  => $po['po_id'],
                        'expected_delivery_date' => $po['expected_delivery_date'],
                        'delivery_address'       => $po['delivery_address'],
                        'notes'                  => $po['notes'],
                        'terms'                  => $po['terms'],
                        'tax_rate'               => $poRate,
                        'items_json'             => json_encode($itemsByPo[(int) $po['po_id']] ?? []),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    // Editing is refused server-side once the order is closed;
                    // hide the button rather than offer a dead end.
                    $poEditable = !in_array($po['status'], ['cancelled', 'received', 'rejected', 'closed'], true);

                    $poId    = (int) $po['po_id'];
                    $paid    = (float) $po['amount_paid'];
                    $balance = max((float) $po['total_amount'] - $paid, 0.0);
                    $moves   = po_manual_transitions($po);
                    // Paying needs an order the supplier will honour and an
                    // account to pay from; offering the button without either
                    // is only a dead end.
                    $payable = $balance > 0.005
                        && !in_array($po['status'], ['draft', 'cancelled', 'rejected'], true)
                        && $payAccounts;

                    $statusFill = json_encode([
                        'po_id'       => $poId,
                        'status_note' => $po['status_note'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    $payFill = json_encode([
                        'po_id'  => $poId,
                        // Seed the balance: paying it off in full is the
                        // common case, and typing it again invites a typo.
                        'amount' => number_format($balance, 2, '.', ''),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($po['po_number']) ?></span>
                            <span class="cell-sub">by <?= e($po['issued_by_name'] ?: '—') ?></span>
                        </td>
                        <td><?= e($po['supplier_name'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($po['issue_date'])) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($po['expected_delivery_date'])) ?></td>
                        <td class="cell-muted"><?= e(num($po['grn_count'])) ?></td>
                        <td>
                            <span class="badge badge--<?= e($po['status']) ?>"><?= e(po_status_label($po['status'])) ?></span>
                            <?php if ($po['status_note']): ?>
                                <span class="cell-sub" title="<?= e($po['status_note']) ?>">
                                    <?= e(mb_strimwidth($po['status_note'], 0, 42, '…')) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge--<?= e(po_payment_tone($po['payment_status'])) ?>">
                                <?= e(po_payment_label($po['payment_status'])) ?>
                            </span>
                        </td>
                        <td class="ta-right"><strong><?= e(money($po['total_amount'])) ?></strong></td>
                        <td class="ta-right <?= $paid > 0.005 ? 'tone-out' : 'cell-muted' ?>">
                            <?= $paid > 0.005 ? e(money($paid)) : '—' ?>
                        </td>
                        <td class="ta-right <?= $balance > 0.005 ? '' : 'cell-muted' ?>">
                            <?= $balance > 0.005 ? e(money($balance)) : 'Settled' ?>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($moves): ?>
                                    <button type="button" class="icon-btn" title="Set status"
                                            data-modal-open="tplPoStatus"
                                            data-modal-title="<?= e($po['po_number']) ?> — set status"
                                            data-prefill="<?= e($statusFill) ?>"><?= icon('check') ?></button>
                                <?php endif; ?>
                                <?php if ((int) ($po['services_pending'] ?? 0) > 0
                                          && $po['status'] !== 'cancelled'): ?>
                                    <button type="button" class="icon-btn" title="The service work has been done"
                                            data-modal-open="tplPoAcceptService"
                                            data-modal-title="<?= e($po['po_number']) ?> — accept the work"
                                            data-modal-auto
                                            data-prefill='<?= e(json_encode([
                                                'po_id'       => $poId,
                                                'accepted_at' => date('Y-m-d'),
                                            ], JSON_UNESCAPED_SLASHES)) ?>'><?= icon('check') ?></button>
                                <?php endif; ?>
                                <?php if ($payable): ?>
                                    <button type="button" class="icon-btn" title="Record a payment"
                                            data-modal-open="tplPoPay"
                                            data-modal-title="Pay against <?= e($po['po_number']) ?>"
                                            data-prefill="<?= e($payFill) ?>"><?= icon('money') ?></button>
                                <?php endif; ?>
                                <?php if ($poEditable): ?>
                                    <button type="button" class="icon-btn" title="Edit purchase order"
                                            data-modal-open="tplPoForm" data-modal-title="Edit <?= e($po['po_number']) ?>"
                                            data-modal-xl
                                            data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <?php endif; ?>
                                <a href="<?= e(url('modules/purchasing/print_po.php?po_id=' . $poId)) ?>"
                                   target="_blank" class="icon-btn" title="Print PDF"><?= icon('file') ?></a>
                                <?php // Downloads under the document's own number, rather than
                                      // leaving the browser's viewer to name it after the URL. ?>
                                <a href="<?= e(url('modules/purchasing/print_po.php?po_id=' . $poId . '&dl=1')) ?>"
                                   class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                                <?php if ($po['status'] !== 'cancelled' && (int) $po['grn_count'] === 0 && $paid < 0.005): ?>
                                    <form method="POST" action="<?= e(url('modules/purchasing/manage_pos.php')) ?>" class="inline-form"
                                          data-confirm="Cancel <?= e($po['po_number']) ?>? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="cancel">
                                        <input type="hidden" name="po_id" value="<?= $poId ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Cancel PO"><?= icon('x') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'purchase order'); ?>
</div>

<!-- Set status modal — what the supplier said -->
<template id="tplPoStatus">
    <form method="POST" action="<?= e(url('modules/purchasing/manage_pos.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="status">
        <input type="hidden" name="po_id" value="">

        <div class="form-group">
            <label class="form-label">Status <span class="req">*</span></label>
            <select name="status" class="form-control" required>
                <option value="">— Choose —</option>
                <?php foreach (PO_STATUSES as $key => $meta): ?>
                    <?php if (!$meta['manual']) { continue; } ?>
                    <option value="<?= e($key) ?>"><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint">
                Partially received and Received are set by the goods received
                notes, so they are not offered here — receiving stock moves the
                order on its own.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Note <span class="opt">(required when rejecting)</span></label>
            <textarea name="status_note" class="form-control" rows="2"
                      placeholder="Why the supplier accepted, rejected, or what was agreed"></textarea>
        </div>

        <p class="form-hint">
            An order that has taken delivery or a payment cannot be cancelled or
            rejected — raise a debit note, or reverse the payment first.
        </p>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save status</button>
        </div>
    </form>
</template>

<!-- Pay a supplier -->
<template id="tplPoPay">
    <form method="POST" action="<?= e(url('modules/purchasing/manage_pos.php')) ?>"
          data-ajax enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="pay">
        <input type="hidden" name="po_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Amount <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
                <p class="form-hint">Pre-filled with the balance. Enter less to pay in part.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Payment date <span class="req">*</span></label>
                <input type="date" name="payment_date" class="form-control"
                       value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Paid from <span class="req">*</span></label>
                <select name="cash_account_id" class="form-control" required data-searchable="Search accounts">
                    <option value="">— Choose account —</option>
                    <?php foreach ($payAccounts as $a): ?>
                        <option value="<?= (int) $a['cash_account_id'] ?>"><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Method <span class="req">*</span></label>
                <select name="method" class="form-control" required>
                    <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                        <?php if ($key === 'salary_deduction') { continue; } ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Reference <span class="opt">(proof of payment code)</span></label>
            <input type="text" name="reference" class="form-control"
                   placeholder="M-Pesa code, cheque number, or bank slip reference">
        </div>

        <div class="form-group">
            <label class="form-label">Attach proof <span class="opt">(slip or statement)</span></label>
            <input type="file" name="proof" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control">
        </div>

        <p class="form-hint">
            The money leaves the account you choose and appears in the cash book
            straight away, under <strong>Paid to suppliers</strong> on the daily
            position. What is left on the order stays under trade payables.
        </p>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record payment</button>
        </div>
    </form>
</template>

<!-- Edit PO modal — full document, product lines included -->
<template id="tplPoForm">
    <form method="POST" action="<?= e(url('modules/purchasing/manage_pos.php')) ?>" data-ajax data-docbuilder>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="edit">
        <input type="hidden" name="po_id" value="">
        <input type="hidden" name="items_json" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Expected delivery date</label>
                <input type="date" name="expected_delivery_date" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Delivery address</label>
                <input type="text" name="delivery_address" class="form-control">
            </div>
        </div>

        <div class="db-head">
            <span>Product</span><span>Qty</span><span>Unit price</span><span>Discount</span><span>Line total</span><span></span>
        </div>
        <div class="db-rows" data-db-rows></div>
        <button type="button" class="btn btn-ghost btn--sm" data-db-add><?= icon('plus') ?> Add line</button>
        <p class="form-hint">
            Quantities already received cannot be reduced or removed — receive
            the rest, or cancel the order and raise a new one.
        </p>

        <div class="form-grid-2 u-mt">
            <div class="form-group">
                <label class="form-label">Tax</label>
                <select name="tax_rate" class="form-control">
                    <?= vat_rate_options($_POST['tax_rate'] ?? VAT_RATE_DEFAULT) ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Terms</label>
            <textarea name="terms" class="form-control" rows="2"></textarea>
        </div>

        <div class="db-totals">
            <div><span>Subtotal</span><strong data-db-subtotal>0.00</strong></div>
            <div><span>Tax</span><strong data-db-tax>0.00</strong></div>
            <div class="db-grand"><span>Total</span><strong data-db-total>0.00</strong></div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</template>

<template id="tplPoCreate">
    <form method="POST" action="<?= e(url('modules/purchasing/manage_pos.php')) ?>" data-ajax data-docbuilder>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Supplier <span class="req">*</span></label>
                <select name="supplier_id" class="form-control" required data-searchable="Search suppliers">
                    <option value="">— Select supplier —</option>
                    <?php foreach ($supplierOptions as $so): ?>
                        <option value="<?= (int) $so['supplier_id'] ?>"><?= e($so['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Currency <span class="req">*</span></label>
                <select name="currency_id" class="form-control" required>
                    <?php foreach ($currencyOptions as $cu): ?>
                        <option value="<?= (int) $cu['currency_id'] ?>"><?= e($cu['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Issue date</label>
                <input type="date" name="issue_date" class="form-control" value="<?= e(date('Y-m-d')) ?>">
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Expected delivery</label>
                <input type="date" name="expected_delivery_date" class="form-control">
            </div>
            <div class="form-group">
                <label class="form-label">Delivery address</label>
                <input type="text" name="delivery_address" class="form-control">
            </div>
        </div>

        <div class="db-head">
            <span>Product</span><span>Qty</span><span>Unit price</span><span>Discount</span><span>Line total</span><span></span>
        </div>
        <div class="db-rows" data-db-rows></div>
        <button type="button" class="btn btn-ghost btn--sm" data-db-add><?= icon('plus') ?> Add line</button>

        <div class="form-grid-2 u-mt">
            <div class="form-group">
                <label class="form-label">Tax</label>
                <select name="tax_rate" class="form-control">
                    <?= vat_rate_options($_POST['tax_rate'] ?? VAT_RATE_DEFAULT) ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Terms</label>
            <textarea name="terms" class="form-control" rows="2"></textarea>
        </div>

        <div class="db-totals">
            <div><span>Subtotal</span><strong data-db-subtotal>0.00</strong></div>
            <div><span>Tax</span><strong data-db-tax>0.00</strong></div>
            <div class="db-grand"><span>Total</span><strong data-db-total>0.00</strong></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Create purchase order</button>
        </div>
    </form>
</template>

<template id="tplPoAcceptService">
    <form method="POST" action="<?= e(url('modules/purchasing/manage_pos.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="accept_service">
        <input type="hidden" name="po_id" value="">

        <p class="form-hint">
            A service never arrives, so it never gets a goods received note.
            Accepting the work is its equivalent: from this date the supplier
            is owed for it and it can be paid.
        </p>

        <div class="form-group">
            <label class="form-label">Work accepted on <span class="req">*</span></label>
            <input type="date" name="accepted_at" class="form-control" required
                   max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>">
            <span class="form-hint">
                Backdate it if the work finished earlier — an aged payables
                report reads this date, not today's.
            </span>
        </div>

        <?php if (column_exists('purchase_order_items', 'supplier_invoice_no')): ?>
        <fieldset class="form-fieldset">
            <legend>Their bill</legend>
            <p class="form-hint">
                What the supplier put on their invoice. Their reference, not
                ours — it is what they will quote when they chase the payment,
                and the VAT below is only claimable against it.
            </p>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Their invoice number</label>
                    <input type="text" name="supplier_invoice_no" class="form-control"
                           maxlength="100" placeholder="e.g. INV-4471">
                </div>
                <div class="form-group">
                    <label class="form-label">Their invoice date</label>
                    <input type="date" name="supplier_invoice_date" class="form-control"
                           max="<?= e(date('Y-m-d')) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">VAT they charged</label>
                <input type="number" name="tax_amount" class="form-control"
                       step="0.01" min="0" placeholder="leave blank if not known yet">
                <span class="form-hint">
                    The figure from their invoice, not what the order estimated.
                    Left blank it is not claimed, and the VAT report lists the
                    work as a claim you may be missing.
                </span>
            </div>
        </fieldset>
        <?php endif; ?>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Accept the work</button>
        </div>
    </form>
</template>

<?php /* A page of products, and where to ask for the rest. The
         'purchase_products' list marks a service in the label and
         seeds the cost price, which is what a purchase order buys
         at — see includes/lookup.php. */ ?>
<?php lookup_seed_block('purchase_products', 'db-products'); ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
