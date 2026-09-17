<?php

/**
 * ============================================================
 *  Batches — reading what dispensing writes
 * ------------------------------------------------------------
 *  product_batches and batch_allocations were both written and
 *  never read. Goods receipt put batches in, the counter took
 *  stock out of them, and the only code that ever looked at
 *  either was includes/dispensing.php doing the taking. Nothing
 *  a pharmacist could open showed a batch, an expiry date, or
 *  where a batch had gone.
 *
 *  That is the whole point of batch tracking. A manufacturer
 *  recalls batch B-2291 and somebody has to answer "who did we
 *  give it to" that afternoon. The data was already being
 *  recorded correctly; it simply had no way out.
 *
 *  This file is the way out. It reads only — allocation and
 *  release stay in dispensing.php, which owns the locking, so
 *  there is still exactly one place that moves batch stock.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

//  For NOTIFY_HORIZON_DAYS below. bootstrap.php already pulls this
//  in, so the require is a no-op in the application — it is here so
//  that the constant cannot depend on include order.
require_once __DIR__ . '/notifications.php';

/**
 * How near an expiry has to be before the shop is warned.
 *
 * The same thirty days the notification scanner uses. If these
 * two disagree the bell says a batch needs attention and the
 * screen it links to does not list it, which is worse than
 * either number being wrong.
 */
const BATCH_EXPIRY_HORIZON_DAYS = NOTIFY_HORIZON_DAYS;

/**
 * The four states a batch can be in, as a pharmacist sees them.
 *
 * Note that "expired" and "depleted" are different answers to
 * different questions: one means go and pull it off the shelf,
 * the other means it sold. A batch that expired after selling
 * out needs nobody's attention, so depletion is checked first.
 */
function batch_state_sql(string $alias = 'pb'): string
{
    return "CASE
                WHEN {$alias}.quantity_remaining <= 0           THEN 'depleted'
                WHEN {$alias}.expiry_date IS NULL               THEN 'undated'
                WHEN {$alias}.expiry_date <  CURRENT_DATE       THEN 'expired'
                WHEN {$alias}.expiry_date <= CURRENT_DATE + " . BATCH_EXPIRY_HORIZON_DAYS . "
                                                               THEN 'expiring'
                ELSE 'ok'
            END";
}

/**
 * Human wording for each state, and the badge modifier to show
 * it in.
 *
 * The modifiers are ones style.css actually defines. Inventing
 * `badge--danger` because it reads better would render as an
 * unstyled grey pill, which is how half this application's
 * markup came to reference classes that do not exist.
 */
function batch_state_label(string $state): array
{
    return [
        'expired'  => ['Expired',       'expired'],
        'expiring' => ['Expiring soon', 'warn'],
        'ok'       => ['In date',       'active'],
        'depleted' => ['Sold out',      'inactive'],
        'undated'  => ['No expiry',     'pending'],
    ][$state] ?? ['Unknown', 'pending'];
}

/**
 * How many batches sit in each state.
 *
 * Shown as the filter chips, because "3 expired" is the number
 * somebody needs to see before they have decided what to look
 * at, not after.
 */
function batch_state_counts(): array
{
    $rows = db_all(
        'SELECT ' . batch_state_sql() . " AS state, COUNT(*) AS n
           FROM product_batches pb
          GROUP BY 1"
    );
    $counts = ['all' => 0, 'expired' => 0, 'expiring' => 0, 'ok' => 0, 'depleted' => 0, 'undated' => 0];
    foreach ($rows as $r) {
        $counts[$r['state']] = (int) $r['n'];
        $counts['all'] += (int) $r['n'];
    }
    return $counts;
}

/**
 * The batch list, filtered.
 *
 * $filters: state, q (drug name, generic or batch number),
 *           product_id (the notifications link in with this).
 */
function batch_list(array $filters, int $perPage, int $offset): array
{
    $where  = [];
    $params = [];

    $state = (string) ($filters['state'] ?? '');
    if (in_array($state, ['expired', 'expiring', 'ok', 'depleted', 'undated'], true)) {
        $where[] = batch_state_sql() . ' = :state';
        $params[':state'] = $state;
    }

    if (!empty($filters['product_id'])) {
        $where[] = 'pb.product_id = :pid';
        $params[':pid'] = (int) $filters['product_id'];
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        //  Batch number as well as the drug, because the question
        //  usually arrives as a batch number on a recall notice.
        $where[] = '(p.name ILIKE :q OR p.generic_name ILIKE :q
                     OR p.sku ILIKE :q OR pb.batch_number ILIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $sql = ' FROM product_batches pb
             JOIN products p ON p.product_id = pb.product_id
        LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
        LEFT JOIN suppliers s ON s.supplier_id = pb.supplier_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

    $total = (int) db_value('SELECT COUNT(*)' . $sql, $params);

    $rows = db_all(
        'SELECT pb.batch_id, pb.batch_number, pb.expiry_date, pb.quantity_received,
                pb.quantity_remaining, pb.unit_cost, pb.created_at,
                p.product_id, p.name, p.generic_name, p.strength,
                p.dosage_form, p.requires_rx, p.controlled_schedule, p.storage,
                u.abbreviation AS uom_abbr,
                s.name AS supplier_name,
                (pb.expiry_date - CURRENT_DATE) AS days_left,
                (pb.quantity_remaining * COALESCE(pb.unit_cost, 0)) AS value_remaining, '
                . batch_state_sql() . ' AS state'
        . $sql .
        /*  Soonest expiry first, which is the order somebody
            actually works in: the shelf that has to be cleared
            this week is the top of the list. Batches with no
            expiry date sort last rather than first — NULL is
            "we do not know", not "urgent".                   */
        ' ORDER BY pb.expiry_date ASC NULLS LAST, p.name
          LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );

    return ['rows' => $rows, 'total' => $total];
}

/** One batch, with enough of its drug to name it properly. */
function batch_get(int $batchId): ?array
{
    $row = db_one(
        'SELECT pb.*, p.name, p.generic_name, p.strength, p.dosage_form,
                p.requires_rx, p.controlled_schedule, p.storage, p.product_id,
                u.abbreviation AS uom_abbr, s.name AS supplier_name,
                (pb.expiry_date - CURRENT_DATE) AS days_left, '
                . batch_state_sql() . ' AS state
           FROM product_batches pb
           JOIN products p ON p.product_id = pb.product_id
      LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
      LEFT JOIN suppliers s ON s.supplier_id = pb.supplier_id
          WHERE pb.batch_id = :id',
        [':id' => $batchId]
    );
    return $row ?: null;
}

/**
 * Where a batch went: the recall trace.
 *
 * One row per allocation, which for a sale means one row per
 * line dispensed. Negative quantities are returns — dispensing
 * writes a negative row beside the original rather than deleting
 * it, precisely so that a recall list still shows the box went
 * out and came back, instead of quietly showing neither.
 *
 * The patient is the person the medicine was for; the customer
 * is whoever paid. They are routinely different and a recall
 * needs the first one, so both are selected and the screen
 * prefers the patient.
 */
function batch_trace(int $batchId): array
{
    return db_all(
        'SELECT ba.allocation_id, ba.quantity, ba.dispensed_at,
                ba.reference_table, ba.reference_id,
                i.invoice_number, i.issue_date, i.prescriber, i.prescription_ref,
                pt.patient_id, pt.patient_number, pt.phone AS patient_phone,
                TRIM(CONCAT(pt.first_name, \' \', pt.last_name)) AS patient_name,
                ' . customer_name_sql('c') . ' AS customer_name,
                TRIM(CONCAT(us.first_name, \' \', us.last_name)) AS dispensed_by_name
           FROM batch_allocations ba
      LEFT JOIN invoices  i  ON ba.reference_table = \'invoices\'
                            AND i.invoice_id = ba.reference_id
      LEFT JOIN patients  pt ON pt.patient_id = i.patient_id
      LEFT JOIN customers c  ON c.customer_id = i.customer_id
      LEFT JOIN users     us ON us.user_id = ba.dispensed_by
          WHERE ba.batch_id = :id
          ORDER BY ba.dispensed_at, ba.allocation_id',
        [':id' => $batchId]
    );
}

/**
 * What a trace adds up to.
 *
 * Returns and issues are summed together, so this is what left
 * and stayed gone. It should equal received minus remaining; the
 * screen shows both so a disagreement is visible rather than
 * assumed impossible.
 */
function batch_trace_total(array $trace): float
{
    $total = 0.0;
    foreach ($trace as $row) {
        $total += (float) $row['quantity'];
    }
    return $total;
}
