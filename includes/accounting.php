<?php

/**
 * ============================================================
 *  Double entry — the chart of accounts and the journal
 * ------------------------------------------------------------
 *  Everything financial in this application was single entry.
 *  That is not a criticism of it: a shop can run for years on
 *  documents and a cash book, and this one does. What single
 *  entry cannot do is prove itself. Nothing has to reconcile,
 *  no figure can be traced through an account, and anything
 *  that is not an invoice, a payment or an expense — a
 *  depreciation charge, an accrual, an owner's drawing, the
 *  opening balances on the first day — has nowhere to go.
 *
 *  The documents stay authoritative. The journal records what
 *  they mean in accounting terms, carrying the document it came
 *  from, and a unique index means one document posts exactly
 *  once however often the posting routine runs. The profit and
 *  loss keeps reading documents; the trial balance reads the
 *  journal; and where the two disagree the screen says so.
 *
 *  Two sets of books that disagree silently is the worst
 *  outcome available here, and it is the one thing this file is
 *  arranged to prevent.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/documents.php';

/**
 * Which side of an account increases it.
 *
 * Assets and expenses are debit-normal; liabilities, equity and
 * income are credit-normal. Every balance and every report in
 * this file is a signed sum, and this function is the sign.
 */
function account_is_debit_normal(string $type): bool
{
    return in_array($type, ['asset', 'expense'], true);
}

/** SQL for an account's movement, signed the way the account runs. */
function account_movement_sql(string $lines = 'jl', string $acc = 'a'): string
{
    return "CASE WHEN {$acc}.type IN ('asset', 'expense')
                 THEN COALESCE(SUM({$lines}.debit), 0) - COALESCE(SUM({$lines}.credit), 0)
                 ELSE COALESCE(SUM({$lines}.credit), 0) - COALESCE(SUM({$lines}.debit), 0)
            END";
}

/** The whole chart, ordered the way it is read. */
function accounts_all(bool $activeOnly = false): array
{
    return db_all(
        'SELECT a.*, p.name AS parent_name
           FROM accounts a
      LEFT JOIN accounts p ON p.account_id = a.parent_id'
        . ($activeOnly ? ' WHERE a.is_active' : '') .
        ' ORDER BY a.code'
    );
}

/** The accounts somebody may actually post to. */
function accounts_postable(): array
{
    return db_all(
        'SELECT account_id, code, name, type, subtype
           FROM accounts
          WHERE is_active AND NOT is_header
          ORDER BY code'
    );
}

/**
 * One account by its code.
 *
 * The posting routines name accounts by code rather than id,
 * because a code is stable, readable in a diff, and survives a
 * database rebuilt from the migrations in a different order.
 */
function account_by_code(string $code): ?array
{
    static $cache = [];
    if (!array_key_exists($code, $cache)) {
        $cache[$code] = db_one(
            'SELECT * FROM accounts WHERE code = :c',
            [':c' => $code]
        );
    }
    return $cache[$code];
}

/**
 * Write one balanced entry.
 *
 *   $entry  date, memo, source_table, source_id
 *   $lines  [ ['account' => '1110', 'debit' => 250.00, 'memo' => …], … ]
 *           'account' takes a code or an account_id.
 *
 * Returns ['ok' => bool, 'message' => string, 'entry_id' => ?int,
 *          'number' => ?string, 'existing' => bool].
 *
 * Idempotent where a source is given: posting the same document
 * twice returns the first entry rather than a second one, so the
 * posting routine can be re-run over history without doubling
 * the books. That is also why it checks before writing rather
 * than relying on the unique index to throw — a caught exception
 * would already have burned a document number.
 */
function journal_post(array $entry, array $lines): array
{
    $sourceTable = $entry['source_table'] ?? null;
    $sourceId    = isset($entry['source_id']) ? (int) $entry['source_id'] : null;

    if ($sourceTable && $sourceId) {
        $already = db_one(
            'SELECT entry_id, entry_number FROM journal_entries
              WHERE source_table = :t AND source_id = :i',
            [':t' => $sourceTable, ':i' => $sourceId]
        );
        if ($already) {
            return [
                'ok'       => true,
                'existing' => true,
                'entry_id' => (int) $already['entry_id'],
                'number'   => $already['entry_number'],
                'message'  => 'Already posted as ' . $already['entry_number'] . '.',
            ];
        }
    }

    //  Resolve codes to ids and total both sides before opening a
    //  transaction. A refusal that never started one cannot leave
    //  anything half-written.
    $resolved = [];
    $debits   = 0.0;
    $credits  = 0.0;

    foreach ($lines as $i => $line) {
        /*  Read the amounts first, because whether this row is a
            line at all is decided by them. The form renders six
            rows and most entries use two; the four blank ones
            have no account and no amount, and demanding an
            account before noticing they are blank refused every
            entry anybody typed. Found by filling the real form
            in a browser rather than by reading this.

            A row with an amount and no account is a different
            thing — somebody meant something by it — so that is
            refused and says which line.                        */
        $debit  = round((float) ($line['debit']  ?? 0), 2);
        $credit = round((float) ($line['credit'] ?? 0), 2);

        $ref = '';
        if (isset($line['account_id']) && $line['account_id'] !== '' && $line['account_id'] !== null) {
            $ref = (string) $line['account_id'];
        } elseif (isset($line['account']) && $line['account'] !== '' && $line['account'] !== null) {
            $ref = (string) $line['account'];
        }

        if ($ref === '' && $debit === 0.0 && $credit === 0.0) {
            continue; // a blank row on the form
        }
        if ($ref === '') {
            return journal_refusal(
                'Line ' . ($i + 1) . ' has an amount but no account.'
            );
        }
        if ($debit === 0.0 && $credit === 0.0) {
            continue; // an account chosen and nothing entered against it
        }

        /*  'account' is a code, 'account_id' is an id, and neither
            is guessed from the other. Sniffing with ctype_digit()
            was the first attempt and cannot work here: every code
            in the chart is digits, so '1110' read as an id found
            nothing and every posting was refused.               */
        $account = isset($line['account_id']) && $line['account_id'] !== '' && $line['account_id'] !== null
            ? db_one('SELECT * FROM accounts WHERE account_id = :id', [':id' => (int) $line['account_id']])
            : account_by_code($ref);

        if (!$account) {
            return journal_refusal('Line ' . ($i + 1) . ' names an account that does not exist: ' . $ref);
        }
        if ($account['is_header']) {
            return journal_refusal(
                $account['name'] . ' is a heading and totals its children; post to one of those instead.'
            );
        }
        if ($debit < 0 || $credit < 0) {
            return journal_refusal('A line cannot be negative. Put it on the other side instead.');
        }
        if ($debit > 0 && $credit > 0) {
            return journal_refusal('Line ' . ($i + 1) . ' has both a debit and a credit.');
        }

        $debits  += $debit;
        $credits += $credit;
        $resolved[] = [
            'account_id' => (int) $account['account_id'],
            'debit'      => $debit,
            'credit'     => $credit,
            'memo'       => $line['memo'] ?? null,
        ];
    }

    if (count($resolved) < 2) {
        return journal_refusal('An entry needs at least two lines — something given and something taken.');
    }

    /*  Compared at two decimals, which is the precision the
        column holds. Comparing floats exactly would reject an
        entry that is correct to the cent because a third of a
        shilling does not divide evenly.                        */
    if (abs($debits - $credits) > 0.005) {
        return journal_refusal(sprintf(
            'That does not balance: debits %s, credits %s, out by %s.',
            number_format($debits, 2),
            number_format($credits, 2),
            number_format(abs($debits - $credits), 2)
        ));
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $number = next_document_number('journal_entries', 'entry_number', 'JE');

        $ins = $pdo->prepare(
            'INSERT INTO journal_entries
                (entry_number, entry_date, memo, source_table, source_id, reverses_id, created_by)
             VALUES (:n, :d, :m, :st, :si, :rev, :by)
             RETURNING entry_id'
        );
        $ins->execute([
            ':n'   => $number,
            ':d'   => $entry['date'] ?? date('Y-m-d'),
            ':m'   => ($entry['memo'] ?? '') !== '' ? $entry['memo'] : null,
            ':st'  => $sourceTable,
            ':si'  => $sourceTable ? $sourceId : null,
            ':rev' => $entry['reverses_id'] ?? null,
            ':by'  => current_user()['id'] ?? null,
        ]);
        $entryId = (int) $ins->fetchColumn();

        $lineIns = $pdo->prepare(
            'INSERT INTO journal_lines (entry_id, account_id, debit, credit, memo, line_no)
             VALUES (:e, :a, :d, :c, :m, :n)'
        );
        foreach ($resolved as $n => $line) {
            $lineIns->execute([
                ':e' => $entryId,
                ':a' => $line['account_id'],
                ':d' => $line['debit'],
                ':c' => $line['credit'],
                ':m' => $line['memo'],
                ':n' => $n + 1,
            ]);
        }

        /*  The deferred constraint trigger fires here, not above.
            If the arithmetic disagrees with the database's own
            view of it, this is where it is caught — and nothing
            is written.                                          */
        $pdo->commit();

        return [
            'ok'       => true,
            'existing' => false,
            'entry_id' => $entryId,
            'number'   => $number,
            'message'  => 'Posted as ' . $number . '.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[JOURNAL] ' . $e->getMessage());

        //  The balance trigger's own words are worth passing on;
        //  anything else is not something a user can act on.
        $message = str_contains($e->getMessage(), 'does not balance')
            ? 'That entry does not balance.'
            : 'That entry could not be posted. Nothing was written.';

        return ['ok' => false, 'existing' => false, 'entry_id' => null, 'number' => null, 'message' => $message];
    }
}

/** Shorthand for the refusals above. */
function journal_refusal(string $message): array
{
    return ['ok' => false, 'existing' => false, 'entry_id' => null, 'number' => null, 'message' => $message];
}

/**
 * The trial balance.
 *
 * Every account with its debits, its credits, and the balance in
 * the column the account runs in. If the two totals differ, the
 * books do not balance — which the constraint trigger should make
 * impossible, so the screen treats it as an alarm rather than a
 * figure.
 */
function trial_balance(string $from, string $to): array
{
    $rows = db_all(
        'SELECT a.account_id, a.code, a.name, a.type, a.subtype,
                COALESCE(SUM(jl.debit), 0)  AS debits,
                COALESCE(SUM(jl.credit), 0) AS credits
           FROM accounts a
      LEFT JOIN journal_lines   jl ON jl.account_id = a.account_id
      LEFT JOIN journal_entries je ON je.entry_id = jl.entry_id
                                  AND je.entry_date BETWEEN :from AND :to
          WHERE NOT a.is_header
            AND je.entry_id IS NOT NULL
          GROUP BY a.account_id, a.code, a.name, a.type, a.subtype
          ORDER BY a.code',
        [':from' => $from, ':to' => $to]
    );

    $totalDebit = $totalCredit = 0.0;
    foreach ($rows as &$row) {
        $row['debits']  = (float) $row['debits'];
        $row['credits'] = (float) $row['credits'];
        $row['balance'] = account_is_debit_normal((string) $row['type'])
            ? $row['debits'] - $row['credits']
            : $row['credits'] - $row['debits'];
        $totalDebit  += $row['debits'];
        $totalCredit += $row['credits'];
    }
    unset($row);

    return [
        'rows'      => $rows,
        'debits'    => $totalDebit,
        'credits'   => $totalCredit,
        'balanced'  => abs($totalDebit - $totalCredit) < 0.005,
        'out_by'    => $totalDebit - $totalCredit,
    ];
}

/**
 * What an account held before the period opened.
 *
 * The date filter is a WHERE over an inner join, and that is the
 * whole point of this function rather than a detail of it. It was
 * written as
 *
 *     LEFT JOIN journal_entries je
 *            ON je.entry_id = jl.entry_id AND je.entry_date < :from
 *
 * which reads as a filter and is not one: a condition in a LEFT
 * JOIN's ON clause does not remove rows, it only leaves the right
 * side NULL. Every line the account had ever carried stayed in
 * the SUM, so the opening balance was the all-time total — on
 * every ledger, in every period, silently, because with a few
 * entries in one month it looks plausible.
 *
 * A load test is what found it: ten thousand entries made the
 * opening balance twenty-five million against a first movement of
 * four thousand, which is not a number anybody can misread.
 */
function account_opening_balance(int $accountId, string $from): float
{
    $type = db_value('SELECT type FROM accounts WHERE account_id = :id', [':id' => $accountId]);
    if ($type === null || $type === false) {
        return 0.0;
    }

    $row = db_one(
        'SELECT COALESCE(SUM(jl.debit), 0)  AS debits,
                COALESCE(SUM(jl.credit), 0) AS credits
           FROM journal_lines jl
           JOIN journal_entries je ON je.entry_id = jl.entry_id
          WHERE jl.account_id = :id
            AND je.entry_date < :from',
        [':id' => $accountId, ':from' => $from]
    );

    $debits  = (float) ($row['debits']  ?? 0);
    $credits = (float) ($row['credits'] ?? 0);

    return account_is_debit_normal((string) $type) ? $debits - $credits : $credits - $debits;
}

/**
 * One account's movements, with a running balance, one page at
 * a time.
 *
 * Paginated because it was not, and a load test said so: ten
 * thousand entries against Cash in hand rendered a seven
 * megabyte page. A pharmacy taking a hundred payments a day puts
 * thirty-six thousand lines a year through that one account, so
 * the page was going to reach twenty-five megabytes inside a
 * year of ordinary trading and then keep going.
 *
 * The running balance is computed by Postgres over the whole
 * period and only then cut to a page, because a balance carried
 * down has to count every row before it — doing the sum in PHP
 * would mean fetching everything again, which is the thing being
 * fixed.
 */
function account_ledger(int $accountId, string $from, string $to, int $perPage = 50, int $offset = 0): array
{
    $account = db_one('SELECT * FROM accounts WHERE account_id = :id', [':id' => $accountId]);
    if (!$account) {
        return ['account' => null, 'rows' => [], 'opening' => 0.0, 'closing' => 0.0, 'total' => 0];
    }

    $debitNormal = account_is_debit_normal((string) $account['type']);
    $movement    = $debitNormal ? '(jl.debit - jl.credit)' : '(jl.credit - jl.debit)';
    $opening     = account_opening_balance($accountId, $from);

    $total = (int) db_value(
        'SELECT COUNT(*)
           FROM journal_lines jl
           JOIN journal_entries je ON je.entry_id = jl.entry_id
          WHERE jl.account_id = :id AND je.entry_date BETWEEN :from AND :to',
        [':id' => $accountId, ':from' => $from, ':to' => $to]
    );

    $rows = db_all(
        'SELECT * FROM (
             SELECT je.entry_id, je.entry_number, je.entry_date, je.memo AS entry_memo,
                    je.source_table, je.source_id,
                    jl.debit, jl.credit, jl.memo AS line_memo,
                    SUM(' . $movement . ') OVER (
                        ORDER BY je.entry_date, je.entry_id, jl.line_no, jl.line_id
                        ROWS UNBOUNDED PRECEDING
                    ) AS running
               FROM journal_lines jl
               JOIN journal_entries je ON je.entry_id = jl.entry_id
              WHERE jl.account_id = :id
                AND je.entry_date BETWEEN :from AND :to
         ) t
         ORDER BY t.entry_date, t.entry_id
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        [':id' => $accountId, ':from' => $from, ':to' => $to]
    );

    foreach ($rows as &$row) {
        $row['balance'] = $opening + (float) $row['running'];
    }
    unset($row);

    //  The closing balance is the whole period's, not the page's:
    //  somebody looking at page one still needs to know where the
    //  account ends up.
    $closing = $opening + (float) db_value(
        'SELECT COALESCE(SUM(' . $movement . '), 0)
           FROM journal_lines jl
           JOIN journal_entries je ON je.entry_id = jl.entry_id
          WHERE jl.account_id = :id AND je.entry_date BETWEEN :from AND :to',
        [':id' => $accountId, ':from' => $from, ':to' => $to]
    );

    return [
        'account' => $account,
        'rows'    => $rows,
        'opening' => $opening,
        'closing' => $closing,
        'total'   => $total,
    ];
}

/**
 * What the journal says the period made, by the same shape the
 * profit and loss uses.
 *
 * This exists to be compared against report_profit_and_loss(),
 * which reads documents instead. The two are built from different
 * sources on purpose: agreeing is evidence, and disagreeing is a
 * finding rather than a rounding difference to be hidden.
 */
function journal_profit_and_loss(string $from, string $to): array
{
    $rows = db_all(
        'SELECT a.type, a.subtype,
                COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) AS credit_net
           FROM accounts a
           JOIN journal_lines   jl ON jl.account_id = a.account_id
           JOIN journal_entries je ON je.entry_id = jl.entry_id
          WHERE je.entry_date BETWEEN :from AND :to
            AND a.type IN (\'income\', \'expense\')
          GROUP BY a.type, a.subtype',
        [':from' => $from, ':to' => $to]
    );

    $income = $costOfSales = $operating = 0.0;
    foreach ($rows as $row) {
        $net = (float) $row['credit_net'];
        if ($row['type'] === 'income') {
            $income += $net;
        } elseif ($row['subtype'] === 'cost_of_sales') {
            $costOfSales += -$net;
        } else {
            $operating += -$net;
        }
    }

    return [
        'income'        => $income,
        'cost_of_sales' => $costOfSales,
        'gross_profit'  => $income - $costOfSales,
        'expenses'      => $operating,
        'net_profit'    => $income - $costOfSales - $operating,
    ];
}
