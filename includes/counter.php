<?php

/**
 * ============================================================
 *  The counter
 * ------------------------------------------------------------
 *  Somebody is standing there. They want three things and the
 *  queue behind them is four people long. Everything here is
 *  shaped by that: one screen, one submit, no document to
 *  convert into another document.
 *
 *  The ERP this grew from could only make an invoice out of
 *  something else — a sales order, a proforma, a return, a
 *  contract. That is right when a sale takes three weeks and
 *  wrong when it takes ninety seconds.
 *
 *  What a completed sale does, all inside one transaction:
 *
 *    1. refuses a prescription-only medicine with no prescriber
 *    2. takes the stock out of named batches, oldest expiry first
 *    3. writes the invoice and its lines
 *    4. moves the headline stock figure and the stock ledger
 *    5. records the payment against a till
 *
 *  If any of it fails, none of it happened. A pharmacy that took
 *  the money and not the stock — or the stock and not the money —
 *  is worse off than one whose till threw an error.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/dispensing.php';
require_once __DIR__ . '/pharmacy.php';
//  next_document_number() lives here, and a sale cannot be
//  written without one.
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/patients.php';

/** How the counter takes money. Keys must satisfy the CHECK on invoice_payments.method. */
const COUNTER_METHODS = [
    'cash'         => 'Cash',
    'mobile_money' => 'M-Pesa',
    'card'         => 'Card',
    'bank_transfer' => 'Bank transfer',
];

/**
 * The shop's anonymous customer.
 *
 * Migration 063 creates exactly one and a partial unique index
 * keeps it that way. Returns null only if somebody has deleted
 * it, in which case the caller says so rather than silently
 * invoicing the first customer in the table.
 */
function counter_walk_in_customer(): ?int
{
    if (!column_exists('customers', 'is_walk_in')) {
        return null;
    }
    $id = db_value('SELECT customer_id FROM customers WHERE is_walk_in LIMIT 1');
    return $id !== null ? (int) $id : null;
}

/**
 * Find something to sell.
 *
 * Searches brand name, generic name and SKU together, because the
 * person at the counter says "Panadol", the box on the shelf says
 * "Hedex", and the label under it says paracetamol. Any of the
 * three has to find it.
 *
 * @return list<array<string,mixed>>
 */
function counter_search(string $term, int $limit = 12): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)) . '%';

    $rows = db_all(
        "SELECT p.product_id, p.name, p.sku, p.generic_name, p.strength,
                p.dosage_form, p.selling_price, p.stock_quantity,
                p.requires_rx, p.controlled_schedule, p.storage,
                u.abbreviation AS uom_abbr
           FROM products p
           LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
          WHERE p.is_active
            AND " . goods_only_sql('p') . "
            AND (LOWER(p.name) LIKE :t
              OR LOWER(p.generic_name) LIKE :t
              OR LOWER(p.sku) LIKE :t)
          ORDER BY
                /*  An exact-ish match first: somebody who typed the
                 *  whole brand name does not want it third. */
                CASE WHEN LOWER(p.name) LIKE :starts THEN 0
                     WHEN LOWER(p.generic_name) LIKE :starts THEN 1
                     ELSE 2 END,
                p.name
          LIMIT " . max(1, $limit),
        [':t' => $like, ':starts' => mb_strtolower($term) . '%']
    );

    /*  What can actually be handed over, which is not
     *  stock_quantity when the drug is batch tracked and some of
     *  those batches have expired. Showing the headline figure
     *  here would offer stock the sale then refuses. */
    foreach ($rows as &$r) {
        $pid = (int) $r['product_id'];
        if (batch_tracked($pid)) {
            $r['available'] = batch_available($pid);
            $r['expired']   = batch_expired_quantity($pid);
            $r['tracked']   = true;
        } else {
            $r['available'] = (float) $r['stock_quantity'];
            $r['expired']   = 0.0;
            $r['tracked']   = false;
        }
    }
    unset($r);

    return $rows;
}

/**
 * Complete a sale.
 *
 * @param list<array{product_id:int, quantity:float, unit_price:float}> $lines
 * @param array{method:string, cash_account_id:int, customer_id:?int,
 *              prescriber:?string, prescription_ref:?string} $sale
 * @return array{ok: bool, message: string, invoice_id: ?int, invoice_number: ?string}
 */
function counter_complete(array $lines, array $sale): array
{
    $fail = static fn(string $m): array =>
        ['ok' => false, 'message' => $m, 'invoice_id' => null, 'invoice_number' => null];

    if (!$lines) {
        return $fail('There is nothing on this sale.');
    }

    $method = (string) ($sale['method'] ?? '');
    if (!array_key_exists($method, COUNTER_METHODS)) {
        return $fail('Choose how the customer is paying.');
    }

    $tillId = (int) ($sale['cash_account_id'] ?? 0);
    if ($tillId <= 0 || !db_value('SELECT 1 FROM cash_accounts WHERE cash_account_id = :c AND is_active',
                                  [':c' => $tillId])) {
        return $fail('Choose which till or account the money went into.');
    }

    $customerId = (int) ($sale['customer_id'] ?? 0) ?: counter_walk_in_customer();
    if (!$customerId) {
        return $fail('This shop has no walk-in customer record. '
                   . 'Ask an administrator to restore it, or pick a customer.');
    }

    /*  Gather and check every line BEFORE anything is written. A
     *  refusal after three of five lines have moved stock is the
     *  failure mode this whole function exists to avoid. */
    $clean  = [];
    $wants  = [];
    $needRx = [];
    foreach ($lines as $i => $line) {
        $pid = (int) ($line['product_id'] ?? 0);
        $qty = (float) ($line['quantity'] ?? 0);

        if ($pid <= 0 || $qty <= 0) {
            return $fail('Line ' . ($i + 1) . ' has no medicine or no quantity.');
        }

        //  Aliased p because goods_only_sql() writes "p.product_type"
        //  by default. Unaliased, the fragment refers to a table this
        //  query never named.
        $p = db_one(
            "SELECT p.product_id, p.name, p.selling_price, p.requires_rx, p.controlled_schedule
               FROM products p
              WHERE p.product_id = :p AND p.is_active AND " . goods_only_sql('p'),
            [':p' => $pid]
        );
        if (!$p) {
            return $fail('Line ' . ($i + 1) . ' is not something this shop sells.');
        }

        if (!empty($p['requires_rx'])) {
            $needRx[] = $p['name'];
        }

        /*  The price is taken from the line so a pharmacist can
         *  discount, but never below nothing and never silently
         *  absent — a blank price box must not become a free box
         *  of antibiotics. */
        $price = array_key_exists('unit_price', $line) && $line['unit_price'] !== ''
            ? (float) $line['unit_price']
            : (float) $p['selling_price'];
        if ($price < 0) {
            return $fail('Line ' . ($i + 1) . ' has a price below zero.');
        }

        /*  One line per drug. invoice_items has a unique index on
         *  (invoice_id, product_id), so a second line for the same
         *  medicine cannot be written at all.
         *
         *  Refused rather than merged. Merging two lines at
         *  different prices means inventing a third price and
         *  charging something nobody chose, and a till that quietly
         *  rewrites what the pharmacist typed is worse than one
         *  that asks them to fix it. The screen already increments
         *  the existing row when the same drug is added twice, so
         *  this is the guard for anything that is not the screen. */
        if (isset($wants[$pid])) {
            return $fail($p['name'] . ' is on this sale twice. '
                       . 'Put the whole quantity on one line.');
        }

        $clean[] = ['product_id' => $pid, 'quantity' => $qty, 'unit_price' => $price];
        $wants[$pid] = $qty;
    }

    /*  Checked before the sale rather than trusted: a posted id
     *  that is not a patient must not become a dangling reference
     *  on a clinical record. */
    $patientId = (int) ($sale['patient_id'] ?? 0) ?: null;
    if ($patientId !== null && !patient_get($patientId)) {
        return $fail('That patient record could not be found.');
    }

    $prescriber = trim((string) ($sale['prescriber'] ?? ''));
    $rxRef      = trim((string) ($sale['prescription_ref'] ?? ''));
    if ($needRx && $prescriber === '') {
        return $fail(implode(' and ', array_unique($needRx))
                   . ' needs a prescription. Record who prescribed it before selling it.');
    }

    $me  = function_exists('current_user') ? current_user() : null;
    $uid = $me['id'] ?? null;
    $pdo = db();

    $pdo->beginTransaction();
    try {
        // ── The invoice, first, so the stock has something to
        //    point at ──
        //
        //  Nothing is burned by doing this before the stock check:
        //  next_document_number() increments a row in
        //  document_sequences, and a rollback takes that increment
        //  with it. So a sale refused for short stock leaves no gap
        //  in the numbering.
        $subtotal = 0.0;
        foreach ($clean as $l) {
            $subtotal += $l['quantity'] * $l['unit_price'];
        }

        $number = next_document_number('invoices', 'invoice_number', 'INV');
        /*  The patient is optional and stays optional. Somebody
         *  buying plasters is not an encounter, and making them
         *  one to satisfy a column would fill the register with
         *  people who were never treated. */
        $patientCol = column_exists('invoices', 'patient_id') ? ', patient_id' : '';
        $patientVal = $patientCol !== '' ? ', :patient' : '';

        $stmt = $pdo->prepare(
            "INSERT INTO invoices
                (invoice_number, customer_id, issued_by, issue_date, due_date,
                 subtotal, discount_amount, tax_amount, tax_rate, total_amount,
                 status, prescriber, prescription_ref$patientCol)
             VALUES
                (:num, :cust, :user, CURRENT_DATE, CURRENT_DATE,
                 :sub, 0, 0, 0, :total,
                 'paid', :presc, :ref$patientVal)
             RETURNING invoice_id"
        );
        $stmt->execute([
            ':num'   => $number,
            ':cust'  => $customerId,
            ':user'  => $uid,
            ':sub'   => $subtotal,
            ':total' => $subtotal,
            ':presc' => $prescriber !== '' ? $prescriber : null,
            ':ref'   => $rxRef !== '' ? $rxRef : null,
        ] + ($patientCol !== '' ? [':patient' => $patientId] : []));
        $invoiceId = (int) $stmt->fetchColumn();

        // ── Stock, out of named batches, oldest expiry first ──
        $alloc = batch_allocate_many($wants, 'invoices', $invoiceId, $uid);
        if (!$alloc['ok']) {
            //  Refused, not thrown: this is the shop being short of
            //  stock, which is ordinary, and the pharmacist needs
            //  the sentence rather than an error page.
            $pdo->rollBack();
            return $fail($alloc['message']);
        }

        $lineStmt = $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, product_id, quantity, unit_price, discount)
             VALUES (:inv, :p, :q, :u, 0)'
        );
        foreach ($clean as $l) {
            $lineStmt->execute([
                ':inv' => $invoiceId, ':p' => $l['product_id'],
                ':q'   => $l['quantity'], ':u' => $l['unit_price'],
            ]);
        }

        // ── The headline stock figure and the ledger ──
        $warehouseId = db_value('SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1');
        foreach ($wants as $pid => $qty) {
            $after = db_value(
                'UPDATE products
                    SET stock_quantity = stock_quantity - :q, updated_at = NOW()
                  WHERE product_id = :p
              RETURNING stock_quantity',
                [':q' => $qty, ':p' => $pid]
            );

            if (table_exists('inventory_transactions')) {
                db_run(
                    "INSERT INTO inventory_transactions
                        (product_id, warehouse_id, quantity, movement_type,
                         reference_table, reference_id, reference_number, balance_after, created_by)
                     VALUES (:p, :w, :q, 'delivery', 'invoices', :r, :n, :bal, :u)",
                    [
                        ':p' => $pid, ':w' => $warehouseId, ':q' => -$qty,
                        ':r' => $invoiceId, ':n' => $number,
                        ':bal' => $after, ':u' => $uid,
                    ]
                );
            }
        }

        // ── The money ──
        db_run(
            "INSERT INTO invoice_payments
                (invoice_id, payment_date, amount, method, cash_account_id, recorded_by)
             VALUES (:inv, CURRENT_DATE, :amt, :m, :c, :u)",
            [':inv' => $invoiceId, ':amt' => $subtotal, ':m' => $method,
             ':c' => $tillId, ':u' => $uid]
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[COUNTER] sale failed: ' . $e->getMessage());
        return $fail('The sale could not be completed, and nothing was changed. '
                   . 'Please try again.');
    }

    if (function_exists('audit_log')) {
        audit_log('counter_sale', 'invoice', $invoiceId);
    }

    return ['ok' => true, 'message' => 'Sale completed.',
            'invoice_id' => $invoiceId, 'invoice_number' => $number];
}
