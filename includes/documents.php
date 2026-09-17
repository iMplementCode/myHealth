<?php

/**
 * ============================================================
 *  Document numbering
 * ------------------------------------------------------------
 *  Sequential, year-scoped document numbers for proformas and
 *  invoices (e.g. INV-2026-0007). Call inside the transaction
 *  that inserts the document: the number is taken by locking a
 *  counter row, and that lock is only worth anything for as long
 *  as the transaction that writes the document holds it.
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


/**
 * The default currency for new documents. Prefers the business
 * base currency (KES) so quotes/orders/invoices are never
 * accidentally created in a non-base currency just because it
 * happened to have a lower id. Falls back to the first active
 * currency, then any currency.
 */
function default_currency_id(): ?int
{
    $id = db_value(
        "SELECT currency_id FROM currencies
         ORDER BY (LOWER(code) = 'kes') DESC, is_active DESC, currency_id ASC
         LIMIT 1"
    );
    return $id !== null ? (int) $id : null;
}

/**
 * When an invoice raised on a given day falls due.
 *
 * Every invoice needs one, and for a long time only invoices
 * raised from a sales order got it: the proforma route and the
 * "goods not returned" route both left the column NULL. Aged
 * receivables falls back to the issue date when there is no due
 * date, so those invoices were counted as overdue the day after
 * they were raised — which made the ageing report either alarming
 * or, on the day everything was invoiced, empty.
 *
 * One function, so all three routes state the same terms, and
 * INVOICE_PAYMENT_TERMS_DAYS changes them in one place.
 */
function invoice_due_date(?string $issueDate = null): string
{
    $issue = $issueDate ?: date('Y-m-d');
    return date('Y-m-d', strtotime($issue . ' +' . INVOICE_PAYMENT_TERMS_DAYS . ' days'));
}

/**
 * The highest sequence number already used for a prefix and year,
 * read from the document table itself. 0 when there is none.
 *
 * This is what the allocator used to do on every single call. It is
 * now the seeding read only: once for a document type in a year, and
 * again if the counter is ever found to be behind the table.
 */
function document_number_high_water(string $table, string $column, string $prefix, string $year): int
{
    return (int) db_value(
        "SELECT MAX(CAST(SUBSTRING($column FROM '\\d+$') AS INTEGER))
         FROM $table WHERE $column LIKE :like",
        [':like' => $prefix . '-' . $year . '-%']
    );
}

/**
 * The number the next document of this kind should carry, e.g.
 * INV-2026-0007. Call it inside the transaction that inserts the
 * document.
 *
 * That instruction used to come with the claim that a transaction was
 * enough to stop two requests claiming the same number. It was not.
 * MAX(number)+1 read the table and let go of it; two transactions read
 * the same 41, both built INV-2026-0042, and the UNIQUE constraint
 * threw out whichever committed second. Two workers saving at the same
 * instant lost one of themselves in every round of eight — and what
 * the loser saw was an error page where their invoice used to be.
 *
 * So the number now comes from a row in document_sequences, one row
 * per document type per year, and the number is produced by
 * incrementing it. Postgres holds that row until the transaction ends,
 * which turns a race into a short wait: the second caller does not
 * read a stale maximum, it waits for the first to commit and is handed
 * the next number. Because the row is transactional, a save that rolls
 * back releases its number too, and the numbering stays gapless —
 * which is the reason this is not a Postgres SEQUENCE. Migration 053
 * has the full argument.
 */
function next_document_number(string $table, string $column, string $prefix): string
{
    // Both are interpolated into the SQL below. Every caller passes a
    // literal; this is what keeps that true if one day one does not.
    foreach ([$table, $column] as $identifier) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('next_document_number: ' . $identifier . ' is not an identifier.');
        }
    }

    $year = date('Y');

    if (!table_exists('document_sequences')) {
        // One migration behind. The old read, and the old race with it
        // — but the app answers, which is the point of the guard.
        return sprintf('%s-%s-%04d', $prefix, $year,
            document_number_high_water($table, $column, $prefix, $year) + 1);
    }

    $scope = $table . '.' . $column . '.' . $prefix . '.' . $year;

    // The row is the lock. A second allocator blocks here instead of
    // reading a maximum that is about to be wrong.
    $next = db_value(
        "UPDATE document_sequences
            SET last_value = last_value + 1, updated_at = clock_timestamp()
          WHERE scope = :scope
      RETURNING last_value",
        [':scope' => $scope]
    );

    if ($next === null) {
        // First document of this kind this year. Seed from what the
        // table actually holds. Two callers can arrive here at once, so
        // the loser of the insert takes the increment instead.
        $seed = document_number_high_water($table, $column, $prefix, $year) + 1;
        $next = db_value(
            "INSERT INTO document_sequences (scope, last_value) VALUES (:scope, :seed)
             ON CONFLICT (scope) DO UPDATE
                SET last_value = document_sequences.last_value + 1,
                    updated_at = clock_timestamp()
             RETURNING last_value",
            [':scope' => $scope, ':seed' => $seed]
        );
    }

    /*  A counter can fall behind the table it counts: a restored
     *  backup, an import, a number somebody typed straight into SQL.
     *  The column is UNIQUE, so checking costs an index probe, and it
     *  is the difference between noticing that here and handing
     *  somebody a number that is already on a document. Nothing
     *  uncommitted can hide from this read — whoever allocated before
     *  us had to commit before we could take the row. */
    for ($attempt = 1; ; $attempt++) {
        $candidate = sprintf('%s-%s-%04d', $prefix, $year, (int) $next);
        if (db_value("SELECT 1 FROM $table WHERE $column = :number", [':number' => $candidate]) === null) {
            return $candidate;
        }
        if ($attempt >= 5) {
            throw new RuntimeException('next_document_number: no free number for ' . $scope . '.');
        }
        // Move the counter to where the table actually got to.
        $next = document_number_high_water($table, $column, $prefix, $year) + 1;
        db_run(
            "UPDATE document_sequences SET last_value = :value, updated_at = clock_timestamp()
              WHERE scope = :scope",
            [':value' => $next, ':scope' => $scope]
        );
    }
}

/**
 * A unique, readable SKU derived from the product name and, when
 * present, its category — e.g. "CAM-DOME-0007".
 *
 * The trailing sequence is what guarantees uniqueness; the prefix is
 * only there to make the code recognisable on a shelf or a picking
 * list. Collisions are resolved by walking the sequence forward, so
 * two people saving at once cannot land on the same code (the unique
 * index on products.sku is the final arbiter either way).
 */
function generate_sku(string $name, ?int $categoryId = null): string
{
    /** Uppercase alphanumeric tokens from a string, longest-first order kept. */
    $tokens = static function (?string $text, int $max, int $len): array {
        $out = [];
        foreach (preg_split('/[^A-Za-z0-9]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            $out[] = strtoupper(substr($w, 0, $len));
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    };

    // One token from the category groups the shelf; up to two from the
    // product name keep sibling products apart.
    $parts = [];
    if ($categoryId !== null) {
        $cat = db_value("SELECT name FROM categories WHERE category_id = :id", [':id' => $categoryId]);
        $parts = array_merge($parts, $tokens($cat, 1, 4));
    }
    $parts = array_merge($parts, $tokens($name, 2, 4));

    $prefix = implode('-', array_filter($parts));
    if ($prefix === '') {
        $prefix = 'SKU';
    }

    // The trailing sequence is what actually guarantees uniqueness.
    // Walk it forward past anything already taken; the unique index on
    // products.sku is the final arbiter if two people save at once.
    $max = db_value(
        "SELECT MAX(CAST(SUBSTRING(sku FROM '\\d+$') AS INTEGER))
           FROM products WHERE sku LIKE :like",
        [':like' => $prefix . '-%']
    );
    $seq = ((int) $max) + 1;

    do {
        $candidate = sprintf('%s-%04d', $prefix, $seq);
        $taken = db_value("SELECT 1 FROM products WHERE sku = :s", [':s' => $candidate]);
        $seq++;
    } while ($taken && $seq < 100000);

    return $candidate;
}

/**
 * Margin on a line: absolute value and percentage of the selling
 * price. Percentage is null when nothing is being charged, since
 * margin on zero revenue is meaningless rather than infinite.
 *
 * @return array{value: float, percent: ?float}
 */
function margin_of(float $cost, float $sell, float $qty = 1.0, float $discount = 0.0): array
{
    $revenue = ($sell * $qty) - $discount;
    $value   = $revenue - ($cost * $qty);
    return [
        'value'   => $value,
        'percent' => $revenue > 0 ? ($value / $revenue) * 100 : null,
    ];
}

/**
 * The same margin percentage, as SQL — for ordering a list by it.
 *
 * margin_of() cannot be called from an ORDER BY, so this is the one
 * place the rule is written a second time, and the two must agree:
 * (price − cost) / price, and NULL when nothing is being charged,
 * because margin on no revenue is meaningless rather than infinite.
 * NULL sorts last either way, so the products with no price do not
 * pile up at the top of "worst margin first".
 *
 * If you change one, change the other. t_margin.py compares what
 * this orders by against what margin_of() prints, on every row, and
 * fails when they disagree.
 */
function margin_percent_sql(string $costCol, string $sellCol): string
{
    foreach ([$costCol, $sellCol] as $col) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $col)) {
            throw new InvalidArgumentException('margin_percent_sql: not a column name.');
        }
    }
    return "CASE WHEN $sellCol > 0 THEN (($sellCol - $costCol) / $sellCol) * 100 END";
}
