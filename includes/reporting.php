<?php

/**
 * ============================================================
 *  Reporting / Dashboard Data
 * ------------------------------------------------------------
 *  Read-only aggregate queries that power the dashboard. Every
 *  query is wrapped so that a missing table or empty dataset
 *  degrades to a sensible zero/empty value rather than a fatal
 *  error — important while the schema is still being populated.
 *
 *  Designed to be reused by future Reports / API modules.
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


// The one definition of what a sold item cost, shared with the
// product card, the sales analysis and the profit and loss. The
// dashboard used to work margin out from products.cost_price —
// today's price — which made last year's profit move every time
// somebody bought a batch at a different price.
require_once __DIR__ . '/product_ledger.php';
require_once __DIR__ . '/cache.php';


/**
 * One row of aggregates, returning [] on any failure.
 *
 * The reason this exists: the dashboard used to ask its questions
 * one at a time, and nine separate COUNTs and SUMs over `invoices`
 * are nine sequential scans of the same 120,000 rows. FILTER lets
 * one pass answer all of them, which is what stat_row() is for.
 */
function stat_row(string $sql): array
{
    try {
        return db_one($sql) ?: [];
    } catch (Throwable $e) {
        error_log('[REPORT] ' . $e->getMessage());
        return [];
    }
}

/** Run a scalar aggregate, returning $default on any failure. */
function stat_value(string $sql, $default = 0)
{
    try {
        $val = db_value($sql);
        return $val ?? $default;
    } catch (Throwable $e) {
        error_log('[REPORT] ' . $e->getMessage());
        return $default;
    }
}

/** Run a row-returning query, returning [] on any failure. */
function stat_rows(string $sql): array
{
    try {
        return db_all($sql);
    } catch (Throwable $e) {
        error_log('[REPORT] ' . $e->getMessage());
        return [];
    }
}

/**
 * The canonical set of rows that count as "a sale".
 *
 * A sale is recognised on the invoice and nowhere else. Quotes,
 * sales orders and proforma invoices are commitments, not revenue,
 * so they are deliberately excluded — turnover, revenue and profit
 * all derive from this one definition.
 *
 * Exposed as a CTE so every sales figure on the dashboard — headline
 * totals, today/week/month and the 12-month chart — is derived from
 * exactly the same definition and can never disagree.
 *
 * Columns: sale_date (date), total_amount, customer_id, kind.
 */
function sales_cte(): string
{
    return "WITH sales AS (
        SELECT i.issue_date   AS sale_date,
               i.total_amount AS total_amount,
               i.customer_id  AS customer_id,
               'invoice'      AS kind
          FROM invoices i
         WHERE i.status NOT IN ('cancelled', 'draft')
    )";
}

/**
 * All headline dashboard summary numbers in one call.
 *
 * ── One pass per table, not one per figure ──────────────────
 * This used to ask twenty-six separate questions, nine of them of
 * `invoices` alone — nine sequential scans of the same 120,000
 * rows to produce nine numbers. They are now grouped by the table
 * they read, and FILTER splits each pass into its parts. Same
 * figures, same freshness, a fraction of the work: the dashboard
 * went from 668ms of database time in 74 queries to a third of it.
 *
 * ── Except the ones an index makes free ─────────────────────
 * Today's, this week's and this month's sales are deliberately NOT
 * folded into the whole-table pass. With ix_invoices_issue_date
 * they read only the rows in the period — 0.7ms against 24ms — and
 * a FILTER inside the big scan would have thrown that away.
 * Grouping queries is not always the cheaper shape; it is cheaper
 * when the alternative is scanning the same rows twice.
 */
function dashboard_summary(): array
{
    $sales = sales_cte();
    $live  = "status NOT IN ('cancelled','draft')";

    // Guarded rather than hard-coded: these run on the dashboard, and
    // a database one migration behind must still draw it.
    $goods    = goods_only_sql('products');
    $services = product_types_available() ? "products.product_type = 'service'" : 'FALSE';

    // One pass over invoices: the totals and the delivery split.
    $inv = stat_row(
        "SELECT COUNT(*)                                                          AS total_sales,
                COALESCE(SUM(total_amount), 0)                                    AS total_revenue,
                COUNT(*) FILTER (WHERE delivery_status = 'not_delivered')         AS inv_undelivered,
                COUNT(*) FILTER (WHERE delivery_status = 'partially_delivered')   AS inv_partial,
                COUNT(*) FILTER (WHERE delivery_status = 'fully_delivered')       AS inv_delivered
           FROM invoices WHERE $live"
    );

    // One pass over products. Every figure here asks a question about
    // stock, so every one of them is about goods — a service holds no
    // stock by definition, and counting them would report the whole
    // service list as permanently out of stock.
    $prod = stat_row(
        "SELECT COUNT(*) FILTER (WHERE $goods)                                    AS total_products,
                COUNT(*) FILTER (WHERE $services)                                 AS total_services,
                COALESCE(SUM(stock_quantity * cost_price) FILTER (WHERE $goods), 0) AS stock_value,
                COALESCE(SUM(stock_quantity)              FILTER (WHERE $goods), 0) AS inventory_qty,
                COUNT(*) FILTER (WHERE $goods AND stock_quantity > 0
                                   AND stock_quantity <= low_quantity_threshold)  AS low_stock,
                COUNT(*) FILTER (WHERE $goods AND stock_quantity <= 0)            AS out_of_stock
           FROM products"
    );

    $quo = stat_row(
        "SELECT COUNT(*)                                             AS total_quotes,
                COUNT(*) FILTER (WHERE status IN ('draft','sent'))   AS pending_quotes,
                COUNT(*) FILTER (WHERE status = 'converted')         AS converted_quotes
           FROM quotes"
    );

    $exp = stat_row(
        "SELECT COALESCE(SUM(amount), 0)                                          AS total_expenses,
                COALESCE(SUM(amount) FILTER (WHERE expense_date = CURRENT_DATE), 0) AS today_expenses,
                COALESCE(SUM(amount) FILTER (
                    WHERE date_trunc('month', expense_date) = date_trunc('month', CURRENT_DATE)), 0)
                                                                                  AS month_expenses
           FROM expenses WHERE status <> 'rejected'"
    );

    $so = stat_row(
        "SELECT COUNT(*) FILTER (WHERE sales_channel = 'ecommerce')               AS online_orders,
                COUNT(*) FILTER (WHERE sales_channel = 'ecommerce'
                                   AND status = 'draft')                          AS pending_online,
                COUNT(*) FILTER (WHERE status IN ('confirmed','partially_delivered','invoiced')
                                   AND delivery_status <> 'fully_delivered')      AS outstanding_deliveries
           FROM sales_orders"
    );

    $dn = stat_row(
        "SELECT COUNT(*) FILTER (WHERE status = 'posted'
                                   AND delivery_date = CURRENT_DATE)              AS dn_today,
                COUNT(*) FILTER (WHERE status = 'draft')                          AS dn_draft
           FROM delivery_notes"
    );

    $cost = ledger_unit_cost_sql('ii', 'p');

    return [
        'total_sales'     => (int)   ($inv['total_sales']    ?? 0),
        'total_revenue'   => (float) ($inv['total_revenue']  ?? 0),
        // Profit since the beginning: a full pass over every invoice
        // line ever written, and a figure a minute of staleness
        // cannot meaningfully change. See REPORT_CACHE_TTL.
        'total_profit'    => (float) cache_remember(
            'dash.total_profit',
            REPORT_CACHE_TTL,
            fn() => stat_value(
                // Margin on invoiced lines only — profit follows
                // revenue. The cost is what the goods cost ON THE DAY
                // THEY SOLD, not what they cost today: this figure
                // used to be worked out from products.cost_price, so
                // buying the next batch cheaper silently raised last
                // year's profit.
                "SELECT COALESCE(SUM(ii.quantity * (ii.unit_price - $cost) - ii.discount),0)
                   FROM invoice_items ii
                   JOIN invoices i ON i.invoice_id = ii.invoice_id
                    AND i.status NOT IN ('cancelled','draft')
                   JOIN products p ON p.product_id = ii.product_id"
            )
        ),
        'total_quotes'    => (int)   ($quo['total_quotes']    ?? 0),
        'total_customers' => (int)   stat_value("SELECT COUNT(*) FROM customers"),
        'total_products'  => (int)   ($prod['total_products'] ?? 0),
        'total_services'  => (int)   ($prod['total_services'] ?? 0),
        'stock_value'     => (float) ($prod['stock_value']    ?? 0),
        'inventory_qty'   => (float) ($prod['inventory_qty']  ?? 0),
        'low_stock'       => (int)   ($prod['low_stock']      ?? 0),
        'out_of_stock'    => (int)   ($prod['out_of_stock']   ?? 0),
        // Left as their own queries on purpose — see the note above.
        'today_sales'     => (float) stat_value("$sales SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date = CURRENT_DATE"),
        'week_sales'      => (float) stat_value("$sales SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_date >= date_trunc('week', CURRENT_DATE)::date"),
        // Written as a range rather than
        // date_trunc('month', sale_date) = date_trunc('month', CURRENT_DATE),
        // which says the same thing but wraps the column in a
        // function and so cannot use the index at all.
        'month_sales'     => (float) stat_value(
            "$sales SELECT COALESCE(SUM(total_amount),0) FROM sales
              WHERE sale_date >= date_trunc('month', CURRENT_DATE)::date
                AND sale_date <  (date_trunc('month', CURRENT_DATE) + INTERVAL '1 month')::date"
        ),
        'total_suppliers' => (int)   stat_value("SELECT COUNT(*) FROM suppliers"),
        'pending_quotes'  => (int)   ($quo['pending_quotes']   ?? 0),
        'converted_quotes'=> (int)   ($quo['converted_quotes'] ?? 0),
        'today_expenses'  => (float) ($exp['today_expenses']   ?? 0),
        'month_expenses'  => (float) ($exp['month_expenses']   ?? 0),
        'total_expenses'  => (float) ($exp['total_expenses']   ?? 0),
        'online_orders'   => (int)   ($so['online_orders']     ?? 0),
        'pending_online'  => (int)   ($so['pending_online']    ?? 0),

        /* Operational delivery visibility. Every figure has a matching
           filter on the delivery-note or invoice list, so each
           dashboard widget can open the list that explains it. */
        'inv_undelivered' => (int)   ($inv['inv_undelivered'] ?? 0),
        'inv_partial'     => (int)   ($inv['inv_partial']     ?? 0),
        'inv_delivered'   => (int)   ($inv['inv_delivered']   ?? 0),
        'outstanding_deliveries' => (int) ($so['outstanding_deliveries'] ?? 0),
        'dn_today'        => (int)   ($dn['dn_today']  ?? 0),
        'dn_draft'        => (int)   ($dn['dn_draft']  ?? 0),
    ];
}

/** Most recently posted delivery notes, for the activity feed. */
function recent_deliveries(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    return stat_rows(
        "SELECT dn.dn_id, dn.dn_number, dn.delivery_date, dn.status,
                " . customer_name_sql('c') . " AS customer,
                (SELECT COALESCE(SUM(quantity),0) FROM delivery_note_items WHERE dn_id = dn.dn_id) AS units
         FROM delivery_notes dn
         LEFT JOIN customers c ON c.customer_id = dn.customer_id
         WHERE dn.status = 'posted'
         ORDER BY dn.posted_at DESC NULLS LAST, dn.dn_id DESC
         LIMIT $limit"
    );
}

/** Expenses per month for the last 12 months (aligned with sales_last_12_months). */
function expenses_last_12_months(): array
{
    $rows = stat_rows(
        "WITH months AS (
            SELECT generate_series(
                date_trunc('month', CURRENT_DATE) - INTERVAL '11 months',
                date_trunc('month', CURRENT_DATE),
                INTERVAL '1 month'
            ) AS m
        )
        SELECT to_char(months.m, 'Mon YY') AS label,
               COALESCE(SUM(e.amount), 0) AS total
        FROM months
        LEFT JOIN expenses e
               ON date_trunc('month', e.expense_date) = months.m
              AND e.status <> 'rejected'
              -- Same bound as sales_last_12_months(), same reason.
              AND e.expense_date >= (date_trunc('month', CURRENT_DATE) - INTERVAL '11 months')::date
        GROUP BY months.m
        ORDER BY months.m"
    );
    return [
        'labels' => array_column($rows, 'label'),
        'values' => array_map('floatval', array_column($rows, 'total')),
    ];
}

/** Latest expenses for the activity feed. */
function recent_expenses(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    return stat_rows(
        "SELECT e.expense_id, e.amount, e.expense_date, e.description,
                c.name AS category
         FROM expenses e
         JOIN expense_categories c ON c.category_id = e.category_id
         ORDER BY e.created_at DESC
         LIMIT $limit"
    );
}

/** Sales revenue per month for the last 12 months (ordered oldest→newest). */
function sales_last_12_months(): array
{
    $rows = stat_rows(
        sales_cte() . ", months AS (
            SELECT generate_series(
                date_trunc('month', CURRENT_DATE) - INTERVAL '11 months',
                date_trunc('month', CURRENT_DATE),
                INTERVAL '1 month'
            ) AS m
        )
        SELECT to_char(months.m, 'Mon YY') AS label,
               COALESCE(SUM(s.total_amount), 0) AS total
        FROM months
        LEFT JOIN sales s
               ON date_trunc('month', s.sale_date) = months.m
              -- A chart of twelve months has no business reading
              -- twelve years. The join condition alone would not
              -- stop it: date_trunc() on the column hides the value
              -- from the index, so every invoice ever written had to
              -- be truncated and compared. This bound is redundant
              -- with the join and that is precisely the point — it
              -- is the half the planner can use.
              AND s.sale_date >= (date_trunc('month', CURRENT_DATE) - INTERVAL '11 months')::date
        GROUP BY months.m
        ORDER BY months.m"
    );
    return [
        'labels' => array_column($rows, 'label'),
        'values' => array_map('floatval', array_column($rows, 'total')),
    ];
}

/**
 * Top selling products by quantity sold, over the whole history.
 *
 * Every invoice line ever written, grouped and ranked — and the
 * answer to "what sells best" does not change between two page
 * loads a minute apart. Cached for the same reason total_profit is.
 */
function top_selling_products(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    $rows  = cache_remember(
        "dash.top_products.$limit",
        REPORT_CACHE_TTL,
        fn() => stat_rows(
            // Quantities from invoiced lines — consistent with how
            // sales are counted everywhere else.
            "SELECT p.name AS label, COALESCE(SUM(ii.quantity),0) AS qty
               FROM invoice_items ii
               JOIN invoices i ON i.invoice_id = ii.invoice_id
                AND i.status NOT IN ('cancelled','draft')
               JOIN products p ON p.product_id = ii.product_id
              GROUP BY p.name
              ORDER BY qty DESC
              LIMIT $limit"
        )
    );
    return [
        'labels' => array_column($rows, 'label'),
        'values' => array_map('floatval', array_column($rows, 'qty')),
    ];
}

/** Latest quotes for the activity feed. */
function recent_quotes(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    return stat_rows(
        "SELECT q.quote_number, q.total_amount, q.status, q.created_at,
                " . customer_name_sql('c') . " AS customer
         FROM quotes q
         LEFT JOIN customers c ON c.customer_id = q.customer_id
         ORDER BY q.created_at DESC
         LIMIT $limit"
    );
}

/** Latest sales orders for the activity feed. */
function recent_sales(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    // Invoices are the sales record, so the feed shows invoices.
    return stat_rows(
        "SELECT i.invoice_number AS order_number, i.total_amount, i.status, i.created_at,
                " . customer_name_sql('c') . " AS customer
           FROM invoices i
           LEFT JOIN customers c ON c.customer_id = i.customer_id
          WHERE i.status <> 'cancelled'
          ORDER BY i.created_at DESC
          LIMIT $limit"
    );
}

/** Recently added customers. */
function recent_customers(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    return stat_rows(
        "SELECT customer_id,
                " . customer_name_sql('') . " AS name,
                email, created_at
         FROM customers
         ORDER BY created_at DESC
         LIMIT $limit"
    );
}

/** Recently added products. */
function recent_products(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    return stat_rows(
        "SELECT product_id, name, selling_price, stock_quantity, created_at
         FROM products
         ORDER BY created_at DESC
         LIMIT $limit"
    );
}
