<?php

/**
 * ============================================================
 *  Disposals — taking stock off the books that cannot be sold
 * ------------------------------------------------------------
 *  Expired, damaged, recalled or contaminated stock leaves the
 *  shelf physically and has to leave the system too. Until now
 *  it could not: expired stock stayed in its batch for ever, so
 *  the valuation counted medicine the shop was forbidden to
 *  sell, and a recalled batch had no way of being retired.
 *
 *  Three things move in one transaction, or none do:
 *
 *    the batch      quantity_remaining comes down
 *    the product    stock_quantity comes down
 *    the ledger     an inventory_transactions row records it
 *
 *  The second one deserves an explanation, because dispensing.php
 *  deliberately does NOT touch products.stock_quantity — it says
 *  so at length, and it is right: a sale already moves the
 *  headline figure through the delivery trigger, so decrementing
 *  it again there would halve the stock on every sale.
 *
 *  A disposal has no invoice and no delivery note. Nothing else
 *  in the application will ever move the headline figure for it.
 *  So this one must, and the asymmetry is the point rather than
 *  an oversight: each mover exists exactly where nothing else
 *  moves it.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * Why stock was destroyed.
 *
 * Constrained rather than free text because the reason decides
 * who has to be told: a recall is reported to the manufacturer
 * and the board, an expiry is ordinary waste, and a shop that
 * writes "expired" in a notes field cannot count either of them.
 *
 * Matches the CHECK constraint in migration 065. Widening this
 * list means widening that constraint in the same commit.
 */
const DISPOSAL_REASONS = [
    'expired'      => 'Expired',
    'damaged'      => 'Damaged',
    'recalled'     => 'Recalled by the manufacturer',
    'contaminated' => 'Contaminated',
    'other'        => 'Other',
];

/**
 * Write a quantity of one batch off.
 *
 * Returns ['ok' => bool, 'message' => string, 'disposal_id' => ?int].
 * Refusals are in words a pharmacist can act on, because this is
 * called from a form somebody is standing at.
 */
function disposal_write_off(
    int $batchId,
    float $quantity,
    string $reason,
    string $notes = '',
    string $witness = ''
): array {
    if ($quantity <= 0) {
        return ['ok' => false, 'message' => 'Enter how much is being written off.', 'disposal_id' => null];
    }
    if (!isset(DISPOSAL_REASONS[$reason])) {
        return ['ok' => false, 'message' => 'Choose why this stock is being written off.', 'disposal_id' => null];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        /*  Lock the batch row before reading what is left of it.
            Two people clearing the same expired shelf at once
            would otherwise both read 40, both write off 40, and
            take 80 off a batch that held 40. The CHECK constraint
            on quantity_remaining would catch the second one, but
            with a constraint violation rather than a sentence,
            and dispensing.php learned that lesson already.     */
        $batch = db_one(
            'SELECT pb.batch_id, pb.product_id, pb.batch_number, pb.quantity_remaining,
                    pb.unit_cost, p.name AS product_name
               FROM product_batches pb
               JOIN products p ON p.product_id = pb.product_id
              WHERE pb.batch_id = :id
              FOR UPDATE OF pb',
            [':id' => $batchId]
        );

        if (!$batch) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'That batch no longer exists.', 'disposal_id' => null];
        }

        $remaining = (float) $batch['quantity_remaining'];
        if ($quantity > $remaining + 0.0005) {
            $pdo->rollBack();
            return [
                'ok'      => false,
                'message' => sprintf(
                    'Batch %s only has %s left, so %s cannot be written off.',
                    $batch['batch_number'],
                    rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.')
                ),
                'disposal_id' => null,
            ];
        }

        $userId = current_user()['id'] ?? null;

        $pdo->prepare(
            'UPDATE product_batches
                SET quantity_remaining = quantity_remaining - :q, updated_at = NOW()
              WHERE batch_id = :id'
        )->execute([':q' => $quantity, ':id' => $batchId]);

        //  See the note at the top of this file: nothing else in
        //  the application moves the headline figure for a
        //  disposal, so this must.
        $stock = $pdo->prepare(
            'UPDATE products
                SET stock_quantity = stock_quantity - :q, updated_at = NOW()
              WHERE product_id = :p
          RETURNING stock_quantity'
        );
        $stock->execute([':q' => $quantity, ':p' => (int) $batch['product_id']]);
        $balance = (float) $stock->fetchColumn();

        $ins = $pdo->prepare(
            'INSERT INTO stock_disposals
                (batch_id, product_id, quantity, reason, unit_cost, notes, witnessed_by, disposed_by)
             VALUES (:b, :p, :q, :r, :c, :n, :w, :u)
             RETURNING disposal_id'
        );
        $ins->execute([
            ':b' => $batchId,
            ':p' => (int) $batch['product_id'],
            ':q' => $quantity,
            ':r' => $reason,
            ':c' => $batch['unit_cost'],
            ':n' => $notes !== '' ? $notes : null,
            ':w' => $witness !== '' ? $witness : null,
            ':u' => $userId,
        ]);
        $disposalId = (int) $ins->fetchColumn();

        $pdo->prepare(
            "INSERT INTO inventory_transactions
                (product_id, quantity, movement_type, reference_table, reference_id,
                 reference_number, balance_after, notes, created_by)
             VALUES (:p, :q, 'disposal', 'stock_disposals', :ref, :num, :bal, :notes, :by)"
        )->execute([
            ':p'     => (int) $batch['product_id'],
            //  Negative: the ledger reads as a running movement
            //  column, and stock leaving is a negative movement
            //  whatever the reason it left.
            ':q'     => -$quantity,
            ':ref'   => $disposalId,
            ':num'   => 'Batch ' . $batch['batch_number'],
            ':bal'   => $balance,
            ':notes' => DISPOSAL_REASONS[$reason]
                        . ($witness !== '' ? ' — witnessed by ' . $witness : '')
                        . ($notes !== '' ? ' — ' . $notes : ''),
            ':by'    => $userId,
        ]);

        $pdo->commit();

        return [
            'ok'      => true,
            'message' => sprintf(
                '%s of %s written off from batch %s.',
                rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.'),
                $batch['product_name'],
                $batch['batch_number']
            ),
            'disposal_id' => $disposalId,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[DISPOSAL] ' . $e->getMessage());
        return [
            'ok' => false,
            'message' => 'That write-off could not be saved. Nothing was changed.',
            'disposal_id' => null,
        ];
    }
}

/** Everything written off from one batch, newest first. */
function disposals_for_batch(int $batchId): array
{
    if (!table_exists('stock_disposals')) {
        return [];
    }
    return db_all(
        'SELECT d.*, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS disposed_by_name
           FROM stock_disposals d
      LEFT JOIN users u ON u.user_id = d.disposed_by
          WHERE d.batch_id = :id
          ORDER BY d.disposed_at DESC, d.disposal_id DESC',
        [':id' => $batchId]
    );
}
