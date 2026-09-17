<?php

/**
 * ============================================================
 *  Reports — the arithmetic behind the period reports
 * ------------------------------------------------------------
 *  The daily position answers "what is true today". These
 *  answer "how did we do", which is a different question and
 *  needs a different shape: a start date, an end date, and
 *  totals that add up across it.
 *
 *  Everything here reads dated rows and computes on the way
 *  past. Nothing is stored, so a report cannot drift away from
 *  the documents that produced it, and a correction made to an
 *  invoice last month shows up the next time the month is
 *  looked at.
 *
 *  Where a figure is an approximation, the function says so in
 *  its comment and the page says so on the screen. A report
 *  that quietly rounds off the truth is worse than no report.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/cashbook.php';

/** Invoices that count: issued, not draft, not cancelled. */
const REPORT_LIVE_INVOICES = "i.status NOT IN ('draft', 'cancelled')";

// Cost of sales is worked out the same way here and on the product
// card. Two versions of "what did this cost" would disagree the first
// time either was touched.
require_once __DIR__ . '/product_ledger.php';

/**
 * A payment method column, as the words a customer would use.
 *
 * Built from PAYMENT_METHODS so the statement cannot start calling
 * a cheque something the rest of the system does not. Anything the
 * list does not know falls back to its own key, tidied up, rather
 * than vanishing.
 */
function report_method_label_sql(string $column): string
{
    $pdo   = db();
    $cases = '';
    foreach (PAYMENT_METHODS as $key => $label) {
        $cases .= ' WHEN ' . $pdo->quote($key) . ' THEN ' . $pdo->quote($label);
    }
    return "CASE $column$cases ELSE INITCAP(REPLACE($column, '_', ' ')) END";
}

/**
 * Resolve a from/to pair from the query string.
 *
 * Defaults to the current month, which is the period anybody
 * actually asks about, and swaps a backwards range rather than
 * returning nothing and looking broken.
 */
function report_period(array $get): array
{
    $isDate = static fn($d): bool => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

    $from = $isDate($get['from'] ?? null) ? $get['from'] : date('Y-m-01');
    $to   = $isDate($get['to'] ?? null)   ? $get['to']   : date('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    return ['from' => $from, 'to' => $to];
}

/** A period in words, for a page heading or a printed report. */
function report_period_label(string $from, string $to): string
{
    if ($from === $to) {
        return fmt_date($from, 'd F Y');
    }
    return fmt_date($from, 'd M Y') . ' to ' . fmt_date($to, 'd M Y');
}

/* ─────────────────────────────────────────────────────────────
 *  Trading — what was sold, what it cost
 * ──────────────────────────────────────────────────────────── */

/**
 * Revenue for a period: invoiced sales, less credit notes.
 *
 * Invoiced rather than received, because a sale is made when the
 * goods go and the invoice is raised — not when the customer
 * eventually pays. What is still owed is a receivable, and it
 * has its own report.
 */
function report_revenue(string $from, string $to): array
{
    $invoiced = (float) db_value(
        "SELECT COALESCE(SUM(i.total_amount - i.tax_amount), 0) FROM invoices i
          WHERE " . REPORT_LIVE_INVOICES . " AND i.issue_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    );

    // Ex-VAT, like the invoiced figure it is subtracted from. Taking
    // a VAT-inclusive credit note off ex-VAT revenue knocks the tax
    // off twice: a 116,000 credit note reduced revenue by 116,000
    // when the sale it reverses only ever counted 100,000.
    $credited = table_exists('credit_notes') ? (float) db_value(
        "SELECT COALESCE(SUM(cn.total_amount - cn.tax_amount), 0) FROM credit_notes cn
          WHERE cn.status = 'approved' AND cn.issue_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    ) : 0.0;

    return [
        'invoiced' => $invoiced,
        'credited' => $credited,
        'net'      => $invoiced - $credited,
    ];
}

/**
 * Cost of the goods sold in a period.
 *
 * Each line at what the goods cost on the day it was invoiced,
 * recorded by migration 049. Lines older than that have no cost of
 * their own and fall back to today's price — which is what this
 * whole figure used to do, and why a price rise restated last
 * year's margin. report_cost_of_sales_estimated() says how many
 * lines are still guessing, and the pages that show the figure say
 * so while any remain.
 */
function report_cost_of_sales(string $from, string $to): float
{
    $sold = (float) db_value(
        "SELECT COALESCE(SUM(ii.quantity * " . ledger_unit_cost_sql() . "), 0)
           FROM invoice_items ii
           JOIN invoices i ON i.invoice_id = ii.invoice_id
           JOIN products p ON p.product_id = ii.product_id
          WHERE " . REPORT_LIVE_INVOICES . " AND i.issue_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    );

    // Goods that came back were not sold, so they cost nothing to
    // sell — and they are back on the shelf, counted in stock again.
    // Leaving their cost in while their revenue comes out turns
    // every return into a loss the size of the cost.
    //
    // Only where goods actually returned: a money-only credit note
    // is a price correction and the customer keeps the item.
    //
    // At the cost recorded on the credit note, which is the cost the
    // sale went out at — not today's, or a return would book a profit
    // or a loss purely from a buying price having moved since.
    $returned = table_exists('credit_note_items') ? (float) db_value(
        "SELECT COALESCE(SUM(cni.quantity * COALESCE(NULLIF(cni.unit_cost, 0), p.cost_price)), 0)
           FROM credit_note_items cni
           JOIN credit_notes cn ON cn.credit_note_id = cni.credit_note_id
           JOIN products p ON p.product_id = cni.product_id
          WHERE cn.status = 'approved' AND cn.return_scope <> 'none'
            AND cn.issue_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    ) : 0.0;

    return $sold - $returned;
}

/**
 * How many invoice lines in a period had no recorded cost, and had
 * to be valued at today's price.
 *
 * Zero means the gross profit for that period is a fact. Anything
 * else means part of it still moves when a buying price is edited,
 * and the page saying so is the difference between a report that is
 * trusted and one that is believed.
 */
function report_cost_of_sales_estimated(string $from, string $to): int
{
    return (int) db_value(
        "SELECT COUNT(*)
           FROM invoice_items ii
           JOIN invoices i ON i.invoice_id = ii.invoice_id
          WHERE " . REPORT_LIVE_INVOICES . " AND i.issue_date BETWEEN :f AND :t
            AND " . ledger_cost_estimated_sql(),
        [':f' => $from, ':t' => $to]
    );
}

/**
 * Operating expenses for a period, by category.
 *
 * Dated by when the expense was incurred, not when it was paid —
 * a profit and loss reports the cost of running the month, and
 * rent paid late is still that month's rent. Rejected expenses
 * are not costs at all.
 */
function report_expenses(string $from, string $to): array
{
    return db_all(
        "SELECT c.name AS category, COALESCE(SUM(e.amount), 0) AS amount, COUNT(*) AS entries
           FROM expenses e
           JOIN expense_categories c ON c.category_id = e.category_id
          WHERE e.status <> 'rejected' AND e.expense_date BETWEEN :f AND :t
          GROUP BY c.name
          ORDER BY amount DESC",
        [':f' => $from, ':t' => $to]
    );
}

/** The whole profit and loss for a period, in one call. */
function report_profit_and_loss(string $from, string $to): array
{
    $revenue   = report_revenue($from, $to);
    $cost      = report_cost_of_sales($from, $to);
    $expenses  = report_expenses($from, $to);
    $expTotal  = array_sum(array_map(fn($r) => (float) $r['amount'], $expenses));
    $gross     = $revenue['net'] - $cost;

    return [
        'revenue'          => $revenue,
        'cost_of_sales'    => $cost,
        'gross_profit'     => $gross,
        'gross_margin'     => $revenue['net'] > 0.005 ? $gross / $revenue['net'] * 100 : 0.0,
        'expenses'         => $expenses,
        'expenses_total'   => $expTotal,
        'net_profit'       => $gross - $expTotal,
        'goods_received'   => finance_goods_received_between($from, $to),
    ];
}

/* ─────────────────────────────────────────────────────────────
 *  Ageing — who owes what, and for how long
 * ──────────────────────────────────────────────────────────── */

/** The buckets every ageing report uses. Days overdue, inclusive. */
const AGEING_BUCKETS = [
    'current' => ['label' => 'Not yet due', 'min' => null, 'max' => 0],
    'd30'     => ['label' => '1–30 days',   'min' => 1,    'max' => 30],
    'd60'     => ['label' => '31–60 days',  'min' => 31,   'max' => 60],
    'd90'     => ['label' => '61–90 days',  'min' => 61,   'max' => 90],
    'older'   => ['label' => 'Over 90 days','min' => 91,   'max' => null],
];

/** Which bucket a number of days overdue falls in. */
function ageing_bucket(int $daysOverdue): string
{
    if ($daysOverdue <= 0)  return 'current';
    if ($daysOverdue <= 30) return 'd30';
    if ($daysOverdue <= 60) return 'd60';
    if ($daysOverdue <= 90) return 'd90';
    return 'older';
}

/**
 * Unpaid invoices as at a date, one row per invoice, aged by how
 * long they have been overdue.
 *
 * The balance is the one formula used everywhere else in the
 * system — total less paid less credited plus refunded — so a
 * customer's debt here can never disagree with the daily
 * position's trade receivables.
 */
function report_receivables(string $asOf, ?int $limit = null, int $offset = 0): array
{
    if (!table_exists('invoice_payments')) {
        return [];
    }

    $cap = $limit !== null
        ? ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        : '';

    return db_all(report_receivables_sql() . " ORDER BY days_overdue DESC, balance DESC$cap",
        [':d' => $asOf]);
}

/**
 * The receivables query, without an ORDER BY or a LIMIT.
 *
 * Split out so the totals can be taken in SQL rather than by
 * fetching every unpaid invoice into PHP and adding them up there.
 * On four years of trading that was 80,241 rows read to display
 * ten, and it cost 315ms on the reports hub, which shows one
 * headline figure from it.
 *
 * One definition, four callers: the paged rows, the bucket totals,
 * the customer count, and the export. They cannot disagree.
 */
function report_receivables_sql(): string
{
    $balance = table_exists('credit_notes')
        ? INVOICE_BALANCE_SQL
        : "i.total_amount - COALESCE(p.paid, 0)";
    $joins = table_exists('credit_notes')
        ? INVOICE_BALANCE_JOINS
        : "LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM invoice_payments
                       WHERE payment_date <= :d GROUP BY invoice_id) p
             ON p.invoice_id = i.invoice_id";

    return "SELECT i.invoice_id, i.invoice_number, i.issue_date, i.due_date, i.total_amount,
                   i.customer_id,
                   " . customer_name_sql('c') . " AS customer_name,
                   c.email AS customer_email, c.phone AS customer_phone,
                   ($balance) AS balance,
                   GREATEST((:d)::date - COALESCE(i.due_date, i.issue_date), 0) AS days_overdue
              FROM invoices i
              $joins
              LEFT JOIN customers c ON c.customer_id = i.customer_id
             WHERE " . REPORT_LIVE_INVOICES . "
               AND i.issue_date <= :d
               AND ($balance) > 0.005";
}

/**
 * The ageing buckets, as SQL, matching ageing_bucket() exactly.
 *
 * Written once here so the PHP and the SQL cannot drift into two
 * different definitions of "31–60 days" — which would show a
 * different total depending on which page you opened.
 */
function ageing_bucket_sql(string $days = 'days_overdue'): string
{
    return "CASE
                WHEN $days <= 0  THEN 'current'
                WHEN $days <= 30 THEN 'd30'
                WHEN $days <= 60 THEN 'd60'
                WHEN $days <= 90 THEN 'd90'
                ELSE 'older'
            END";
}

/**
 * How much is owed, how it ages, and how many customers owe it —
 * in one pass, without reading a row into PHP.
 *
 * Returns the same shape as report_ageing_totals() plus 'rows' and
 * 'customers', so a page can show its headline figures and its
 * pagination without fetching the ledger.
 *
 * ── Why not just use invoices.balance_due ───────────────────
 * It is a generated column holding exactly this arithmetic, and
 * reading it instead of joining the three aggregates measures four
 * times faster — 108ms against 26ms. It also gives a DIFFERENT
 * ANSWER, by 59 million shillings on the test ledger.
 *
 * The reason is the "as at" date. balance_due counts every payment
 * ever recorded; this counts payments dated on or before the date
 * asked for. A post-dated cheque entered today is money the
 * customer still owes as at today, and the two disagree about it.
 * A receivables report that could not be run as at the end of last
 * month would not be a receivables report.
 *
 * So the joins stay, and the result is cached instead. Written down
 * because the substitution looks obviously correct and is not.
 */
function report_receivables_summary(string $asOf): array
{
    return cache_remember(
        "rep.receivables.$asOf",
        REPORT_CACHE_TTL,
        fn() => report_receivables_summary_now($asOf)
    );
}

function report_receivables_summary_now(string $asOf): array
{
    $out = array_fill_keys(array_keys(AGEING_BUCKETS), 0.0);
    $out['total'] = 0.0;
    $out['rows']  = 0;
    $out['customers'] = 0;

    if (!table_exists('invoice_payments')) {
        return $out;
    }

    $bucket = ageing_bucket_sql('r.days_overdue');
    $sums   = [];
    foreach (array_keys(AGEING_BUCKETS) as $key) {
        $sums[] = "COALESCE(SUM(r.balance) FILTER (WHERE $bucket = '$key'), 0) AS \"$key\"";
    }

    $row = db_one(
        "SELECT " . implode(', ', $sums) . ",
                COALESCE(SUM(r.balance), 0)        AS total,
                COUNT(*)                           AS \"rows\",
                COUNT(DISTINCT r.customer_id)      AS customers
           FROM (" . report_receivables_sql() . ") r",
        [':d' => $asOf]
    ) ?: [];

    foreach ($out as $k => $_) {
        $out[$k] = in_array($k, ['rows', 'customers'], true)
            ? (int) ($row[$k] ?? 0)
            : (float) ($row[$k] ?? 0);
    }
    return $out;
}

/**
 * One line per customer, worst debt first, paged in SQL.
 *
 * The by-customer view is what people actually read, and folding
 * 80,241 invoices into 12,000 customers is work the database does
 * far better than a foreach.
 */
function report_receivables_customers(string $asOf, ?int $limit = null, int $offset = 0): array
{
    if (!table_exists('invoice_payments')) {
        return [];
    }
    $bucket = ageing_bucket_sql('r.days_overdue');
    $sums   = [];
    foreach (array_keys(AGEING_BUCKETS) as $key) {
        $sums[] = "COALESCE(SUM(r.balance) FILTER (WHERE $bucket = '$key'), 0) AS \"$key\"";
    }
    $cap = $limit !== null ? ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset) : '';

    return db_all(
        "SELECT r.customer_id,
                MIN(r.customer_name)  AS name,
                MIN(r.customer_email) AS email,
                MIN(r.customer_phone) AS phone,
                COUNT(*)              AS invoices,
                MAX(r.days_overdue)   AS oldest,
                " . implode(', ', $sums) . ",
                COALESCE(SUM(r.balance), 0) AS total
           FROM (" . report_receivables_sql() . ") r
          GROUP BY r.customer_id
          ORDER BY total DESC$cap",
        [':d' => $asOf]
    );
}

/**
 * The same rows, folded into one line per customer with a
 * column per bucket. This is the view people actually read.
 */
function report_receivables_by_customer(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['customer_id'];
        if (!isset($out[$id])) {
            $out[$id] = [
                'customer_id' => $id,
                'name'        => $r['customer_name'],
                'email'       => $r['customer_email'],
                'phone'       => $r['customer_phone'],
                'invoices'    => 0,
                'total'       => 0.0,
                'oldest'      => 0,
            ] + array_fill_keys(array_keys(AGEING_BUCKETS), 0.0);
        }
        $bucket  = ageing_bucket((int) $r['days_overdue']);
        $balance = (float) $r['balance'];

        $out[$id][$bucket] += $balance;
        $out[$id]['total'] += $balance;
        $out[$id]['invoices']++;
        $out[$id]['oldest'] = max($out[$id]['oldest'], (int) $r['days_overdue']);
    }
    // Worst debt first: that is the order anyone works down.
    uasort($out, fn($a, $b) => $b['total'] <=> $a['total']);
    return array_values($out);
}

/** Bucket totals across every row, for the summary strip. */
function report_ageing_totals(array $rows, string $amountKey = 'balance'): array
{
    $totals = array_fill_keys(array_keys(AGEING_BUCKETS), 0.0);
    $totals['total'] = 0.0;
    foreach ($rows as $r) {
        $bucket = ageing_bucket((int) $r['days_overdue']);
        $totals[$bucket] += (float) $r[$amountKey];
        $totals['total'] += (float) $r[$amountKey];
    }
    return $totals;
}

/**
 * What is owed to suppliers, per purchase order, aged by the
 * date the goods arrived.
 *
 * You owe from delivery, not from ordering, so an order with
 * nothing received yet does not appear however large it is — and
 * you do not owe for goods sent back, so debit notes come off:
 *
 *     owed = received − returned − paid
 *
 * See po_debited_sql() for why a draft debit note counts.
 */
function report_payables(string $asOf): array
{
    if (!table_exists('purchase_payments')) {
        return [];
    }
    // Literally the subquery the daily position uses, so the report
    // and the balance sheet cannot drift apart. It correlates on
    // po.po_id, which is why the balance is worked out in an inner
    // select and filtered outside it rather than written twice.
    $debited = po_debited_sql();

    // What each order is owed for: goods that arrived, plus service
    // work that was accepted. A service never gets a GRN — see
    // migration 042 — and joining on receipts alone dropped a
    // services-only order out of the payables report entirely, so
    // the bill for it was owed to nobody.
    $servicesLeg = column_exists('purchase_order_items', 'accepted_at')
        ? "UNION ALL
                          SELECT poi.po_id,
                                 -- Tax included: the goods leg is a GRN
                                 -- total, which is tax-inclusive.
                                 ROUND(poi.quantity_ordered * poi.unit_price
                                       * (1 + COALESCE(po2.tax_rate, 0)), 2),
                                 poi.accepted_at
                            FROM purchase_order_items poi
                            JOIN purchase_orders po2 ON po2.po_id = poi.po_id
                           WHERE poi.accepted_at IS NOT NULL
                             AND poi.accepted_at <= :d"
        : '';
    $owedFor = "SELECT po_id, SUM(amount) AS received, MAX(dt) AS last_receipt
                    FROM (
                          SELECT g.po_id, g.total_amount AS amount, g.receipt_date AS dt
                            FROM grns g
                           WHERE g.status <> 'cancelled' AND g.receipt_date <= :d
                          $servicesLeg
                    ) x
                   GROUP BY po_id";

    return db_all(
        "SELECT * FROM (
            SELECT po.po_id, po.po_number, po.issue_date, po.total_amount, po.supplier_id,
                   s.name AS supplier_name, s.email AS supplier_email, s.phone AS supplier_phone,
                   r.received, r.last_receipt,
                   $debited AS debited,
                   COALESCE(p.paid, 0) AS paid,
                   (r.received - $debited - COALESCE(p.paid, 0)) AS balance,
                   GREATEST((:d)::date - r.last_receipt, 0) AS days_overdue
              FROM purchase_orders po
              JOIN ($owedFor) r ON r.po_id = po.po_id
              LEFT JOIN (SELECT pp.po_id, SUM(pp.amount) AS paid
                           FROM purchase_payments pp
                          WHERE pp.payment_date <= :d
                          GROUP BY pp.po_id) p ON p.po_id = po.po_id
              LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
             WHERE po.status <> 'cancelled'
         ) owed
         WHERE balance > 0.005
         ORDER BY days_overdue DESC, balance DESC",
        [':d' => $asOf]
    );
}

/**
 * Supplier prepayments, order by order.
 *
 * The mirror of a payable: money already paid for goods that have
 * not arrived, so the supplier is holding the company's money and
 * it is an asset until the stock turns up.
 *
 *     prepaid = paid − received, per order, where that is positive
 *
 * "Received" is the value of the goods received notes against the
 * order, not the order's own total — you can pay 100,000 against a
 * 100,000 order and still be prepaid 60,000 if only 40,000 of goods
 * have been booked in.
 *
 * Which is why this list exists. The payables report joins to the
 * GRNs, so an order paid with **no goods received note at all**
 * appears nowhere in it — and that is the commonest reason the
 * prepayment figure looks too big: the goods arrived and nobody
 * raised the GRN. This names the orders so the gap can be found
 * rather than guessed at.
 */
function report_supplier_prepayments(string $asOf): array
{
    if (!table_exists('purchase_payments') || !table_exists('grns')) {
        return [];
    }
    $debited = po_debited_sql();

    return db_all(
        "SELECT * FROM (
            SELECT po.po_id, po.po_number, po.issue_date, po.total_amount AS ordered,
                   po.status,
                   s.name AS supplier_name, s.phone AS supplier_phone,
                   COALESCE(p.paid, 0) AS paid,
                   -- Goods sent back stop counting as delivered, so the
                   -- money paid against them is once again money the
                   -- supplier is holding.
                   COALESCE(r.received, 0) - $debited AS received,
                   COALESCE(p.paid, 0) - (COALESCE(r.received, 0) - $debited) AS prepaid,
                   r.grn_count,
                   p.last_payment,
                   GREATEST((:d)::date - p.last_payment, 0) AS days_waiting
              FROM purchase_orders po
              -- LEFT, unlike the payables report: an order with no GRN
              -- at all is precisely the case worth showing here.
              LEFT JOIN (SELECT g.po_id, SUM(g.total_amount) AS received, COUNT(*) AS grn_count
                           FROM grns g
                          WHERE g.status <> 'cancelled' AND g.receipt_date <= :d
                          GROUP BY g.po_id) r ON r.po_id = po.po_id
              LEFT JOIN (SELECT pp.po_id, SUM(pp.amount) AS paid, MAX(pp.payment_date) AS last_payment
                           FROM purchase_payments pp
                          WHERE pp.payment_date <= :d
                          GROUP BY pp.po_id) p ON p.po_id = po.po_id
              LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
             WHERE po.status <> 'cancelled'
         ) ahead
         WHERE prepaid > 0.005
         ORDER BY prepaid DESC",
        [':d' => $asOf]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  A customer's account
 * ──────────────────────────────────────────────────────────── */

/**
 * Every movement on one customer's account over a period:
 * invoices raised, receipts taken, credit notes, refunds — in
 * date order with a running balance, which is what a statement
 * is.
 */
function report_customer_statement(int $customerId, string $from, string $to): array
{
    // Movements dated the same day are ordered by what they are, not
    // by the name of what they are: the charge first, then whatever
    // came off it. Sorting on `kind` alphabetically put a credit note
    // ahead of the invoice it credits, and the running balance went
    // negative in the middle of a statement that ends in the black.
    $parts = [
        "SELECT i.issue_date AS entry_date, 'invoice' AS kind, 1 AS sort_rank,
                i.invoice_number AS reference,
                i.total_amount AS debit, 0 AS credit, i.invoice_id AS doc_id
           FROM invoices i
          WHERE i.customer_id = :c AND " . REPORT_LIVE_INVOICES . "
            AND i.issue_date BETWEEN :f AND :t",

        // The same invoice filter as the line above it. Without it a
        // receipt against a cancelled invoice would be credited to the
        // customer while the invoice that justified it was left out,
        // and the statement would show money the customer never had.
        //
        // A payment's reference always leads with the invoice it was
        // allocated to, because that is the question a statement is
        // asked: what did this money settle? The instrument follows
        // when there is one — "INV-2026-0007 · Cheque 004512".
        //
        // NULLIF(TRIM(...)) rather than COALESCE alone: the form posts
        // an empty string when the box is left blank, which COALESCE
        // treats as a value and prints as nothing at all.
        "SELECT pay.payment_date, 'payment', 3,
                i.invoice_number || ' · ' || " . report_method_label_sql('pay.method') . "
                  || COALESCE(' ' || NULLIF(TRIM(pay.reference), ''), ''),
                0, pay.amount, i.invoice_id
           FROM invoice_payments pay
           JOIN invoices i ON i.invoice_id = pay.invoice_id
          WHERE i.customer_id = :c AND " . REPORT_LIVE_INVOICES . "
            AND pay.payment_date BETWEEN :f AND :t",
    ];

    if (table_exists('credit_notes')) {
        $parts[] =
        "SELECT cn.issue_date, 'credit_note', 2, cn.cn_number, 0, cn.total_amount, cn.credit_note_id
           FROM credit_notes cn
          WHERE cn.customer_id = :c AND cn.status = 'approved'
            AND cn.issue_date BETWEEN :f AND :t";
    }
    if (table_exists('customer_refunds')) {
        $parts[] =
        // Same rule as a receipt: lead with the document it settles —
        // here the credit note — then the instrument it was paid by.
        "SELECT r.refund_date, 'refund', 4,
                COALESCE(cn.cn_number, r.refund_number)
                  || ' · ' || " . report_method_label_sql('r.method') . "
                  || COALESCE(' ' || NULLIF(TRIM(r.reference), ''), ''),
                r.amount, 0, r.refund_id
           FROM customer_refunds r
           LEFT JOIN credit_notes cn ON cn.credit_note_id = r.credit_note_id
          WHERE r.customer_id = :c AND r.refund_date BETWEEN :f AND :t";
    }

    $rows = db_all(
        implode(' UNION ALL ', $parts) . ' ORDER BY entry_date, sort_rank',
        [':c' => $customerId, ':f' => $from, ':t' => $to]
    );

    // Anything owed before the period opens, so the statement
    // starts where the last one finished.
    $opening = report_customer_balance($customerId, date('Y-m-d', strtotime($from . ' -1 day')));

    $running = $opening;
    foreach ($rows as $k => $r) {
        $running += (float) $r['debit'] - (float) $r['credit'];
        $rows[$k]['balance'] = $running;
    }

    return ['opening' => $opening, 'entries' => $rows, 'closing' => $running];
}

/** What one customer owed, all documents, as at a date. */
function report_customer_balance(int $customerId, string $asOf): float
{
    if (!table_exists('invoice_payments')) {
        return 0.0;
    }
    $balance = table_exists('credit_notes')
        ? INVOICE_BALANCE_SQL
        : "i.total_amount - COALESCE(p.paid, 0)";
    $joins = table_exists('credit_notes')
        ? INVOICE_BALANCE_JOINS
        : "LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM invoice_payments
                       WHERE payment_date <= :d GROUP BY invoice_id) p
             ON p.invoice_id = i.invoice_id";

    return (float) db_value(
        "SELECT COALESCE(SUM($balance), 0) FROM invoices i $joins
          WHERE i.customer_id = :c AND " . REPORT_LIVE_INVOICES . " AND i.issue_date <= :d",
        [':c' => $customerId, ':d' => $asOf]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  A supplier's account
 * ──────────────────────────────────────────────────────────── */

/**
 * Every movement on one supplier's account over a period, in date
 * order with a running balance — the mirror of the customer
 * statement, and the document you sit down with when the supplier
 * sends theirs and the two do not agree.
 *
 * Three things move a supplier's account:
 *
 *     goods received      increases what we owe
 *     goods sent back     decreases it   (debit note)
 *     money paid          decreases it
 *
 * so the columns are the same shape as the customer statement,
 * pointing the other way. `debit` is what the supplier charged us,
 * `credit` is what came off.
 *
 * Values are net of VAT throughout, because `grns.total_amount` is
 * the cost of the goods with the tax recorded alongside it, and
 * `debit_notes.total_refund_amount` is quantity × unit cost on the
 * same basis. Input VAT is claimed on the VAT return, not settled
 * through the supplier's account.
 */
function report_supplier_statement(int $supplierId, string $from, string $to): array
{
    if (!table_exists('grns')) {
        return ['opening' => 0.0, 'entries' => [], 'closing' => 0.0];
    }

    // Same-day movements are ordered by what they are: the goods
    // arriving, then whatever came off them. See the customer
    // statement above for what sorting on the name instead did.
    $parts = [
        // The supplier's own invoice number is what they will quote
        // when querying a line, so it leads when we have it.
        "SELECT g.receipt_date AS entry_date, 'receipt' AS kind, 1 AS sort_rank,
                g.grn_number || COALESCE(' · ' || NULLIF(TRIM(g.supplier_invoice_no), ''), '') AS reference,
                g.total_amount AS debit, 0 AS credit, g.grn_id AS doc_id
           FROM grns g
          WHERE g.supplier_id = :s AND g.status <> 'cancelled'
            AND g.receipt_date BETWEEN :f AND :t",
    ];

    if (table_exists('purchase_payments')) {
        // Payments hang off the order, not the supplier, so the
        // order is how they are joined and what they are labelled by.
        $parts[] =
        "SELECT pp.payment_date, 'payment', 3,
                po.po_number || ' · ' || " . report_method_label_sql('pp.method') . "
                  || COALESCE(' ' || NULLIF(TRIM(pp.reference), ''), ''),
                0, pp.amount, pp.payment_id
           FROM purchase_payments pp
           JOIN purchase_orders po ON po.po_id = pp.po_id
          WHERE po.supplier_id = :s AND po.status <> 'cancelled'
            AND pp.payment_date BETWEEN :f AND :t";
    }

    if (table_exists('debit_notes')) {
        // Same rule the payables figures use: everything not
        // cancelled, because raising the note is what takes the
        // stock off the shelf.
        $parts[] =
        "SELECT dn.issue_date, 'debit_note', 2,
                dn.debit_note_number || COALESCE(' · ' || g.grn_number, ''),
                0, dn.total_refund_amount, dn.debit_note_id
           FROM debit_notes dn
           LEFT JOIN grns g ON g.grn_id = dn.grn_id
          WHERE dn.supplier_id = :s AND dn.status <> 'cancelled'
            AND dn.issue_date BETWEEN :f AND :t";
    }

    $rows = db_all(
        implode(' UNION ALL ', $parts) . ' ORDER BY entry_date, sort_rank',
        [':s' => $supplierId, ':f' => $from, ':t' => $to]
    );

    $opening = report_supplier_balance($supplierId, date('Y-m-d', strtotime($from . ' -1 day')));

    $running = $opening;
    foreach ($rows as $k => $r) {
        $running += (float) $r['debit'] - (float) $r['credit'];
        $rows[$k]['balance'] = $running;
    }

    return ['opening' => $opening, 'entries' => $rows, 'closing' => $running];
}

/**
 * What one supplier's account stood at, as at a date.
 *
 *     received − returned − paid
 *
 * across every one of their orders, **not** clamped at zero per
 * order the way the payables report and the daily position clamp
 * it. A statement is a ledger: the closing figure has to be the
 * entries added up, or the document contradicts itself.
 *
 * The two therefore differ whenever one order with this supplier
 * is prepaid while another is owed — payables reports the debt and
 * carries the prepayment separately as an asset, while the account
 * nets them off. The statement page says so where it shows both.
 */
function report_supplier_balance(int $supplierId, string $asOf): float
{
    if (!table_exists('grns')) {
        return 0.0;
    }

    $received = (float) db_value(
        "SELECT COALESCE(SUM(g.total_amount), 0) FROM grns g
          WHERE g.supplier_id = :s AND g.status <> 'cancelled' AND g.receipt_date <= :d",
        [':s' => $supplierId, ':d' => $asOf]
    );

    $paid = table_exists('purchase_payments') ? (float) db_value(
        "SELECT COALESCE(SUM(pp.amount), 0)
           FROM purchase_payments pp
           JOIN purchase_orders po ON po.po_id = pp.po_id
          WHERE po.supplier_id = :s AND po.status <> 'cancelled' AND pp.payment_date <= :d",
        [':s' => $supplierId, ':d' => $asOf]
    ) : 0.0;

    $returned = table_exists('debit_notes') ? (float) db_value(
        "SELECT COALESCE(SUM(dn.total_refund_amount), 0) FROM debit_notes dn
          WHERE dn.supplier_id = :s AND dn.status <> 'cancelled' AND dn.issue_date <= :d",
        [':s' => $supplierId, ':d' => $asOf]
    ) : 0.0;

    return $received - $returned - $paid;
}

/* ─────────────────────────────────────────────────────────────
 *  Stock
 * ──────────────────────────────────────────────────────────── */

/**
 * Every active product with stock or a shortfall, valued at
 * cost and at what it would sell for.
 *
 * `low_quantity_threshold` has sat on the products table since
 * the beginning without anything ever reading it. It is the
 * reorder line, and this is where it finally earns its keep.
 */
/**
 * What the stock you can actually sell is worth.
 *
 * ── What counts as saleable ─────────────────────────────────
 * There is no is_saleable column, so the definition has to come
 * from what the data already says:
 *
 *     active, and carrying a selling price
 *
 * A product with no selling price is not something the business
 * sells — a consumable, a fitting used on a job, a line set up
 * and never priced. Counting it in "what the stock is worth"
 * inflates a figure that is supposed to answer *if we sold what
 * is on the shelf, what would it come to*.
 *
 * Stock is still stock, so the excluded items are counted and
 * shown rather than silently dropped: a valuation that quietly
 * omits things is worse than one that says what it omitted.
 *
 * Valued two ways, because they answer different questions:
 *
 *   at cost    what the business paid — the balance-sheet figure,
 *              and the one the daily position uses
 *   at retail  what it would fetch — the sales-potential figure
 *
 * The gap between them is margin not yet earned. It is not profit
 * and must never be added to one: nothing has been sold.
 */
const REPORT_SALEABLE_SQL = "p.is_active AND p.selling_price > 0";

/**
 * Holding stock. The tolerance is there because the quantity is
 * NUMERIC and a part-unit line — half a metre of cable — must still
 * count, while a rounding crumb left by a return must not.
 */
const REPORT_HELD_SQL = "p.stock_quantity > 0.0005";

/**
 * What the stock on hand cost, receipt by receipt.
 *
 * The working behind `products.cost_price`. Under FIFO the stock
 * still on the shelf is the most recent receipts, so this walks
 * them newest-first until they account for the quantity held —
 * "15 at 3,400 and 10 at 2,600" — which is the answer to "why is
 * this product costed at 3,080?".
 *
 * The last entry may be an uncovered remainder: stock that was
 * entered by hand at go-live and has no purchase behind it. It is
 * marked as such rather than being dressed up as a receipt.
 */
function product_cost_layers(int $productId): array
{
    if (!table_exists('grn_items')) {
        return [];
    }

    $product = db_one(
        "SELECT stock_quantity, cost_price FROM products WHERE product_id = :p",
        [':p' => $productId]
    );
    if (!$product) {
        return [];
    }

    $remaining = max(0.0, (float) $product['stock_quantity']);
    $layers    = [];

    foreach (db_all(
        "SELECT g.grn_number, g.receipt_date, gi.unit_cost,
                gi.quantity_received - COALESCE((
                    SELECT SUM(dni.quantity_returned)
                      FROM debit_note_items dni
                      JOIN debit_notes dn ON dn.debit_note_id = dni.debit_note_id
                     WHERE dni.grn_item_id = gi.grn_item_id
                       AND dn.status <> 'cancelled'), 0) AS quantity
           FROM grn_items gi
           JOIN grns g ON g.grn_id = gi.grn_id
          WHERE gi.product_id = :p AND g.status <> 'cancelled'
          ORDER BY g.receipt_date DESC, g.grn_id DESC, gi.grn_item_id DESC",
        [':p' => $productId]
    ) as $row) {
        if ($remaining <= 0.0000001) {
            break;
        }
        $qty = (float) $row['quantity'];
        if ($qty <= 0) {
            continue;               // the whole line went back to the supplier
        }
        $take       = min($remaining, $qty);
        $remaining -= $take;
        $layers[]   = [
            'quantity'     => $take,
            'unit_cost'    => (float) $row['unit_cost'],
            'value'        => $take * (float) $row['unit_cost'],
            'reference'    => $row['grn_number'],
            'receipt_date' => $row['receipt_date'],
            'covered'      => true,
        ];
    }

    if ($remaining > 0.0000001) {
        $layers[] = [
            'quantity'     => $remaining,
            'unit_cost'    => (float) $product['cost_price'],
            'value'        => $remaining * (float) $product['cost_price'],
            'reference'    => 'Opening stock',
            'receipt_date' => null,
            'covered'      => false,
        ];
    }

    return $layers;
}

/**
 * "15 × 3,400.00 + 10 × 2,600.00", for a tooltip or a caption.
 *
 * Runs at the same price are added together. Six receipts of one
 * unit each at 1,500 are one fact — "6 × 1,500" — and listing them
 * separately buries the price change the reader is looking for.
 */
function product_cost_layers_label(array $layers): string
{
    $merged = [];
    foreach ($layers as $l) {
        $last = $merged ? array_key_last($merged) : null;
        if ($last !== null
            && abs($merged[$last]['unit_cost'] - $l['unit_cost']) < 0.005
            && $merged[$last]['covered'] === $l['covered']) {
            $merged[$last]['quantity'] += $l['quantity'];
            continue;
        }
        $merged[] = $l;
    }

    return implode(' + ', array_map(
        fn($l) => num($l['quantity'], 0) . ' × ' . num($l['unit_cost'], 2)
                . ($l['covered'] ? '' : ' (no receipt)'),
        $merged
    ));
}

function report_stock_value(string $scope = 'saleable', bool $heldOnly = false): array
{
    $where = $scope === 'excluded'
        ? "NOT (" . REPORT_SALEABLE_SQL . ")"
        : REPORT_SALEABLE_SQL;

    // Outside the saleable test, not inside it. A service is not
    // excluded stock — a valuation that listed "Installation" under
    // "counted but not valued" would be answering a question nobody
    // asked. It is not stock at all, so it is not on this page.
    $where = goods_only_sql() . " AND (" . $where . ")";

    if ($heldOnly) {
        $where .= " AND " . REPORT_HELD_SQL;
    }

    return db_all(
        "SELECT p.product_id, p.name, p.sku, p.stock_quantity,
                p.cost_price, p.selling_price,
                (p.stock_quantity * p.cost_price)    AS value_at_cost,
                (p.stock_quantity * p.selling_price) AS value_at_retail,
                (p.stock_quantity * (p.selling_price - p.cost_price)) AS margin,
                CASE WHEN p.selling_price > 0
                     THEN ROUND((p.selling_price - p.cost_price) / p.selling_price * 100, 1)
                     ELSE NULL END AS margin_pct,
                p.is_active, c.name AS category, b.name AS brand,
                u.abbreviation AS uom
           FROM products p
           LEFT JOIN categories c ON c.category_id = p.category_id
           LEFT JOIN brands b ON b.brand_id = p.brand_id
           LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
          WHERE $where
          ORDER BY (p.stock_quantity * p.cost_price) DESC, p.name"
    );
}

/**
 * The same valuation, one line per category.
 *
 * Takes the same `$heldOnly` switch as the product list for a
 * reason: the two panels sit on one page under one control, and a
 * category summary counting lines the table below it is not showing
 * is a page arguing with itself.
 */
function report_stock_value_by_category(bool $heldOnly = false): array
{
    $where = goods_only_sql() . " AND " . REPORT_SALEABLE_SQL
           . ($heldOnly ? " AND " . REPORT_HELD_SQL : '');

    return db_all(
        "SELECT COALESCE(c.name, 'Uncategorised') AS category,
                COUNT(*)                                  AS products,
                SUM(p.stock_quantity)                     AS quantity,
                SUM(p.stock_quantity * p.cost_price)      AS value_at_cost,
                SUM(p.stock_quantity * p.selling_price)   AS value_at_retail,
                SUM(p.stock_quantity * (p.selling_price - p.cost_price)) AS margin
           FROM products p
           LEFT JOIN categories c ON c.category_id = p.category_id
          WHERE $where
          GROUP BY COALESCE(c.name, 'Uncategorised')
          ORDER BY SUM(p.stock_quantity * p.cost_price) DESC"
    );
}

/** Totals across a set of valuation rows. */
function report_stock_value_totals(array $rows): array
{
    $t = ['products' => count($rows), 'quantity' => 0.0,
          'at_cost' => 0.0, 'at_retail' => 0.0, 'margin' => 0.0];
    foreach ($rows as $r) {
        $t['quantity']  += (float) ($r['stock_quantity'] ?? $r['quantity'] ?? 0);
        $t['at_cost']   += (float) $r['value_at_cost'];
        $t['at_retail'] += (float) $r['value_at_retail'];
        $t['margin']    += (float) $r['margin'];
    }
    return $t;
}

function report_stock(string $filter = 'all'): array
{
    // Services hold no stock by definition, so every one of them
    // would report as "out" and land at the top of a reorder list
    // that cannot be acted on. You do not reorder installation.
    $where = ["p.is_active", goods_only_sql()];
    if ($filter === 'low') {
        $where[] = "p.stock_quantity <= p.low_quantity_threshold";
    } elseif ($filter === 'out') {
        $where[] = "p.stock_quantity <= 0";
    } elseif ($filter === 'held') {
        $where[] = "p.stock_quantity > 0";
    }

    return db_all(
        "SELECT p.product_id, p.name, p.sku, p.stock_quantity, p.low_quantity_threshold,
                p.cost_price, p.selling_price,
                (p.stock_quantity * p.cost_price)    AS value_at_cost,
                (p.stock_quantity * p.selling_price) AS value_at_retail,
                c.name AS category, b.name AS brand,
                CASE
                    WHEN p.stock_quantity <= 0                             THEN 'out'
                    WHEN p.stock_quantity <= p.low_quantity_threshold      THEN 'low'
                    ELSE 'ok'
                END AS stock_state
           FROM products p
           LEFT JOIN categories c ON c.category_id = p.category_id
           LEFT JOIN brands b ON b.brand_id = p.brand_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY (p.stock_quantity <= p.low_quantity_threshold) DESC,
                   (p.stock_quantity * p.cost_price) DESC, p.name"
    );
}

/* ─────────────────────────────────────────────────────────────
 *  Tax
 * ──────────────────────────────────────────────────────────── */

/**
 * VAT charged and VAT paid over a period.
 *
 * Output tax is what was charged on invoices, less the tax on
 * credit notes — a sale reversed takes its VAT with it.
 *
 * Input tax is **what the supplier charged**, recorded on the
 * goods received note against their tax invoice. It used to be
 * apportioned off the purchase order instead, which was wrong in
 * a way that only ever went one direction: a purchase order is
 * our own document, its VAT is a forecast typed on a form that
 * opens on 16%, and a receipt from a supplier who charges no VAT
 * still produced input tax on the return. See migration 029.
 *
 * Expenses are not included: the table has no tax field, so
 * there is nothing to claim from. The page says so rather than
 * letting the figure look complete.
 */
function report_vat(string $from, string $to): array
{
    $output = (float) db_value(
        "SELECT COALESCE(SUM(i.tax_amount), 0) FROM invoices i
          WHERE " . REPORT_LIVE_INVOICES . " AND i.issue_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    );

    $outputCredited = table_exists('credit_notes') ? (float) db_value(
        "SELECT COALESCE(SUM(cn.tax_amount), 0) FROM credit_notes cn
          WHERE cn.status = 'approved' AND cn.issue_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    ) : 0.0;

    $input = (float) db_value(
        "SELECT COALESCE(SUM(g.tax_amount), 0)
           FROM grns g
          WHERE g.status <> 'cancelled' AND g.receipt_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    );

    // Services bought in. A logo design or a subcontracted install
    // is charged VAT like anything else, and until migration 043
    // there was nowhere to record it — so it was never claimed, and
    // the business declared more payable than it owed every month.
    //
    // Only lines carrying the supplier's invoice number count. That
    // is not bookkeeping fussiness: an input claim has to be
    // defended with the tax invoice it came from, and a figure with
    // no invoice behind it is exactly what an audit disallows.
    // Anything accepted without one is surfaced separately, by
    // report_vat_unclaimed().
    $input += (column_exists('purchase_order_items', 'tax_amount')
               && column_exists('purchase_order_items', 'supplier_invoice_no'))
        ? (float) db_value(
            "SELECT COALESCE(SUM(poi.tax_amount), 0)
               FROM purchase_order_items poi
               JOIN purchase_orders po ON po.po_id = poi.po_id
              WHERE poi.tax_amount IS NOT NULL
                AND COALESCE(TRIM(poi.supplier_invoice_no), '') <> ''
                AND po.status <> 'cancelled'
                AND COALESCE(poi.supplier_invoice_date, poi.accepted_at) BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
          )
        : 0.0;

    $netOutput = $output - $outputCredited;
    return [
        'output'          => $output,
        'output_credited' => $outputCredited,
        'output_net'      => $netOutput,
        'input'           => $input,
        'payable'         => $netOutput - $input,
    ];
}

/**
 * The receipts behind the input tax figure.
 *
 * A total nobody can trace to documents is a total nobody can
 * defend, and input tax is the half of a VAT return that gets
 * asked about.
 */
function report_vat_input_receipts(string $from, string $to): array
{
    $goods = db_all(
        "SELECT g.grn_number AS document, g.receipt_date AS doc_date, g.supplier_invoice_no,
                g.total_amount, g.tax_rate, g.tax_amount,
                (g.total_amount + g.tax_amount) AS gross,
                po.po_number, 'Goods' AS kind,
                COALESCE(s.name, 'Supplier') AS supplier_name, s.tax_pin AS supplier_pin
           FROM grns g
           LEFT JOIN purchase_orders po ON po.po_id = g.po_id
           LEFT JOIN suppliers s ON s.supplier_id = g.supplier_id
          WHERE g.status <> 'cancelled' AND g.tax_amount > 0
            AND g.receipt_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    );

    // Service lines carry their own bill, so each is its own receipt.
    $services = (column_exists('purchase_order_items', 'tax_amount')
                 && column_exists('purchase_order_items', 'supplier_invoice_no'))
        ? db_all(
            "SELECT p.name AS document,
                    COALESCE(poi.supplier_invoice_date, poi.accepted_at) AS doc_date,
                    poi.supplier_invoice_no,
                    ROUND(poi.quantity_ordered * poi.unit_price, 2) AS total_amount,
                    CASE WHEN ROUND(poi.quantity_ordered * poi.unit_price, 2) > 0
                         THEN ROUND(poi.tax_amount
                              / ROUND(poi.quantity_ordered * poi.unit_price, 2), 4)
                         ELSE 0 END AS tax_rate,
                    poi.tax_amount,
                    ROUND(poi.quantity_ordered * poi.unit_price, 2) + poi.tax_amount AS gross,
                    po.po_number, 'Service' AS kind,
                    COALESCE(s.name, 'Supplier') AS supplier_name, s.tax_pin AS supplier_pin
               FROM purchase_order_items poi
               JOIN purchase_orders po ON po.po_id = poi.po_id
               JOIN products p ON p.product_id = poi.product_id
               LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
              WHERE poi.tax_amount > 0
                AND COALESCE(TRIM(poi.supplier_invoice_no), '') <> ''
                AND po.status <> 'cancelled'
                AND COALESCE(poi.supplier_invoice_date, poi.accepted_at) BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
          )
        : [];

    $rows = array_merge($goods, $services);
    usort($rows, fn($a, $b) => [$a['doc_date'], $a['document']] <=> [$b['doc_date'], $b['document']]);
    return $rows;
}

/**
 * Receipts whose order expected VAT but which recorded none.
 *
 * Either the supplier did not charge it — in which case there is
 * nothing to claim and the order was simply left on the form's
 * default — or their tax invoice never made it onto the receipt,
 * in which case a real claim is being missed. The two cannot be
 * told apart from the data, so both are shown and the person
 * filing decides.
 */
function report_vat_unclaimed(string $from, string $to): array
{
    $goods = db_all(
        "SELECT g.grn_number AS document, g.receipt_date AS doc_date, g.supplier_invoice_no,
                g.total_amount, po.po_number, po.tax_rate AS po_tax_rate, 'Goods' AS kind,
                ROUND(po.tax_amount * (g.total_amount / NULLIF(po.subtotal, 0)), 2) AS po_would_claim,
                COALESCE(s.name, 'Supplier') AS supplier_name, s.tax_pin AS supplier_pin
           FROM grns g
           JOIN purchase_orders po ON po.po_id = g.po_id
           LEFT JOIN suppliers s ON s.supplier_id = g.supplier_id
          WHERE g.status <> 'cancelled' AND g.tax_amount = 0
            AND COALESCE(po.tax_amount, 0) > 0 AND COALESCE(po.subtotal, 0) > 0
            AND g.receipt_date BETWEEN :f AND :t",
        [':f' => $from, ':t' => $to]
    );

    // Service work accepted with no bill recorded against it. The
    // order expected VAT, so either the supplier charged it and
    // their invoice never got entered — a real claim going unmade —
    // or they did not charge it. Same ambiguity as the goods side,
    // shown the same way, and the person filing decides.
    $services = column_exists('purchase_order_items', 'tax_amount')
        ? db_all(
            "SELECT p.name AS document, poi.accepted_at AS doc_date,
                    poi.supplier_invoice_no,
                    ROUND(poi.quantity_ordered * poi.unit_price, 2) AS total_amount,
                    po.po_number, po.tax_rate AS po_tax_rate, 'Service' AS kind,
                    ROUND(poi.quantity_ordered * poi.unit_price
                          * COALESCE(po.tax_rate, 0), 2) AS po_would_claim,
                    COALESCE(s.name, 'Supplier') AS supplier_name, s.tax_pin AS supplier_pin
               FROM purchase_order_items poi
               JOIN purchase_orders po ON po.po_id = poi.po_id
               JOIN products p ON p.product_id = poi.product_id
               LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
              WHERE poi.accepted_at IS NOT NULL
                AND poi.tax_amount IS NULL
                AND COALESCE(po.tax_rate, 0) > 0
                AND po.status <> 'cancelled'
                AND poi.accepted_at BETWEEN :f AND :t",
            [':f' => $from, ':t' => $to]
          )
        : [];

    $rows = array_merge($goods, $services);
    usort($rows, fn($a, $b) => [$a['doc_date'], $a['document']] <=> [$b['doc_date'], $b['document']]);
    return $rows;
}

/* ─────────────────────────────────────────────────────────────
 *  Sales analysis
 * ──────────────────────────────────────────────────────────── */

/** Best-selling products over a period, by value. */
function report_sales_by_product(
    string $from,
    string $to,
    ?int $limit = null,
    string $order = 'revenue'
): array {
    $cap = $limit !== null ? 'LIMIT ' . max(1, $limit) : '';

    /*  Revenue answers "what sells". Profit answers "what is worth
     *  selling", and they are not the same list: a line can turn over
     *  a fortune at a margin that does not cover handling it. Sorting
     *  in SQL rather than in PHP so the caller can still cap the list
     *  and get the right end of it. */
    $sort = match ($order) {
        'profit'  => 'profit DESC',
        'worst'   => 'profit ASC',
        'margin'  => 'margin_pct DESC NULLS LAST',
        'units'   => 'quantity DESC',
        'name'    => 'p.name ASC',
        default   => 'revenue DESC',
    };
    $credits = table_exists('credit_note_items');

    // Line value is the generated `subtotal`, not a second expression
    // repeated here. The old one read the line discount as a
    // percentage — quantity × price × (1 − discount/100) — when the
    // column holds an amount, so any discounted line was reported at
    // the wrong revenue.
    $creditCte = $credits ? "
        credited AS (
            SELECT cni.product_id,
                   -- Goods only come back on a return. A money-only
                   -- credit note is a price correction: the customer
                   -- keeps the item, so the quantity sold stands and
                   -- only the money comes off.
                   SUM(CASE WHEN cn.return_scope <> 'none' THEN cni.quantity ELSE 0 END) AS quantity,
                   SUM(cni.subtotal) AS revenue
              FROM credit_note_items cni
              JOIN credit_notes cn ON cn.credit_note_id = cni.credit_note_id
             WHERE cn.status = 'approved' AND cn.issue_date BETWEEN :f2 AND :t2
             GROUP BY cni.product_id
        )," : '';

    $creditJoin = $credits
        ? "LEFT JOIN credited c ON c.product_id = p.product_id"
        : '';
    $credQty = $credits ? "COALESCE(c.quantity, 0)" : '0';
    $credRev = $credits ? "COALESCE(c.revenue, 0)"  : '0';

    $params = [':f' => $from, ':t' => $to];
    if ($credits) {
        $params[':f2'] = $from;
        $params[':t2'] = $to;
    }

    /*  What the goods cost on the day they sold, where migration 049
     *  recorded it. Before that this was quantity × today's cost
     *  price, so buying the next batch cheaper silently improved last
     *  year's margin — a report that changed while the invoices did
     *  not. Lines older than 049 still fall back, and estimated_lines
     *  says how many, so the page can admit it rather than average
     *  the two silently. */
    $unitCost  = ledger_unit_cost_sql();
    $estimated = ledger_cost_estimated_sql();

    return db_all(
        "WITH $creditCte
         sold AS (
            SELECT ii.product_id,
                   SUM(ii.quantity)                 AS quantity,
                   SUM(ii.subtotal)                 AS revenue,
                   -- Per line, because two batches of one product can
                   -- have cost different amounts.
                   SUM(ii.quantity * $unitCost)     AS cost,
                   COUNT(*) FILTER (WHERE $estimated) AS estimated_lines
              FROM invoice_items ii
              JOIN invoices i ON i.invoice_id = ii.invoice_id
              JOIN products p ON p.product_id = ii.product_id
             WHERE " . REPORT_LIVE_INVOICES . " AND i.issue_date BETWEEN :f AND :t
             GROUP BY ii.product_id
         ),
         net AS (
            SELECT p.product_id, p.name, p.sku,
                   COALESCE(s.quantity, 0) - $credQty AS quantity,
                   COALESCE(s.revenue, 0)  - $credRev AS revenue,
                   COALESCE(s.revenue, 0)             AS invoiced,
                   $credRev                           AS credited,
                   COALESCE(s.estimated_lines, 0)     AS estimated_lines,
                   -- Goods that came back were not sold, so their cost
                   -- comes off too — at what the sale went out at,
                   -- which is this product's average over the period,
                   -- not today's price.
                   GREATEST(COALESCE(s.cost, 0) - ($credQty * COALESCE(
                       CASE WHEN COALESCE(s.quantity, 0) > 0
                            THEN s.cost / s.quantity END, p.cost_price)), 0) AS cost
              FROM products p
              LEFT JOIN sold s ON s.product_id = p.product_id
              $creditJoin
             WHERE s.product_id IS NOT NULL" . ($credits ? " OR c.product_id IS NOT NULL" : '') . "
         )
         -- Wrapped so profit and margin exist as columns: ORDER BY can
         -- name a select-list alias, but not one built from two others
         -- in the same select list.
         SELECT n.*,
                n.revenue - n.cost AS profit,
                CASE WHEN ABS(n.revenue) > 0.005
                     THEN (n.revenue - n.cost) / n.revenue * 100 END AS margin_pct
           FROM net n
           JOIN products p ON p.product_id = n.product_id
          ORDER BY $sort
          $cap",
        $params
    );
}

/** Biggest customers over a period, by value invoiced. */
function report_sales_by_customer(string $from, string $to, ?int $limit = null): array
{
    $cap = $limit !== null ? 'LIMIT ' . max(1, $limit) : '';
    // Ex-VAT throughout. The tax on an invoice is collected for the
    // revenue authority and was never the business's money, and this
    // column used to include it while the cards beside it did not —
    // so the two could never be added up together.
    $creditJoin = table_exists('credit_notes') ? "
           LEFT JOIN (SELECT cn.customer_id,
                             SUM(cn.total_amount - cn.tax_amount) AS credited
                        FROM credit_notes cn
                       WHERE cn.status = 'approved'
                         AND cn.issue_date BETWEEN :f2 AND :t2
                       GROUP BY cn.customer_id) cr ON cr.customer_id = i.customer_id" : '';
    $credited = table_exists('credit_notes') ? "COALESCE(MAX(cr.credited), 0)" : '0';

    $params = [':f' => $from, ':t' => $to];
    if (table_exists('credit_notes')) {
        $params[':f2'] = $from;
        $params[':t2'] = $to;
    }

    return db_all(
        "SELECT i.customer_id,
                " . customer_name_sql('c') . " AS name,
                COUNT(*) AS invoices,
                SUM(i.total_amount - i.tax_amount) AS invoiced,
                $credited AS credited,
                SUM(i.total_amount - i.tax_amount) - $credited AS revenue
           FROM invoices i
           LEFT JOIN customers c ON c.customer_id = i.customer_id
           $creditJoin
          WHERE " . REPORT_LIVE_INVOICES . " AND i.issue_date BETWEEN :f AND :t
          GROUP BY i.customer_id, name
          ORDER BY revenue DESC
          $cap",
        $params
    );
}
