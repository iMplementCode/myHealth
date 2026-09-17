<?php

/**
 * ============================================================
 *  One product's whole life
 * ------------------------------------------------------------
 *  Stock reports answer "how much is on the shelf". This answers
 *  the questions somebody actually asks holding a box:
 *
 *      When did this last come in, and from whom?
 *      What did we pay, and what did we charge?
 *      Who has one, and since when?
 *      Has this line ever made us any money?
 *
 *  All of it is already recorded — in grn_items, invoice_items
 *  and inventory_transactions. None of it was readable in one
 *  place, which is the same as not having it.
 *
 *  ── Cost, and telling the truth about it ────────────────────
 *  Profit needs a cost. Since migration 049 every invoice line
 *  carries what the goods cost on the day they sold; lines older
 *  than that carry nothing, and fall back to today's cost price.
 *  That fallback is a guess, so everything here counts how many
 *  lines needed it and the pages say so out loud. A margin figure
 *  that quietly mixes recorded cost with today's is worse than
 *  one that admits which is which.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/functions.php';

/** Invoices that count as real sales — not drafts, not cancelled. */
const LEDGER_LIVE_INVOICES = "i.status NOT IN ('draft', 'cancelled')";

/**
 * SQL for what one unit cost, best available.
 *
 * The stamped cost where there is one, today's cost price where
 * there is not. Guarded because the column arrives in migration 049
 * and this is read by the sales report, which has to keep working on
 * a database that is one migration behind.
 */
function ledger_unit_cost_sql(string $item = 'ii', string $product = 'p'): string
{
    return column_exists('invoice_items', 'unit_cost')
        ? "COALESCE($item.unit_cost, $product.cost_price)"
        : "$product.cost_price";
}

/** True where a line had to fall back to today's price. */
function ledger_cost_estimated_sql(string $item = 'ii'): string
{
    return column_exists('invoice_items', 'unit_cost')
        ? "$item.unit_cost IS NULL"
        : 'TRUE';
}

/**
 * The product itself, with the numbers that belong in a header.
 *
 * @return array|null null when there is no such product
 */
function ledger_product(int $productId): ?array
{
    return db_one(
        'SELECT p.*,
                c.name AS category_name,
                b.name AS brand_name,
                s.name AS supplier_name,
                u.abbreviation AS uom
           FROM products p
           LEFT JOIN categories c ON c.category_id = p.category_id
           LEFT JOIN brands b     ON b.brand_id    = p.brand_id
           LEFT JOIN suppliers s  ON s.supplier_id = p.supplier_id
           LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
          WHERE p.product_id = :id',
        [':id' => $productId]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  Where it came from
 * ──────────────────────────────────────────────────────────── */

/**
 * Every receipt of this product: when, from whom, how many, what
 * each one cost.
 *
 * Cancelled receipts are excluded — goods on a cancelled note never
 * arrived, and counting them would overstate both the quantity and
 * what the business paid.
 */
function ledger_purchases(int $productId, int $limit = 0, int $offset = 0): array
{
    if (!table_exists('grn_items') || !table_exists('grns')) {
        return [];
    }
    $cap = $limit > 0 ? "LIMIT $limit OFFSET $offset" : '';

    return db_all(
        "SELECT g.grn_id, g.grn_number, g.receipt_date, g.supplier_invoice_no,
                g.po_id, po.po_number,
                s.supplier_id, s.name AS supplier_name,
                gi.quantity_received, gi.quantity_rejected, gi.unit_cost,
                gi.subtotal, gi.batch_number, gi.expiry_date
           FROM grn_items gi
           JOIN grns g       ON g.grn_id = gi.grn_id
           LEFT JOIN suppliers s ON s.supplier_id = g.supplier_id
           LEFT JOIN purchase_orders po ON po.po_id = g.po_id
          WHERE gi.product_id = :id
            AND COALESCE(g.status, '') <> 'cancelled'
          ORDER BY g.receipt_date DESC, g.grn_id DESC
          $cap",
        [':id' => $productId]
    );
}

/** How many receipts there are, for paging. */
function ledger_purchase_count(int $productId): int
{
    if (!table_exists('grn_items') || !table_exists('grns')) {
        return 0;
    }
    return (int) db_value(
        "SELECT COUNT(*) FROM grn_items gi JOIN grns g ON g.grn_id = gi.grn_id
          WHERE gi.product_id = :id AND COALESCE(g.status, '') <> 'cancelled'",
        [':id' => $productId]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  Where it went
 * ──────────────────────────────────────────────────────────── */

/**
 * Every sale of this product: when, to whom, how many, at what
 * price, and what it cost us at the time.
 */
function ledger_sales(int $productId, int $limit = 0, int $offset = 0): array
{
    $cap  = $limit > 0 ? "LIMIT $limit OFFSET $offset" : '';
    $cost = ledger_unit_cost_sql();
    $est  = ledger_cost_estimated_sql();

    return db_all(
        "SELECT i.invoice_id, i.invoice_number, i.issue_date, i.status,
                i.customer_id, " . customer_name_sql('c') . " AS customer_name,
                ii.quantity, ii.unit_price, ii.discount, ii.subtotal,
                ii.delivered_quantity, ii.credited_quantity,
                $cost AS unit_cost,
                $est  AS cost_estimated,
                ii.subtotal - (ii.quantity * $cost) AS margin
           FROM invoice_items ii
           JOIN invoices i    ON i.invoice_id = ii.invoice_id
           JOIN products p    ON p.product_id = ii.product_id
           LEFT JOIN customers c ON c.customer_id = i.customer_id
          WHERE ii.product_id = :id
            AND " . LEDGER_LIVE_INVOICES . "
          ORDER BY i.issue_date DESC, i.invoice_id DESC
          $cap",
        [':id' => $productId]
    );
}

/** How many sales there are, for paging. */
function ledger_sale_count(int $productId): int
{
    return (int) db_value(
        "SELECT COUNT(*) FROM invoice_items ii JOIN invoices i ON i.invoice_id = ii.invoice_id
          WHERE ii.product_id = :id AND " . LEDGER_LIVE_INVOICES,
        [':id' => $productId]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  Every movement, in order
 * ──────────────────────────────────────────────────────────── */

/**
 * The stock ledger for one product: every row of
 * inventory_transactions, with the document it came from resolved
 * to something a person can read and click.
 *
 * The counterparty — supplier on a receipt, customer on a delivery
 * — is looked up per movement type rather than stored, because the
 * ledger row only records which document moved the stock.
 */
function ledger_movements(int $productId, int $limit = 0, int $offset = 0): array
{
    if (!table_exists('inventory_transactions')) {
        return [];
    }
    $cap = $limit > 0 ? "LIMIT $limit OFFSET $offset" : '';

    $hasDN   = table_exists('delivery_notes');
    $hasGRN  = table_exists('grns');

    // Left-joined by reference rather than a CASE over one table, so
    // a movement whose document has since been deleted still shows —
    // the stock moved, and hiding the row would leave the balance
    // jumping with nothing to explain it.
    $dnJoin = $hasDN ? "
        LEFT JOIN delivery_notes dn
               ON t.reference_table = 'delivery_notes' AND dn.dn_id = t.reference_id
        LEFT JOIN customers dnc ON dnc.customer_id = dn.customer_id" : '';
    $grnJoin = $hasGRN ? "
        LEFT JOIN grns g       ON t.reference_table = 'grns' AND g.grn_id = t.reference_id
        LEFT JOIN suppliers gs ON gs.supplier_id = g.supplier_id" : '';

    /*  Chosen by movement type, not COALESCE'd. customer_name_sql()
     *  ends in a literal 'Customer' so it never returns NULL — put it
     *  first in a COALESCE and every receipt claims a customer where
     *  the supplier's name belongs. */
    $party = [];
    if ($hasDN) {
        $party[] = "WHEN t.reference_table = 'delivery_notes' AND dn.dn_id IS NOT NULL
                    THEN " . customer_name_sql('dnc');
    }
    if ($hasGRN) {
        $party[] = "WHEN t.reference_table = 'grns' AND g.grn_id IS NOT NULL
                    THEN gs.name";
    }
    $partySql = $party ? 'CASE ' . implode(' ', $party) . ' END' : 'NULL';

    return db_all(
        "SELECT t.txn_id, t.created_at, t.movement_type, t.quantity, t.balance_after,
                t.reference_table, t.reference_id, t.reference_number, t.notes,
                TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS by_name,
                $partySql AS party
           FROM inventory_transactions t
           LEFT JOIN users u ON u.user_id = t.created_by
           $dnJoin
           $grnJoin
          WHERE t.product_id = :id
          ORDER BY t.created_at DESC, t.txn_id DESC
          $cap",
        [':id' => $productId]
    );
}

/** How many movements there are, for paging. */
function ledger_movement_count(int $productId): int
{
    if (!table_exists('inventory_transactions')) {
        return 0;
    }
    return (int) db_value(
        'SELECT COUNT(*) FROM inventory_transactions WHERE product_id = :id',
        [':id' => $productId]
    );
}

/**
 * Where a movement's document lives, so the ledger can link to it.
 *
 * Returns null for a movement whose document has no page, or whose
 * reference no longer resolves — a dead link is worse than none.
 */
function ledger_movement_url(array $m): ?string
{
    if (empty($m['reference_id'])) {
        return null;
    }
    $id = (int) $m['reference_id'];

    return match ($m['reference_table']) {
        'delivery_notes' => 'delivery_notes/view.php?id=' . $id,
        'grns'           => 'modules/purchasing/manage_grns.php?q=' . urlencode((string) $m['reference_number']),
        'invoices'       => 'invoices/view.php?id=' . $id,
        'stock_counts'   => 'modules/inventory/stock_take.php?id=' . $id,
        'credit_notes'   => 'credit_notes/index.php?q=' . urlencode((string) $m['reference_number']),
        'debit_notes'    => 'modules/purchasing/list_debit_notes.php?q=' . urlencode((string) $m['reference_number']),
        'goods_returns'  => 'returns/index.php?q=' . urlencode((string) $m['reference_number']),
        default          => null,
    };
}

/** How a movement reads in a list, and which way the stock went. */
function ledger_movement_label(string $type): array
{
    return match ($type) {
        'receipt'           => ['Received',        'in'],
        'delivery'          => ['Delivered',       'out'],
        'delivery_reversal' => ['Delivery cancelled', 'in'],
        'return'            => ['Returned',        'in'],
        'return_reversal'   => ['Return cancelled', 'out'],
        'adjustment'        => ['Stock count',     'adjust'],
        'transfer'          => ['Transferred',     'adjust'],
        'opening'           => ['Opening balance', 'in'],
        default             => [ucfirst(str_replace('_', ' ', $type)), 'adjust'],
    };
}

/* ─────────────────────────────────────────────────────────────
 *  What it has made
 * ──────────────────────────────────────────────────────────── */

/**
 * The money on one product over a period.
 *
 * Revenue is net of what came back: a credit note that returns goods
 * un-sells them, so both the money and the cost come off. A money-
 * only credit note is a price correction — the customer keeps the
 * item — so only the money comes off, which is the same rule the
 * sales report follows.
 *
 * @return array{units_sold:float, revenue:float, cost:float,
 *               profit:float, margin_pct:?float, invoices:int,
 *               customers:int, estimated_lines:int,
 *               units_bought:float, spent:float, avg_buy:?float,
 *               avg_sell:?float}
 */
function ledger_money(int $productId, ?string $from = null, ?string $to = null): array
{
    $cost   = ledger_unit_cost_sql();
    $est    = ledger_cost_estimated_sql();
    $params = [':id' => $productId];
    $window = '';
    if ($from !== null && $to !== null) {
        $window = ' AND i.issue_date BETWEEN :f AND :t';
        $params[':f'] = $from;
        $params[':t'] = $to;
    }

    $sold = db_one(
        "SELECT COALESCE(SUM(ii.quantity), 0)               AS units,
                COALESCE(SUM(ii.subtotal), 0)               AS revenue,
                COALESCE(SUM(ii.quantity * $cost), 0)       AS cost,
                COUNT(DISTINCT i.invoice_id)                AS invoices,
                COUNT(DISTINCT i.customer_id)               AS customers,
                COUNT(*) FILTER (WHERE $est)                AS estimated_lines
           FROM invoice_items ii
           JOIN invoices i ON i.invoice_id = ii.invoice_id
           JOIN products p ON p.product_id = ii.product_id
          WHERE ii.product_id = :id AND " . LEDGER_LIVE_INVOICES . $window,
        $params
    ) ?: [];

    // What came back, on the same rule the sales report uses.
    $back = ['units' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
    if (table_exists('credit_note_items') && table_exists('credit_notes')) {
        $cnParams = [':id' => $productId];
        $cnWindow = '';
        if ($from !== null && $to !== null) {
            $cnWindow = ' AND cn.issue_date BETWEEN :f AND :t';
            $cnParams[':f'] = $from;
            $cnParams[':t'] = $to;
        }
        $back = db_one(
            "SELECT COALESCE(SUM(CASE WHEN cn.return_scope <> 'none' THEN cni.quantity ELSE 0 END), 0) AS units,
                    COALESCE(SUM(cni.subtotal), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN cn.return_scope <> 'none'
                                      THEN cni.quantity * cni.unit_cost ELSE 0 END), 0) AS cost
               FROM credit_note_items cni
               JOIN credit_notes cn ON cn.credit_note_id = cni.credit_note_id
              WHERE cni.product_id = :id AND cn.status = 'approved'" . $cnWindow,
            $cnParams
        ) ?: $back;
    }

    // What was bought, over the same window where one is given.
    $bought = ['units' => 0.0, 'spent' => 0.0];
    if (table_exists('grn_items') && table_exists('grns')) {
        $gParams = [':id' => $productId];
        $gWindow = '';
        if ($from !== null && $to !== null) {
            $gWindow = ' AND g.receipt_date BETWEEN :f AND :t';
            $gParams[':f'] = $from;
            $gParams[':t'] = $to;
        }
        $bought = db_one(
            "SELECT COALESCE(SUM(gi.quantity_received), 0) AS units,
                    COALESCE(SUM(gi.subtotal), 0)          AS spent
               FROM grn_items gi
               JOIN grns g ON g.grn_id = gi.grn_id
              WHERE gi.product_id = :id
                AND COALESCE(g.status, '') <> 'cancelled'" . $gWindow,
            $gParams
        ) ?: $bought;
    }

    $units   = (float) ($sold['units'] ?? 0) - (float) $back['units'];
    $revenue = (float) ($sold['revenue'] ?? 0) - (float) $back['revenue'];
    $costOf  = (float) ($sold['cost'] ?? 0) - (float) $back['cost'];
    $profit  = $revenue - $costOf;
    $inUnits = (float) $bought['units'];
    $spent   = (float) $bought['spent'];

    return [
        'units_sold'      => $units,
        'revenue'         => $revenue,
        'cost'            => $costOf,
        'profit'          => $profit,
        // Guarded: a product given away, or one whose only sale was
        // credited back, has no revenue to divide by.
        'margin_pct'      => abs($revenue) > 0.005 ? $profit / $revenue * 100 : null,
        'invoices'        => (int) ($sold['invoices'] ?? 0),
        'customers'       => (int) ($sold['customers'] ?? 0),
        'estimated_lines' => (int) ($sold['estimated_lines'] ?? 0),
        'units_bought'    => $inUnits,
        'spent'           => $spent,
        'avg_buy'         => $inUnits > 0.0005 ? $spent / $inUnits : null,
        'avg_sell'        => abs($units) > 0.0005 ? $revenue / $units : null,
    ];
}

/**
 * The one-line answers a product card leads with.
 *
 * @return array{last_receipt:?array, last_sale:?array}
 */
function ledger_headlines(int $productId): array
{
    $purchases = ledger_purchases($productId, 1);
    $sales     = ledger_sales($productId, 1);

    return [
        'last_receipt' => $purchases[0] ?? null,
        'last_sale'    => $sales[0] ?? null,
    ];
}
