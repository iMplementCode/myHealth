<?php

/**
 * ============================================================
 *  Cash book, receivables, liabilities and capital
 * ------------------------------------------------------------
 *  The arithmetic behind the finance reports, in one place so
 *  every page agrees with every other.
 *
 *  Nothing here is stored. Balances are summed from the ledger
 *  on every read, so a figure cannot drift away from the
 *  movements that produced it.
 *
 *  ── On the vocabulary ──────────────────────────────────────
 *  The spreadsheet these reports replace used "operating
 *  revenue" for cash plus money owed to the business, and
 *  "company valuation" for that plus stock. Neither is what
 *  those words mean in accounting: revenue is what you earn from
 *  sales, and a valuation is what someone would pay for the
 *  business. The quantities themselves were the right ones to
 *  track, so they are kept and renamed:
 *
 *    cash at hand      → cash and bank balances
 *    debtors           → trade receivables
 *    creditors         → trade payables
 *    operating revenue → total current assets
 *    company valuation → net asset value  (assets − liabilities)
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

require_once __DIR__ . '/cache.php';

/**
 * A whole-ledger total, remembered for a moment.
 *
 * finance_trade_receivables() and its three siblings each read every
 * invoice or order ever raised, join three aggregate subqueries to
 * it, and return one number — about 100ms on four years of trading.
 * The dashboard, the reports hub and the financial position all ask
 * for the same numbers, so on a busy morning the same full pass over
 * the ledger runs again for every person looking at a screen.
 *
 * The date is part of the key, so a historical figure and today's
 * never share an answer. A figure "as at" a past date cannot change
 * at all; today's changes when money moves, which is why the window
 * is a minute and not an hour.
 *
 * @param callable():float $compute
 */
function finance_remember(string $what, string $date, callable $compute): float
{
    return (float) cache_remember("fin.$what.$date", REPORT_CACHE_TTL, $compute);
}

// The daily position totals fixed assets alongside the current
// ones, so the register comes with it rather than being something
// each page has to remember to include.
require_once __DIR__ . '/assets.php';


/** Movement categories, in the order they should be offered. */
const CASH_CATEGORIES = [
    'sale'             => ['label' => 'Cash sale',          'dir' => 'in',   'help' => 'Paid for on the spot, no invoice raised'],
    'invoice_payment'  => ['label' => 'Invoice payment',    'dir' => 'in',   'help' => 'Received against an invoice — say which one below'],
    'customer_advance' => ['label' => 'Customer advance',   'dir' => 'in',   'help' => 'Paid before invoicing — a liability until invoiced'],
    'credit_sale'      => ['label' => 'Sale on credit',     'dir' => 'in',   'help' => 'Goods released on credit with no invoice — no cash moved'],
    'investment'       => ['label' => 'Investment received','dir' => 'in',   'help' => 'Normally recorded under Investors, not here'],
    'other_income'     => ['label' => 'Other income',       'dir' => 'in',   'help' => 'Anything else coming in'],
    'capital'          => ['label' => 'Capital introduced', 'dir' => 'in',   'help' => 'Owner putting money into the business'],
    'purchase'         => ['label' => 'Purchase',           'dir' => 'out',  'help' => 'Stock bought for resale — name the order if there is one'],
    'expense'          => ['label' => 'Operating expense',  'dir' => 'out',  'help' => 'Lunch, transport, rent, cleaning…'],
    'refund'           => ['label' => 'Refund to customer', 'dir' => 'out',  'help' => 'Money given back'],
    'drawing'          => ['label' => 'Owner drawing',      'dir' => 'out',  'help' => 'Owner taking money out'],
    'transfer'         => ['label' => 'Transfer',           'dir' => 'both', 'help' => 'Between two accounts — banking a till, or a staff loan'],
    'adjustment'       => ['label' => 'Adjustment',         'dir' => 'both', 'help' => 'Correction to a counted balance'],
];

/**
 * Account types.
 *
 * `liquid` accounts hold real money and make up cash and bank
 * balances; the rest are amounts owed to the business, which are
 * assets but not cash.
 */
const CASH_ACCOUNT_TYPES = [
    'cash'         => ['label' => 'Cash / till',      'liquid' => true,  'help' => 'Notes and coins in a till or float.'],
    'bank'         => ['label' => 'Bank account',     'liquid' => true,  'help' => 'A current, savings or deposit account.'],
    'mobile_money' => ['label' => 'Mobile money',     'liquid' => true,  'help' => 'A paybill, till or wallet.'],
    'loan'         => ['label' => 'Staff loan',       'liquid' => false, 'help' => 'Money lent to an employee. Maintained by the Staff Loans page.'],
    'credit'       => ['label' => 'Informal credit',  'liquid' => false, 'help' => 'Goods released on credit with no invoice raised. Invoiced credit is a trade receivable instead.'],
];

/** How a payment was made, for receipts and repayments alike. */
const PAYMENT_METHODS = [
    'cash'             => 'Cash',
    'bank_transfer'    => 'Bank transfer',
    'mobile_money'     => 'Mobile money',
    'cheque'           => 'Cheque',
    'card'             => 'Card',
    'salary_deduction' => 'Salary deduction',
    'other'            => 'Other',
];

/**
 * One invoice's balance, as SQL.
 *
 * Shared by every figure derived from it so the three can never
 * drift apart: receivables, refunds due, and the balance shown on
 * the invoice list are the same expression.
 *
 *   positive → the customer owes us
 *   negative → we owe the customer
 */
const INVOICE_BALANCE_SQL =
    "i.total_amount - COALESCE(p.paid, 0) - COALESCE(cn.credited, 0) + COALESCE(rf.refunded, 0)";

/** The joins INVOICE_BALANCE_SQL needs. `:d` bounds every part by date. */
const INVOICE_BALANCE_JOINS = "
    LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid
                 FROM invoice_payments WHERE payment_date <= :d
                GROUP BY invoice_id) p ON p.invoice_id = i.invoice_id
    LEFT JOIN (SELECT invoice_id, SUM(total_amount) AS credited
                 FROM credit_notes WHERE status = 'approved' AND issue_date <= :d
                GROUP BY invoice_id) cn ON cn.invoice_id = i.invoice_id
    LEFT JOIN (SELECT invoice_id, SUM(amount) AS refunded
                 FROM customer_refunds WHERE refund_date <= :d
                GROUP BY invoice_id) rf ON rf.invoice_id = i.invoice_id";

/**
 * Categories that are really a movement against a document.
 *
 * A receipt is not simply money arriving — it is money arriving
 * *against an invoice*, and until it is applied to one the
 * customer's account still says they owe it. So where a category
 * names a document, the cash book asks which document, and writes
 * the movement through that ledger rather than straight into the
 * book.
 *
 *   required → the category has no meaning without the document.
 *              A receipt with no invoice is not an invoice payment;
 *              it is money on account, which is a customer advance.
 *
 * A purchase is the exception: stock bought over a counter is a
 * genuine purchase with no order behind it, so the order is
 * offered and not demanded.
 */
const CASH_DOCUMENT_CATEGORIES = [
    'invoice_payment' => [
        'kind'     => 'invoice',
        'field'    => 'invoice_id',
        'label'    => 'Invoice being paid',
        'required' => true,
        'empty'    => 'Every issued invoice is settled, so there is nothing to receive against. '
                    . 'Money with no invoice behind it is a customer advance.',
        'help'     => 'The receipt is applied to this invoice: its balance falls and the '
                    . 'customer statement follows. Only invoices still owed for are listed.',
    ],
    'purchase' => [
        'kind'     => 'purchase_order',
        'field'    => 'po_id',
        'label'    => 'Purchase order being paid',
        'required' => false,
        'empty'    => 'No purchase order has a balance to pay. Leave this blank for a counter purchase.',
        'help'     => 'Naming the order reduces what is owed to that supplier. '
                    . 'Leave it blank for a counter purchase made with no order raised.',
    ],
];

/** Human label for a category key. */
function cash_category_label(string $key): string
{
    return CASH_CATEGORIES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * The document a ledger entry came from, named without its
 * article — for a badge or a column, where "From invoice payment"
 * reads better than "From an invoice payment".
 */
function cash_source_noun(string $key): string
{
    return ltrim(preg_replace('/^an? /', '', cash_source_label($key)));
}

/** Human label for the document a ledger entry came from. */
function cash_source_label(string $key): string
{
    return [
        'invoice'                 => 'an invoice',
        'invoice_payment'         => 'an invoice payment',
        'purchase_payment'        => 'a supplier payment',
        'purchase_order'          => 'a purchase order',
        'grn'                     => 'a goods received note',
        'expense'                 => 'an expense',
        'employee_loan'           => 'a staff loan',
        'employee_loan_repayment' => 'a loan repayment',
        'customer_advance'        => 'a customer advance',
        'customer_refund'         => 'a customer refund',
        'investment'              => 'an investment',
        'investment_repayment'    => 'an investor repayment',
    ][$key] ?? str_replace('_', ' ', $key);
}

/**
 * Where to go to see the document a ledger entry came from.
 *
 * A cash book that cannot be traced back is half a cash book. Every
 * line the system posts for you says which document produced it,
 * and this turns that into somewhere to click — because the first
 * question anybody asks of a figure is "what was that?", and the
 * answer is never in the cash book itself.
 *
 * Two shapes of source, and the difference matters:
 *
 *  - Most carry the id of the document in their own list, so the
 *    link lands on that one row (`?focus=`).
 *  - Repayments and refunds carry the id of the *repayment*, which
 *    is a child row with no list of its own. Sending `focus` there
 *    would highlight whichever unrelated parent happens to share
 *    that number, so those link to the list and stop.
 */
function cash_source_url(?string $type, ?int $id): ?string
{
    if ($type === null || $type === '') {
        return null;
    }

    // source_type => [page, does source_id identify a row on it?]
    $map = [
        'invoice'                 => ['invoices/index.php',              false],
        'invoice_payment'         => ['invoices/payments.php',           true],
        'purchase_payment'        => ['modules/purchasing/payments.php', true],
        'purchase_order'          => ['modules/purchasing/manage_pos.php', false],
        'grn'                     => ['modules/purchasing/manage_grns.php', false],
        'expense'                 => ['expenses/index.php',              true],
        'employee_loan'           => ['finance/loans.php',               true],
        'employee_loan_repayment' => ['finance/loans.php',               false],
        'customer_advance'        => ['finance/advances.php',            true],
        'customer_refund'         => ['credit_notes/index.php',          false],
        'investment'              => ['finance/investors.php',           true],
        'investment_repayment'    => ['finance/investors.php',           false],
    ];

    if (!isset($map[$type])) {
        return null;
    }
    [$page, $pinpoints] = $map[$type];

    return url($page . ($pinpoints && $id ? '?focus=' . $id : ''));
}

/**
 * The payment method a given account implies.
 *
 * Money in a bank account did not arrive as notes, and money in a
 * wallet did not arrive by cheque. The account already says most
 * of what the method field is asking, so it seeds the answer.
 */
function cash_method_for_account(string $accountType): string
{
    return [
        'cash'         => 'cash',
        'bank'         => 'bank_transfer',
        'mobile_money' => 'mobile_money',
    ][$accountType] ?? 'other';
}

/** Human label for an account type. */
function cash_account_type_label(string $key): string
{
    return CASH_ACCOUNT_TYPES[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
}

/** Human label for a payment method. */
function payment_method_label(string $key): string
{
    return PAYMENT_METHODS[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/** True when an account type holds actual money. */
function cash_type_is_liquid(string $type): bool
{
    return (bool) (CASH_ACCOUNT_TYPES[$type]['liquid'] ?? false);
}

/** The account types that hold actual money, for SQL IN lists. */
function cash_liquid_types(): array
{
    return array_keys(array_filter(CASH_ACCOUNT_TYPES, fn($m) => $m['liquid']));
}

/**
 * Every account, with the type metadata the pages need.
 *
 * @param bool $activeOnly Omit deactivated accounts.
 */
function cash_accounts(bool $activeOnly = true): array
{
    return db_all(
        "SELECT a.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS holder_name
           FROM cash_accounts a
           LEFT JOIN users u ON u.user_id = a.holder_user_id
          " . ($activeOnly ? "WHERE a.is_active" : "") . "
          ORDER BY
            CASE a.account_type
                WHEN 'bank' THEN 0 WHEN 'mobile_money' THEN 1 WHEN 'cash' THEN 2
                WHEN 'loan' THEN 3 ELSE 4 END,
            a.sort_order, a.name"
    );
}

/** Only the accounts money can actually be paid into or out of. */
function cash_liquid_accounts(bool $activeOnly = true): array
{
    return array_values(array_filter(
        cash_accounts($activeOnly),
        fn($a) => cash_type_is_liquid($a['account_type'])
    ));
}

/**
 * Balance of every account as at the end of a given date.
 *
 * Opening balance plus every movement up to and including the
 * date. Accounts with no movements still appear, at their opening
 * balance, so the report never silently drops one.
 *
 * @return array<int, float> keyed by cash_account_id
 */
function cash_balances_asof(string $date): array
{
    // A multi-day report asks for consecutive dates, and each day
    // needs the day before it too — so every date is requested
    // twice. Memoising halves the queries for free.
    static $cache = [];
    if (isset($cache[$date])) {
        return $cache[$date];
    }

    $rows = db_all(
        "SELECT a.cash_account_id,
                a.opening_balance + COALESCE(SUM(
                    CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END
                ), 0) AS balance
           FROM cash_accounts a
           LEFT JOIN cash_transactions t
                  ON t.cash_account_id = a.cash_account_id
                 AND t.txn_date <= :d
          GROUP BY a.cash_account_id, a.opening_balance",
        [':d' => $date]
    );

    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['cash_account_id']] = (float) $r['balance'];
    }
    return $cache[$date] = $out;
}

/**
 * The day's movements, totalled by what the report needs.
 *
 * Everything except `sales_credit` is restricted to liquid
 * accounts, because these figures reconcile cash. `transfers_net`
 * is what makes that reconciliation complete: lending an employee
 * money takes cash out without being an expense, and a report
 * with no line for it simply stops adding up.
 */
function cash_day_totals(string $date): array
{
    $liquid = "'" . implode("','", cash_liquid_types()) . "'";

    $r = db_one(
        "SELECT
            COALESCE(SUM(t.amount) FILTER (WHERE t.category = 'sale'), 0)             AS sales_cash,
            COALESCE(SUM(t.amount) FILTER (WHERE t.category = 'invoice_payment'), 0)  AS invoice_receipts,
            COALESCE(SUM(t.amount) FILTER (WHERE t.category = 'credit_sale'), 0)      AS sales_credit,
            COALESCE(SUM(t.amount) FILTER (WHERE t.category = 'customer_advance'), 0) AS advances_in,
            COALESCE(SUM(t.amount) FILTER (WHERE t.category = 'investment'), 0)       AS investment_in,
            COALESCE(SUM(t.amount) FILTER (WHERE t.category = 'purchase'), 0)         AS purchases,
            COALESCE(SUM(t.amount) FILTER (WHERE t.category = 'expense'), 0)          AS expenses,
            COALESCE(SUM(t.amount) FILTER (
                WHERE a.account_type IN ($liquid) AND t.direction = 'in'
                  AND t.category IN ('other_income', 'capital')), 0)                  AS other_in,
            COALESCE(SUM(t.amount) FILTER (
                WHERE a.account_type IN ($liquid) AND t.direction = 'out'
                  AND t.category IN ('drawing', 'refund')), 0)                        AS other_out,
            COALESCE(SUM(
                CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END
            ) FILTER (
                WHERE a.account_type IN ($liquid)
                  AND t.category IN ('transfer', 'adjustment')), 0)                   AS transfers_net
           FROM cash_transactions t
           JOIN cash_accounts a ON a.cash_account_id = t.cash_account_id
          WHERE t.txn_date = :d",
        [':d' => $date]
    ) ?: [];

    $keys = ['sales_cash', 'invoice_receipts', 'sales_credit', 'advances_in', 'investment_in',
             'purchases', 'expenses', 'other_in', 'other_out', 'transfers_net'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = (float) ($r[$k] ?? 0);
    }
    return $out;
}

/* ─────────────────────────────────────────────────────────────
 *  Assets, liabilities and capital
 * ──────────────────────────────────────────────────────────── */

/** Inventory at cost, as the products table stands right now. */
function finance_live_stock_value(): float
{
    return (float) db_value(
        "SELECT COALESCE(SUM(cost_price * stock_quantity), 0)
           FROM products p WHERE p.is_active AND " . goods_only_sql()
    );
}

/**
 * Trade receivables: what customers owe on invoices as at a date.
 *
 * Per invoice, the balance is
 *
 *     total − paid − credited + refunded
 *
 * A positive balance is owed to us; a negative one is owed back to
 * the customer, and belongs under liabilities rather than netted
 * off against other customers' debts — which is why each invoice
 * is clamped at zero before being summed.
 */
function finance_trade_receivables(string $date): float
{
    if (!table_exists('invoices') || !table_exists('invoice_payments')) {
        return 0.0;
    }
    $b = invoice_balance_parts();
    return finance_remember('receivables', $date, fn() => (float) db_value(
        "SELECT COALESCE(SUM(GREATEST({$b['expr']}, 0)), 0)
           FROM invoices i
           {$b['joins']}
          WHERE i.status NOT IN ('cancelled', 'draft')
            AND i.issue_date <= :d",
        [':d' => $date]
    ));
}

/**
 * The invoice-balance expression, and the joins it needs, written
 * for the schema actually in front of us.
 *
 * Credit notes and refunds arrived in migration 017. Before it an
 * invoice balance is simply what was invoiced less what was
 * received, and asking for a table that does not exist would take
 * the whole page down. Every figure derived from an invoice
 * balance reads this one function, so a database at either level
 * gets one consistent answer rather than three different ones.
 *
 * `:d` bounds every part by date.
 *
 * @return array{expr:string, joins:string, paid:string, credited:string, refunded:string}
 */
function invoice_balance_parts(): array
{
    if (table_exists('credit_notes') && table_exists('customer_refunds')) {
        return [
            'expr'     => INVOICE_BALANCE_SQL,
            'joins'    => INVOICE_BALANCE_JOINS,
            'paid'     => 'COALESCE(p.paid, 0)',
            'credited' => 'COALESCE(cn.credited, 0)',
            'refunded' => 'COALESCE(rf.refunded, 0)',
        ];
    }
    return [
        'expr'  => 'i.total_amount - COALESCE(p.paid, 0)',
        'joins' => "LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid
                                 FROM invoice_payments WHERE payment_date <= :d
                                GROUP BY invoice_id) p ON p.invoice_id = i.invoice_id",
        'paid'     => 'COALESCE(p.paid, 0)',
        'credited' => '0',
        'refunded' => '0',
    ];
}

/**
 * What one invoice is still owed, as at a date.
 *
 * The same arithmetic as trade receivables, for a single invoice:
 * invoiced, less received, less credited, plus anything refunded.
 * A settled invoice returns zero; an over-credited one returns a
 * negative, which is money owed back rather than owed to us.
 */
function invoice_outstanding(int $invoiceId, ?string $asof = null): float
{
    if (!table_exists('invoices') || !table_exists('invoice_payments')) {
        return 0.0;
    }
    $b = invoice_balance_parts();
    return (float) db_value(
        "SELECT COALESCE({$b['expr']}, 0)
           FROM invoices i
           {$b['joins']}
          WHERE i.invoice_id = :id",
        [':d' => $asof ?? date('Y-m-d'), ':id' => $invoiceId]
    );
}

/**
 * Invoices a payment can still be received against.
 *
 * Issued, not cancelled, and with something left owing — which is
 * exactly the list a receipt should be allowed to name. A settled
 * invoice is deliberately absent: applying money to it would
 * over-pay it, and the database refuses to record that anyway.
 */
function cash_open_invoices(): array
{
    if (!table_exists('invoices') || !table_exists('invoice_payments')) {
        return [];
    }
    $b = invoice_balance_parts();

    // The balance is wrapped in a sub-select rather than repeated,
    // because it is long enough that a copy in the WHERE clause
    // would eventually stop matching the copy in the SELECT.
    return db_all(
        "SELECT * FROM (
            SELECT i.invoice_id, i.invoice_number, i.issue_date, i.due_date,
                   i.status, i.customer_id, i.total_amount,
                   " . customer_name_sql('c') . "      AS customer_name,
                   {$b['paid']}                              AS paid,
                   {$b['credited']}                          AS credited,
                   {$b['refunded']}                          AS refunded,
                   {$b['expr']}                              AS balance
              FROM invoices i
              LEFT JOIN customers c ON c.customer_id = i.customer_id
              {$b['joins']}
             WHERE i.status NOT IN ('draft', 'cancelled')
         ) q
         WHERE q.balance > 0.005
         ORDER BY q.due_date NULLS LAST, q.issue_date, q.invoice_number",
        [':d' => date('Y-m-d')]
    );
}

/**
 * Purchase orders with a balance still to pay.
 *
 * The mirror of the invoice list on the buying side. Draft orders
 * are excluded because nothing has been committed yet, and
 * rejected or cancelled ones because there is nothing to settle.
 */
function cash_open_purchase_orders(): array
{
    if (!table_exists('purchase_orders') || !table_exists('purchase_payments')) {
        return [];
    }
    return db_all(
        "SELECT po.po_id, po.po_number, po.issue_date, po.status, po.supplier_id,
                po.total_amount, po.amount_paid,
                po.total_amount - po.amount_paid AS balance,
                s.name AS supplier_name
           FROM purchase_orders po
           LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
          WHERE po.status NOT IN ('draft', 'cancelled', 'rejected')
            AND po.total_amount - po.amount_paid > 0.005
          ORDER BY po.issue_date, po.po_number"
    );
}

/**
 * Money owed back to customers: invoices credited past what was
 * paid, less refunds already disbursed.
 *
 * This arises when goods that were paid for are returned. The
 * business holds cash that is no longer its own, so it is a
 * liability until the customer is paid.
 */
function finance_customer_refunds_due(string $date): float
{
    if (!table_exists('credit_notes') || !table_exists('customer_refunds')) {
        return 0.0;
    }
    return finance_remember('refunds_due', $date, fn() => (float) db_value(
        "SELECT COALESCE(SUM(GREATEST(-(" . INVOICE_BALANCE_SQL . "), 0)), 0)
           FROM invoices i
           " . INVOICE_BALANCE_JOINS . "
          WHERE i.issue_date <= :d",
        [':d' => $date]
    ));
}

/* ─────────────────────────────────────────────────────────────
 *  The purchase side
 * ────────────────────────────────────────────────────────────
 *  Two different figures, and the difference between them is
 *  what the business owes its suppliers:
 *
 *      goods received   — stock that arrived, priced at cost
 *      paid to supplier — money that actually left the business
 *
 *  Only the second is a cash movement. Goods bought on credit
 *  arrive without a shilling moving, which is precisely why they
 *  create a payable rather than a payment.
 * ──────────────────────────────────────────────────────────── */

/**
 * Goods received on one day, at cost.
 *
 * This is the purchases figure in the trading sense — what was
 * bought — taken from the goods received notes rather than from
 * the cash book, because a purchase on credit never touches cash
 * and would otherwise never be counted at all.
 */
function finance_goods_received(string $date): float
{
    if (!table_exists('grns')) {
        return 0.0;
    }
    return (float) db_value(
        "SELECT COALESCE(SUM(total_amount), 0) FROM grns
          WHERE receipt_date = :d AND status <> 'cancelled'",
        [':d' => $date]
    );
}

/** Goods received between two dates, at cost. */
function finance_goods_received_between(string $from, string $to): float
{
    if (!table_exists('grns')) {
        return 0.0;
    }
    return (float) db_value(
        "SELECT COALESCE(SUM(total_amount), 0) FROM grns
          WHERE receipt_date BETWEEN :f AND :t AND status <> 'cancelled'",
        [':f' => $from, ':t' => $to]
    );
}

/**
 * Goods received up to a date, per purchase order.
 *
 * Sub-selected by the payables and prepayments figures below, so
 * that each order can be clamped at zero on its own rather than
 * one supplier's credit balance cancelling another's debt.
 */
const PO_RECEIVED_SQL = "
    (SELECT COALESCE(SUM(g.total_amount), 0)
       FROM grns g
      WHERE g.po_id = po.po_id
        AND g.status <> 'cancelled'
        AND g.receipt_date <= :d)";

/**
 * Service work accepted up to a date, per purchase order.
 *
 * A service never arrives, so it never gets a GRN — see migration
 * 042. The day the work was accepted does for a service exactly
 * what a receipt date does for goods: from then on it is owed, and
 * it is owed AS OF that date, so last month's payables report is
 * not moved by work accepted this month.
 *
 * Written as a function rather than a constant because a database
 * that has not had 042 applied has no `accepted_at` column, and a
 * payables figure is not worth taking the whole finance section
 * down for.
 */
function po_services_accepted_sql(): string
{
    if (!column_exists('purchase_order_items', 'accepted_at')) {
        return '0';
    }
    // Tax included, because the goods side of this sum is a GRN's
    // total_amount, which is tax-inclusive. Leaving it out would make
    // every service under-owed by exactly its VAT, and the supplier
    // bills for the VAT too.
    return "
    (SELECT COALESCE(SUM(ROUND(poi.quantity_ordered * poi.unit_price
                               * (1 + COALESCE(po.tax_rate, 0)), 2)), 0)
       FROM purchase_order_items poi
      WHERE poi.po_id = po.po_id
        AND poi.accepted_at IS NOT NULL
        AND poi.accepted_at <= :d)";
}

/**
 * Everything a purchase order is owed for up to a date: goods that
 * arrived, plus service work that was accepted.
 *
 * One place, so payables and supplier prepayments cannot disagree
 * about what "received" means.
 */
function po_owed_for_sql(): string
{
    return '(' . PO_RECEIVED_SQL . ' + ' . po_services_accepted_sql() . ')';
}

const PO_PAID_SQL = "
    (SELECT COALESCE(SUM(pp.amount), 0)
       FROM purchase_payments pp
      WHERE pp.po_id = po.po_id
        AND pp.payment_date <= :d)";

/**
 * Goods sent back to the supplier up to a date, per order.
 *
 * Returned goods are not owed for. The debit note is the claim
 * against the supplier for them, and raising one takes the stock
 * off the shelf there and then — so from that moment the business
 * neither holds the goods nor owes for them.
 *
 * Every note that has not been cancelled counts, draft included,
 * for exactly that reason: the stock leaves when the note is
 * raised, not when somebody remembers to mark it sent, and a debt
 * for goods that are demonstrably gone is not a debt.
 *
 * `debit_notes.total_refund_amount` is quantity × unit cost, the
 * same net-of-VAT basis as `grns.total_amount`, so the two
 * subtract cleanly.
 *
 * Returns SQL, not a constant, because the table arrived in
 * migration 020 and a database older than that must still answer.
 */
function po_debited_sql(): string
{
    if (!table_exists('debit_notes')) {
        return '0';
    }
    return "
    (SELECT COALESCE(SUM(dn.total_refund_amount), 0)
       FROM debit_notes dn
       JOIN grns dg ON dg.grn_id = dn.grn_id
      WHERE dg.po_id = po.po_id
        AND dn.status <> 'cancelled'
        AND dn.issue_date <= :d)";
}

/**
 * Trade payables: what the business owes suppliers as at a date.
 *
 * You owe for what you have received, not for what you have
 * ordered — an order placed and not yet delivered is a commitment,
 * not a liability. And you do not owe for what you have sent back.
 * So per order:
 *
 *     payable = goods received − goods returned − paid
 *
 * clamped at zero, because an order paid ahead of delivery is a
 * prepayment sitting with the supplier, which is an asset and is
 * reported as one below rather than netted off someone else's debt.
 *
 * Before migration 020 there was no purchase ledger at all and
 * this figure was typed in when a day was closed; the stored
 * value is still honoured for days closed under the old scheme.
 */
function finance_trade_payables(string $date): float
{
    if (!table_exists('purchase_payments') || !table_exists('grns')) {
        return 0.0;
    }
    return finance_remember('payables', $date, fn() => (float) db_value(
        "SELECT COALESCE(SUM(GREATEST("
            . po_owed_for_sql() . " - " . po_debited_sql() . " - " . PO_PAID_SQL . ", 0)), 0)
           FROM purchase_orders po
          WHERE po.status <> 'cancelled'",
        [':d' => $date]
    ));
}

/**
 * Supplier prepayments: money paid ahead of the goods arriving.
 *
 * The mirror image of a payable — the supplier is holding the
 * company's money, so it is an asset until the stock turns up.
 * Goods sent back move the line the same way they do above: they
 * stop counting as delivered, so money paid against them is once
 * again money the supplier is holding.
 */
function finance_supplier_prepayments(string $date): float
{
    if (!table_exists('purchase_payments') || !table_exists('grns')) {
        return 0.0;
    }
    return finance_remember('prepayments', $date, fn() => (float) db_value(
        "SELECT COALESCE(SUM(GREATEST(" . PO_PAID_SQL
            . " - (" . po_owed_for_sql() . " - " . po_debited_sql() . "), 0)), 0)
           FROM purchase_orders po
          WHERE po.status <> 'cancelled'",
        [':d' => $date]
    ));
}

/** Credit notes approved but with goods still to come back. */
function finance_returns_awaited(): int
{
    if (!table_exists('goods_return_notes')) {
        return 0;
    }
    return (int) db_value("SELECT COUNT(*) FROM goods_return_notes WHERE status = 'awaiting'");
}

/**
 * Customer advances still owed as goods or services.
 *
 * Money taken before the invoice is not income — the business
 * owes for it until an invoice draws it down, which makes it a
 * liability rather than a receipt.
 */
function finance_customer_advances(string $date): float
{
    if (!table_exists('customer_advances')) {
        return 0.0;
    }
    return (float) db_value(
        "SELECT COALESCE(SUM(a.amount - COALESCE(u.used, 0)), 0)
           FROM customer_advances a
           LEFT JOIN (
                SELECT from_advance_id, SUM(amount) AS used
                  FROM invoice_payments
                 WHERE from_advance_id IS NOT NULL AND payment_date <= :d
                 GROUP BY from_advance_id
           ) u ON u.from_advance_id = a.advance_id
          WHERE a.received_on <= :d
            AND a.status <> 'refunded'",
        [':d' => $date]
    );
}

/** Investor loans still outstanding — borrowed, so a liability. */
function finance_investor_loans(string $date): float
{
    if (!table_exists('investments')) {
        return 0.0;
    }
    return (float) db_value(
        "SELECT COALESCE(SUM(v.amount - COALESCE(r.repaid, 0)), 0)
           FROM investments v
           LEFT JOIN (
                SELECT investment_id, SUM(amount) AS repaid
                  FROM investment_repayments
                 WHERE paid_on <= :d
                 GROUP BY investment_id
           ) r ON r.investment_id = v.investment_id
          WHERE v.investment_type = 'loan'
            AND v.received_on <= :d",
        [':d' => $date]
    );
}

/** Equity put in by investors — capital, never repaid. */
function finance_invested_capital(string $date): float
{
    if (!table_exists('investments')) {
        return 0.0;
    }
    return (float) db_value(
        "SELECT COALESCE(SUM(amount), 0)
           FROM investments
          WHERE investment_type = 'equity' AND received_on <= :d",
        [':d' => $date]
    );
}

/**
 * The full daily position: a statement of what the business owns
 * and owes on one day.
 *
 * Cash and receivable figures come from the ledger. Inventory and
 * trade payables come from that day's snapshot if the day was
 * closed, and from live data otherwise — `stock_live` says which,
 * so an unclosed day can be labelled honestly rather than quietly
 * showing today's stock against last week's cash.
 */
function cash_position(string $date): array
{
    $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));

    $balances     = cash_balances_asof($date);
    $prevBalances = cash_balances_asof($prevDate);
    $accounts     = cash_accounts(false);
    $totals       = cash_day_totals($date);

    $cashAndBank    = 0.0;
    $prevCash       = 0.0;
    $staffLoans     = 0.0;
    $informalCredit = 0.0;
    $lines          = [];

    foreach ($accounts as $a) {
        $id  = (int) $a['cash_account_id'];
        $bal = $balances[$id] ?? (float) $a['opening_balance'];

        // A closed account with nothing left in it is noise on the
        // report; one still holding money must always be shown.
        if (!$a['is_active'] && abs($bal) < 0.005) {
            continue;
        }

        if (cash_type_is_liquid($a['account_type'])) {
            $cashAndBank += $bal;
            $prevCash    += $prevBalances[$id] ?? (float) $a['opening_balance'];
        } elseif ($a['account_type'] === 'loan') {
            $staffLoans += $bal;
        } else {
            $informalCredit += $bal;
        }

        $lines[] = [
            'name'    => $a['name'],
            'type'    => $a['account_type'],
            'liquid'  => cash_type_is_liquid($a['account_type']),
            'balance' => $bal,
        ];
    }

    $snapshot = db_one("SELECT * FROM daily_positions WHERE position_date = :d", [':d' => $date]);
    $isToday  = $date === date('Y-m-d');

    // Inventory cannot be reconstructed for a past day, so a
    // closed day keeps the figure that was captured.
    $inventory = $snapshot ? (float) $snapshot['stock_value'] : finance_live_stock_value();

    // Trade payables used to be in the same boat and had to be
    // typed. Since migration 020 they come from dated goods
    // received less dated payments, so they are computed like any
    // other reconstructible figure. A day closed before that
    // migration has only the typed number, so it is used when the
    // ledger has nothing to say.
    $tradePayable = finance_trade_payables($date);
    if ($tradePayable < 0.005 && $snapshot) {
        $tradePayable = (float) $snapshot['creditors'];
    }
    $supplierPrepaid = finance_supplier_prepayments($date);

    // Receivables and liabilities are reconstructible from dated
    // rows, so they are always computed — even for a closed day.
    $tradeReceivable = finance_trade_receivables($date);
    $advances        = finance_customer_advances($date);
    $investorLoans   = finance_investor_loans($date);
    $investedCapital = finance_invested_capital($date);
    $refundsDue      = finance_customer_refunds_due($date);

    $receivables  = $tradeReceivable + $staffLoans + $informalCredit + $supplierPrepaid;

    // Current assets turn into cash within a year — stock, cash,
    // money owed to us. Fixed assets do not: they are the desks,
    // the laptops and the van, held to run the business rather
    // than to sell, and carried at cost less depreciation. Leaving
    // them out understated what the business is worth by whatever
    // it had spent equipping itself.
    $currentAssets = $inventory + $cashAndBank + $receivables;
    $fixedAssets   = finance_fixed_assets($date);
    $totalAssets   = $currentAssets + $fixedAssets;
    $liabilities  = $tradePayable + $advances + $investorLoans + $refundsDue;

    $receipts = $totals['sales_cash'] + $totals['invoice_receipts'] + $totals['advances_in']
              + $totals['investment_in'] + $totals['other_in'];
    $payments = $totals['purchases'] + $totals['expenses'] + $totals['other_out'];

    // What was bought, as against what was paid for. The cash book
    // knows the second; only the goods received notes know the
    // first, because stock taken on credit never touches cash.
    $goodsReceived = finance_goods_received($date);

    return [
        'date'          => $date,
        'previous_date' => $prevDate,

        // ── Cash movement for the day ──
        'opening_balance'  => $prevCash,
        'sales_cash'       => $totals['sales_cash'],
        'invoice_receipts' => $totals['invoice_receipts'],
        'advances_in'      => $totals['advances_in'],
        'investment_in'    => $totals['investment_in'],
        'other_in'         => $totals['other_in'],
        'receipts'         => $receipts,
        'purchases'        => $totals['purchases'],
        'expenses'         => $totals['expenses'],
        'other_out'        => $totals['other_out'],
        'payments'         => $payments,
        'transfers_net'    => $totals['transfers_net'],
        'closing_balance'  => $cashAndBank,

        // ── Trading for the day ──
        'revenue'         => $totals['sales_cash'] + $totals['invoice_receipts'] + $totals['sales_credit'],
        'sales_credit'    => $totals['sales_credit'],
        // Purchases in the trading sense: stock that arrived,
        // whether it was paid for on the day or not.
        'goods_received'  => $goodsReceived,
        // The part of it still owed for, on the day it arrived.
        'purchased_on_credit' => max($goodsReceived - $totals['purchases'], 0.0),

        // ── Assets ──
        'inventory'         => $inventory,
        'cash_and_bank'     => $cashAndBank,
        'trade_receivables'    => $tradeReceivable,
        'staff_loans'          => $staffLoans,
        'informal_credit'      => $informalCredit,
        'supplier_prepayments' => $supplierPrepaid,
        'current_assets'       => $currentAssets,
        'fixed_assets'         => $fixedAssets,
        'total_assets'         => $totalAssets,

        // ── Liabilities ──
        'trade_payables'    => $tradePayable,
        'customer_advances' => $advances,
        'investor_loans'    => $investorLoans,
        'refunds_due'       => $refundsDue,
        'total_liabilities' => $liabilities,

        // ── What is left ──
        'net_asset_value'  => $totalAssets - $liabilities,
        'invested_capital' => $investedCapital,

        // Cash plus everything owed to the business: current assets
        // other than stock, which is the figure the old sheet was
        // reaching for when it totalled the account column.
        'held_and_owed' => $cashAndBank + $receivables,

        // ── Who holds the money ──
        'accounts'   => $lines,
        'closed'     => (bool) $snapshot,
        'closed_at'  => $snapshot['closed_at'] ?? null,
        'stock_live' => !$snapshot,
        'stale'      => !$snapshot && !$isToday,
    ];
}

/**
 * Whether the day's cash movement adds up.
 *
 * Opening balance, plus receipts, less payments, plus the net of
 * transfers and adjustments, must equal the closing balance.
 * Because both sides are summed from the same rows this is a
 * self-check on the report rather than on the data — it fails
 * loudly if a future change breaks the arithmetic instead of
 * quietly publishing a wrong number.
 */
function cash_position_reconciles(array $p): bool
{
    $expected = $p['opening_balance'] + $p['receipts'] - $p['payments'] + $p['transfers_net'];
    return abs($expected - $p['closing_balance']) < 0.005;
}

/**
 * One day's position as a flat list of statement lines, for an
 * export to lay out.
 *
 *     ['section' => …, 'item' => …, 'amount' => float]
 *
 * Every figure is read straight out of the same cash_position()
 * array the page renders from, so the file and the screen cannot
 * disagree about a number. What they could drift on is wording
 * and running order, which is markup on one side and this list on
 * the other — a test checks the totals line up.
 *
 * Lines that are nil are left out, as the page leaves them out: a
 * statement of what a business has should not be a column of
 * zeros. The ones that are always shown are shown at zero,
 * because "no trade payables" is itself worth reading.
 */
function cash_position_lines(array $p): array
{
    $rows = [];
    $add = function (string $section, string $item, float $amount, bool $always = true)
                use (&$rows) {
        if ($always || abs($amount) > 0.005) {
            $rows[] = ['section' => $section, 'item' => $item, 'amount' => $amount];
        }
    };

    $add('Assets', 'Inventory, at cost',                $p['inventory']);
    $add('Assets', 'Cash and bank balances',            $p['cash_and_bank']);
    $add('Assets', 'Trade receivables',                 $p['trade_receivables']);
    $add('Assets', 'Staff loans receivable',            $p['staff_loans'], false);
    $add('Assets', 'Other receivables',                 $p['informal_credit'], false);
    $add('Assets', 'Supplier prepayments',              $p['supplier_prepayments'], false);
    $add('Assets', 'Total current assets',              $p['current_assets']);
    $add('Assets', 'Fixed assets, at written-down value', $p['fixed_assets'], false);
    $add('Assets', 'Total assets',                      $p['total_assets']);

    $add('Liabilities', 'Trade payables',               $p['trade_payables']);
    $add('Liabilities', 'Customer advances',            $p['customer_advances']);
    $add('Liabilities', 'Investor loans',               $p['investor_loans']);
    $add('Liabilities', 'Refunds owed to customers',    $p['refunds_due']);
    $add('Liabilities', 'Total liabilities',            $p['total_liabilities']);

    $add('Net worth', 'Net asset value',                $p['net_asset_value']);
    $add('Net worth', 'of which investor capital',      $p['invested_capital'], false);

    $add('Cash movement', 'Opening balance',            $p['opening_balance']);
    $add('Cash movement', 'Cash sales',                 $p['sales_cash'], false);
    $add('Cash movement', 'Invoice receipts',           $p['invoice_receipts'], false);
    $add('Cash movement', 'Customer advances received', $p['advances_in'], false);
    $add('Cash movement', 'Investment received',        $p['investment_in'], false);
    $add('Cash movement', 'Other income',               $p['other_in'], false);
    $add('Cash movement', 'Total receipts',             $p['receipts']);
    $add('Cash movement', 'Paid to suppliers',          $p['purchases'], false);
    $add('Cash movement', 'Operating expenses',         $p['expenses'], false);
    $add('Cash movement', 'Drawings and refunds',       $p['other_out'], false);
    $add('Cash movement', 'Total payments',             $p['payments']);
    $add('Cash movement', 'Transfers, net',             $p['transfers_net'], false);
    $add('Cash movement', 'Closing balance',            $p['closing_balance']);

    $add('Trading', 'Revenue',                          $p['revenue']);
    $add('Trading', 'of which sold on credit',          $p['sales_credit'], false);
    $add('Trading', 'Purchases, goods received at cost', $p['goods_received'], false);
    $add('Trading', 'of which not yet paid for',        $p['purchased_on_credit'], false);

    return $rows;
}

/* ─────────────────────────────────────────────────────────────
 *  Posting to the cash book
 * ──────────────────────────────────────────────────────────── */

/**
 * Write one movement into the cash book.
 *
 * Callers are already inside a transaction — a receipt and its
 * ledger entry must land together or not at all.
 */
function cash_post(array $row): int
{
    $stmt = db()->prepare(
        "INSERT INTO cash_transactions
            (txn_date, cash_account_id, direction, amount, category, party, description,
             customer_id, supplier_id, source_type, source_id, transfer_group, created_by)
         VALUES
            (:d, :acct, :dir, :amt, :cat, :party, :descr,
             :cust, :supp, :stype, :sid, :grp, :by)
         RETURNING cash_txn_id"
    );
    $stmt->execute([
        ':d'     => $row['date'],
        ':acct'  => $row['account_id'],
        ':dir'   => $row['direction'],
        ':amt'   => $row['amount'],
        ':cat'   => $row['category'],
        ':party' => $row['party'] ?? null,
        ':descr' => $row['description'] ?? null,
        ':cust'  => $row['customer_id'] ?? null,
        ':supp'  => $row['supplier_id'] ?? null,
        ':stype' => $row['source_type'] ?? null,
        ':sid'   => $row['source_id'] ?? null,
        ':grp'   => $row['transfer_group'] ?? null,
        ':by'    => current_user()['id'] ?? null,
    ]);
    return (int) $stmt->fetchColumn();
}

/**
 * Move money between two accounts, both legs at once.
 *
 * A half-recorded transfer is how a cash book stops balancing, so
 * there is no way to write only one side.
 */
function cash_post_transfer(array $row): int
{
    $group = (int) db_value("SELECT nextval('cash_transfer_group_seq')");

    foreach ([[$row['from_account_id'], 'out'], [$row['to_account_id'], 'in']] as [$acct, $dir]) {
        cash_post([
            'date'           => $row['date'],
            'account_id'     => $acct,
            'direction'      => $dir,
            'amount'         => $row['amount'],
            'category'       => 'transfer',
            'party'          => $row['party'] ?? null,
            'description'    => $row['description'] ?? null,
            'source_type'    => $row['source_type'] ?? null,
            'source_id'      => $dir === 'out' ? ($row['source_id'] ?? null) : null,
            'transfer_group' => $group,
        ]);
    }
    return $group;
}

/**
 * Remove the ledger entries a document wrote.
 *
 * Used when a receipt or disbursement is reversed. Transfers go
 * as a pair, found through the group the outgoing leg carries.
 */
function cash_unpost(string $sourceType, int $sourceId): void
{
    $groups = db_all(
        "SELECT DISTINCT transfer_group FROM cash_transactions
          WHERE source_type = :t AND source_id = :i AND transfer_group IS NOT NULL",
        [':t' => $sourceType, ':i' => $sourceId]
    );
    foreach ($groups as $g) {
        db_run("DELETE FROM cash_transactions WHERE transfer_group = :g", [':g' => $g['transfer_group']]);
    }
    db_run(
        "DELETE FROM cash_transactions WHERE source_type = :t AND source_id = :i",
        [':t' => $sourceType, ':i' => $sourceId]
    );
}

/* ─────────────────────────────────────────────────────────────
 *  Payments against a document
 * ────────────────────────────────────────────────────────────
 *  A receipt has to land in two places at once: the cash book,
 *  which says money arrived, and the customer's account, which
 *  says they no longer owe it. Writing one without the other is
 *  how a set of books starts disagreeing with itself — the cash
 *  is right and the debtors are wrong, or the other way round.
 *
 *  So there is one function for a customer receipt and one for a
 *  supplier payment, and every page that records either calls it:
 *  the invoice, the payments register, and the cash book. The
 *  rules about what may be paid, and how much, are therefore the
 *  same rules wherever the entry is made from.
 *
 *  Both expect to be called inside a transaction, and both raise
 *  RuntimeException with a message written for the person at the
 *  screen. Callers must catch PDOException *before* it, since
 *  PDOException extends RuntimeException.
 * ──────────────────────────────────────────────────────────── */

/**
 * Record a receipt against an invoice.
 *
 * @param array $in invoice_id, date, amount, method, reference,
 *                  account_id | advance_id, notes, proof_url,
 *                  proof_filename
 * @return array{payment_id:int, invoice:array, outstanding:float, posted:bool}
 */
function cash_record_invoice_receipt(array $in): array
{
    $invoiceId = (int) ($in['invoice_id'] ?? 0);
    $invoice   = $invoiceId > 0 ? db_one(
        "SELECT i.*,
                " . customer_name_sql('c') . " AS customer_name
           FROM invoices i
           LEFT JOIN customers c ON c.customer_id = i.customer_id
          WHERE i.invoice_id = :id",
        [':id' => $invoiceId]
    ) : null;

    if (!$invoice) {
        throw new RuntimeException('Choose the invoice this payment is for.');
    }
    if ($invoice['status'] === 'cancelled') {
        throw new RuntimeException('A cancelled invoice cannot take a payment.');
    }
    if ($invoice['status'] === 'draft') {
        throw new RuntimeException('Issue the invoice before recording a payment against it.');
    }

    $date = (string) ($in['date'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Please provide a valid payment date.');
    }
    if ($date > date('Y-m-d')) {
        throw new RuntimeException('A payment cannot be dated in the future.');
    }

    $amount = round((float) ($in['amount'] ?? 0), 2);
    if ($amount <= 0) {
        throw new RuntimeException('The amount must be more than zero.');
    }

    $method = (string) ($in['method'] ?? '');
    if (!isset(PAYMENT_METHODS[$method]) || $method === 'salary_deduction') {
        throw new RuntimeException('Choose how the payment was made.');
    }

    // Either new money into an account, or an advance the customer
    // paid earlier being drawn down. Never both: the money for an
    // advance arrived when the advance did, and banking it twice
    // would invent cash the business does not have.
    $advanceId = isset($in['advance_id']) && $in['advance_id'] !== null ? (int) $in['advance_id'] : null;
    $accountId = isset($in['account_id']) && $in['account_id'] !== null ? (int) $in['account_id'] : null;

    if ($advanceId !== null) {
        $accountId = null;
    } else {
        $account = $accountId !== null
            ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
            : null;
        if (!$account || !cash_type_is_liquid($account['account_type'])) {
            throw new RuntimeException('Choose the account the money was paid into.');
        }
    }

    // What is still owed, credit notes and refunds included. The
    // database refuses a receipt bigger than the invoice total; this
    // is the stricter and more useful rule, because an invoice half
    // credited is not owed in full any more.
    $outstanding = invoice_outstanding($invoiceId);
    if ($outstanding <= 0.005) {
        throw new RuntimeException($invoice['invoice_number'] . ' has nothing left outstanding.');
    }
    if ($amount > $outstanding + 0.005) {
        throw new RuntimeException(sprintf(
            'Only %s is still owed on %s. Record the difference as a customer advance instead.',
            money($outstanding),
            $invoice['invoice_number']
        ));
    }

    $reference = trim((string) ($in['reference'] ?? ''));

    $stmt = db()->prepare(
        "INSERT INTO invoice_payments
            (invoice_id, payment_date, amount, method, reference, cash_account_id,
             proof_url, proof_filename, from_advance_id, notes, recorded_by)
         VALUES (:inv, :d, :amt, :method, :ref, :acct, :purl, :pname, :adv, :notes, :by)
         RETURNING payment_id"
    );
    $stmt->execute([
        ':inv'   => $invoiceId,
        ':d'     => $date,
        ':amt'   => $amount,
        ':method' => $method,
        ':ref'   => $reference !== '' ? $reference : null,
        ':acct'  => $accountId,
        ':purl'  => $in['proof_url'] ?? null,
        ':pname' => $in['proof_filename'] ?? null,
        ':adv'   => $advanceId,
        ':notes' => ($in['notes'] ?? '') !== '' ? $in['notes'] : null,
        ':by'    => current_user()['id'] ?? null,
    ]);
    $paymentId = (int) $stmt->fetchColumn();

    // Only a receipt of new money moves the cash book.
    if ($accountId !== null) {
        cash_post([
            'date'        => $date,
            'account_id'  => $accountId,
            'direction'   => 'in',
            'amount'      => $amount,
            'category'    => 'invoice_payment',
            'party'       => ($in['party'] ?? '') !== '' ? $in['party'] : ($invoice['customer_name'] ?: null),
            'description' => ($in['description'] ?? '') !== ''
                ? $in['description']
                : 'Payment for ' . $invoice['invoice_number'] . ($reference !== '' ? ' · ' . $reference : ''),
            'customer_id' => $invoice['customer_id'],
            'source_type' => 'invoice_payment',
            'source_id'   => $paymentId,
        ]);
    }

    return [
        'payment_id'  => $paymentId,
        'invoice'     => $invoice,
        'outstanding' => round($outstanding - $amount, 2),
        'posted'      => $accountId !== null,
    ];
}

/**
 * Record a payment against a purchase order.
 *
 * @param array $in po_id, date, amount, method, reference,
 *                  account_id, notes, proof_url, proof_filename
 * @return array{payment_id:int, order:array, outstanding:float}
 */
function cash_record_supplier_payment(array $in): array
{
    $poId  = (int) ($in['po_id'] ?? 0);
    $order = $poId > 0 ? db_one(
        "SELECT po.*, s.name AS supplier_name
           FROM purchase_orders po
           LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id
          WHERE po.po_id = :id",
        [':id' => $poId]
    ) : null;

    if (!$order) {
        throw new RuntimeException('That purchase order no longer exists.');
    }
    // The database refuses these too, but a rule the user can read
    // is worth more than a trigger message with a 500 behind it.
    if (in_array($order['status'], ['cancelled', 'rejected'], true)) {
        throw new RuntimeException(
            $order['po_number'] . ' is ' . $order['status'] . ' and cannot take a payment.'
        );
    }
    if ($order['status'] === 'draft') {
        throw new RuntimeException(
            $order['po_number'] . ' is still a draft. Send it before paying against it.'
        );
    }

    $date = (string) ($in['date'] ?? '') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Please provide a valid payment date.');
    }
    if ($date > date('Y-m-d')) {
        throw new RuntimeException('A payment cannot be dated in the future.');
    }

    $amount = round((float) ($in['amount'] ?? 0), 2);
    if ($amount <= 0) {
        throw new RuntimeException('The amount must be more than zero.');
    }

    $method = (string) ($in['method'] ?? '');
    if (!isset(PAYMENT_METHODS[$method]) || $method === 'salary_deduction') {
        throw new RuntimeException('Choose how the payment was made.');
    }

    // Unlike a customer receipt there is no advance to draw on: the
    // money always leaves a real account.
    $accountId = isset($in['account_id']) && $in['account_id'] !== null ? (int) $in['account_id'] : null;
    $account   = $accountId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
        : null;
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        throw new RuntimeException('Choose the account the money was paid from.');
    }

    $outstanding = round((float) $order['total_amount'] - (float) $order['amount_paid'], 2);
    if ($outstanding <= 0.005) {
        throw new RuntimeException($order['po_number'] . ' is already paid in full.');
    }
    if ($amount > $outstanding + 0.005) {
        throw new RuntimeException(sprintf(
            'Only %s is still to pay on %s.',
            money($outstanding),
            $order['po_number']
        ));
    }

    $reference = trim((string) ($in['reference'] ?? ''));

    $stmt = db()->prepare(
        "INSERT INTO purchase_payments
            (po_id, payment_date, amount, method, reference, cash_account_id,
             proof_url, proof_filename, notes, recorded_by)
         VALUES (:po, :d, :amt, :method, :ref, :acct, :purl, :pname, :notes, :by)
         RETURNING payment_id"
    );
    $stmt->execute([
        ':po'    => $poId,
        ':d'     => $date,
        ':amt'   => $amount,
        ':method' => $method,
        ':ref'   => $reference !== '' ? $reference : null,
        ':acct'  => $accountId,
        ':purl'  => $in['proof_url'] ?? null,
        ':pname' => $in['proof_filename'] ?? null,
        ':notes' => ($in['notes'] ?? '') !== '' ? $in['notes'] : null,
        ':by'    => current_user()['id'] ?? null,
    ]);
    $paymentId = (int) $stmt->fetchColumn();

    // The money really left an account, so the cash book says so.
    // This is what puts a figure on the daily position's "paid to
    // suppliers" line.
    cash_post([
        'date'        => $date,
        'account_id'  => $accountId,
        'direction'   => 'out',
        'amount'      => $amount,
        'category'    => 'purchase',
        'party'       => ($in['party'] ?? '') !== '' ? $in['party'] : ($order['supplier_name'] ?: null),
        'description' => ($in['description'] ?? '') !== ''
            ? $in['description']
            : 'Payment for ' . $order['po_number'] . ($reference !== '' ? ' · ' . $reference : ''),
        'supplier_id' => $order['supplier_id'],
        'source_type' => 'purchase_payment',
        'source_id'   => $paymentId,
    ]);

    return [
        'payment_id'  => $paymentId,
        'order'       => $order,
        'outstanding' => round($outstanding - $amount, 2),
    ];
}

/**
 * The receivable account carrying an employee's loan balance.
 *
 * Created on first disbursement so the staff-loan page never asks
 * anyone to set one up by hand.
 */
function cash_staff_loan_account(int $userId, string $employeeName): int
{
    $existing = db_value(
        "SELECT cash_account_id FROM cash_accounts
          WHERE account_type = 'loan' AND holder_user_id = :u
          ORDER BY cash_account_id LIMIT 1",
        [':u' => $userId]
    );
    if ($existing) {
        return (int) $existing;
    }

    // Two employees can share a name, so the account name is made
    // unique rather than allowed to collide.
    $base = trim($employeeName) . ' (staff loan)';
    $name = $base;
    for ($n = 2; db_value("SELECT 1 FROM cash_accounts WHERE LOWER(name) = LOWER(:n)", [':n' => $name]); $n++) {
        $name = $base . ' ' . $n;
    }

    $stmt = db()->prepare(
        "INSERT INTO cash_accounts (name, account_type, holder_user_id, sort_order, notes)
         VALUES (:n, 'loan', :u, 50, 'Created automatically when a staff loan was disbursed.')
         RETURNING cash_account_id"
    );
    $stmt->execute([':n' => $name, ':u' => $userId]);
    return (int) $stmt->fetchColumn();
}
