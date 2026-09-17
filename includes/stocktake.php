<?php

/**
 * ============================================================
 *  Stock counts
 * ------------------------------------------------------------
 *  The arithmetic and the rules behind counting a shelf.
 *
 *  A count is the only movement of stock with no other document
 *  behind it. Everything else has one — a goods received note, a
 *  delivery note, a return — and each of those already writes to
 *  the stock ledger. A count is what records the shelf and the
 *  books disagreeing, and why.
 *
 *  Nothing here changes stock. Posting does, in one transaction,
 *  and that lives in the page — but every figure it needs is
 *  computed here so the page and the report can never disagree
 *  about what a variance is worth.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * Why the shelf and the books disagreed.
 *
 * Not decoration. "Twelve missing" and "twelve missing because a
 * pallet was damaged in the rain" are the same number and quite
 * different problems, and only one of them is worth putting a
 * camera on. The list is deliberately short: a reason nobody can
 * choose between is a reason nobody records honestly.
 */
const STOCK_COUNT_REASONS = [
    'miscount'  => ['label' => 'Miscount / booking error',
                    'help'  => 'The stock was always there. Something was keyed wrong.'],
    'shrinkage' => ['label' => 'Shrinkage — missing, unexplained',
                    'help'  => 'Gone, with no document to explain it. The one worth watching.'],
    'damage'    => ['label' => 'Damaged, written off',
                    'help'  => 'Still on the premises or thrown away, but not sellable.'],
    'found'     => ['label' => 'Found — more than expected',
                    'help'  => 'Turned up behind something, or was received without paperwork.'],
    'expiry'    => ['label' => 'Expired',
                    'help'  => 'Past its date and withdrawn from sale.'],
    'opening'   => ['label' => 'Opening balance',
                    'help'  => 'First count of stock the system never saw arrive.'],
    'other'     => ['label' => 'Other — see note',
                    'help'  => 'Anything else. Say what in the note, or it helps nobody.'],
];

/** Human label for a reason code. */
function stock_reason_label(string $key): string
{
    return STOCK_COUNT_REASONS[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
}

/** One count, with its totals. */
function stock_count_row(?int $countId): ?array
{
    if ($countId === null) {
        return null;
    }
    return db_one(
        "SELECT sc.*,
                w.name AS warehouse_name,
                TRIM(CONCAT(cu.first_name, ' ', cu.last_name)) AS counted_by_name,
                TRIM(CONCAT(pu.first_name, ' ', pu.last_name)) AS posted_by_name,
                (SELECT COUNT(*) FROM stock_count_items i WHERE i.count_id = sc.count_id) AS line_count,
                (SELECT COUNT(*) FROM stock_count_items i
                  WHERE i.count_id = sc.count_id AND i.counted_quantity IS NOT NULL) AS counted_lines
           FROM stock_counts sc
           LEFT JOIN warehouses w ON w.warehouse_id = sc.warehouse_id
           LEFT JOIN users cu ON cu.user_id = sc.counted_by
           LEFT JOIN users pu ON pu.user_id = sc.posted_by
          WHERE sc.count_id = :id",
        [':id' => $countId]
    );
}

/**
 * The lines of a count, with the variance worked out.
 *
 * `system_quantity` is what the books said when the line was
 * written, not what they say now — that is the whole point of
 * storing it. The variance is measured against it so a delivery
 * made while the counting was going on does not get undone when
 * the sheet is posted.
 */
function stock_count_items(int $countId): array
{
    $rows = db_all(
        "SELECT i.*, p.name AS product_name, p.sku, p.stock_quantity AS live_quantity,
                u.abbreviation AS uom_name, c.name AS category_name
           FROM stock_count_items i
           JOIN products p ON p.product_id = i.product_id
           LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
           LEFT JOIN categories c ON c.category_id = p.category_id
          WHERE i.count_id = :id
          ORDER BY c.name NULLS LAST, p.name",
        [':id' => $countId]
    );

    foreach ($rows as &$r) {
        $counted = $r['counted_quantity'];
        $r['is_counted'] = $counted !== null;
        $r['variance']   = $counted === null
            ? 0.0
            : round((float) $counted - (float) $r['system_quantity'], 3);
        $r['variance_value'] = round($r['variance'] * (float) $r['unit_cost'], 2);
    }
    return $rows;
}

/**
 * What a count adds up to.
 *
 * Gains and losses are kept apart as well as netted, because they
 * are different questions. A net of zero across two hundred lines
 * is not a tidy shelf; it is very often a hundred lines wrong in
 * one direction and a hundred in the other.
 */
function stock_count_totals(array $items): array
{
    $t = [
        'lines' => count($items), 'counted' => 0, 'uncounted' => 0,
        'agreed' => 0, 'over' => 0, 'short' => 0,
        'gain_qty' => 0.0, 'loss_qty' => 0.0, 'net_qty' => 0.0,
        'gain_value' => 0.0, 'loss_value' => 0.0, 'net_value' => 0.0,
    ];

    foreach ($items as $i) {
        if (!$i['is_counted']) {
            $t['uncounted']++;
            continue;
        }
        $t['counted']++;
        $v = (float) $i['variance'];
        $val = (float) $i['variance_value'];

        if (abs($v) < 0.0005) {
            $t['agreed']++;
        } elseif ($v > 0) {
            $t['over']++;
            $t['gain_qty'] += $v;
            $t['gain_value'] += $val;
        } else {
            $t['short']++;
            $t['loss_qty'] += abs($v);
            $t['loss_value'] += abs($val);
        }
        $t['net_qty'] += $v;
        $t['net_value'] += $val;
    }

    foreach (['gain_value', 'loss_value', 'net_value'] as $k) {
        $t[$k] = round($t[$k], 2);
    }
    return $t;
}

/**
 * Products to put on a new sheet.
 *
 * Counting everything at once is how a stock take never gets
 * finished, so the sheet is built from a slice: a category, the
 * things that have moved, or the things the system thinks are low
 * — which is the slice worth checking most often, because that is
 * where being wrong costs a sale.
 *
 * @param string $scope all | category | low | moved
 */
function stock_count_candidates(string $scope, ?int $categoryId = null): array
{
    $where  = ['p.is_active'];
    $params = [];

    if ($scope === 'category' && $categoryId !== null) {
        $where[] = 'p.category_id = :cat';
        $params[':cat'] = $categoryId;
    } elseif ($scope === 'low') {
        $where[] = 'p.stock_quantity <= COALESCE(p.low_quantity_threshold, 0)';
    } elseif ($scope === 'moved') {
        // Anything that has moved in the last ninety days. A part
        // nobody has touched since last year is unlikely to have
        // walked off on its own.
        $where[] = "EXISTS (SELECT 1 FROM inventory_transactions t
                             WHERE t.product_id = p.product_id
                               AND t.created_at > NOW() - INTERVAL '90 days')";
    }

    // You cannot walk into the store and count installations.
    $where[] = goods_only_sql();

    return db_all(
        "SELECT p.product_id, p.name, p.sku, p.stock_quantity, p.cost_price,
                c.name AS category_name
           FROM products p
           LEFT JOIN categories c ON c.category_id = p.category_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY c.name NULLS LAST, p.name",
        $params
    );
}

/**
 * Shrinkage over a period, from posted counts.
 *
 * The figure worth a manager's attention: what went missing with
 * no document to explain it, valued at cost. Miscounts are
 * excluded — a booking error is not a loss, it is a correction.
 */
function stock_shrinkage_between(string $from, string $to): array
{
    if (!table_exists('stock_counts')) {
        return ['value' => 0.0, 'lines' => 0];
    }
    $row = db_one(
        "SELECT COALESCE(SUM((i.counted_quantity - i.system_quantity) * i.unit_cost), 0) AS value,
                COUNT(*) AS lines
           FROM stock_count_items i
           JOIN stock_counts sc ON sc.count_id = i.count_id
          WHERE sc.status = 'posted'
            AND sc.counted_on BETWEEN :f AND :t
            AND i.counted_quantity IS NOT NULL
            AND i.reason IN ('shrinkage', 'damage', 'expiry')",
        [':f' => $from, ':t' => $to]
    ) ?: [];

    return [
        'value' => round((float) ($row['value'] ?? 0), 2),
        'lines' => (int) ($row['lines'] ?? 0),
    ];
}
