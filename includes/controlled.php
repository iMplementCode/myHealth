<?php

/**
 * ============================================================
 *  The controlled drugs register
 * ------------------------------------------------------------
 *  docs/PHARMACY.md has been saying this out loud since the
 *  pharmacy layer was written: the controlled flag is recorded,
 *  and the bound register an inspector asks for is not built.
 *  A pharmacy holding narcotics or psychotropics has to be able
 *  to produce one, per drug, showing every unit in and every
 *  unit out with a running balance.
 *
 *  Nothing new is recorded to build it. Receipts are already in
 *  product_batches — one row per batch received, with its
 *  supplier and goods received note — and issues are already in
 *  batch_allocations, one row per line dispensed, carrying the
 *  patient and prescriber through the invoice. The register is
 *  those two streams in date order with a balance carried down.
 *
 *  That is also its limit, and the screen says so rather than
 *  implying otherwise: a register derived from records is only
 *  as complete as the records. Stock moved by a stock take, or
 *  a drug whose batches were never entered, does not appear as
 *  a movement, and the balance will disagree with the shelf.
 *  A real bound register is signed and witnessed at each entry;
 *  this one is not, and calling it a substitute for that would
 *  be a lie told to somebody who can be prosecuted for it.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * The drugs that need a register at all.
 *
 * Anything with a controlled_schedule. Drugs with no movements
 * are still listed: "we hold none and moved none this month" is
 * an answer an inspector wants, and an empty list looks like the
 * question was never asked.
 */
function controlled_drugs(): array
{
    return db_all(
        "SELECT p.product_id, p.name, p.generic_name, p.strength, p.dosage_form,
                p.controlled_schedule, p.stock_quantity,
                u.abbreviation AS uom_abbr,
                COALESCE(SUM(pb.quantity_remaining), 0) AS batch_balance
           FROM products p
      LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
      LEFT JOIN product_batches pb     ON pb.product_id = p.product_id
          WHERE p.controlled_schedule IS NOT NULL
            AND p.controlled_schedule <> ''
          GROUP BY p.product_id, p.name, p.generic_name, p.strength, p.dosage_form,
                   p.controlled_schedule, p.stock_quantity, u.abbreviation
          ORDER BY p.name"
    );
}

/**
 * Every receipt of one controlled drug, as register entries.
 *
 * The date is the goods received note's receipt_date where the
 * batch came from one, and the row's own created_at where it did
 * not. Those differ: a note entered on Monday for stock that
 * arrived on Friday is dated Friday in the register, which is
 * the date that matters and the date the delivery paperwork
 * carries.
 */
function controlled_receipts(int $productId, ?string $from = null, ?string $to = null): array
{
    $where  = ['pb.product_id = :pid'];
    $params = [':pid' => $productId];
    $date   = 'COALESCE(g.receipt_date, pb.created_at::date)';

    if ($from !== null) {
        $where[] = "$date >= :from";
        $params[':from'] = $from;
    }
    if ($to !== null) {
        $where[] = "$date <= :to";
        $params[':to'] = $to;
    }

    return db_all(
        "SELECT $date AS entry_date,
                pb.batch_id, pb.batch_number, pb.expiry_date,
                pb.quantity_received AS quantity,
                g.grn_number, s.name AS supplier_name
           FROM product_batches pb
      LEFT JOIN grn_items gi ON gi.grn_item_id = pb.grn_item_id
      LEFT JOIN grns      g  ON g.grn_id = gi.grn_id
      LEFT JOIN suppliers s  ON s.supplier_id = COALESCE(pb.supplier_id, g.supplier_id)
          WHERE " . implode(' AND ', $where) . "
          ORDER BY 1, pb.batch_id",
        $params
    );
}

/**
 * Every issue of one controlled drug.
 *
 * Negative quantities are returns to stock. They stay in the
 * register as their own entries rather than being netted off the
 * issue they reverse: a register is a chronological record, and
 * an issue that was later returned still happened.
 */
function controlled_issues(int $productId, ?string $from = null, ?string $to = null): array
{
    $where  = ['ba.product_id = :pid'];
    $params = [':pid' => $productId];

    if ($from !== null) {
        $where[] = 'ba.dispensed_at::date >= :from';
        $params[':from'] = $from;
    }
    if ($to !== null) {
        $where[] = 'ba.dispensed_at::date <= :to';
        $params[':to'] = $to;
    }

    return db_all(
        'SELECT ba.dispensed_at::date AS entry_date, ba.dispensed_at,
                ba.quantity, ba.batch_id,
                pb.batch_number,
                i.invoice_number, i.prescriber, i.prescription_ref,
                pt.patient_number,
                TRIM(CONCAT(pt.first_name, \' \', pt.last_name)) AS patient_name,
                ' . customer_name_sql('c') . ' AS customer_name,
                TRIM(CONCAT(us.first_name, \' \', us.last_name)) AS dispensed_by_name
           FROM batch_allocations ba
      LEFT JOIN product_batches pb ON pb.batch_id = ba.batch_id
      LEFT JOIN invoices  i  ON ba.reference_table = \'invoices\'
                            AND i.invoice_id = ba.reference_id
      LEFT JOIN patients  pt ON pt.patient_id = i.patient_id
      LEFT JOIN customers c  ON c.customer_id = i.customer_id
      LEFT JOIN users     us ON us.user_id = ba.dispensed_by
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY 1, ba.allocation_id',
        $params
    );
}

/**
 * What the balance was before the period opened.
 *
 * Everything received minus everything issued, up to the day
 * before. A register that starts at zero every month is not a
 * register; the balance has to carry.
 */
function controlled_opening_balance(int $productId, string $from): float
{
    $in = (float) db_value(
        'SELECT COALESCE(SUM(pb.quantity_received), 0)
           FROM product_batches pb
      LEFT JOIN grn_items gi ON gi.grn_item_id = pb.grn_item_id
      LEFT JOIN grns      g  ON g.grn_id = gi.grn_id
          WHERE pb.product_id = :pid
            AND COALESCE(g.receipt_date, pb.created_at::date) < :from',
        [':pid' => $productId, ':from' => $from]
    );

    $out = (float) db_value(
        'SELECT COALESCE(SUM(ba.quantity), 0)
           FROM batch_allocations ba
          WHERE ba.product_id = :pid
            AND ba.dispensed_at::date < :from',
        [':pid' => $productId, ':from' => $from]
    );

    return $in - $out;
}

/**
 * The register itself: receipts and issues interleaved by date,
 * each with the balance after it.
 *
 * Sorted by date, and within a date receipts come before issues.
 * A balance cannot be reconstructed from entries that are only
 * sorted by day, and stock has to arrive before it can go out —
 * showing the issue first would put a negative balance on a line
 * that never happened.
 */
function controlled_register(int $productId, string $from, string $to): array
{
    $entries = [];

    foreach (controlled_receipts($productId, $from, $to) as $r) {
        $entries[] = [
            'date'      => $r['entry_date'],
            'direction' => 'in',
            'quantity'  => (float) $r['quantity'],
            'batch'     => $r['batch_number'],
            'reference' => $r['grn_number'] ?: '',
            'party'     => $r['supplier_name'] ?: 'Supplier not recorded',
            'person'    => '',
            'prescriber' => '',
        ];
    }

    foreach (controlled_issues($productId, $from, $to) as $i) {
        $qty = (float) $i['quantity'];
        $entries[] = [
            'date'      => $i['entry_date'],
            'direction' => $qty < 0 ? 'return' : 'out',
            'quantity'  => $qty,
            'batch'     => $i['batch_number'],
            'reference' => $i['invoice_number'] ?: '',
            //  The patient is who the medicine was for. A register
            //  asks who received it, not who paid, and those are
            //  routinely different people.
            'party'     => $i['patient_name'] ?: ($i['customer_name'] ?: ''),
            'person'    => $i['dispensed_by_name'] ?: '',
            'prescriber' => $i['prescriber'] ?: '',
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        if ($a['date'] !== $b['date']) {
            return strcmp((string) $a['date'], (string) $b['date']);
        }
        $rank = ['in' => 0, 'return' => 1, 'out' => 2];
        return ($rank[$a['direction']] ?? 3) <=> ($rank[$b['direction']] ?? 3);
    });

    $balance = controlled_opening_balance($productId, $from);
    $opening = $balance;
    $in = $out = 0.0;

    foreach ($entries as &$entry) {
        //  An issue reduces the balance; a receipt and a return
        //  raise it. batch_allocations already signs returns
        //  negative, so both issue cases subtract.
        if ($entry['direction'] === 'in') {
            $balance += $entry['quantity'];
            $in      += $entry['quantity'];
        } else {
            $balance -= $entry['quantity'];
            $out     += $entry['quantity'];
        }
        $entry['balance'] = $balance;
    }
    unset($entry);

    return [
        'entries' => $entries,
        'opening' => $opening,
        'closing' => $balance,
        'in'      => $in,
        'out'     => $out,
    ];
}
