<?php

/**
 * ============================================================
 *  Shared Helper Functions
 * ------------------------------------------------------------
 *  Reusable, side-effect-free helpers used across the app:
 *  output escaping, CSRF, flash messages, redirects, input
 *  handling, formatting, slugs and pagination.
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


/* ─────────────────────────────────────────────────────────────
 *  Output escaping (XSS prevention)
 * ──────────────────────────────────────────────────────────── */

/** Escape a value for safe HTML output. Use everywhere echoing user data. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Escape a value for use inside a HTML attribute (alias of e()). */
function attr($value): string
{
    return e($value);
}

/* ─────────────────────────────────────────────────────────────
 *  Input handling / validation
 * ──────────────────────────────────────────────────────────── */

/** Read + trim a string field from an input array. */
function input(array $src, string $key, string $default = ''): string
{
    return isset($src[$key]) ? trim((string) $src[$key]) : $default;
}

/** Read an integer field, or null when empty/invalid. */
function input_int(array $src, string $key): ?int
{
    if (!isset($src[$key]) || $src[$key] === '') {
        return null;
    }
    return (int) $src[$key];
}

/** True when the current request is a POST. */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Validate an email address, returning the normalised value or null. */
function valid_email(string $email): ?string
{
    $email = strtolower(trim($email));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/* ─────────────────────────────────────────────────────────────
 *  CSRF protection
 * ──────────────────────────────────────────────────────────── */

/** Return the current CSRF token, generating one for the session if needed. */
function csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_KEY];
}

/** Render a hidden CSRF input for embedding in forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Verify a submitted CSRF token in constant time. */
function csrf_verify(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION[CSRF_TOKEN_KEY])
        && hash_equals($_SESSION[CSRF_TOKEN_KEY], $token);
}

/**
 * Enforce CSRF on state-changing requests. Call at the top of any
 * POST handler. Aborts the request with 403 on failure.
 */
function csrf_check(): void
{
    if (!is_post()) {
        return;
    }
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        die('Invalid or missing security token. Please reload the page and try again.');
    }
}

/* ─────────────────────────────────────────────────────────────
 *  Flash messages (survive one redirect)
 * ──────────────────────────────────────────────────────────── */

/** Queue a flash message of a given type: success | error | info | warning. */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear all queued flash messages. */
function flash_pull(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/* ─────────────────────────────────────────────────────────────
 *  Redirects / URLs
 * ──────────────────────────────────────────────────────────── */

/** Build an absolute app URL from a root-relative path. */
function url(string $path = ''): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

/**
 * URL for a stylesheet or script, stamped with its modification time.
 *
 * Browsers cache CSS and JS hard, and rightly so — but that means a
 * fix pulled onto a server can sit there doing nothing while every
 * open browser keeps running yesterday's copy, and the only cure is
 * telling each person to hard-refresh. The stamp changes when the
 * file does, so the URL changes too and the cache is bypassed
 * exactly when it needs to be, and honoured the rest of the time.
 */
function asset(string $path): string
{
    $stamp = @filemtime(PUBLIC_PATH . '/' . ltrim($path, '/'));
    return url($path) . ($stamp ? '?v=' . $stamp : '');
}

/** Send a redirect to an app path and stop execution. */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/* ─────────────────────────────────────────────────────────────
 *  VAT
 * ────────────────────────────────────────────────────────────
 *  Kenya charges VAT at two rates, not one. 16% is the general
 *  rate; 8% applies to petroleum products. Zero-rated and exempt
 *  supplies both come to nothing on the document — they differ
 *  in how they are declared, which is a matter for the return
 *  rather than for the invoice — so one "No VAT" line covers
 *  both here.
 *
 *  Every rate picker in the system is built from this list, so
 *  the day a rate changes it changes in one place. Rates are
 *  held as fractions because that is what the documents store
 *  and what the totals arithmetic multiplies by.
 * ──────────────────────────────────────────────────────────── */

const VAT_RATES = [
    '0.16' => 'VAT 16%',
    '0.08' => 'VAT 8%',
    '0'    => 'No VAT (0%)',
];

/* ─────────────────────────────────────────────────────────────
 *  Goods and services
 * ------------------------------------------------------------
 *  Installation, transport, a site survey, a service contract —
 *  the business sells all of these, and none of them sit on a
 *  shelf. They are products in every way that matters to a
 *  document (a name, a price, a quantity, a line total) and in
 *  no way that matters to stock.
 *
 *  Everything that counts, values, reorders or delivers stock
 *  filters on this. See migration 034 and docs/INVENTORY.md.
 * ──────────────────────────────────────────────────────────── */

const PRODUCT_TYPES = [
    'goods'   => 'Product',
    'service' => 'Service',
];

/**
 * SQL fragment: only physical things. Pass the products alias.
 *
 * Degrades to TRUE on a database that has not had migration 034
 * yet, where nothing is a service and everything is goods. Without
 * that, one unapplied migration takes out every page that values,
 * counts or reorders stock — including the daily position, which
 * has nothing to do with services.
 */
function goods_only_sql(string $alias = 'p'): string
{
    return column_exists('products', 'product_type')
        ? "$alias.product_type = 'goods'"
        : 'TRUE';
}

/** The same test, for a database column list rather than a query. */
function product_types_available(): bool
{
    return column_exists('products', 'product_type');
}

/**
 * SQL fragment: what to call a customer. Pass the customers alias.
 *
 * The company is the customer. Mitchelle did not buy the cameras —
 * Multline Autosystems did, Multline is on the LPO, Multline pays
 * the invoice, and Multline is who the debt is chased from. The
 * contact is who to ask for when you get there.
 *
 * So the company name leads wherever a customer is named — every
 * document, every list, every picker — and the contact follows it.
 * A walk-in with no company still shows as a person; nobody is
 * left as "Customer" who has a name of any kind.
 *
 * One expression, spliced into every query, because forty-odd
 * copies of it is how the invoice and the delivery note for the
 * same sale end up disagreeing about who it is for.
 */
function customer_name_sql(string $alias = 'c'): string
{
    $a = $alias === '' ? '' : $alias . '.';
    return "COALESCE(NULLIF(TRIM({$a}company_name), ''),"
         . " NULLIF(TRIM(CONCAT({$a}first_name, ' ', {$a}last_name)), ''),"
         . " 'Customer')";
}

/** The same choice, for a row already fetched. */
function customer_display_name(array $row): string
{
    $company = trim((string) ($row['company_name'] ?? ''));
    if ($company !== '') {
        return $company;
    }
    $person = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return $person !== '' ? $person : 'Customer';
}

/**
 * Who to ask for on arrival — blank when that is the company itself.
 *
 * Needs company_name, first_name and last_name on the row. A query
 * that selects the name columns but forgets company_name would
 * otherwise make the person look like the whole identity and drop
 * the contact off the document silently, which is exactly how three
 * of them lost it. Missing the key is treated as "cannot tell" and
 * shows the contact; an empty company is a walk-in, whose own name
 * is already the heading.
 */
function customer_contact_name(array $row): string
{
    $person = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($person === '') {
        return '';
    }
    if (!array_key_exists('company_name', $row)) {
        return $person;
    }
    return trim((string) $row['company_name']) === '' ? '' : $person;
}

/** Is this row a service? Tolerates a row read before migration 034. */
function is_service(array $product): bool
{
    return ($product['product_type'] ?? 'goods') === 'service';
}

/** "Product" or "Service", for a badge or a column. */
function product_type_label(?string $type): string
{
    return PRODUCT_TYPES[$type ?? 'goods'] ?? 'Product';
}

/** The default a new document opens on. */
const VAT_RATE_DEFAULT = '0.16';

/**
 * The <option> list for a VAT rate picker.
 *
 * Matching on the value rather than the string, so 0.16, "0.16"
 * and "0.1600" all select the same rate — a document read back
 * out of PostgreSQL brings its NUMERIC as "0.1600".
 */
function vat_rate_options($selected = VAT_RATE_DEFAULT): string
{
    $chosen = (float) $selected;
    $html   = '';
    foreach (VAT_RATES as $value => $label) {
        $on = abs((float) $value - $chosen) < 0.0001;
        $html .= '<option value="' . e($value) . '"' . ($on ? ' selected' : '') . '>'
               . e($label) . '</option>';
    }
    return $html;
}

/**
 * A stored rate as the exact string one of the options carries.
 *
 * A prefilled `<select>` shows nothing at all when its value
 * matches no option, and PostgreSQL hands back NUMERIC(6,4) as
 * "0.1600", which is not "0.16" to a browser. Unknown rates fall
 * back to no VAT rather than to a rate nobody chose.
 */
function vat_rate_value($rate): string
{
    foreach (array_keys(VAT_RATES) as $value) {
        if (abs((float) $value - (float) $rate) < 0.0001) {
            return (string) $value;
        }
    }
    return '0';
}

/** What to call a rate once it is on a document. */
function vat_rate_label($rate): string
{
    foreach (VAT_RATES as $value => $label) {
        if (abs((float) $value - (float) $rate) < 0.0001) {
            return $label;
        }
    }
    return 'VAT ' . num((float) $rate * 100, 2) . '%';
}

/* ─────────────────────────────────────────────────────────────
 *  Formatting
 * ──────────────────────────────────────────────────────────── */

/** Format a monetary amount. */
function money($amount, string $symbol = 'KES'): string
{
    return $symbol . ' ' . number_format((float) $amount, 2);
}

/**
 * An amount written out, the way a cheque carries it.
 *
 * A voucher for "5,000.00" can be altered with a pen; one that also
 * says "five thousand shillings only" cannot, quietly. That is the
 * entire reason cheques have carried both for two centuries, and a
 * voucher recording money paid out wants the same protection.
 *
 * Kenyan usage: shillings and cents.
 */
function amount_in_words(float $amount, string $currency = 'KES'): string
{
    $units = ['shillings' => 'shillings', 'cents' => 'cents'];
    if (strtoupper($currency) !== 'KES') {
        $units = ['shillings' => strtoupper($currency), 'cents' => 'cents'];
    }

    $negative = $amount < 0;
    $amount   = abs($amount);

    // Rounded to the cent first: 0.005 formatted as "0.01" but read
    // as zero cents would put two different figures on one page.
    $whole = (int) floor($amount);
    $cents = (int) round(($amount - $whole) * 100);
    if ($cents === 100) {
        $whole++;
        $cents = 0;
    }

    // "One shillings" reads as a typo on a document somebody is
    // being asked to sign.
    $unit = ($whole === 1 && $units['shillings'] === 'shillings') ? 'shilling' : $units['shillings'];

    $words = number_to_words($whole) . ' ' . $unit;
    if ($cents > 0) {
        $words .= ' and ' . number_to_words($cents)
                . ' ' . ($cents === 1 && $units['cents'] === 'cents' ? 'cent' : $units['cents']);
    }
    $words .= ' only';

    // Capitalise once, at the front, after the sign is attached —
    // doing it before produced "Minus Three hundred".
    return ucfirst(($negative ? 'minus ' : '') . $words);
}

/**
 * A whole number in English words.
 *
 * Written out rather than taken from NumberFormatter, which needs
 * ext-intl — an extension this application does not otherwise
 * require, and one that is missing often enough on shared hosting
 * that depending on it here would make a voucher fail to print on
 * exactly the servers least able to diagnose it.
 */
function number_to_words(int $n): string
{
    if ($n === 0) {
        return 'zero';
    }

    $small = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight',
              'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen',
              'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens  = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy',
              'eighty', 'ninety'];

    $under1000 = static function (int $n) use ($small, $tens, &$under1000): string {
        if ($n < 20) {
            return $small[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $small[$n % 10] : '');
        }
        return $small[intdiv($n, 100)] . ' hundred'
             . ($n % 100 ? ' and ' . $under1000($n % 100) : '');
    };

    $scales = [1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'];
    $parts  = [];
    foreach ($scales as $value => $name) {
        if ($n >= $value) {
            $parts[] = $under1000(intdiv($n, $value)) . ' ' . $name;
            $n %= $value;
        }
    }
    if ($n > 0) {
        $parts[] = $under1000($n);
    }

    return implode(' ', $parts);
}

/** Format a number with optional decimals. */
function num($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals);
}

/** Format a date/datetime string for display. */
function fmt_date(?string $value, string $format = 'd M Y'): string
{
    if (empty($value)) {
        return '—';
    }
    try {
        return (new DateTime($value))->format($format);
    } catch (Exception $e) {
        return '—';
    }
}

/** URL-friendly slug from arbitrary text. */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s]+/', '-', $text);
    return trim(preg_replace('/-+/', '-', $text), '-');
}

/** Return the initials for a full name (max 2 chars). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_filter($parts);
    if (!$parts) {
        return '?';
    }
    $first = mb_substr(reset($parts), 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return strtoupper($first . $last);
}

/* ─────────────────────────────────────────────────────────────
 *  Pagination
 * ──────────────────────────────────────────────────────────── */

/** True when a table exists in the connected database. */
function table_exists(string $table): bool
{
    try {
        return db_value("SELECT to_regclass(:t) IS NOT NULL", [':t' => $table]) === true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Does a column exist yet?
 *
 * The column-level twin of table_exists(), and it exists for the
 * same reason: a migration adds a column, and every page that
 * reads it must still answer on a database where that migration
 * has not been run. A table guard is not enough — `products` has
 * been there since the first migration; `products.product_type`
 * arrived in the thirty-fourth, and a query naming it turns the
 * daily position into a 500 on a database one migration behind.
 *
 * Cached per request. It is asked inside report queries, and a
 * catalogue round-trip per row would be a silly price for a
 * question whose answer cannot change mid-request.
 */
function column_exists(string $table, string $column): bool
{
    static $seen = [];
    $key = $table . '.' . $column;

    if (!array_key_exists($key, $seen)) {
        try {
            $seen[$key] = db_value(
                "SELECT EXISTS (SELECT 1 FROM information_schema.columns
                                 WHERE table_name = :t AND column_name = :c)",
                [':t' => $table, ':c' => $column]
            ) === true;
        } catch (Throwable $e) {
            $seen[$key] = false;
        }
    }
    return $seen[$key];
}

/**
 * Guard for pages whose tables arrive via migrations: renders a
 * friendly setup notice (instead of a 500) when a table is missing.
 */
function require_tables(string ...$tables): void
{
    foreach ($tables as $t) {
        if (!table_exists($t)) {
            http_response_code(503);
            $pageTitle = 'Setup required';
            require BASE_PATH . '/includes/header.php';
            echo '<div class="state-block">'
                . '<h1 class="state-title">One more setup step</h1>'
                . '<p class="state-text">This module needs a database migration that has not been applied yet '
                . '(missing table: <strong>' . e($t) . '</strong>). From the project folder, run:</p>'
                . '<pre class="state-text" style="user-select:all;">php database_structure/migrate.php</pre>'
                . '<p class="state-text">then reload this page.</p>'
                . '<a href="' . e(url('dashboard/index.php')) . '" class="btn btn-primary">Back to dashboard</a>'
                . '</div>';
            require BASE_PATH . '/includes/footer.php';
            exit;
        }
    }
}

/**
 * How many rows a list page shows before paging.
 *
 * One number for the whole application: every list agrees, and
 * changing it is a one-line change rather than a hunt through
 * twenty files.
 */
const PER_PAGE_DEFAULT = 10;

/** Largest page a user may ask for, so a URL cannot fetch everything. */
const PER_PAGE_MAX = 100;

/**
 * The page size for this request.
 *
 * Reads `?per_page=` and clamps it. Callers pass no argument
 * unless a page genuinely needs a different default.
 */
function per_page(int $default = PER_PAGE_DEFAULT): int
{
    return min(PER_PAGE_MAX, max(5, input_int($_GET, 'per_page') ?? $default));
}

/**
 * Compute pagination metadata.
 * Returns: page, per_page, total, total_pages, offset.
 */
function paginate(int $total, int $perPage = PER_PAGE_DEFAULT, ?int $page = null): array
{
    $page       = max(1, $page ?? (int) ($_GET['page'] ?? 1));
    $perPage    = max(1, $perPage);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);

    return [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'offset'      => ($page - 1) * $perPage,
    ];
}

/**
 * Render the pagination control every list uses.
 *
 * Ten rows a page, then Previous and Next. It used to be written
 * out by hand at the foot of each list, which meant a page could
 * quietly end up with numbers and no Next button — and several
 * did. One function means the control is the same everywhere and
 * a new list gets it right by calling one line.
 *
 * The page numbers are windowed. A list of forty pages printed
 * forty links, which is not navigation; it is a wall. Now the
 * first, the last and a few either side of the current page are
 * shown, with gaps marked.
 *
 * @param array  $pg        The array paginate() returned.
 * @param array  $baseQuery Filters to carry onto every page link.
 * @param string $noun      What is being counted, for the caption.
 * @param string $pageKey   Query parameter holding the page number.
 *                          Two tables on one page each need their
 *                          own, or paging one would page both.
 */
/**
 * The Filter button every list toolbar needs, and a way back out.
 *
 * The selects and the date boxes submit the moment they change, so
 * they always worked. The search box cannot do that — you would be
 * searching after every letter — and there was no button, which
 * left the only working way to run a search as "press Enter and
 * hope". People reasonably concluded the page could not be
 * filtered at all.
 *
 * The Clear link appears only once something is actually filtered,
 * because a permanent one is noise, and disappears again when
 * there is nothing to clear.
 *
 * @param string $path  The page's own path, for the Clear link.
 * @param array  $keep  Query keys that are not filters — a tab
 *                      selection, a document id the page is scoped
 *                      to — which Clear should leave alone.
 */
function toolbar_apply(string $path, array $keep = []): string
{
    // Page number and page size describe where you are in a result,
    // not what the result is, so neither counts as a filter.
    $ignore = array_merge(['page', 'per_page', 'export', 'sort'], $keep);
    $active = false;
    foreach ($_GET as $k => $v) {
        if (in_array($k, $ignore, true)) {
            continue;
        }
        if (is_array($v) ? $v !== [] : trim((string) $v) !== '') {
            $active = true;
            break;
        }
    }

    $html = '<button type="submit" class="btn btn-primary toolbar-apply">'
          . icon('search') . ' Filter</button>';

    if ($active) {
        $kept  = array_intersect_key($_GET, array_flip($keep));
        $query = $kept ? '?' . http_build_query($kept) : '';
        $html .= '<a class="btn btn-ghost toolbar-clear" href="' . e(url($path . $query)) . '">'
               . icon('x') . ' Clear</a>';
    }
    return $html;
}

function pagination_nav(array $pg, array $baseQuery = [], string $noun = 'item', string $pageKey = 'page'): void
{
    $page  = (int) $pg['page'];
    $last  = (int) $pg['total_pages'];
    $total = (int) $pg['total'];

    // The caption is worth showing even on a single page: it is
    // how someone checks a filter did what they expected.
    $first = $total === 0 ? 0 : (int) $pg['offset'] + 1;
    $upTo  = min((int) $pg['offset'] + (int) $pg['per_page'], $total);

    $link = static function (int $n) use ($baseQuery, $pageKey): string {
        return '?' . http_build_query([$pageKey => $n] + $baseQuery);
    };

    // First page, last page, and a window either side of this one.
    $window = [];
    for ($i = 1; $i <= $last; $i++) {
        if ($i === 1 || $i === $last || abs($i - $page) <= 2) {
            $window[] = $i;
        }
    }

    echo '<nav class="pagination" aria-label="Pagination">';
    echo '<span class="pagination-count">'
       . ($total === 0
            ? 'Nothing to show'
            : 'Showing ' . e(num($first)) . '–' . e(num($upTo)) . ' of ' . e(num($total))
              . ' ' . e($total === 1 ? $noun : plural($noun)))
       . '</span>';

    if ($last > 1) {
        echo '<span class="pagination-pages">';

        if ($page > 1) {
            echo '<a class="page-link" rel="prev" href="' . e($link($page - 1)) . '">&laquo; Prev</a>';
        } else {
            echo '<span class="page-link is-disabled">&laquo; Prev</span>';
        }

        $previous = 0;
        foreach ($window as $n) {
            if ($previous && $n > $previous + 1) {
                echo '<span class="page-gap">…</span>';
            }
            echo '<a class="page-link' . ($n === $page ? ' is-active' : '') . '"'
               . ($n === $page ? ' aria-current="page"' : '')
               . ' href="' . e($link($n)) . '">' . $n . '</a>';
            $previous = $n;
        }

        if ($page < $last) {
            echo '<a class="page-link" rel="next" href="' . e($link($page + 1)) . '">Next &raquo;</a>';
        } else {
            echo '<span class="page-link is-disabled">Next &raquo;</span>';
        }

        echo '</span>';
    }
    echo '</nav>';
}

/**
 * The plural of an English noun, well enough for a caption.
 *
 * Sticking an "s" on the end gives "categorys" and "entrys", which
 * looks like a bug even though the number beside it is right. The
 * two rules that matter are the -y and the sibilant ones; anything
 * genuinely irregular can be passed in already plural.
 */
function plural(string $noun): string
{
    if (preg_match('/[^aeiou]y$/i', $noun)) {
        return substr($noun, 0, -1) . 'ies';
    }
    if (preg_match('/(s|x|z|ch|sh)$/i', $noun)) {
        return $noun . 'es';
    }
    return $noun . 's';
}

/**
 * Slice an already-loaded array into the current page.
 *
 * Most lists page in SQL, which is right when the rows come
 * straight from one table. A few — the ageing reports, the stock
 * valuation — have to read everything to work out their totals
 * before they can show anything, so they page the result in
 * memory instead. Same rule, same control, different mechanism.
 */
function paginate_rows(array $rows, int $perPage = PER_PAGE_DEFAULT, ?int $page = null): array
{
    $pg = paginate(count($rows), $perPage, $page);
    return [$pg, array_slice($rows, $pg['offset'], $pg['per_page'])];
}

/* ─────────────────────────────────────────────────────────────
 *  JSON responses (for AJAX endpoints)
 * ──────────────────────────────────────────────────────────── */

/** Emit a JSON response and stop. */
/**
 * True when the caller is expecting JSON rather than a page.
 *
 * Every AJAX form in the app sends `X-Requested-With`, and the
 * storefront API asks for JSON by Accept header. Guards use this to
 * decide whether a refusal should be a rendered page or a sentence
 * the browser can actually read back to the user.
 */
function wants_json(): bool
{
    if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) {
        return true;
    }
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/* ─────────────────────────────────────────────────────────────
 *  Database error messages
 * ──────────────────────────────────────────────────────────── */

/**
 * Turn a database error into something worth showing a user.
 *
 * The business-rule triggers raise messages written for people
 * ("Goods released on credit must be recorded against a credit
 * account"). Those are worth surfacing verbatim. Anything else —
 * a syntax error, a constraint name, a stack of PL/pgSQL context —
 * tells an attacker more than it tells the user, so it is replaced
 * by the caller's fallback and left in the error log instead.
 *
 * Note that PDOException extends RuntimeException: catch this
 * class *before* any RuntimeException a handler raises itself, or
 * raw SQL reaches the browser.
 */
function db_rule_message(Throwable $ex, string $fallback): string
{
    $code = (string) $ex->getCode();

    // 42703 (undefined column) and 42P01 (undefined table) mean the
    // database is behind the code, and nothing the person typed is
    // wrong. Saying "could not save changes" sends them looking at
    // their own input for a fault that is not there — which is
    // exactly what happened with a supplier that would not save and
    // a logo that would not change.
    if ($code === '42703' || $code === '42P01') {
        $missing = preg_match('/column "?([\w.]+)"?|relation "([\w.]+)"/', $ex->getMessage(), $m)
            ? ' (' . ($m[1] ?: $m[2]) . ')' : '';
        return 'This needs a database update that has not been applied yet' . $missing
             . '. Run the outstanding migrations — nothing you entered is wrong.';
    }

    // P0001 is PostgreSQL's raise_exception — a rule we wrote.
    // Anything else is a bug, not a message for the user.
    if ($code !== 'P0001' || !preg_match('/ERROR:\s*(.+)/', $ex->getMessage(), $m)) {
        return $fallback;
    }
    return trim(explode("\n", $m[1])[0]);
}

/**
 * SQL fragment: where a supplier is. Pass the suppliers alias.
 *
 * The column was `address` until migration 036 renamed it to
 * `location`, so that every party in the system says the same word.
 * A database that has not had 036 applied still has the old name,
 * and three purchasing documents referenced the new one — which is
 * how every purchasing print answered a blank 500 at once.
 *
 * Degrades to the old column, then to an empty string, so the
 * document prints either way. Code has to survive being one
 * migration ahead of the database it is pointed at.
 */
function supplier_location_sql(string $alias = 's'): string
{
    if (column_exists('suppliers', 'location')) {
        return "$alias.location";
    }
    if (column_exists('suppliers', 'address')) {
        return "$alias.address";
    }
    return "''";
}

/* ─────────────────────────────────────────────────────────────
 *  Is the database behind the code?
 * ------------------------------------------------------------
 *  Four separate bug reports had one cause: migrations that had
 *  not been run. Adding a supplier answered "Could not save
 *  changes", every purchasing document answered a blank 500, and
 *  a changed logo silently would not change — because each of
 *  those touched a column a migration was supposed to have added.
 *
 *  None of them said so. The application knew exactly which
 *  files were outstanding and kept it to itself.
 * ──────────────────────────────────────────────────────────── */

/**
 * Migration files on disk that the database has no record of.
 *
 * Cheap enough to call on every page: one directory listing and
 * one indexed read, both cached for the request. Returns an empty
 * array on any database that cannot answer — a broken check must
 * not become a broken page.
 *
 * @return string[] filenames, in the order they should be applied
 */
function pending_migrations(): array
{
    static $pending = null;
    if ($pending !== null) {
        return $pending;
    }
    $pending = [];

    $dir = BASE_PATH . '/database_structure/migrations';
    if (!is_dir($dir) || !table_exists('schema_migrations')) {
        return $pending;
    }

    try {
        $applied = array_flip(array_map(
            static fn($r) => $r['filename'],
            db_all("SELECT filename FROM schema_migrations")
        ));
    } catch (Throwable $e) {
        return $pending;
    }

    foreach (glob($dir . '/*.sql') ?: [] as $path) {
        $name = basename($path);
        if (!isset($applied[$name])) {
            $pending[] = $name;
        }
    }
    sort($pending);
    return $pending;
}
