<?php

/**
 * ============================================================
 *  Goods Received Notes — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page. Preserves the
 *  legacy actions exactly:
 *    • safe edit (supplier invoice no + notes)
 *    • cancel & reverse — restores product stock, rolls back
 *      PO fulfilled quantities and removes batches, refusing
 *      when stock from the GRN has already been used
 *  Adds pagination, search, status filter and a create button.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/documents.php';
require_once __DIR__ . '/../../../includes/purchasing.php';
require_once __DIR__ . '/../../../includes/export.php';

// Purchasing is a Manager's module: the menu has always hidden it
// from everyone else, but hiding a link is not access control —
// the page was reachable by anyone who knew the URL.
require_role(ROLE_MANAGER);

/* ── AJAX: receive goods against a purchase order ─────────── */
// Receiving is what brings stock in, so it runs in one transaction:
// stock, batch records, PO fulfilment and the inventory ledger all
// move together or not at all.
if (is_post() && ($_POST['_action'] ?? '') === 'receive') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $poId    = input_int($_POST, 'po_id');
    $recDate = input($_POST, 'receipt_date') ?: date('Y-m-d');

    if ($poId === null) {
        json_response(['success' => false, 'message' => 'Choose the purchase order being received.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recDate)) {
        json_response(['success' => false, 'message' => 'Please provide a valid receipt date.']);
    }

    // What the supplier actually charged. Defaults to nothing, because
    // a receipt with no tax invoice behind it has nothing to claim.
    $taxRate = (float) ($_POST['tax_rate'] ?? 0);
    if ($taxRate < 0 || $taxRate > 1) {
        json_response(['success' => false, 'message' => 'That VAT rate is not one we can charge.']);
    }
    if ($taxRate > 0 && input($_POST, 'supplier_invoice_no') === '') {
        json_response(['success' => false, 'message' =>
            'Input VAT can only be claimed against the supplier\'s tax invoice. '
            . 'Enter their invoice number, or set the VAT to "No VAT".']);
    }

    $po = db_one(
        "SELECT po_id, po_number, supplier_id, currency_id, status
           FROM purchase_orders WHERE po_id = :id",
        [':id' => $poId]
    );
    if (!$po) {
        json_response(['success' => false, 'message' => 'That purchase order no longer exists.']);
    }
    // An order the supplier turned down, or one already closed off,
    // has no goods coming against it.
    if (in_array($po['status'], ['cancelled', 'received', 'rejected', 'closed'], true)) {
        json_response(['success' => false, 'message' =>
            'That purchase order is ' . strtolower(po_status_label($po['status'])) . '.']);
    }

    // Outstanding quantity per PO line, keyed by po_item_id.
    $outstanding = [];
    foreach (db_all(
        "SELECT poi.po_item_id, poi.product_id, poi.unit_price, p.name,
                (poi.quantity_ordered - poi.quantity_fulfilled) AS remaining
           FROM purchase_order_items poi
           JOIN products p ON p.product_id = poi.product_id
          -- Goods only. A service on the order never arrives, so it is
          -- accepted on the purchase order rather than received here
          -- (migration 042). Offering it would invite a receipt that
          -- moves stock which does not exist.
          WHERE poi.po_id = :id AND " . goods_only_sql('p'),
        [':id' => $poId]
    ) as $row) {
        $outstanding[(int) $row['po_item_id']] = $row;
    }

    $lines = [];
    foreach ((array) ($_POST['po_item_id'] ?? []) as $i => $rawItemId) {
        $itemId   = (int) $rawItemId;
        $received = (float) ($_POST['quantity_received'][$i] ?? 0);
        $rejected = (float) ($_POST['quantity_rejected'][$i] ?? 0);
        $cost     = (float) ($_POST['unit_cost'][$i] ?? 0);

        if ($received <= 0 && $rejected <= 0) {
            continue;                       // line not received this time
        }
        if (!isset($outstanding[$itemId])) {
            json_response(['success' => false, 'message' => 'A line was submitted that is not on that purchase order.']);
        }
        if ($received < 0 || $rejected < 0 || $cost < 0) {
            json_response(['success' => false, 'message' => 'Quantities and costs must be 0 or more.']);
        }
        $line = $outstanding[$itemId];
        // More may arrive than was ordered. That is a real thing
        // suppliers do, and refusing to record it does not send the
        // goods back — it just leaves the stock on the shelf and off
        // the books. It is recorded as an over-delivery instead, and
        // the order is amended below to say what actually arrived.
        $over = max(0.0, $received + $rejected - (float) $line['remaining']);
        $lines[] = [
            'po_item_id' => $itemId,
            'product_id' => (int) $line['product_id'],
            'received'   => $received,
            'rejected'   => $rejected,
            'over'       => $over,
            'cost'       => $cost > 0 ? $cost : (float) $line['unit_price'],
            'batch'      => trim((string) ($_POST['batch_number'][$i] ?? '')),
            'expiry'     => trim((string) ($_POST['expiry_date'][$i] ?? '')),
        ];
    }

    /* ── Goods that were never on the order at all ───────────────
     *  The supplier put something extra in the box. Accepting it
     *  means the order changed, so the line is added to the order
     *  at the price actually charged — after which the order, the
     *  receipt and what is owed all agree.
     * ─────────────────────────────────────────────────────────── */
    $extras = [];
    foreach ((array) ($_POST['extra_product_id'] ?? []) as $i => $rawPid) {
        $pid      = (int) $rawPid;
        $received = (float) ($_POST['extra_received'][$i] ?? 0);
        $cost     = (float) ($_POST['extra_cost'][$i] ?? 0);
        if ($pid <= 0 || $received <= 0) {
            continue;
        }
        $product = db_one("SELECT p.product_id, p.name FROM products p
                            WHERE p.product_id = :id AND p.is_active = TRUE AND " . goods_only_sql(),
                          [':id' => $pid]);
        if (!$product) {
            json_response(['success' => false, 'message' => 'One of the extra items is not a product we stock.']);
        }
        if ($cost <= 0) {
            json_response(['success' => false, 'message' =>
                $product['name'] . ' came in unordered, so the system has no price for it. '
                . 'Enter what the supplier charged.']);
        }
        $extras[] = ['product_id' => $pid, 'name' => $product['name'],
                     'received' => $received, 'cost' => $cost,
                     'batch' => trim((string) ($_POST['extra_batch'][$i] ?? '')),
                     'expiry' => trim((string) ($_POST['extra_expiry'][$i] ?? ''))];
    }

    // An over-delivery changes what the business owes, so it is never
    // recorded silently.
    $overDelivered = $extras !== [] || array_sum(array_column($lines, 'over')) > 1e-9;
    if ($overDelivered && input($_POST, 'notes') === '') {
        json_response(['success' => false, 'message' =>
            'More arrived than was ordered. Say what happened in the notes — '
            . 'the order is amended to match, and that has to be explainable.']);
    }

    if (!$lines) {
        json_response(['success' => false, 'message' => 'Enter a received quantity for at least one line.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        $grnNumber = next_document_number('grns', 'grn_number', 'GRN');
        $total = 0.0;
        foreach ($lines as $l) {
            $total += $l['received'] * $l['cost'];
        }
        foreach ($extras as $x) {
            $total += $x['received'] * $x['cost'];
        }

        // total_amount stays net — it is what the goods cost, and stock
        // is valued from it. The VAT sits alongside it.
        $taxAmount = round($total * $taxRate, 2);

        $stmt = $pdo->prepare(
            "INSERT INTO grns (grn_number, supplier_id, po_id, received_by, receipt_date,
                               supplier_invoice_no, currency_id, total_amount,
                               tax_rate, tax_amount, notes, status)
             VALUES (:num, :sup, :po, :by, :date, :inv, :cur, :total,
                     :rate, :tax, :notes, 'confirmed')
             RETURNING grn_id"
        );
        $stmt->execute([
            ':num' => $grnNumber, ':sup' => $po['supplier_id'], ':po' => $poId,
            ':by' => current_user()['id'], ':date' => $recDate,
            ':inv' => input($_POST, 'supplier_invoice_no') ?: null,
            ':cur' => $po['currency_id'], ':total' => $total,
            ':rate' => $taxRate, ':tax' => $taxAmount,
            ':notes' => input($_POST, 'notes') ?: null,
        ]);
        $grnId = (int) $stmt->fetchColumn();

        $itemStmt = $pdo->prepare(
            "INSERT INTO grn_items (grn_id, product_id, po_item_id, batch_number, expiry_date,
                                    quantity_ordered, quantity_received, quantity_rejected, unit_cost)
             VALUES (:grn, :pid, :poitem, :batch, :exp, :ordered, :rec, :rej, :cost)
             RETURNING grn_item_id"
        );
        $stockStmt = $pdo->prepare(
            "UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
              WHERE product_id = :pid RETURNING stock_quantity"
        );
        $poStmt = $pdo->prepare(
            "UPDATE purchase_order_items
                SET quantity_fulfilled = quantity_fulfilled + :qty
              WHERE po_item_id = :id"
        );

        $wh = db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1");

        // What each balance must become, worked out before anything
        // moves. The check at the end needs something independent to
        // compare against: asking the ledger whether the ledger is
        // right proves nothing, and a balance that quietly refuses to
        // change would write a movement agreeing with itself.
        $expect = [];
        foreach ([...$lines, ...$extras] as $row) {
            $pid = (int) $row['product_id'];
            $expect[$pid] ??= (float) db_value(
                "SELECT stock_quantity FROM products WHERE product_id = :p", [':p' => $pid]);
            $expect[$pid] += (float) $row['received'];
        }

        foreach ($lines as $l) {
            $itemStmt->execute([
                ':grn' => $grnId, ':pid' => $l['product_id'], ':poitem' => $l['po_item_id'],
                ':batch' => $l['batch'] !== '' ? $l['batch'] : null,
                ':exp' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $l['expiry']) ? $l['expiry'] : null,
                ':ordered' => $outstanding[$l['po_item_id']]['remaining'],
                ':rec' => $l['received'], ':rej' => $l['rejected'], ':cost' => $l['cost'],
            ]);

            if ($l['received'] > 0) {
                $stockStmt->execute([':qty' => $l['received'], ':pid' => $l['product_id']]);
                $balance = (float) $stockStmt->fetchColumn();

                // The balance and the ledger are one fact written twice.
                // This write is deliberately NOT fenced: a balance that
                // moved with no movement explaining it is a discrepancy
                // nobody can diagnose six months later, and refusing the
                // receipt is visible where a silent divergence is not.
                db_run(
                    "INSERT INTO inventory_transactions
                        (product_id, warehouse_id, quantity, movement_type,
                         reference_table, reference_id, reference_number, balance_after, created_by)
                     VALUES (:pid, :wh, :qty, 'receipt', 'grns', :ref, :num, :bal, :by)",
                    [
                        ':pid' => $l['product_id'], ':wh' => $wh, ':qty' => $l['received'],
                        ':ref' => $grnId, ':num' => $grnNumber, ':bal' => $balance,
                        ':by' => current_user()['id'],
                    ]
                );
            }
            // An over-delivery raises the ordered quantity to what
            // actually came, so quantity_fulfilled never exceeds
            // quantity_ordered and the order can still close.
            if ($l['over'] > 1e-9) {
                $pdo->prepare(
                    "UPDATE purchase_order_items
                        SET quantity_ordered = quantity_ordered + :over
                      WHERE po_item_id = :id"
                )->execute([':over' => $l['over'], ':id' => $l['po_item_id']]);
            }
            $poStmt->execute([':qty' => $l['received'] + $l['rejected'], ':id' => $l['po_item_id']]);
        }

        /* ── The extra items join the order ─────────────────────── */
        $newPoItem = $pdo->prepare(
            "INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered,
                                               quantity_fulfilled, unit_price)
             VALUES (:po, :pid, :qty, :qty, :cost)
             RETURNING po_item_id"
        );
        foreach ($extras as $x) {
            $newPoItem->execute([':po' => $poId, ':pid' => $x['product_id'],
                                 ':qty' => $x['received'], ':cost' => $x['cost']]);
            $poItemId = (int) $newPoItem->fetchColumn();

            $itemStmt->execute([
                ':grn' => $grnId, ':pid' => $x['product_id'], ':poitem' => $poItemId,
                ':batch' => $x['batch'] ?: null, ':exp' => $x['expiry'] ?: null,
                ':ordered' => 0,   // nothing was ordered: that is the point
                ':rec' => $x['received'], ':rej' => 0, ':cost' => $x['cost'],
            ]);
            $stockStmt->execute([':qty' => $x['received'], ':pid' => $x['product_id']]);
            $balance = (float) $stockStmt->fetchColumn();

            // Same shape as the ordered lines above, and unfenced for the
            // same reason: an unordered arrival is as traceable as any
            // other, or it is not recorded at all.
            db_run(
                "INSERT INTO inventory_transactions
                    (product_id, warehouse_id, quantity, movement_type,
                     reference_table, reference_id, reference_number, balance_after, created_by)
                 VALUES (:pid, :wh, :qty, 'receipt', 'grns', :ref, :num, :bal, :by)",
                [
                    ':pid' => $x['product_id'], ':wh' => $wh, ':qty' => $x['received'],
                    ':ref' => $grnId, ':num' => $grnNumber, ':bal' => $balance,
                    ':by' => current_user()['id'],
                ]
            );
        }

        // The order's money has to follow its lines, or the payables
        // and prepayment figures compare a receipt against a total
        // that no longer describes the order.
        if ($overDelivered) {
            $pdo->prepare(
                "UPDATE purchase_orders po
                    SET subtotal     = v.sub,
                        tax_amount   = ROUND(v.sub * po.tax_rate, 2),
                        total_amount = v.sub + ROUND(v.sub * po.tax_rate, 2),
                        updated_at   = NOW()
                   FROM (SELECT COALESCE(SUM(quantity_ordered * unit_price), 0) AS sub
                           FROM purchase_order_items WHERE po_id = :id) v
                  WHERE po.po_id = :id"
            )->execute([':id' => $poId]);
        }

        // Reflect fulfilment back onto the purchase order.
        $stillOwed = (int) db_value(
            "SELECT COUNT(*) FROM purchase_order_items
              WHERE po_id = :id AND quantity_fulfilled < quantity_ordered",
            [':id' => $poId]
        );
        $pdo->prepare("UPDATE purchase_orders SET status = :s, updated_at = NOW() WHERE po_id = :id")
            ->execute([':s' => $stillOwed > 0 ? 'partially_received' : 'received', ':id' => $poId]);

        // ── Before committing, prove the stock actually moved ─────
        //  The whole point of a receipt is that goods went on the
        //  shelf. Saying so and not doing it is the one failure that
        //  cannot be spotted by looking at the receipt afterwards, so
        //  it is checked here rather than trusted, two ways, because
        //  they fail differently:
        //
        //    landed   every balance is exactly where it should be,
        //             against the figure read before anything moved
        //    moved    the stock ledger has a movement explaining it
        //
        //  A receipt that passes both really did put the goods on the
        //  shelf, and can say where they came from. One that fails
        //  either is rolled back whole.
        foreach ($expect as $pid => $want) {
            $now = (float) db_value(
                "SELECT stock_quantity FROM products WHERE product_id = :p", [':p' => $pid]);
            if (abs($now - $want) > 0.0005) {
                throw new RuntimeException(sprintf(
                    '%s was not taken into stock — its balance should be %s and is %s. '
                    . 'Nothing has been saved. Please try again, and tell your '
                    . 'administrator if it happens twice.',
                    db_value("SELECT name FROM products WHERE product_id = :p", [':p' => $pid]),
                    num($want, 2), num($now, 2)
                ));
            }
        }
        $unmoved = db_all(
            "SELECT p.name, r.received, COALESCE(l.moved, 0) AS moved
               FROM (SELECT product_id, SUM(quantity_received) AS received
                       FROM grn_items WHERE grn_id = :g1 GROUP BY product_id) r
               JOIN products p ON p.product_id = r.product_id
               LEFT JOIN (SELECT product_id, SUM(quantity) AS moved
                            FROM inventory_transactions
                           WHERE reference_table = 'grns' AND reference_id = :g2
                             AND movement_type = 'receipt'
                           GROUP BY product_id) l ON l.product_id = r.product_id
              WHERE r.received > 0
                AND ABS(r.received - COALESCE(l.moved, 0)) > 0.0005",
            [':g1' => $grnId, ':g2' => $grnId]
        );
        if ($unmoved) {
            throw new RuntimeException(sprintf(
                'The stock moved for %s but nothing recorded why (%s received, %s in the '
                . 'ledger). Nothing has been saved.',
                $unmoved[0]['name'],
                num((float) $unmoved[0]['received'], 2),
                num((float) $unmoved[0]['moved'], 2)
            ));
        }

        $pdo->commit();
        audit_log('grn.create', 'grns', $grnId, [
            'number' => $grnNumber, 'po' => $po['po_number'], 'lines' => count($lines),
        ]);
        json_response(['success' => true, 'message' => "$grnNumber received — stock updated."]);
    } catch (PDOException $ex) {
        // PDOException extends RuntimeException, so it is caught first.
        // A database rule that refused the receipt has already said why
        // in words — pass that through rather than replacing it with
        // "please try again", which tells nobody anything.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[GRN] receive failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' =>
            db_rule_message($ex, 'Could not record the receipt. Please try again.')], 500);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[GRN] receive refused: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getMessage()], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[GRN] receive failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the receipt. Please try again.'], 500);
    }
}

/* ── AJAX: edit GRN details (modal) ───────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'edit') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id  = input_int($_POST, 'grn_id');
    $grn = $id !== null
        ? db_one("SELECT grn_id, total_amount FROM grns WHERE grn_id = :id", [':id' => $id])
        : null;
    if (!$grn) {
        json_response(['success' => false, 'message' => 'That GRN no longer exists.']);
    }

    // This is the correction path for input VAT: a receipt entered
    // before the supplier's invoice turned up, or one left on an
    // order's assumed rate, is put right here.
    $taxRate = (float) ($_POST['tax_rate'] ?? 0);
    if ($taxRate < 0 || $taxRate > 1) {
        json_response(['success' => false, 'message' => 'That VAT rate is not one we can charge.']);
    }
    if ($taxRate > 0 && input($_POST, 'invoice_no') === '') {
        json_response(['success' => false, 'message' =>
            'Input VAT can only be claimed against the supplier\'s tax invoice. '
            . 'Enter their invoice number, or set the VAT to "No VAT".']);
    }

    try {
        db_run(
            "UPDATE grns
                SET supplier_invoice_no = :inv, notes = :notes,
                    tax_rate = :rate, tax_amount = ROUND(total_amount * :rate2, 2),
                    updated_at = NOW()
             WHERE grn_id = :id AND status != 'cancelled'",
            [
                ':inv'   => input($_POST, 'invoice_no') ?: null,
                ':notes' => input($_POST, 'notes') ?: null,
                ':rate'  => $taxRate,
                ':rate2' => $taxRate,
                ':id'    => $id,
            ]
        );
        audit_log('grn.update', 'grns', $id);
        json_response(['success' => true, 'message' => 'GRN details updated.']);
    } catch (PDOException $ex) {
        error_log('[GRN] edit failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not save changes.')], 500);
    }
}

/* ── POST: cancel & reverse (ported legacy transaction) ───── */
if (is_post() && ($_POST['_action'] ?? '') === 'cancel') {
    csrf_check();
    $id  = input_int($_POST, 'grn_id');
    $grn = $id !== null ? db_one("SELECT grn_number, status FROM grns WHERE grn_id = :id", [':id' => $id]) : null;
    $pdo = db();

    if (!$grn) {
        flash('error', 'That GRN no longer exists.');
    } elseif ($grn['status'] === 'cancelled') {
        flash('info', $grn['grn_number'] . ' is already cancelled.');
    } else {
        try {
            $pdo->beginTransaction();

            // Refuse when any batch from this GRN has been consumed.
            $used = $pdo->prepare(
                "SELECT COUNT(*) FROM product_batches bg
                 JOIN grn_items gi ON bg.grn_item_id = gi.grn_item_id
                 WHERE gi.grn_id = :id AND bg.quantity_remaining < bg.quantity_received"
            );
            $used->execute([':id' => $id]);
            if ($used->fetchColumn() > 0) {
                throw new Exception('Cannot cancel: stock from this GRN has already been used or sold.');
            }

            // Reverse stock and PO fulfilment.
            $itemsStmt = $pdo->prepare("SELECT product_id, quantity_received, po_item_id FROM grn_items WHERE grn_id = :id");
            $itemsStmt->execute([':id' => $id]);
            $stockStmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - :qty WHERE product_id = :pid RETURNING stock_quantity");
            $poStmt    = $pdo->prepare("UPDATE purchase_order_items SET quantity_fulfilled = quantity_fulfilled - :qty WHERE po_item_id = :po_item_id");
            $wh        = db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1");
            foreach ($itemsStmt->fetchAll() as $item) {
                $stockStmt->execute([':qty' => $item['quantity_received'], ':pid' => $item['product_id']]);
                $balance = (float) $stockStmt->fetchColumn();

                // Mirror the reversal into the stock ledger so the
                // movement in and the movement back out both show.
                try {
                    db_run(
                        "INSERT INTO inventory_transactions
                            (product_id, warehouse_id, quantity, movement_type,
                             reference_table, reference_id, reference_number, balance_after, created_by)
                         VALUES (:pid, :wh, :qty, 'adjustment', 'grns', :ref, :num, :bal, :by)",
                        [
                            ':pid' => $item['product_id'], ':wh' => $wh,
                            ':qty' => -(float) $item['quantity_received'],
                            ':ref' => $id, ':num' => $grn['grn_number'], ':bal' => $balance,
                            ':by' => current_user()['id'],
                        ]
                    );
                } catch (PDOException $e) {
                    error_log('[GRN] cancel ledger write skipped: ' . $e->getMessage());
                }

                if ($item['po_item_id']) {
                    $poStmt->execute([':qty' => $item['quantity_received'], ':po_item_id' => $item['po_item_id']]);
                }
            }

            $pdo->prepare("DELETE FROM product_batches WHERE grn_item_id IN (SELECT grn_item_id FROM grn_items WHERE grn_id = :id)")
                ->execute([':id' => $id]);
            $pdo->prepare("UPDATE grns SET status = 'cancelled', updated_at = NOW() WHERE grn_id = :id")
                ->execute([':id' => $id]);

            $pdo->commit();
            audit_log('grn.cancel', 'grns', $id, ['number' => $grn['grn_number']]);
            flash('success', $grn['grn_number'] . ' cancelled — stock additions reversed safely.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', $e->getMessage());
        }
    }
    redirect('modules/purchasing/manage_grns.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$GRN_STATUSES = ['draft', 'confirmed', 'partially_received', 'cancelled'];
$search   = input($_GET, 'q');
$status   = input($_GET, 'status');
$dateFrom = input($_GET, 'from');
$dateTo   = input($_GET, 'to');
$perPage  = per_page();

$where  = [];
$params = [];
// Everything written on a receipt that somebody might have in front
// of them when they come looking: our number, the supplier, their
// invoice, the order, and what was actually in the box.
if ($search !== '') {
    $where[] = "(LOWER(g.grn_number) LIKE :q OR LOWER(s.name) LIKE :q
                 OR LOWER(COALESCE(g.supplier_invoice_no,'')) LIKE :q
                 OR LOWER(COALESCE(po.po_number,'')) LIKE :q
                 OR LOWER(COALESCE(g.notes,'')) LIKE :q
                 OR EXISTS (SELECT 1 FROM grn_items gi
                              JOIN products p ON p.product_id = gi.product_id
                             WHERE gi.grn_id = g.grn_id
                               AND (LOWER(p.name) LIKE :q OR LOWER(COALESCE(p.sku,'')) LIKE :q)))";
    $params[':q'] = '%' . strtolower($search) . '%';
}
// Dated by when the goods arrived, which is the date on the note and
// the one anybody reconciling a month is working to.
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = "g.receipt_date >= :from";
    $params[':from'] = $dateFrom;
} else {
    $dateFrom = '';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = "g.receipt_date <= :to";
    $params[':to'] = $dateTo;
} else {
    $dateTo = '';
}
if (in_array($status, $GRN_STATUSES, true)) {
    $where[] = "g.status = :status";
    $params[':status'] = $status;
} else {
    $status = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$joinSql = "FROM grns g
            LEFT JOIN suppliers s ON s.supplier_id = g.supplier_id
            LEFT JOIN purchase_orders po ON po.po_id = g.po_id
            $whereSql";
if (wants_export()) {
    export_deliver(export_filename('goods-received'), [[
        'name'  => 'Goods received',
        'title' => 'Goods received notes',
        'meta'  => export_meta([
            'Status'   => $status !== '' ? ucfirst($status) : 'any',
            'Search'   => $search,
            'Received' => $dateFrom !== '' || $dateTo !== ''
                ? trim(($dateFrom !== '' ? 'from ' . fmt_date($dateFrom, 'd M Y') : '')
                     . ($dateTo   !== '' ? ' to ' . fmt_date($dateTo, 'd M Y') : ''))
                : 'any date',
        ]),
        'columns' => [
            ['label' => 'GRN',          'key' => 'grn_number'],
            ['label' => 'Received',     'key' => 'receipt_date', 'type' => 'date'],
            ['label' => 'Supplier',     'key' => 'supplier_name'],
            ['label' => 'Order',        'key' => 'po_number'],
            ['label' => 'Supplier invoice', 'key' => 'supplier_invoice_no'],
            ['label' => 'Status',       'key' => 'status', 'value' => fn($r) => ucwords(str_replace('_',' ', (string) $r['status']))],
            ['label' => 'Value',        'key' => 'total_amount', 'type' => 'money'],
            ['label' => 'VAT charged',  'key' => 'tax_amount',   'type' => 'money'],
            ['label' => 'Received by',  'key' => 'received_by_name'],
            ['label' => 'Notes',        'key' => 'notes'],
        ],
        'rows' => db_all(
            "SELECT g.*, s.name AS supplier_name, po.po_number,
                    TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS received_by_name
               FROM grns g
               LEFT JOIN suppliers s ON s.supplier_id = g.supplier_id
               LEFT JOIN purchase_orders po ON po.po_id = g.po_id
               LEFT JOIN users u ON u.user_id = g.received_by
             $whereSql
             ORDER BY g.receipt_date DESC, g.grn_id DESC",
            $params
        ),
        'total' => true,
    ]]);
}

$total = (int) db_value("SELECT COUNT(*) $joinSql", $params);
$pg    = paginate($total, $perPage);

$grns = db_all(
    "SELECT g.*, s.name AS supplier_name, po.po_number,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS received_by_name
     FROM grns g
     LEFT JOIN suppliers s ON s.supplier_id = g.supplier_id
     LEFT JOIN purchase_orders po ON po.po_id = g.po_id
     LEFT JOIN users u ON u.user_id = g.received_by
     $whereSql
     ORDER BY g.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$pageTitle    = 'Goods Received';
$pageSubtitle = num($total) . ' goods received note' . ($total === 1 ? '' : 's');
$pageStyles   = ['forms.css'];

// Purchase orders still owing goods, with their outstanding lines, so
// the receive modal can render them without a round-trip.
$openPos = db_all(
    "SELECT po.po_id, po.po_number, s.name AS supplier,
            po.tax_rate,
            -- No tax PIN, no tax invoice, so nothing to claim. It is the
            -- best evidence the system holds of whether a supplier is
            -- registered, and it prefills the VAT picker accordingly.
            (COALESCE(TRIM(s.tax_pin), '') <> '') AS supplier_registered
       FROM purchase_orders po
       JOIN suppliers s ON s.supplier_id = po.supplier_id
      WHERE po.status NOT IN ('cancelled','received','rejected','closed')
        AND EXISTS (SELECT 1 FROM purchase_order_items poi
                     WHERE poi.po_id = po.po_id
                       AND poi.quantity_fulfilled < poi.quantity_ordered)
      ORDER BY po.po_id DESC"
);
$poLines = [];
foreach (db_all(
    "SELECT poi.po_id, poi.po_item_id, p.name, p.sku, poi.unit_price,
            (poi.quantity_ordered - poi.quantity_fulfilled) AS remaining
       FROM purchase_order_items poi
       JOIN purchase_orders po ON po.po_id = poi.po_id
       JOIN products p ON p.product_id = poi.product_id
      WHERE po.status NOT IN ('cancelled','received','rejected','closed')
        AND poi.quantity_fulfilled < poi.quantity_ordered
      ORDER BY p.name"
) as $l) {
    $poLines[(int) $l['po_id']][] = [
        'item_id'   => (int) $l['po_item_id'],
        'name'      => $l['name'],
        'sku'       => $l['sku'],
        'remaining' => (float) $l['remaining'],
        'cost'      => (float) $l['unit_price'],
    ];
}
// For the "not on this order" picker. A supplier can only put in the
// box something we actually stock; anything else is a conversation,
// not a data-entry problem.

$breadcrumbs  = [['label' => 'Purchasing'], ['label' => 'Goods Received']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter([
    'q' => $search, 'status' => $status, 'from' => $dateFrom, 'to' => $dateTo,
], fn($v) => $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/purchasing/manage_grns.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search GRN, supplier, invoice, order or product…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach ($GRN_STATUSES as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $st)) ?></option>
            <?php endforeach; ?>
        </select>
        <?php // By receipt date — the date on the note, which is what
              // anybody reconciling a month or chasing a delivery has. ?>
        <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control toolbar-select"
               data-autosubmit title="Received on or after">
        <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control toolbar-select"
               data-autosubmit title="Received on or before">
        <?= toolbar_apply('modules/purchasing/manage_grns.php') ?>
    </form>
    <div class="toolbar-actions">
        <?= export_button('modules/purchasing/manage_grns.php', $baseQuery) ?>
        <button type="button" class="btn btn-primary"
                data-modal-open="tplGrnReceive" data-modal-title="Receive Goods" data-modal-xl data-modal-auto>
            <?= icon('plus') ?> Receive New Goods
        </button>
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>GRN Number</th>
                    <th>Supplier</th>
                    <th>PO</th>
                    <th>Invoice No</th>
                    <th>Received</th>
                    <th>Status</th>
                    <th class="ta-right">Total</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$grns): ?>
                    <tr><td colspan="8" class="table-empty">No goods received notes match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($grns as $g):
                    $prefill = json_encode([
                        'grn_id'     => $g['grn_id'],
                        'invoice_no' => $g['supplier_invoice_no'],
                        // NUMERIC(6,4) comes back as "0.1600", which is not
                        // "0.16" to a <select> and would show nothing at all.
                        'tax_rate'   => vat_rate_value($g['tax_rate'] ?? 0),
                        'notes'      => $g['notes'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($g['grn_number']) ?></span>
                            <span class="cell-sub">by <?= e($g['received_by_name'] ?: '—') ?></span>
                        </td>
                        <td><?= e($g['supplier_name'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e($g['po_number'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e($g['supplier_invoice_no'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($g['receipt_date'])) ?></td>
                        <td><span class="badge badge--<?= e($g['status']) ?>"><?= e(str_replace('_', ' ', $g['status'])) ?></span></td>
                        <td class="ta-right"><strong><?= e(money($g['total_amount'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($g['status'] !== 'cancelled'): ?>
                                    <button type="button" class="icon-btn" title="Edit details"
                                            data-modal-open="tplGrnForm" data-modal-title="Edit <?= e($g['grn_number']) ?>"
                                            data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <?php endif; ?>
                                <a href="<?= e(url('modules/purchasing/print_grn.php?grn_id=' . (int) $g['grn_id'])) ?>"
                                   target="_blank" class="icon-btn" title="Print PDF"><?= icon('file') ?></a>
                                <?php // Downloads under the document's own number, rather than
                                      // leaving the browser's viewer to name it after the URL. ?>
                                <a href="<?= e(url('modules/purchasing/print_grn.php?grn_id=' . (int) $g['grn_id'] . '&dl=1')) ?>"
                                   class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                                <?php if ($g['status'] !== 'cancelled'): ?>
                                    <form method="POST" action="<?= e(url('modules/purchasing/manage_grns.php')) ?>" class="inline-form"
                                          data-confirm="Cancel <?= e($g['grn_number']) ?>? Stock added by this GRN will be reversed (refused if already used).">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="cancel">
                                        <input type="hidden" name="grn_id" value="<?= (int) $g['grn_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Cancel & reverse"><?= icon('x') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'goods received note'); ?>
</div>

<!-- Edit GRN modal -->
<template id="tplGrnForm">
    <form method="POST" action="<?= e(url('modules/purchasing/manage_grns.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="edit">
        <input type="hidden" name="grn_id" value="">

        <div class="form-group">
            <label class="form-label">Supplier invoice number</label>
            <input type="text" name="invoice_no" class="form-control" maxlength="100">
        </div>
        <div class="form-group">
            <label class="form-label">VAT the supplier charged</label>
            <select name="tax_rate" class="form-control">
                <?= vat_rate_options('0') ?>
            </select>
            <span class="form-hint">
                What is on their tax invoice. This is what the VAT return claims
                as input tax, so correcting it here corrects the return.
            </span>
        </div>
        <div class="form-group">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="3"></textarea>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</template>

<?php /* A page of stockable products, and where to ask for the
         rest. Rendering all of them put half a megabyte on this
         page and a <select> holding every product into every row an
         amendment added. */ ?>
<?php lookup_seed_block('stock_products', 'grn-products'); ?>
<script type="application/json" id="grn-po-lines"><?= json_encode($poLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<template id="tplGrnReceive">
    <form method="POST" action="<?= e(url('modules/purchasing/manage_grns.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="receive">

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Purchase order <span class="req">*</span></label>
                <select name="po_id" class="form-control" required data-grn-po data-searchable="Search purchase orders">
                    <option value="">— Select purchase order —</option>
                    <?php foreach ($openPos as $po): ?>
                        <option value="<?= (int) $po['po_id'] ?>"
                                data-tax="<?= e($po['supplier_registered'] ? vat_rate_value($po['tax_rate']) : '0') ?>">
                            <?= e($po['po_number']) ?> — <?= e($po['supplier']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Receipt date <span class="req">*</span></label>
                <input type="date" name="receipt_date" class="form-control" required value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Supplier invoice no.</label>
                <input type="text" name="supplier_invoice_no" class="form-control" maxlength="100">
            </div>
        </div>

        <?php // Input VAT is what the supplier charged, and this is the
              // moment their invoice is in somebody's hand. The order's
              // rate is a forecast, so it prefills but never decides. ?>
        <div class="form-group">
            <label class="form-label">VAT the supplier charged</label>
            <select name="tax_rate" class="form-control" data-grn-tax>
                <?= vat_rate_options('0') ?>
            </select>
            <span class="form-hint">
                Read it off their invoice. This — and only this — is what the
                VAT return claims as input tax. No tax invoice means
                <strong>No VAT</strong>: the order's rate is what we expected to
                be billed, not evidence that we were.
            </span>
        </div>

        <div class="table-wrap">
            <table class="data-table" data-grn-table hidden>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="ta-right">Outstanding</th>
                        <th class="ta-right">Received</th>
                        <th class="ta-right">Rejected</th>
                        <th class="ta-right">Unit cost</th>
                        <th>Batch</th>
                        <th>Expiry</th>
                    </tr>
                </thead>
                <tbody data-grn-rows></tbody>
            </table>
        </div>
        <p class="form-hint" data-grn-empty>Choose a purchase order to list the goods still outstanding.</p>

        <?php /* Goods that were never on the order. Receiving more than
                 was ordered does not send it back — it just leaves the
                 stock on the shelf and off the books — so it is recorded
                 here and the order is amended to match. */ ?>
        <div data-grn-extras hidden>
            <div class="box-title u-pad-t">Arrived but not ordered</div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="ta-right">Received</th>
                            <th class="ta-right">Unit cost charged</th>
                            <th>Batch</th>
                            <th>Expiry</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody data-extra-rows></tbody>
                </table>
            </div>
            <button type="button" class="btn btn-ghost btn--sm" data-add-extra>
                <?= icon('plus') ?> Item not on this order
            </button>
            <p class="form-hint">
                Accepting goods you did not order means the order changed. These
                lines are added to the purchase order at the price you enter, so
                the order, this receipt and what you owe all agree afterwards.
            </p>
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="If more arrived than was ordered, say what happened"></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Receive goods &amp; update stock</button>
        </div>
    </form>
</template>

<script nonce="<?= csp_nonce() ?>">
    // Render the chosen purchase order's outstanding lines. Quantities
    // pre-fill with what is still owed, so a part-delivery is just a
    // matter of reducing a number.
    (function () {
        let lines = {};
        try { lines = JSON.parse(document.getElementById('grn-po-lines').textContent) || {}; }
        catch (e) { lines = {}; }

        const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        document.addEventListener('change', (e) => {
            const sel = e.target.closest('[data-grn-po]');
            if (!sel) return;
            const form  = sel.closest('form');
            const body  = form.querySelector('[data-grn-rows]');
            const table = form.querySelector('[data-grn-table]');
            const note  = form.querySelector('[data-grn-empty]');
            const rows  = lines[sel.value] || [];

            body.innerHTML = rows.map((l) =>
                '<tr>' +
                '<td>' + esc(l.name) +
                    '<span class="cell-sub">' + esc(l.sku || '') + '</span>' +
                    '<input type="hidden" name="po_item_id[]" value="' + l.item_id + '"></td>' +
                '<td class="ta-right"><strong>' + l.remaining + '</strong></td>' +
                // No max: more can arrive than was ordered, and the
                // server records that as an over-delivery rather than
                // refusing it.
                '<td class="ta-right"><input type="number" class="form-control ta-right" name="quantity_received[]" ' +
                    'value="' + l.remaining + '" min="0" step="0.001" style="max-width:110px"></td>' +
                '<td class="ta-right"><input type="number" class="form-control ta-right" name="quantity_rejected[]" ' +
                    'value="0" min="0" step="0.001" style="max-width:110px"></td>' +
                '<td class="ta-right"><input type="number" class="form-control ta-right" name="unit_cost[]" ' +
                    'value="' + l.cost + '" min="0" step="0.01" style="max-width:120px"></td>' +
                '<td><input type="text" class="form-control" name="batch_number[]" maxlength="100" style="max-width:130px"></td>' +
                '<td><input type="date" class="form-control" name="expiry_date[]" style="max-width:150px"></td>' +
                '</tr>').join('');

            // Suggest the VAT: the order's rate where the supplier holds a
            // tax PIN, nothing where they do not — an unregistered supplier
            // cannot issue a tax invoice, so there is nothing to claim.
            // A suggestion only; the person holding the invoice decides,
            // and the server still refuses VAT with no invoice number.
            const tax = form.querySelector('[data-grn-tax]');
            const opt = sel.selectedOptions[0];
            if (tax && opt) { tax.value = opt.dataset.tax || '0'; }

            const has = rows.length > 0;
            table.hidden = !has;
            note.hidden = has;

            // An order in hand is the point at which something extra
            // can have arrived with it.
            const extras = form.querySelector('[data-grn-extras]');
            if (extras) { extras.hidden = !sel.value; }
            if (!has && sel.value) {
                note.hidden = false;
                // Nothing outstanding no longer means nothing to do: a
                // late extra can still arrive against a closed order.
                note.textContent = 'That purchase order has nothing outstanding. '
                    + 'Anything that arrived anyway can still be recorded below.';
            }
        });
    })();

    /* ── Items that were never on the order ─────────────────────
     *  A row per unordered item, with the price the supplier
     *  actually charged — there is no ordered price to fall back
     *  on, which is exactly what makes it an amendment.
     * ─────────────────────────────────────────────────────────── */
    (function () {
        // The seed only: this list holds a page of products and
        // the search box beside it asks the server for the rest.
        const src = document.getElementById('grn-products');
        let products = [];
        try { products = JSON.parse(src.textContent) || []; }
        catch (e) { products = []; }

        const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        const options = products.map((p) =>
            '<option value="' + p.id + '" data-cost="' + (p.cost || 0) + '">' +
            esc(p.name) + '</option>').join('');

        const lookupAttrs =
            ' data-search-select data-lookup="' + esc(src.dataset.lookup || '') +
            '" data-lookup-url="' + esc(src.dataset.lookupUrl || 'lookup.php') + '"';

        document.addEventListener('click', (e) => {
            const add = e.target.closest('[data-add-extra]');
            if (add) {
                const body = add.closest('[data-grn-extras]').querySelector('[data-extra-rows]');
                const tr = document.createElement('tr');
                tr.innerHTML =
                    // Select before input, so the label labels the
                    // select — see includes/lookup.php. CSS puts the
                    // search box back on top.
                    '<td><span class="select-search">' +
                        '<select class="form-control" name="extra_product_id[]" data-extra-product>' +
                        '<option value="">— Which item? —</option>' + options + '</select>' +
                        '<input type="search" class="form-control select-search-input" autocomplete="off" ' +
                        'placeholder="Type to search item…" aria-label="Search items"' + lookupAttrs + '>' +
                        '</span></td>' +
                    '<td class="ta-right"><input type="number" class="form-control ta-right" ' +
                        'name="extra_received[]" min="0" step="0.001" value="1" style="max-width:110px"></td>' +
                    '<td class="ta-right"><input type="number" class="form-control ta-right" ' +
                        'name="extra_cost[]" min="0" step="0.01" placeholder="what was charged" ' +
                        'style="max-width:150px"></td>' +
                    '<td><input type="text" class="form-control" name="extra_batch[]" maxlength="100" style="max-width:130px"></td>' +
                    '<td><input type="date" class="form-control" name="extra_expiry[]" style="max-width:150px"></td>' +
                    '<td><button type="button" class="icon-btn icon-btn--danger" data-drop-extra ' +
                        'title="Remove">&times;</button></td>';
                body.appendChild(tr);
                return;
            }
            const drop = e.target.closest('[data-drop-extra]');
            if (drop) { drop.closest('tr').remove(); }
        });

        // Seed the cost with what we last paid, as a starting point
        // rather than an answer — the supplier's price is the one that
        // goes on the order.
        document.addEventListener('change', (e) => {
            const sel = e.target.closest('[data-extra-product]');
            if (!sel) return;
            const cost = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.cost : '';
            const box  = sel.closest('tr').querySelector('[name="extra_cost[]"]');
            if (box && !box.value && cost) { box.value = cost; }
        });
    })();
</script>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
