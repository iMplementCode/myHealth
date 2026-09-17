<?php

/**
 * ============================================================
 *  Receipts that do not add up
 * ------------------------------------------------------------
 *  Two things a goods received note is supposed to do, and this
 *  page finds the ones that did neither.
 *
 *  ── It is supposed to put goods on the shelf ────────────────
 *  A receipt writes two things in one transaction: the
 *  product's balance, and a stock-ledger row explaining the
 *  change. The receiving screen now proves both landed before
 *  it commits — the balance against a figure read before
 *  anything moved, and the ledger against what the note says
 *  arrived. Receipts from before that rule can say goods came
 *  in while the stock never moved, which is the one failure a
 *  receipt cannot show by looking at it.
 *
 *  ── It is supposed to match its order ───────────────────────
 *  A goods received note is the delivery against
 *  an order. When the two disagree, the money goes strange:
 *  payables and prepayments are both worked out by comparing
 *  what was paid against the **value of the receipts**, so an
 *  order whose receipt says one thing and whose lines say
 *  another reads as paid-and-not-received while the stock is on
 *  the shelf.
 *
 *  Two ways that happens:
 *
 *    the supplier over-delivered   handled at the counter now
 *                                  (Goods Received → Receive),
 *                                  which amends the order
 *
 *    the record predates the rule  receipts imported or raised
 *                                  before the purchasing rebuild
 *                                  were never checked against
 *                                  their order at all
 *
 *  This finds both, and brings the order into line with what
 *  the receipt says actually arrived — the same amendment the
 *  receiving screen now makes, applied after the fact.
 *
 *  It never touches the receipt. What arrived is a fact; the
 *  order is the document that was wrong about it.
 *
 *  Administrators only: it moves money between two lines of the
 *  balance sheet.
 * ============================================================
 */

require_once __DIR__ . '/../../../includes/bootstrap.php';
require_once __DIR__ . '/../../../includes/cashbook.php';
require_once __DIR__ . '/../../../includes/workflow.php';
require_role(ROLE_ADMIN);
require_tables('purchase_orders', 'purchase_order_items', 'grns', 'grn_items');

/**
 * Orders whose receipts say something their own lines do not.
 *
 * Compared two ways, because they fail differently: the money
 * (receipt value against order value) is what shows up on the
 * daily position, and the quantities are what stop an order
 * closing.
 */
function grn_mismatches(): array
{
    return db_all(
        "SELECT po.po_id, po.po_number, po.status, po.total_amount AS po_total,
                po.tax_rate, s.name AS supplier_name,
                g.grn_total, g.grn_count, g.last_receipt,
                g.grn_total - po.total_amount AS money_gap,
                (SELECT COUNT(*) FROM purchase_order_items poi
                  WHERE poi.po_id = po.po_id
                    AND poi.quantity_fulfilled > poi.quantity_ordered + 0.0005) AS over_lines,
                (SELECT COUNT(*) FROM grn_items gi
                   JOIN grns g2 ON g2.grn_id = gi.grn_id
                  WHERE g2.po_id = po.po_id AND g2.status <> 'cancelled'
                    AND gi.po_item_id IS NULL) AS unmatched_lines,
                COALESCE(p.paid, 0) AS paid
           FROM purchase_orders po
           JOIN (SELECT po_id, SUM(total_amount) AS grn_total, COUNT(*) AS grn_count,
                        MAX(receipt_date) AS last_receipt
                   FROM grns WHERE status <> 'cancelled' GROUP BY po_id) g
                ON g.po_id = po.po_id
           LEFT JOIN (SELECT po_id, SUM(amount) AS paid FROM purchase_payments GROUP BY po_id) p
                ON p.po_id = po.po_id
           LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
          WHERE po.status <> 'cancelled'
            AND (ABS(g.grn_total - po.total_amount) > 0.005
                 OR EXISTS (SELECT 1 FROM purchase_order_items poi
                             WHERE poi.po_id = po.po_id
                               AND poi.quantity_fulfilled > poi.quantity_ordered + 0.0005)
                 OR EXISTS (SELECT 1 FROM grn_items gi JOIN grns g3 ON g3.grn_id = gi.grn_id
                             WHERE g3.po_id = po.po_id AND g3.status <> 'cancelled'
                               AND gi.po_item_id IS NULL))
          ORDER BY ABS(g.grn_total - po.total_amount) DESC, po.po_id DESC"
    );
}

/**
 * Receipts that say goods arrived, with no stock movement to show
 * for it.
 *
 * A receipt exists to put goods on the shelf. Every one writes two
 * things in the same transaction — the product's balance, and a row
 * in the stock ledger explaining the change — and the receiving
 * screen now refuses to commit unless both went in. This finds the
 * ones from before that rule, where a line was recorded and the
 * stock never moved.
 *
 * The ledger is the evidence, not the balance: a balance can be
 * right by coincidence, but a receipt with no movement against it
 * has nothing saying the goods were ever taken in.
 */
function grn_stock_gaps(): array
{
    return db_all(
        "SELECT g.grn_id, g.grn_number, g.receipt_date, po.po_number,
                p.product_id, p.name AS product_name, p.sku, p.stock_quantity,
                r.received, COALESCE(l.moved, 0) AS moved,
                r.received - COALESCE(l.moved, 0) AS gap,
                COALESCE(s.name, 'Supplier') AS supplier_name
           FROM (SELECT grn_id, product_id, SUM(quantity_received) AS received
                   FROM grn_items GROUP BY grn_id, product_id) r
           JOIN grns g ON g.grn_id = r.grn_id AND g.status <> 'cancelled'
           JOIN products p ON p.product_id = r.product_id
           LEFT JOIN purchase_orders po ON po.po_id = g.po_id
           LEFT JOIN suppliers s ON s.supplier_id = g.supplier_id
           LEFT JOIN (SELECT reference_id, product_id, SUM(quantity) AS moved
                        FROM inventory_transactions
                       WHERE reference_table = 'grns' AND movement_type = 'receipt'
                       GROUP BY reference_id, product_id) l
                  ON l.reference_id = r.grn_id AND l.product_id = r.product_id
          WHERE r.received > 0
            AND ABS(r.received - COALESCE(l.moved, 0)) > 0.0005
          ORDER BY g.receipt_date DESC, g.grn_id DESC"
    );
}

/* ── POST: put a receipt's goods into stock ──────────────────
 *  Two outcomes, because the data cannot tell them apart and the
 *  person can: either the goods never went on the shelf (add them
 *  and record the movement), or they did and only the movement is
 *  missing (record the movement alone). Guessing here would either
 *  double-count the stock or leave it short, so it is asked.
 * ─────────────────────────────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'take_into_stock') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $grnId = input_int($_POST, 'grn_id');
    $pid   = input_int($_POST, 'product_id');
    $mode  = ($_POST['mode'] ?? '') === 'ledger_only' ? 'ledger_only' : 'add_stock';

    // Re-derived here rather than trusted from the form: the gap is
    // the quantity about to be posted, and a stale page must not be
    // able to name it.
    $gap = $grnId !== null && $pid !== null ? db_one(
        "SELECT g.grn_id, g.grn_number, p.product_id, p.name AS product_name,
                r.received - COALESCE(l.moved, 0) AS gap
           FROM (SELECT grn_id, product_id, SUM(quantity_received) AS received
                   FROM grn_items WHERE grn_id = :g1 AND product_id = :p1
                  GROUP BY grn_id, product_id) r
           JOIN grns g ON g.grn_id = r.grn_id AND g.status <> 'cancelled'
           JOIN products p ON p.product_id = r.product_id
           LEFT JOIN (SELECT reference_id, product_id, SUM(quantity) AS moved
                        FROM inventory_transactions
                       WHERE reference_table = 'grns' AND movement_type = 'receipt'
                         AND reference_id = :g2 AND product_id = :p2
                       GROUP BY reference_id, product_id) l
                  ON l.reference_id = r.grn_id AND l.product_id = r.product_id
          WHERE r.received > 0 AND ABS(r.received - COALESCE(l.moved, 0)) > 0.0005",
        [':g1' => $grnId, ':p1' => $pid, ':g2' => $grnId, ':p2' => $pid]
    ) : null;

    if (!$gap) {
        json_response(['success' => false, 'message' =>
            'That receipt line is already in stock — nothing to put right.']);
    }
    $qty = (float) $gap['gap'];
    if ($qty <= 0) {
        json_response(['success' => false, 'message' =>
            'That line moved more stock than it received, which needs looking at by hand '
            . 'rather than adding more.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $balance = (float) db_value("SELECT stock_quantity FROM products WHERE product_id = :p",
                                    [':p' => $pid]);
        if ($mode === 'add_stock') {
            $balance = (float) db_value(
                "UPDATE products SET stock_quantity = stock_quantity + :q, updated_at = NOW()
                  WHERE product_id = :p RETURNING stock_quantity",
                [':q' => $qty, ':p' => $pid]
            );
        }

        $wh = db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1");
        db_run(
            "INSERT INTO inventory_transactions
                (product_id, warehouse_id, quantity, movement_type,
                 reference_table, reference_id, reference_number, balance_after, created_by, notes)
             VALUES (:pid, :wh, :qty, 'receipt', 'grns', :ref, :num, :bal, :by, :note)",
            [
                ':pid' => $pid, ':wh' => $wh, ':qty' => $qty, ':ref' => $grnId,
                ':num' => $gap['grn_number'], ':bal' => $balance, ':by' => current_user()['id'],
                ':note' => $mode === 'add_stock'
                    ? 'Taken into stock after the fact — the receipt recorded it, the shelf did not.'
                    : 'Movement recorded after the fact — the stock was already on the shelf.',
            ]
        );
        $pdo->commit();

        audit_log('grn.take_into_stock', 'grns', $grnId, [
            'product_id' => $pid, 'quantity' => $qty, 'mode' => $mode,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s: %s %s. %s now holds %s.',
            $gap['grn_number'], num($qty, 2),
            $mode === 'add_stock' ? 'taken into stock' : 'recorded as already in stock',
            $gap['product_name'], num($balance, 2)
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        json_response(['success' => false, 'message' =>
            db_rule_message($ex, 'Could not put that receipt into stock.')]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[GRN-STOCK] ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not put that receipt into stock.']);
    }
}

/* ── POST: bring one order into line with its receipts ───────── */
if (is_post() && ($_POST['_action'] ?? '') === 'reconcile') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $poId = input_int($_POST, 'po_id');
    $po   = $poId !== null
        ? db_one("SELECT * FROM purchase_orders WHERE po_id = :id", [':id' => $poId])
        : null;
    if (!$po) {
        json_response(['success' => false, 'message' => 'That purchase order no longer exists.']);
    }
    if ($po['status'] === 'cancelled') {
        json_response(['success' => false, 'message' => 'A cancelled order has nothing to reconcile.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // What the receipts say arrived, product by product. This is
        // the fact; the order is what gets corrected.
        $arrived = db_all(
            "SELECT gi.product_id,
                    SUM(gi.quantity_received + gi.quantity_rejected) AS qty,
                    MAX(gi.unit_cost) AS cost
               FROM grn_items gi
               JOIN grns g ON g.grn_id = gi.grn_id
              WHERE g.po_id = :id AND g.status <> 'cancelled'
              GROUP BY gi.product_id",
            [':id' => $poId]
        );
        if (!$arrived) {
            throw new RuntimeException('That order has no goods received against it.');
        }

        $ordered = [];
        foreach (db_all(
            "SELECT po_item_id, product_id, quantity_ordered, unit_price
               FROM purchase_order_items WHERE po_id = :id", [':id' => $poId]
        ) as $r) {
            $ordered[(int) $r['product_id']] = $r;
        }

        $added = 0;
        $lifted = 0;
        foreach ($arrived as $a) {
            $pid = (int) $a['product_id'];
            $qty = (float) $a['qty'];

            if (!isset($ordered[$pid])) {
                // Never ordered, but it came and it was kept.
                db_run(
                    "INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered,
                                                       quantity_fulfilled, unit_price)
                     VALUES (:po, :pid, :qty, :qty, :cost)",
                    [':po' => $poId, ':pid' => $pid, ':qty' => $qty, ':cost' => (float) $a['cost']]
                );
                $added++;
                continue;
            }

            $line = $ordered[$pid];
            // The order under-states what arrived: raise it to the
            // truth. Never lower it — an order part-delivered is not
            // an order that shrank.
            if ($qty > (float) $line['quantity_ordered'] + 0.0005) {
                db_run(
                    "UPDATE purchase_order_items
                        SET quantity_ordered = :qty
                      WHERE po_item_id = :id",
                    [':qty' => $qty, ':id' => $line['po_item_id']]
                );
                $lifted++;
            }
            // Fulfilment follows the receipts, always.
            db_run(
                "UPDATE purchase_order_items SET quantity_fulfilled = :qty WHERE po_item_id = :id",
                [':qty' => $qty, ':id' => $line['po_item_id']]
            );
        }

        // The order's money follows its lines.
        db_run(
            "UPDATE purchase_orders po
                SET subtotal     = v.sub,
                    tax_amount   = ROUND(v.sub * po.tax_rate, 2),
                    total_amount = v.sub + ROUND(v.sub * po.tax_rate, 2),
                    updated_at   = NOW()
               FROM (SELECT COALESCE(SUM(quantity_ordered * unit_price), 0) AS sub
                       FROM purchase_order_items WHERE po_id = :id) v
              WHERE po.po_id = :id",
            [':id' => $poId]
        );

        $stillOwed = (int) db_value(
            "SELECT COUNT(*) FROM purchase_order_items
              WHERE po_id = :id AND quantity_fulfilled < quantity_ordered", [':id' => $poId]
        );
        db_run(
            "UPDATE purchase_orders SET status = :s, updated_at = NOW() WHERE po_id = :id",
            [':s' => $stillOwed > 0 ? 'partially_received' : 'received', ':id' => $poId]
        );

        $after = db_one("SELECT total_amount, status FROM purchase_orders WHERE po_id = :id", [':id' => $poId]);
        $pdo->commit();

        audit_log('po.reconcile', 'purchase_orders', $poId, [
            'number' => $po['po_number'],
            'total_was' => $po['total_amount'], 'total_now' => $after['total_amount'],
            'lines_added' => $added, 'lines_raised' => $lifted,
        ]);

        json_response(['success' => true, 'message' =>
            $po['po_number'] . ' now says what arrived: ' . money($after['total_amount'])
            . ($added ? ', ' . $added . ' item(s) added' : '')
            . ($lifted ? ', ' . $lifted . ' quantity raised' : '')
            . '. Status ' . str_replace('_', ' ', $after['status']) . '.']);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not reconcile that order.')]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[PO-RECONCILE] ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not reconcile that order.']);
    }
}

$rows     = grn_mismatches();
$stockGaps = grn_stock_gaps();

$pageTitle    = 'Receipts that do not add up';
$pageSubtitle = 'Goods received notes that disagree with the stock, or with their order';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Purchasing'], ['label' => 'Reconcile receipts']];

require __DIR__ . '/../../../includes/header.php';
?>

<?php // Goods recorded as received that never reached the shelf. This
      // is the one failure a receipt cannot show on its own — the note
      // says the goods came, and only the stock ledger disagrees. ?>
<div class="panel">
    <div class="panel-head">
        <h2>Received, but not in stock
            <span class="cell-muted">the receipt says they came; no stock movement says so</span></h2>
        <strong><?= e(num(count($stockGaps))) ?>
            <?= count($stockGaps) === 1 ? 'line' : 'lines' ?></strong>
    </div>
    <?php if (!$stockGaps): ?>
        <p class="table-empty">
            Every receipt put its goods on the shelf. Nothing missing.
        </p>
    <?php else: ?>
        <p class="form-hint u-pad">
            A receipt exists to take goods into stock, and every one now writes the
            balance and its stock-ledger movement in a single transaction — the
            receiving screen refuses to save unless both went in. These predate that
            rule. The system cannot tell whether the goods reached the shelf and only
            the record is missing, or whether they never arrived on the books at all,
            so it asks: <strong>Take into stock</strong> adds the quantity and records
            the movement; <strong>Already on the shelf</strong> records the movement
            alone. Count it before choosing.
        </p>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Product</th>
                        <th>Supplier</th>
                        <th class="ta-right">Received</th>
                        <th class="ta-right">Moved</th>
                        <th class="ta-right">Missing</th>
                        <th class="ta-right">Stock now</th>
                        <th class="ta-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stockGaps as $g): ?>
                        <tr>
                            <td>
                                <span class="cell-title"><?= e($g['grn_number']) ?></span>
                                <span class="cell-sub"><?= e(fmt_date($g['receipt_date'])) ?>
                                    · <?= e($g['po_number'] ?: 'no order') ?></span>
                            </td>
                            <td>
                                <span class="cell-title"><?= e($g['product_name']) ?></span>
                                <span class="cell-sub"><?= e($g['sku'] ?: '—') ?></span>
                            </td>
                            <td class="cell-muted"><?= e($g['supplier_name']) ?></td>
                            <td class="ta-right"><?= e(num($g['received'], 2)) ?></td>
                            <td class="ta-right cell-muted"><?= e(num($g['moved'], 2)) ?></td>
                            <td class="ta-right tone-out"><strong><?= e(num($g['gap'], 2)) ?></strong></td>
                            <td class="ta-right cell-muted"><?= e(num($g['stock_quantity'], 2)) ?></td>
                            <td class="ta-right">
                                <form method="POST" class="inline-form"
                                      action="<?= e(url('modules/purchasing/reconcile_grns.php')) ?>" data-ajax
                                      data-confirm="Add <?= e(num($g['gap'], 2)) ?> of <?= e($g['product_name']) ?> to stock? Only do this if the goods are not already counted on the shelf.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="take_into_stock">
                                    <input type="hidden" name="grn_id" value="<?= (int) $g['grn_id'] ?>">
                                    <input type="hidden" name="product_id" value="<?= (int) $g['product_id'] ?>">
                                    <input type="hidden" name="mode" value="add_stock">
                                    <button class="btn btn-sm btn-primary" type="submit">Take into stock</button>
                                </form>
                                <form method="POST" class="inline-form"
                                      action="<?= e(url('modules/purchasing/reconcile_grns.php')) ?>" data-ajax
                                      data-confirm="Record the movement without changing the balance? Use this only when the goods are already counted in stock.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="take_into_stock">
                                    <input type="hidden" name="grn_id" value="<?= (int) $g['grn_id'] ?>">
                                    <input type="hidden" name="product_id" value="<?= (int) $g['product_id'] ?>">
                                    <input type="hidden" name="mode" value="ledger_only">
                                    <button class="btn btn-sm btn-ghost" type="submit">Already on the shelf</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!$rows): ?>
    <div class="panel">
        <p class="table-empty">
            Every goods received note agrees with its purchase order. Nothing to reconcile.
        </p>
    </div>
<?php else: ?>
    <p class="form-hint u-pad">
        Each of these has a receipt saying something its order does not, which is
        what makes the daily position read <em>paid for and not received</em> while
        the stock is on the shelf. <strong>Reconcile</strong> brings the order into
        line with what the receipt says arrived — adding the items that were never
        ordered at the price they were charged, and raising any quantity that came
        in over. It never changes the receipt: what arrived is a fact, and the
        order is the document that was wrong about it.
    </p>

    <div class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Supplier</th>
                        <th>Last receipt</th>
                        <th class="ta-right">Order says</th>
                        <th class="ta-right">Receipts say</th>
                        <th class="ta-right">Gap</th>
                        <th class="ta-right">Paid</th>
                        <th>What is wrong</th>
                        <th class="ta-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $gap = (float) $r['money_gap'];
                        $why = [];
                        if ((int) $r['unmatched_lines'] > 0) {
                            $why[] = num($r['unmatched_lines']) . ' item(s) received that were never ordered';
                        }
                        if ((int) $r['over_lines'] > 0) {
                            $why[] = num($r['over_lines']) . ' line(s) received over the quantity ordered';
                        }
                        if (abs($gap) > 0.005 && !$why) {
                            $why[] = $gap > 0 ? 'receipts worth more than the order' : 'receipts worth less than the order';
                        }
                    ?>
                        <tr>
                            <td><span class="cell-title"><?= e($r['po_number']) ?></span>
                                <span class="cell-sub"><?= e(status_label($r['status'])) ?></span></td>
                            <td><?= e($r['supplier_name'] ?: '—') ?></td>
                            <td class="cell-muted"><?= e(fmt_date($r['last_receipt'])) ?></td>
                            <td class="ta-right cell-muted"><?= e(money($r['po_total'])) ?></td>
                            <td class="ta-right"><strong><?= e(money($r['grn_total'])) ?></strong></td>
                            <td class="ta-right <?= $gap > 0 ? 'tone-out' : 'tone-in' ?>">
                                <?= $gap > 0 ? '+' : '−' ?><?= e(money(abs($gap))) ?>
                            </td>
                            <td class="ta-right cell-muted"><?= e(money($r['paid'])) ?></td>
                            <td class="cell-muted"><?= e(implode('; ', $why)) ?></td>
                            <td class="ta-right">
                                <form method="POST" action="<?= e(url('modules/purchasing/reconcile_grns.php')) ?>"
                                      class="inline-form" data-ajax
                                      data-confirm="Bring <?= e($r['po_number']) ?> into line with its receipts? The order becomes <?= e(money($r['grn_total'])) ?> — what actually arrived. The receipt is not changed.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="reconcile">
                                    <input type="hidden" name="po_id" value="<?= (int) $r['po_id'] ?>">
                                    <button type="submit" class="btn btn-primary btn--sm">Reconcile</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="form-hint u-pad">
        Afterwards the order, the receipt and what you owe all agree:
        <strong>owed = received − paid</strong>. An order received for 21,000 and
        paid 15,000 shows 6,000 still owed, which is the money you actually have
        to find — not a prepayment, and not nothing.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
