<?php

/**
 * ============================================================
 *  Credit notes, goods returns and customer refunds
 * ------------------------------------------------------------
 *  Undoing a sale, in the order the three things actually
 *  happen:
 *
 *    1. a credit note cancels the money       (receivable ↓)
 *    2. a return note brings the goods back   (inventory ↑)
 *    3. a refund pays the customer            (cash ↓)
 *
 *  They are separate because they happen at separate moments and
 *  can each be partial. Goods can come back before the refund is
 *  paid; a customer can keep half of what they were sent; a price
 *  correction credits money with no goods involved at all.
 *
 *  Everything here derives from the same invoice balance:
 *
 *      balance = total − paid − credited + refunded
 *
 *  positive → the customer owes us   (a trade receivable)
 *  negative → we owe the customer    (a refund liability)
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


/** What a return note expects back, and why. */
const CREDIT_RETURN_SCOPES = [
    'full'    => ['label' => 'All goods on the invoice',
                  'help'  => 'The whole order comes back. A return note is raised for every line.'],
    'partial' => ['label' => 'Only some goods',
                  'help'  => 'Choose the lines and quantities the customer is sending back.'],
    'none'    => ['label' => 'No goods — money only',
                  'help'  => 'A price correction or a discount agreed after invoicing. Nothing returns to stock.'],
];

/**
 * The full money picture for one invoice.
 *
 * Every page that shows what is owed — in either direction — asks
 * this, so none of them can disagree with the daily position.
 */
function invoice_money(int $invoiceId): array
{
    $row = db_one(
        "SELECT i.invoice_id, i.invoice_number, i.customer_id, i.status, i.credit_status,
                i.total_amount, i.amount_paid,
                COALESCE(cn.credited, 0) AS credited,
                COALESCE(rf.refunded, 0) AS refunded,
                " . customer_name_sql('c') . " AS customer_name
           FROM invoices i
           LEFT JOIN customers c ON c.customer_id = i.customer_id
           LEFT JOIN (SELECT invoice_id, SUM(total_amount) AS credited
                        FROM credit_notes WHERE status = 'approved' GROUP BY invoice_id) cn
                  ON cn.invoice_id = i.invoice_id
           LEFT JOIN (SELECT invoice_id, SUM(amount) AS refunded
                        FROM customer_refunds GROUP BY invoice_id) rf
                  ON rf.invoice_id = i.invoice_id
          WHERE i.invoice_id = :id",
        [':id' => $invoiceId]
    );
    if (!$row) {
        return [];
    }

    $balance = (float) $row['total_amount'] - (float) $row['amount_paid']
             - (float) $row['credited'] + (float) $row['refunded'];

    return $row + [
        'balance'     => $balance,
        // Positive and negative halves of the same number, so a
        // caller never has to remember which sign means what.
        'receivable'  => max($balance, 0.0),
        'refund_due'  => max(-$balance, 0.0),
        'creditable'  => max((float) $row['total_amount'] - (float) $row['credited'], 0.0),
    ];
}

/**
 * The lines a credit note would carry if the whole invoice were
 * credited, priced as invoiced and costed as the products stand.
 *
 * Cost matters: goods go back into stock at what they cost, not at
 * what they sold for, or returning them would invent profit.
 */
function invoice_creditable_lines(int $invoiceId): array
{
    return invoice_creditable_lines_bulk([$invoiceId])[$invoiceId] ?? [];
}

/**
 * The same lines, for many invoices at once, keyed by invoice_id.
 *
 * The credit-note page offers the two hundred most recent
 * creditable invoices and needs the lines of each, and it used to
 * get them by calling the singular version two hundred times — two
 * hundred round trips and two hundred plans for one page.
 *
 * The singular version is now a wrapper around this one rather than
 * a second copy of the query, so the form and the picker can never
 * be shown different lines for the same invoice.
 */
function invoice_creditable_lines_bulk(array $invoiceIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
    if (!$ids) {
        return [];
    }
    // Integers, cast above, so interpolation is safe and the list
    // stays one statement however many invoices it covers.
    $in = implode(',', $ids);

    $rows = db_all(
        "SELECT ii.invoice_id, ii.product_id, p.name AS product_name, p.sku,
                ii.quantity, ii.unit_price, ii.discount,
                p.cost_price AS unit_cost,
                COALESCE(dn.delivered, 0)    AS delivered_quantity,
                COALESCE(cn.credited_qty, 0) AS credited_quantity
           FROM invoice_items ii
           JOIN products p ON p.product_id = ii.product_id
           LEFT JOIN (
                SELECT d.invoice_id, dni.product_id, SUM(dni.quantity) AS delivered
                  FROM delivery_note_items dni
                  JOIN delivery_notes d ON d.dn_id = dni.dn_id
                 WHERE d.status = 'posted' AND d.invoice_id IN ($in)
                 GROUP BY d.invoice_id, dni.product_id
           ) dn ON dn.invoice_id = ii.invoice_id AND dn.product_id = ii.product_id
           LEFT JOIN (
                SELECT c.invoice_id, cni.product_id, SUM(cni.quantity) AS credited_qty
                  FROM credit_note_items cni
                  JOIN credit_notes c ON c.credit_note_id = cni.credit_note_id
                 WHERE c.status = 'approved' AND c.invoice_id IN ($in)
                 GROUP BY c.invoice_id, cni.product_id
           ) cn ON cn.invoice_id = ii.invoice_id AND cn.product_id = ii.product_id
          WHERE ii.invoice_id IN ($in)
          ORDER BY ii.invoice_id, ii.invoice_item_id"
    );

    // Every id asked for gets a key, even an invoice with no lines,
    // so a caller can tell "none" from "not looked up".
    $out = array_fill_keys($ids, []);
    foreach ($rows as $row) {
        $out[(int) $row['invoice_id']][] = $row;
    }
    return $out;
}

/** A credit note with its customer, invoice and derived totals. */
function credit_note_row(?int $id): ?array
{
    if ($id === null) {
        return null;
    }
    return db_one(
        "SELECT cn.*, i.invoice_number, i.total_amount AS invoice_total,
                i.amount_paid AS invoice_paid, i.status AS invoice_status,
                " . customer_name_sql('c') . " AS customer_name,
                r.grn_return_id, r.return_number, r.status AS return_status,
                TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS approved_by_name
           FROM credit_notes cn
           JOIN invoices i ON i.invoice_id = cn.invoice_id
           LEFT JOIN customers c ON c.customer_id = cn.customer_id
           LEFT JOIN goods_return_notes r ON r.credit_note_id = cn.credit_note_id
           LEFT JOIN users u ON u.user_id = cn.approved_by
          WHERE cn.credit_note_id = :id",
        [':id' => $id]
    );
}

/** The lines of a credit note, with product names. */
function credit_note_items(int $creditNoteId): array
{
    return db_all(
        "SELECT ci.*, p.name AS product_name, p.sku
           FROM credit_note_items ci
           JOIN products p ON p.product_id = ci.product_id
          WHERE ci.credit_note_id = :id
          ORDER BY ci.cn_item_id",
        [':id' => $creditNoteId]
    );
}

/** Recompute a draft credit note's totals from its lines. */
function credit_note_recalculate(int $creditNoteId, float $taxRate = 0.0): void
{
    $subtotal = (float) db_value(
        "SELECT COALESCE(SUM(subtotal), 0) FROM credit_note_items WHERE credit_note_id = :id",
        [':id' => $creditNoteId]
    );
    $tax = round($subtotal * $taxRate, 2);
    credit_note_set_total($creditNoteId, $subtotal, $tax, $taxRate);
}

/**
 * Credit the whole of an invoice, whatever its lines happen to say.
 *
 * A full credit note means one thing: this sale is cancelled. Its
 * value is therefore what is left owing on the invoice — not what
 * the line items add back up to.
 *
 * They are not the same number often enough to matter. A line can
 * carry a discount; a converted quote can leave the header rounded
 * away from the sum of its lines; an invoice raised for a lump sum
 * may have no lines at all. Rebuilding the money from the lines in
 * any of those cases credits the customer the wrong amount — too
 * much and the database refuses the note outright, too little and
 * the invoice sits forever at "part credited" with a balance
 * nobody owes.
 *
 * So the lines stay what they have always been — the record of
 * which goods are coming back — and the money comes from the
 * invoice.
 *
 * @param float $creditable What is left to credit on the invoice.
 */
function credit_note_credit_in_full(int $creditNoteId, float $creditable, float $taxRate): void
{
    $creditable = round(max($creditable, 0.0), 2);

    // Split back out at the rate the invoice charged, so the VAT
    // return sees the tax reversed as well as the sale.
    $subtotal = $taxRate > 0 ? round($creditable / (1 + $taxRate), 2) : $creditable;
    $tax      = round($creditable - $subtotal, 2);

    credit_note_set_total($creditNoteId, $subtotal, $tax, $taxRate);
}

/** Write a credit note's money, keeping subtotal + tax = total. */
function credit_note_set_total(int $creditNoteId, float $subtotal, float $tax, float $taxRate): void
{
    db_run(
        "UPDATE credit_notes
            SET subtotal = :sub, tax_amount = :tax, tax_rate = :rate,
                total_amount = :total, updated_at = NOW()
          WHERE credit_note_id = :id",
        [':sub' => $subtotal, ':tax' => $tax, ':rate' => $taxRate,
         ':total' => round($subtotal + $tax, 2), ':id' => $creditNoteId]
    );
}

/**
 * Raise the goods return note an approved credit note calls for.
 *
 * Expected quantities come from the credit note; nothing is
 * received yet. A money-only credit note raises no return note at
 * all — there is nothing to come back.
 *
 * @return int|null The return note id, or null when none is needed.
 */
function credit_note_open_return(array $creditNote): ?int
{
    if ($creditNote['return_scope'] === 'none') {
        return null;
    }
    $existing = db_value(
        "SELECT grn_return_id FROM goods_return_notes WHERE credit_note_id = :id",
        [':id' => $creditNote['credit_note_id']]
    );
    if ($existing) {
        return (int) $existing;
    }

    // Goods expected are the credit note's lines. An invoice raised
    // for a lump sum has none, and a return note with nothing on it
    // is a job the warehouse can never finish — it would sit in
    // "awaiting" for good, because there is no line to receive.
    $lines = credit_note_items((int) $creditNote['credit_note_id']);
    if (!$lines) {
        return null;
    }

    $warehouse = db_value("SELECT warehouse_id FROM warehouses WHERE is_default LIMIT 1");
    $number    = next_document_number('goods_return_notes', 'return_number', 'GRT');

    $stmt = db()->prepare(
        "INSERT INTO goods_return_notes
            (return_number, credit_note_id, invoice_id, customer_id, warehouse_id,
             return_date, status, created_by)
         VALUES (:num, :cn, :inv, :cust, :wh, CURRENT_DATE, 'awaiting', :by)
         RETURNING grn_return_id"
    );
    $stmt->execute([
        ':num'  => $number,
        ':cn'   => $creditNote['credit_note_id'],
        ':inv'  => $creditNote['invoice_id'],
        ':cust' => $creditNote['customer_id'],
        ':wh'   => $warehouse,
        ':by'   => current_user()['id'] ?? null,
    ]);
    $returnId = (int) $stmt->fetchColumn();

    $ins = db()->prepare(
        "INSERT INTO goods_return_items
            (grn_return_id, product_id, quantity_expected, unit_cost, unit_price)
         VALUES (:r, :p, :qty, :cost, :price)"
    );
    foreach ($lines as $line) {
        $ins->execute([
            ':r'     => $returnId,
            ':p'     => $line['product_id'],
            ':qty'   => $line['quantity'],
            ':cost'  => $line['unit_cost'],
            ':price' => $line['unit_price'],
        ]);
    }
    return $returnId;
}

/** A return note with its customer, credit note and invoice. */
function return_note_row(?int $id): ?array
{
    if ($id === null) {
        return null;
    }
    return db_one(
        "SELECT r.*, cn.cn_number, cn.total_amount AS credit_total,
                i.invoice_number,
                " . customer_name_sql('c') . " AS customer_name,
                si.invoice_number AS shortfall_invoice_number
           FROM goods_return_notes r
           JOIN credit_notes cn ON cn.credit_note_id = r.credit_note_id
           LEFT JOIN invoices i ON i.invoice_id = r.invoice_id
           LEFT JOIN invoices si ON si.invoice_id = r.shortfall_invoice_id
           LEFT JOIN customers c ON c.customer_id = r.customer_id
          WHERE r.grn_return_id = :id",
        [':id' => $id]
    );
}

/** The lines of a return note, with product names and shortfall. */
function return_note_items(int $returnId): array
{
    return db_all(
        "SELECT ri.*, p.name AS product_name, p.sku,
                ri.quantity_expected - ri.quantity_received AS shortfall
           FROM goods_return_items ri
           JOIN products p ON p.product_id = ri.product_id
          WHERE ri.grn_return_id = :id
          ORDER BY ri.return_item_id",
        [':id' => $returnId]
    );
}

/**
 * Put received goods back into stock.
 *
 * Only goods received in good condition go back on the shelf.
 * Damaged returns are still recorded — the customer is still
 * credited for them — but they are not sellable, so adding them to
 * stock would overstate inventory.
 *
 * Caller is already inside a transaction.
 */
function return_note_post_stock(array $return, array $lines): void
{
    $stock = db()->prepare(
        "UPDATE products SET stock_quantity = stock_quantity + :qty, updated_at = NOW()
          WHERE product_id = :pid
      RETURNING stock_quantity"
    );
    $ledger = db()->prepare(
        "INSERT INTO inventory_transactions
            (product_id, warehouse_id, quantity, movement_type,
             reference_table, reference_id, reference_number, balance_after, notes, created_by)
         VALUES (:pid, :wh, :qty, 'return',
                 'goods_return_notes', :rid, :rnum, :bal, :notes, :by)"
    );

    foreach ($lines as $line) {
        $qty = (float) $line['quantity_received'];
        if ($qty <= 0 || $line['condition'] !== 'good') {
            continue;
        }
        $stock->execute([':qty' => $qty, ':pid' => $line['product_id']]);
        $balance = (float) $stock->fetchColumn();

        $ledger->execute([
            ':pid'   => $line['product_id'],
            ':wh'    => $return['warehouse_id'],
            ':qty'   => $qty,
            ':rid'   => $return['grn_return_id'],
            ':rnum'  => $return['return_number'],
            ':bal'   => $balance,
            ':notes' => 'Returned by customer against ' . $return['cn_number'],
            ':by'    => current_user()['id'] ?? null,
        ]);
    }
}

/**
 * What the customer kept rather than returned.
 *
 * Goods that did not come back were not returned, so crediting
 * them would be a gift. The shortfall is billed instead.
 */
function return_note_shortfall(int $returnId): array
{
    return db_all(
        "SELECT ri.product_id, p.name AS product_name, p.sku,
                ri.quantity_expected - ri.quantity_received AS quantity,
                ri.unit_price, ri.unit_cost
           FROM goods_return_items ri
           JOIN products p ON p.product_id = ri.product_id
          WHERE ri.grn_return_id = :id
            AND ri.quantity_expected - ri.quantity_received > 0.0005
          ORDER BY ri.return_item_id",
        [':id' => $returnId]
    );
}

/** Value of what the customer kept, at the prices they were charged. */
function return_shortfall_value(array $lines): float
{
    $total = 0.0;
    foreach ($lines as $l) {
        $total += (float) $l['quantity'] * (float) $l['unit_price'];
    }
    return round($total, 2);
}

/** Status tones shared by the credit-note and return-note lists. */
function returns_status_tone(string $status): string
{
    return [
        'draft'     => 'pending',
        'approved'  => 'active',
        'cancelled' => 'inactive',
        'awaiting'  => 'pending',
        'received'  => 'completed',
    ][$status] ?? 'pending';
}
