<?php

/**
 * ============================================================
 *  Taking stock out of a named batch
 * ------------------------------------------------------------
 *  A shop decrements a number. A pharmacy has to say WHICH
 *  boxes went out, because three questions have no other answer:
 *
 *    "Batch B-2291 is recalled."   Who did we give it to?
 *    "Is this still good?"         What was on the shelf when
 *                                  it was sold, not what is on
 *                                  it now.
 *    "What did that cost us?"      Two deliveries of the same
 *                                  drug rarely cost the same.
 *
 *  FEFO — first expiry, first out. Not FIFO: the box that
 *  arrived first is not always the one that dies first, and a
 *  pharmacy that ships the newest stock watches the oldest turn
 *  into a write-off on the shelf behind it.
 *
 *  ── What this file does NOT do ──────────────────────────────
 *  It does not touch products.stock_quantity. That column is
 *  maintained by each caller — the GRN, the delivery trigger,
 *  the stock take — and having two places decrement it would
 *  halve the stock on every sale. Allocation moves
 *  product_batches.quantity_remaining and writes
 *  batch_allocations; the caller moves the headline figure the
 *  same way it always has.
 *
 *  The invariant that follows is worth stating: for a batch-
 *  tracked drug, SUM(quantity_remaining) should equal
 *  products.stock_quantity. They can drift — a stock take
 *  adjusts one and not the other — so `batch_drift()` exists to
 *  find it rather than pretend it cannot happen.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * Does this drug have batches at all?
 *
 * A shop part-way through entering its shelves has stock with no
 * batch behind it, and refusing to sell that would make the
 * system unusable on the day it is installed. So: a drug with no
 * batch rows sells on the plain stock figure, and a drug WITH
 * batch rows must be dispensed from one.
 *
 * Note it asks whether any batch has ever existed, not whether
 * one has stock now. Otherwise selling the last box would quietly
 * turn a tracked drug into an untracked one, and the next sale
 * would go through with no batch and no record.
 */
function batch_tracked(int $productId): bool
{
    if (!table_exists('product_batches')) {
        return false;
    }
    return (bool) db_value(
        'SELECT 1 FROM product_batches WHERE product_id = :p LIMIT 1',
        [':p' => $productId]
    );
}

/**
 * How much can actually be dispensed today.
 *
 * Expired stock is not stock. It is still on the shelf and still
 * in quantity_remaining — somebody has to physically pull it —
 * but it is not available to sell, and a screen that offers it is
 * a screen that sells it.
 *
 * A batch whose expiry nobody recorded is counted as available.
 * The alternative is that a missing date silently removes stock
 * from sale, and the shop would sooner see the gap in its data
 * than find an empty shelf that is not empty.
 */
function batch_available(int $productId): float
{
    if (!table_exists('product_batches')) {
        return 0.0;
    }
    return (float) db_value(
        "SELECT COALESCE(SUM(quantity_remaining), 0)
           FROM product_batches
          WHERE product_id = :p
            AND quantity_remaining > 0
            AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)",
        [':p' => $productId]
    );
}

/**
 * Expired stock still sitting in the batches, which somebody has
 * to pull off the shelf. Reported, never sold.
 */
function batch_expired_quantity(int $productId): float
{
    if (!table_exists('product_batches')) {
        return 0.0;
    }
    return (float) db_value(
        "SELECT COALESCE(SUM(quantity_remaining), 0)
           FROM product_batches
          WHERE product_id = :p
            AND quantity_remaining > 0
            AND expiry_date IS NOT NULL
            AND expiry_date < CURRENT_DATE",
        [':p' => $productId]
    );
}

/**
 * The batches this drug would be dispensed from, in the order
 * they would be taken. For showing somebody what they are about
 * to hand over, before they hand it over.
 *
 * @return list<array<string,mixed>>
 */
function batch_queue(int $productId, int $limit = 20): array
{
    if (!table_exists('product_batches')) {
        return [];
    }
    return db_all(
        "SELECT batch_id, batch_number, expiry_date, quantity_remaining, unit_cost
           FROM product_batches
          WHERE product_id = :p
            AND quantity_remaining > 0
            AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)
          ORDER BY expiry_date ASC NULLS LAST, batch_id ASC
          LIMIT " . max(1, $limit),
        [':p' => $productId]
    );
}

/**
 * Take a quantity out of a drug's batches, oldest expiry first.
 *
 * MUST be called inside a transaction the caller owns, because
 * the whole point is that the rows it locked stay locked until
 * the sale they belong to is committed. Called outside one, the
 * locks would be released the instant this returns and two tills
 * could each sell the last box.
 *
 * @return array{ok: bool, tracked: bool, message: string,
 *               allocations: list<array<string,mixed>>}
 */
function batch_allocate(
    int $productId,
    float $quantity,
    string $refTable,
    int $refId,
    ?int $by = null
): array {
    if ($quantity <= 0) {
        return ['ok' => false, 'tracked' => false, 'allocations' => [],
                'message' => 'A quantity to dispense must be more than nothing.'];
    }

    if (!batch_tracked($productId)) {
        // Not an error. See batch_tracked() for why.
        return ['ok' => true, 'tracked' => false, 'allocations' => [],
                'message' => ''];
    }

    $pdo = db();
    if (!$pdo->inTransaction()) {
        /*  Refused rather than quietly opening one. A caller that
         *  forgot the transaction is a caller whose sale can half
         *  happen — stock gone, invoice not written — and that is
         *  worth a loud failure during development rather than a
         *  mystery in a month's books. */
        throw new RuntimeException(
            'batch_allocate() must run inside a transaction: the batch rows it locks '
          . 'have to stay locked until the sale is committed.'
        );
    }

    /*  FOR UPDATE, and this is the whole reason the function
     *  exists rather than an UPDATE ... WHERE.
     *
     *  Two tills selling the last box at the same moment each read
     *  "1 remaining", each decide it is fine, and both sell it.
     *  The parent codebase learned this on invoice payments — six
     *  clicks took 60,000 against a 10,000 invoice because every
     *  guard read a row nobody had locked. Same shape, same fix.
     *
     *  Ordered the same way it allocates, so two callers queue
     *  behind each other on the same first row instead of taking
     *  the set in different orders and deadlocking. */
    $batches = db_all(
        "SELECT batch_id, batch_number, expiry_date, quantity_remaining, unit_cost
           FROM product_batches
          WHERE product_id = :p
            AND quantity_remaining > 0
            AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)
          ORDER BY expiry_date ASC NULLS LAST, batch_id ASC
            FOR UPDATE",
        [':p' => $productId]
    );

    $have = 0.0;
    foreach ($batches as $b) {
        $have += (float) $b['quantity_remaining'];
    }

    /*  Rounded before comparing. Quantities are NUMERIC(14,3) and
     *  come back as strings; a float round-trip can leave 5.0
     *  fractionally under 5 and refuse a sale the shop can plainly
     *  see it has the stock for. */
    if (round($have, 3) < round($quantity, 3)) {
        $expired = batch_expired_quantity($productId);
        $msg = 'Only ' . rtrim(rtrim(number_format($have, 3, '.', ','), '0'), '.')
             . ' can be dispensed.';
        if ($expired > 0) {
            //  The difference between "we are out" and "it is on the
            //  shelf but you may not sell it" is the difference
            //  between ordering more and pulling it.
            $msg .= ' A further '
                  . rtrim(rtrim(number_format($expired, 3, '.', ','), '0'), '.')
                  . ' is in the batches but has expired and cannot be sold.';
        }
        return ['ok' => false, 'tracked' => true, 'allocations' => [], 'message' => $msg];
    }

    $left        = $quantity;
    $allocations = [];

    foreach ($batches as $b) {
        if ($left <= 0.0005) {
            break;
        }
        $take = min($left, (float) $b['quantity_remaining']);
        if ($take <= 0) {
            continue;
        }

        db_run(
            'UPDATE product_batches
                SET quantity_remaining = quantity_remaining - :q,
                    updated_at = NOW()
              WHERE batch_id = :b',
            [':q' => $take, ':b' => $b['batch_id']]
        );

        db_run(
            'INSERT INTO batch_allocations
                 (batch_id, product_id, quantity, reference_table, reference_id, dispensed_by)
             VALUES (:b, :p, :q, :t, :r, :u)',
            [
                ':b' => $b['batch_id'], ':p' => $productId, ':q' => $take,
                ':t' => $refTable, ':r' => $refId, ':u' => $by,
            ]
        );

        $allocations[] = [
            'batch_id'     => (int) $b['batch_id'],
            'batch_number' => $b['batch_number'],
            'expiry_date'  => $b['expiry_date'],
            'quantity'     => $take,
            'unit_cost'    => $b['unit_cost'],
        ];
        $left -= $take;
    }

    return ['ok' => true, 'tracked' => true, 'allocations' => $allocations, 'message' => ''];
}

/**
 * Allocate several lines at once.
 *
 * Exists for the deadlock, not for convenience. Two multi-line
 * sales touching the same two drugs in opposite orders will each
 * hold one row and wait for the other, and Postgres resolves that
 * by killing one of them. Taking products in a fixed order — by
 * id, arbitrary but consistent — means every caller queues the
 * same way and none of them cross.
 *
 * @param array<int, float> $lines product_id => quantity
 * @return array{ok: bool, message: string,
 *               allocations: array<int, list<array<string,mixed>>>}
 */
function batch_allocate_many(array $lines, string $refTable, int $refId, ?int $by = null): array
{
    ksort($lines, SORT_NUMERIC);

    $all = [];
    foreach ($lines as $productId => $qty) {
        $res = batch_allocate((int) $productId, (float) $qty, $refTable, $refId, $by);
        if (!$res['ok']) {
            //  No partial success: the caller's transaction is
            //  rolled back by the caller, which is the only place
            //  that knows what else is in it.
            $name = (string) db_value('SELECT name FROM products WHERE product_id = :p',
                                      [':p' => $productId]);
            return ['ok' => false, 'allocations' => [],
                    'message' => ($name !== '' ? $name . ': ' : '') . $res['message']];
        }
        if ($res['allocations']) {
            $all[(int) $productId] = $res['allocations'];
        }
    }

    return ['ok' => true, 'allocations' => $all, 'message' => ''];
}

/**
 * What batches went out on a document. For the receipt, the
 * recall list, and the reversal.
 *
 * @return list<array<string,mixed>>
 */
function batch_allocations_for(string $refTable, int $refId): array
{
    if (!table_exists('batch_allocations')) {
        return [];
    }
    return db_all(
        "SELECT ba.allocation_id, ba.batch_id, ba.product_id, ba.quantity,
                ba.dispensed_at, pb.batch_number, pb.expiry_date, pb.unit_cost,
                p.name AS product_name
           FROM batch_allocations ba
           JOIN product_batches pb ON pb.batch_id = ba.batch_id
           JOIN products p         ON p.product_id = ba.product_id
          WHERE ba.reference_table = :t AND ba.reference_id = :r
          ORDER BY ba.allocation_id",
        [':t' => $refTable, ':r' => $refId]
    );
}

/**
 * Put back what a document took — a cancelled sale, a return.
 *
 * Stock goes back to the batch it came from, not to whichever
 * batch is convenient. A returned box has the expiry printed on
 * it, and putting it in a different batch would launder that date
 * into whatever the other batch claims.
 *
 * Writes a mirror-image negative row rather than deleting the
 * original, so the history still shows that the stock went out
 * and came back. Deleting it would leave a recall list quietly
 * missing somebody who did receive the box for a fortnight.
 *
 * @return int how many allocations were reversed
 */
function batch_release(string $refTable, int $refId, ?int $by = null): int
{
    $pdo = db();
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('batch_release() must run inside a transaction.');
    }

    /*  Lock first, aggregate second — and they have to be two
     *  statements. Postgres refuses FOR UPDATE on a GROUP BY
     *  ("Feature not supported"), because it cannot say which row
     *  an aggregate came from.
     *
     *  Locking the batch rows themselves is what matters anyway:
     *  the lock is held for the rest of the transaction, so the
     *  net computed next cannot be overtaken between reading it
     *  and writing it back. Ordered by id so two reversals queue
     *  rather than cross. */
    db_all(
        "SELECT batch_id
           FROM product_batches
          WHERE batch_id IN (
                    SELECT DISTINCT batch_id FROM batch_allocations
                     WHERE reference_table = :t AND reference_id = :r)
          ORDER BY batch_id
            FOR UPDATE",
        [':t' => $refTable, ':r' => $refId]
    );

    /*  Net per batch, so releasing twice is a no-op rather than a
     *  way to conjure stock: the second call sees the outward and
     *  the reversal cancelling out and has nothing left to undo. */
    $net = db_all(
        "SELECT batch_id, product_id, SUM(quantity) AS qty
           FROM batch_allocations
          WHERE reference_table = :t AND reference_id = :r
          GROUP BY batch_id, product_id
         HAVING SUM(quantity) > 0
          ORDER BY batch_id",
        [':t' => $refTable, ':r' => $refId]
    );

    $done = 0;
    foreach ($net as $row) {
        db_run(
            'UPDATE product_batches
                SET quantity_remaining = quantity_remaining + :q, updated_at = NOW()
              WHERE batch_id = :b',
            [':q' => $row['qty'], ':b' => $row['batch_id']]
        );
        db_run(
            'INSERT INTO batch_allocations
                 (batch_id, product_id, quantity, reference_table, reference_id, dispensed_by)
             VALUES (:b, :p, :q, :t, :r, :u)',
            [
                ':b' => $row['batch_id'], ':p' => $row['product_id'],
                ':q' => -(float) $row['qty'],
                ':t' => $refTable, ':r' => $refId, ':u' => $by,
            ]
        );
        $done++;
    }

    return $done;
}

/**
 * Where the batches and the headline stock figure disagree.
 *
 * They are maintained by different code — batches here, the
 * headline by whoever moved the stock — so they can drift. A
 * stock take that adjusts one and not the other is the usual
 * cause, and the first anybody knows about it is a sale refused
 * for stock the screen says is there.
 *
 * Only drugs that HAVE batches are compared. For the rest the
 * headline figure is the only record and cannot disagree with
 * anything.
 *
 * @return list<array<string,mixed>>
 */
function batch_drift(float $tolerance = 0.0005): array
{
    if (!table_exists('product_batches')) {
        return [];
    }
    return db_all(
        "SELECT p.product_id, p.name, p.sku,
                p.stock_quantity,
                COALESCE(SUM(pb.quantity_remaining), 0) AS batch_total,
                p.stock_quantity - COALESCE(SUM(pb.quantity_remaining), 0) AS difference
           FROM products p
           JOIN product_batches pb ON pb.product_id = p.product_id
          GROUP BY p.product_id, p.name, p.sku, p.stock_quantity
         HAVING ABS(p.stock_quantity - COALESCE(SUM(pb.quantity_remaining), 0)) > :tol
          ORDER BY ABS(p.stock_quantity - COALESCE(SUM(pb.quantity_remaining), 0)) DESC",
        [':tol' => $tolerance]
    );
}
