<?php

/**
 * ============================================================
 *  Things the business needs telling about
 * ------------------------------------------------------------
 *  The system already knew all of this. Stock had run out, an
 *  invoice had been overdue since March, a batch of cameras was
 *  going out of date in a fortnight — every one of those facts was
 *  sitting in a table, and none of them ever said anything. You had
 *  to think to go and look, which means you found out about the
 *  overdue invoice when the customer mentioned it.
 *
 *  ── One row per condition, not per sighting ─────────────────
 *  The scanner runs every few minutes. An invoice that has been
 *  overdue for six weeks must not produce six weeks of rows, so
 *  every rule hands back a `key` and the write is an upsert on it.
 *  The title is rewritten in place as the figures move, and the
 *  created_at stays at the moment the trouble started — which is
 *  the useful date, not the moment of the last scan.
 *
 *  ── And it takes itself back ────────────────────────────────
 *  A notification whose condition has stopped being true is
 *  resolved rather than deleted. Every row a rule writes is stamped
 *  with the moment it was written, and anything of that kind left
 *  carrying an older stamp is what the rule no longer returns — so
 *  it is resolved. Pay the invoice and the alert goes away by
 *  itself; nobody has to tidy up after it. The row stays, because
 *  "this went unpaid for six weeks" is worth being able to look up
 *  afterwards.
 *
 *  ── A bell, not a ledger ────────────────────────────────────
 *  Every rule here counts something that grows with the business,
 *  so every rule is capped (NOTIFY_ROWS_PER_KIND). The worst are
 *  named individually and the rest become one row — "78,275
 *  invoices are overdue, KES 21.7M outstanding" — pointing at the
 *  report that lists them properly. Nobody reads the seventy
 *  thousandth notification, and the application already has a
 *  receivables report for the question it would answer.
 *
 *  ── Rules are data ──────────────────────────────────────────
 *  Each one is a SELECT that returns key/title/body/url/severity,
 *  plus who it concerns. Adding an alert means adding a rule to the
 *  array in notification_rules(), and everything else — dedupe,
 *  resolution, the bell, the counter, the page — already works.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/functions.php';

/** How stale the notification list may get before a page load refreshes it. */
const NOTIFY_SCAN_EVERY_SECONDS = 600;      // ten minutes

/** How far ahead "soon" looks, in days. */
const NOTIFY_HORIZON_DAYS = 30;

/**
 * The most rows any one rule may open.
 *
 * Every rule here is a condition that grows with the business: one
 * notification per overdue invoice, per low-stock product, per
 * unpaid order. On a database holding four years of trading that
 * came to **78,310 open notifications**, which cost in three ways at
 * once —
 *
 *   - the bell sorted all of them on every page load, 82ms added to
 *     every single request in the application;
 *   - each scan wrote 78,310 upserts and then built a statement with
 *     78,310 placeholders to resolve what it had not returned;
 *   - and no human being has ever read the 78,310th notification, so
 *     the whole cost bought nothing.
 *
 * Past the cap a rule opens ONE row saying how many there are and
 * what they come to, pointing at the report that lists them
 * properly. A notification is a nudge; a list of everything owing is
 * a report, and the application already has one.
 */
const NOTIFY_ROWS_PER_KIND = 25;

/**
 * Is the notification system usable at all?
 *
 * The house rule is that code must survive being one migration
 * ahead of the database. Every entry point asks this first, so a
 * server that has pulled the code but not run the migrations gets
 * an application without a bell rather than a fatal error on every
 * page.
 */
function notifications_ready(): bool
{
    static $ready = null;
    return $ready ??= table_exists('notifications') && table_exists('notification_reads');
}

/** The signed-in user's id, or null. */
function notification_user_id(): ?int
{
    $u = current_user();
    return $u ? (int) $u['id'] : null;
}

/**
 * The rules.
 *
 * Each returns rows with: key, title, body, url, severity,
 * subject_id. `audience` and `subject_type` are properties of the
 * rule rather than the row.
 *
 * Optionally also:
 *
 *   worst    ORDER BY deciding which rows are worth naming
 *            individually when there are more than the cap. Without
 *            it the cap would keep whichever rows the planner
 *            happened to return, which is not a decision.
 *   amount   an expression the rule also selects, so the rollup can
 *            say what the rest come to and not only how many.
 *   rollup   ['title', 'body', 'url', 'severity'] for the single row
 *            that stands in for everything past the cap. `title` and
 *            `body` are sprintf templates over (count, amount).
 *
 * A rule with no `rollup` is simply truncated at the cap — right for
 * a condition where the count is not itself news.
 *
 * Every rule is wrapped in its own try/catch by the scanner, so a
 * rule that references a column a migration has not added yet takes
 * itself out rather than taking the whole scan down.
 */
function notification_rules(): array
{
    $horizon = NOTIFY_HORIZON_DAYS;

    return [

        /* ── Money owed to us ──────────────────────────────── */
        [
            'kind'         => 'invoice_overdue',
            'subject_type' => 'invoice',
            'audience'     => null,
            'sql' => "
                SELECT i.invoice_id AS subject_id,
                       'invoice_overdue:' || i.invoice_id                    AS key,
                       CASE WHEN CURRENT_DATE - i.due_date > 30
                            THEN 'critical' ELSE 'warning' END               AS severity,
                       'Invoice ' || i.invoice_number || ' is '
                           || (CURRENT_DATE - i.due_date) || ' days overdue' AS title,
                       " . customer_name_sql('c') . " || ' still owes '
                           || TO_CHAR(i.balance_due, 'FM999,999,990.00')     AS body,
                       'invoices/view.php?id=' || i.invoice_id               AS url,
                       i.balance_due                                         AS amount
                  FROM invoices i
                  LEFT JOIN customers c ON c.customer_id = i.customer_id
                 WHERE i.due_date < CURRENT_DATE
                   AND i.balance_due > 0.005
                   AND i.status NOT IN ('cancelled', 'draft')",
            // Biggest debt, oldest first: that is the order somebody
            // chasing money would work in.
            'worst'  => 'amount DESC, subject_id',
            'amount' => true,
            'rollup' => [
                'severity' => 'critical',
                'title'    => '%s invoices are overdue',
                'body'     => '%2$s outstanding in total — the aged receivables report lists them',
                'url'      => 'reports/receivables.php',
            ],
        ],
        [
            'kind'         => 'invoice_due_soon',
            'subject_type' => 'invoice',
            'audience'     => null,
            'sql' => "
                SELECT i.invoice_id AS subject_id,
                       'invoice_due_soon:' || i.invoice_id                   AS key,
                       'info'                                               AS severity,
                       'Invoice ' || i.invoice_number || ' falls due in '
                           || (i.due_date - CURRENT_DATE) || ' days'         AS title,
                       " . customer_name_sql('c') . " || ' — '
                           || TO_CHAR(i.balance_due, 'FM999,999,990.00')     AS body,
                       'invoices/view.php?id=' || i.invoice_id               AS url,
                       i.balance_due                                         AS amount
                  FROM invoices i
                  LEFT JOIN customers c ON c.customer_id = i.customer_id
                 WHERE i.due_date >= CURRENT_DATE
                   AND i.due_date <= CURRENT_DATE + 7
                   AND i.balance_due > 0.005
                   AND i.status NOT IN ('cancelled', 'draft')",
            'worst'  => 'amount DESC, subject_id',
            'amount' => true,
            'rollup' => [
                'severity' => 'info',
                'title'    => '%s invoices fall due this week',
                'body'     => '%2$s between them — the aged receivables report lists them',
                'url'      => 'reports/receivables.php',
            ],
        ],

        /* ── Stock ─────────────────────────────────────────── */
        [
            'kind'         => 'stock_out',
            'subject_type' => 'product',
            'audience'     => ROLE_MANAGER,
            'sql' => "
                SELECT p.product_id AS subject_id,
                       'stock_out:' || p.product_id                          AS key,
                       'critical'                                            AS severity,
                       p.name || ' is out of stock'                          AS title,
                       'SKU ' || COALESCE(NULLIF(p.sku, ''), '—')
                           || ' — nothing left to sell or install'           AS body,
                       'modules/inventory/view_products.php?q='
                           || COALESCE(p.sku, '')                            AS url
                  FROM products p
                 WHERE " . goods_only_sql('p') . "
                   AND p.is_active
                   AND p.stock_quantity <= 0",
            'worst'  => 'subject_id',
            'rollup' => [
                'severity' => 'critical',
                'title'    => '%s products are out of stock',
                'body'     => 'Nothing left to sell or install — the stock report lists them',
                'url'      => 'reports/stock.php',
            ],
        ],
        [
            'kind'         => 'stock_low',
            'subject_type' => 'product',
            'audience'     => ROLE_MANAGER,
            'sql' => "
                SELECT p.product_id AS subject_id,
                       'stock_low:' || p.product_id                          AS key,
                       'warning'                                             AS severity,
                       p.name || ' is down to ' || TRIM(TO_CHAR(p.stock_quantity, 'FM999,990.##')) AS title,
                       'Reorder level is '
                           || TRIM(TO_CHAR(p.low_quantity_threshold, 'FM999,990.##'))              AS body,
                       'modules/inventory/view_products.php?q='
                           || COALESCE(p.sku, '')                            AS url
                  FROM products p
                 WHERE " . goods_only_sql('p') . "
                   AND p.is_active
                   AND p.stock_quantity > 0
                   AND p.low_quantity_threshold > 0
                   AND p.stock_quantity <= p.low_quantity_threshold",
            'worst'  => 'subject_id',
            'rollup' => [
                'severity' => 'warning',
                'title'    => '%s products are below their reorder level',
                'body'     => 'The stock report lists them, lowest first',
                'url'      => 'reports/stock.php',
            ],
        ],

        /* ── Expiry ────────────────────────────────────────── */
        [
            'kind'         => 'batch_expired',
            'subject_type' => 'batch',
            'audience'     => ROLE_MANAGER,
            'sql' => "
                SELECT b.batch_id AS subject_id,
                       'batch_expired:' || b.batch_id                        AS key,
                       'critical'                                            AS severity,
                       p.name || ' batch ' || b.batch_number || ' has expired' AS title,
                       TRIM(TO_CHAR(b.quantity_remaining, 'FM999,990.##'))
                           || ' still in stock, expired '
                           || TO_CHAR(b.expiry_date, 'DD Mon YYYY')          AS body,
                       'modules/inventory/batches.php?product_id=' || b.product_id AS url
                  FROM product_batches b
                  JOIN products p ON p.product_id = b.product_id
                 WHERE b.expiry_date IS NOT NULL
                   AND b.expiry_date < CURRENT_DATE
                   AND b.quantity_remaining > 0",
        ],
        [
            'kind'         => 'batch_expiring',
            'subject_type' => 'batch',
            'audience'     => ROLE_MANAGER,
            'sql' => "
                SELECT b.batch_id AS subject_id,
                       'batch_expiring:' || b.batch_id                       AS key,
                       'warning'                                             AS severity,
                       p.name || ' batch ' || b.batch_number || ' expires in '
                           || (b.expiry_date - CURRENT_DATE) || ' days'      AS title,
                       TRIM(TO_CHAR(b.quantity_remaining, 'FM999,990.##'))
                           || ' still in stock'                              AS body,
                       'modules/inventory/batches.php?product_id=' || b.product_id AS url
                  FROM product_batches b
                  JOIN products p ON p.product_id = b.product_id
                 WHERE b.expiry_date IS NOT NULL
                   AND b.expiry_date >= CURRENT_DATE
                   AND b.expiry_date <= CURRENT_DATE + $horizon
                   AND b.quantity_remaining > 0",
        ],
        /* ── Sales ─────────────────────────────────────────── */
        [
            'kind'         => 'quote_expiring',
            'subject_type' => 'quote',
            'audience'     => ROLE_SALES,
            'sql' => "
                SELECT q.quote_id AS subject_id,
                       'quote_expiring:' || q.quote_id                       AS key,
                       'info'                                                AS severity,
                       'Quote ' || q.quote_number || ' expires in '
                           || (q.expiry_date - CURRENT_DATE) || ' days'      AS title,
                       " . customer_name_sql('c') . " || ' — '
                           || TO_CHAR(q.total_amount, 'FM999,999,990.00')
                           || '. Worth a follow-up call.'                    AS body,
                       'modules/sales/print_quote.php?quote_id=' || q.quote_id AS url,
                       q.total_amount                                        AS amount
                  FROM quotes q
                  LEFT JOIN customers c ON c.customer_id = q.customer_id
                 WHERE q.expiry_date IS NOT NULL
                   AND q.expiry_date >= CURRENT_DATE
                   AND q.expiry_date <= CURRENT_DATE + 7
                   AND q.status IN ('draft', 'sent', 'pending')",
            'worst'  => 'amount DESC, subject_id',
            'amount' => true,
            'rollup' => [
                'severity' => 'info',
                'title'    => '%s quotes expire this week',
                'body'     => '%2$s of work still on the table — worth a round of follow-up calls',
                'url'      => 'modules/sales/manage_quotes.php',
            ],
        ],

        /* ── Money we owe ──────────────────────────────────── */
        [
            'kind'         => 'supplier_unpaid',
            'subject_type' => 'purchase_order',
            'audience'     => ROLE_MANAGER,
            'sql' => "
                SELECT po.po_id AS subject_id,
                       'supplier_unpaid:' || po.po_id                        AS key,
                       'warning'                                             AS severity,
                       'Purchase order ' || po.po_number || ' is not paid'   AS title,
                       s.name || ' — '
                           || TO_CHAR(po.total_amount - COALESCE(po.amount_paid, 0),
                                      'FM999,999,990.00') || ' outstanding'  AS body,
                       'modules/purchasing/view_po.php?po_id=' || po.po_id   AS url,
                       po.total_amount - COALESCE(po.amount_paid, 0)          AS amount
                  FROM purchase_orders po
                  JOIN suppliers s ON s.supplier_id = po.supplier_id
                 WHERE po.status NOT IN ('cancelled', 'draft')
                   AND po.total_amount - COALESCE(po.amount_paid, 0) > 0.005
                   AND po.issue_date <= CURRENT_DATE - 7",
            'worst'  => 'amount DESC, subject_id',
            'amount' => true,
            'rollup' => [
                'severity' => 'warning',
                'title'    => '%s purchase orders are unpaid',
                'body'     => '%2$s owed to suppliers — the payables report lists them',
                'url'      => 'reports/payables.php',
            ],
        ],
    ];
}

/**
 * The rows one rule opens, and what it left out.
 *
 * Returns [rows, howManyMore, whatThoseComeTo]. The count is asked
 * for separately rather than by fetching everything and counting in
 * PHP — the whole point is not to fetch everything.
 */
function notification_rule_rows(array $rule): array
{
    $cap   = NOTIFY_ROWS_PER_KIND;
    $order = $rule['worst'] ?? null;

    // Without a `worst` there is no basis for choosing which rows to
    // keep, so keep them all and let the rule be small by nature.
    if ($order === null) {
        return [db_all($rule['sql']), 0, 0.0];
    }

    $rows = db_all("SELECT * FROM ({$rule['sql']}) r ORDER BY $order LIMIT " . ($cap + 1));

    // The cap plus one: cheaper than a COUNT on the common path,
    // because a rule under the cap never needs the count at all.
    if (count($rows) <= $cap) {
        return [$rows, 0, 0.0];
    }

    // Total over everything, then subtract the ones being named
    // individually — which are already in hand. One extra execution
    // of the rule, not two nested ones.
    $sum = !empty($rule['amount']) ? 'COALESCE(SUM(r.amount), 0)' : '0';
    $agg = db_one("SELECT COUNT(*) AS n, $sum AS total FROM ({$rule['sql']}) r");

    $kept       = array_slice($rows, 0, $cap);
    $keptAmount = 0.0;
    foreach ($kept as $row) {
        $keptAmount += (float) ($row['amount'] ?? 0);
    }

    return [
        $kept,
        max(0, (int) ($agg['n'] ?? 0) - count($kept)),
        max(0.0, (float) ($agg['total'] ?? 0) - $keptAmount),
    ];
}

/**
 * Run every rule and reconcile the notification table with reality.
 *
 * Returns a small summary: how many rules ran, how many were opened,
 * how many resolved themselves, and how long it took.
 */
function notifications_scan(): array
{
    if (!notifications_ready()) {
        return ['ok' => false, 'reason' => 'the notifications tables are not there yet'];
    }

    $started  = microtime(true);
    $opened   = 0;
    $resolved = 0;
    $skipped  = [];

    foreach (notification_rules() as $rule) {
        // A rule that references a column a migration has not added
        // yet fails its own statement, and inside a transaction
        // PostgreSQL poisons everything after it too — so each rule
        // needs a savepoint to roll back to.
        //
        // Outside a transaction there is nothing to poison: every
        // statement is its own implicit transaction and the next one
        // starts clean. Asking for a SAVEPOINT there is itself an
        // error (25P01), which is how this first announced itself.
        $inTx = db()->inTransaction();

        try {
            if ($inTx) {
                db()->exec('SAVEPOINT notify_rule');
            }

            [$rows, $extra, $extraAmount] = notification_rule_rows($rule);

            // Everything past the cap becomes one row saying how many
            // there are and what they come to. It carries a fixed
            // dedupe key so it updates itself like any other, and it
            // disappears on its own once the count falls back under
            // the cap and the rule stops returning it.
            if ($extra > 0 && !empty($rule['rollup'])) {
                $r = $rule['rollup'];
                $rows[] = [
                    'subject_id' => null,
                    'key'        => $rule['kind'] . ':many',
                    'severity'   => $r['severity'] ?? 'info',
                    'title'      => sprintf($r['title'], num($extra), money($extraAmount)),
                    'body'       => sprintf($r['body'],  num($extra), money($extraAmount)),
                    'url'        => $r['url'] ?? null,
                ];
            }

            // The moment this rule's writes begin, by the database's
            // clock. Everything of this kind still carrying an
            // updated_at older than this is a row the rule did not
            // return, which is the definition of resolved. See below
            // for why it is a timestamp and not a list of keys.
            $mark = db_value('SELECT clock_timestamp()');

            foreach ($rows as $row) {
                db_run(
                    "INSERT INTO notifications
                        (kind, severity, subject_type, subject_id, title, body, url,
                         dedupe_key, audience)
                     VALUES
                        (:kind, :severity, :stype, :sid, :title, :body, :url,
                         :key, :audience)
                     ON CONFLICT (dedupe_key) DO UPDATE SET
                        -- The figures move; the alert is the same alert.
                        title       = EXCLUDED.title,
                        body        = EXCLUDED.body,
                        severity    = EXCLUDED.severity,
                        url         = EXCLUDED.url,
                        -- Came back after being resolved: it is open again,
                        -- and everybody should see it again.
                        resolved_at = NULL,
                        -- clock_timestamp(), not CURRENT_TIMESTAMP: the
                        -- latter is the transaction's start time, and
                        -- when the scan runs inside somebody else's
                        -- transaction that can be BEFORE the mark taken
                        -- above — which would resolve rows this scan
                        -- had just written.
                        updated_at  = clock_timestamp()",
                    [
                        ':kind'     => $rule['kind'],
                        ':severity' => $row['severity'] ?? 'info',
                        ':stype'    => $rule['subject_type'],
                        ':sid'      => $row['subject_id'] ?? null,
                        ':title'    => mb_strimwidth((string) $row['title'], 0, 200, '…'),
                        ':body'     => $row['body'] ?? null,
                        ':url'      => $row['url'] ?? null,
                        ':key'      => $row['key'],
                        ':audience' => $rule['audience'],
                    ]
                );
                $opened++;
            }

            /*  Everything of this kind that the rule did NOT return
             *  has stopped being true. Resolve it.
             *
             *  This used to name every surviving key in a NOT IN
             *  list. With 78,310 overdue invoices that is 78,310
             *  placeholders, and PostgreSQL's protocol allows 65,535
             *  — so the statement threw, the whole rule was caught
             *  and skipped, and NOTHING of that kind ever resolved
             *  itself. Paying an invoice left its alert standing.
             *  The bug was invisible because the catch was doing its
             *  job: one line in the error log, a working page.
             *
             *  A timestamp says the same thing in one parameter, and
             *  says it the same way whether the rule returned two
             *  rows or two million. */
            $stmt = db()->prepare(
                "UPDATE notifications
                    SET resolved_at = clock_timestamp(), updated_at = clock_timestamp()
                  WHERE kind = :kind
                    AND resolved_at IS NULL
                    AND updated_at < :mark"
            );
            $stmt->execute([':kind' => $rule['kind'], ':mark' => $mark]);
            $resolved += $stmt->rowCount();

            if ($inTx) {
                db()->exec('RELEASE SAVEPOINT notify_rule');
            }
        } catch (Throwable $e) {
            if ($inTx && db()->inTransaction()) {
                db()->exec('ROLLBACK TO SAVEPOINT notify_rule');
                db()->exec('RELEASE SAVEPOINT notify_rule');
            }
            // One rule failing is not the scan failing. Most often it
            // is a table a later migration adds.
            $skipped[] = $rule['kind'] . ': ' . $e->getMessage();
            error_log('[NOTIFY] rule ' . $rule['kind'] . ' skipped: ' . $e->getMessage());
        }
    }

    $ms = (int) round((microtime(true) - $started) * 1000);

    try {
        db_run('UPDATE notification_scan_state SET last_run_at = CURRENT_TIMESTAMP, last_run_ms = :ms',
            [':ms' => $ms]);
    } catch (Throwable $e) {
        // Not worth failing the scan over.
    }

    return [
        'ok'       => true,
        'rules'    => count(notification_rules()),
        'opened'   => $opened,
        'resolved' => $resolved,
        'skipped'  => $skipped,
        'ms'       => $ms,
    ];
}

/**
 * Run the scan, but only if nobody else has recently.
 *
 * Called on page render. The claim is one atomic statement: exactly
 * one caller gets a row back and does the work, everybody else gets
 * nothing and carries on. No lock to release, and nothing left in a
 * bad state if the process dies halfway through.
 */
function notifications_scan_if_due(): void
{
    if (!notifications_ready() || !table_exists('notification_scan_state')) {
        return;
    }

    try {
        $won = db_value(
            "UPDATE notification_scan_state
                SET last_run_at = CURRENT_TIMESTAMP
              WHERE last_run_at < CURRENT_TIMESTAMP - (:secs || ' seconds')::INTERVAL
              RETURNING 1",
            [':secs' => NOTIFY_SCAN_EVERY_SECONDS]
        );
        if ($won) {
            notifications_scan();
        }
    } catch (Throwable $e) {
        // A page must render whether or not the notifications work.
        error_log('[NOTIFY] scan skipped: ' . $e->getMessage());
    }
}

/**
 * The condition every query below shares: open, and mine to see.
 *
 * An administrator sees everything. Everybody else sees what is
 * addressed to nobody in particular, plus what is addressed to a
 * role they hold — a salesperson has no use for the company's
 * payables and should not be shown them.
 */
function notification_audience_sql(): array
{
    if (is_admin()) {
        return ['1=1', []];
    }
    $roles = array_values(array_filter([
        user_has_role(ROLE_MANAGER) ? ROLE_MANAGER : null,
        user_has_role(ROLE_SALES)   ? ROLE_SALES   : null,
    ]));
    if (!$roles) {
        return ['n.audience IS NULL', []];
    }
    $place = [];
    $args  = [];
    foreach ($roles as $i => $r) {
        $place[]        = ":aud$i";
        $args[":aud$i"] = $r;
    }
    return ['(n.audience IS NULL OR n.audience IN (' . implode(',', $place) . '))', $args];
}

/** How many open notifications this person has not read. */
function notifications_unread_count(): int
{
    if (!notifications_ready() || !notification_user_id()) {
        return 0;
    }
    try {
        [$audSql, $audArgs] = notification_audience_sql();
        return (int) db_value(
            "SELECT COUNT(*)
               FROM notifications n
               LEFT JOIN notification_reads r
                      ON r.notification_id = n.notification_id AND r.user_id = :uid
              WHERE n.resolved_at IS NULL
                AND r.notification_id IS NULL
                AND $audSql",
            [':uid' => notification_user_id()] + $audArgs
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * How many open notifications this person can see.
 *
 * Separate from the list because the page pages: it needs the total
 * to work out how many pages there are, and the list only fetches
 * the ten rows actually being shown.
 */
function notifications_count(bool $unreadOnly = false, ?string $severity = null): int
{
    if (!notifications_ready() || !notification_user_id()) {
        return 0;
    }
    try {
        [$audSql, $audArgs] = notification_audience_sql();
        $unread = $unreadOnly ? 'AND r.notification_id IS NULL' : '';
        $sev    = notification_severity_clause($severity, $sevArgs);
        return (int) db_value(
            "SELECT COUNT(*)
               FROM notifications n
               LEFT JOIN notification_reads r
                      ON r.notification_id = n.notification_id AND r.user_id = :uid
              WHERE n.resolved_at IS NULL
                AND $audSql
                $unread
                $sev",
            [':uid' => notification_user_id()] + $audArgs + $sevArgs
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The "and only this severity" fragment, or nothing.
 *
 * Written once because the count and the list must agree exactly:
 * a pager sized from one filter and a list built from another shows
 * three pages of nothing.
 */
function notification_severity_clause(?string $severity, ?array &$args): string
{
    $args = [];
    if ($severity === null || !in_array($severity, ['critical', 'warning', 'info'], true)) {
        return '';
    }
    $args = [':sev' => $severity];
    return 'AND n.severity = :sev';
}

/**
 * Open notifications for this person, worst first.
 *
 * @param bool $unreadOnly  the bell wants unread; the page wants all
 * @param int  $offset      for the paged list; the bell always wants 0
 */
function notifications_list(int $limit = 20, bool $unreadOnly = false, int $offset = 0, ?string $severity = null): array
{
    if (!notifications_ready() || !notification_user_id()) {
        return [];
    }
    try {
        [$audSql, $audArgs] = notification_audience_sql();
        $unread = $unreadOnly ? 'AND r.notification_id IS NULL' : '';
        $sev    = notification_severity_clause($severity, $sevArgs);
        return db_all(
            "SELECT n.*, (r.notification_id IS NOT NULL) AS is_read
               FROM notifications n
               LEFT JOIN notification_reads r
                      ON r.notification_id = n.notification_id AND r.user_id = :uid
              WHERE n.resolved_at IS NULL
                AND $audSql
                $unread
                $sev
              ORDER BY CASE n.severity WHEN 'critical' THEN 0
                                       WHEN 'warning'  THEN 1
                                       ELSE 2 END,
                       n.created_at DESC,
                       n.notification_id DESC
              LIMIT " . max(1, min(200, $limit)) . "
             OFFSET " . max(0, $offset),
            [':uid' => notification_user_id()] + $audArgs + $sevArgs
        );
    } catch (Throwable $e) {
        error_log('[NOTIFY] list failed: ' . $e->getMessage());
        return [];
    }
}

/** Open notifications by severity, for the dashboard panel. */
function notifications_summary(): array
{
    $out = ['critical' => 0, 'warning' => 0, 'info' => 0, 'unread' => 0];
    if (!notifications_ready() || !notification_user_id()) {
        return $out;
    }
    try {
        [$audSql, $audArgs] = notification_audience_sql();
        $rows = db_all(
            "SELECT n.severity, COUNT(*) AS n,
                    COUNT(*) FILTER (WHERE r.notification_id IS NULL) AS unread
               FROM notifications n
               LEFT JOIN notification_reads r
                      ON r.notification_id = n.notification_id AND r.user_id = :uid
              WHERE n.resolved_at IS NULL AND $audSql
              GROUP BY n.severity",
            [':uid' => notification_user_id()] + $audArgs
        );
        foreach ($rows as $r) {
            $out[$r['severity']] = (int) $r['n'];
            $out['unread'] += (int) $r['unread'];
        }
    } catch (Throwable $e) {
        // A dashboard with no counts beats a dashboard with no dashboard.
    }
    return $out;
}

/** Mark one notification read for this person. */
function notification_mark_read(int $id): void
{
    if (!notifications_ready() || !notification_user_id()) {
        return;
    }
    db_run(
        "INSERT INTO notification_reads (notification_id, user_id)
         VALUES (:id, :uid) ON CONFLICT DO NOTHING",
        [':id' => $id, ':uid' => notification_user_id()]
    );
}

/** Mark everything this person can currently see as read. */
function notifications_mark_all_read(): int
{
    if (!notifications_ready() || !notification_user_id()) {
        return 0;
    }
    [$audSql, $audArgs] = notification_audience_sql();
    $stmt = db()->prepare(
        "INSERT INTO notification_reads (notification_id, user_id)
         SELECT n.notification_id, :uid
           FROM notifications n
          WHERE n.resolved_at IS NULL AND $audSql
         ON CONFLICT DO NOTHING"
    );
    $stmt->execute([':uid' => notification_user_id()] + $audArgs);
    return $stmt->rowCount();
}

/** Severity → the badge class the stylesheet already knows. */
function notification_tone(string $severity): string
{
    return match ($severity) {
        'critical' => 'danger',
        'warning'  => 'warn',
        default    => 'info',
    };
}

/** A short, human "3 hours ago". */
function notification_when(?string $ts): string
{
    if (!$ts) {
        return '';
    }
    $secs = time() - strtotime($ts);
    if ($secs < 90)     return 'just now';
    if ($secs < 3600)   return floor($secs / 60) . ' min ago';
    if ($secs < 86400)  return floor($secs / 3600) . ' hr ago';
    if ($secs < 604800) return floor($secs / 86400) . ' days ago';
    return date('d M Y', strtotime($ts));
}
