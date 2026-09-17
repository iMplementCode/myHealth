<?php

/**
 * ============================================================
 *  Caching, in layers
 * ------------------------------------------------------------
 *  Three of them, each cheaper than the one below it:
 *
 *    1. per-request   an array. The same figure asked for twice
 *                     in one page is computed once.
 *    2. per-process   APCu, when the extension is installed.
 *                     Shared by every request that node handles,
 *                     lost when it restarts. Nanoseconds.
 *    3. shared        a table in PostgreSQL. Slower than APCu and
 *                     still far faster than the report it stands
 *                     in front of — and, being shared, one node
 *                     computing a figure saves every other node
 *                     from computing it too.
 *
 *  A fourth layer sits outside the application entirely: a CDN
 *  caching the static assets, which is where the bulk of the
 *  bytes are. See docs/DEPLOYMENT.md.
 *
 *  ── What may be cached ─────────────────────────────────────
 *  Aggregates, not documents. A report of last month's sales is
 *  the same for everyone who may see it; an invoice is not, and
 *  caching one risks handing it to the wrong person. Nothing
 *  here should ever hold a row a permission check applies to.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * How long a whole-history figure may be out of date.
 *
 * ── Which figures, and why it is safe ───────────────────────
 * Some numbers on this dashboard answer "did the sale I just
 * recorded go in?" — today's sales, this week's, this month's.
 * Those are LIVE and always will be; an index makes them nearly
 * free, so there is nothing to trade.
 *
 * Others answer "what has this business made since it started?".
 * That figure is a full pass over every invoice line ever written —
 * 142ms on four years of trading, and growing forever — and it
 * moves by a rounding error when one more sale lands. Nobody has
 * ever refreshed a dashboard to watch a lifetime total tick over.
 *
 * So the second kind is computed once a minute and shared: with
 * APCu, by every request that node serves; without it, through a
 * table, so one node computing it spares all the others. That is
 * the difference between a dashboard that costs half a second of
 * database time per viewer and one that costs half a second per
 * minute however many people are looking at it.
 *
 * Kept short deliberately. A long TTL would make the numbers feel
 * broken to whoever is watching them, and the whole saving is
 * already had in the first few seconds.
 */
const REPORT_CACHE_TTL = 60;

/** Per-request memory, layer one. */
$GLOBALS['__cache_local'] = [];

/** Whether APCu is available and enabled for this SAPI. */
function cache_apcu(): bool
{
    static $on = null;
    if ($on === null) {
        $on = function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL);
    }
    return $on;
}

/** Namespace keys so two deployments on one host cannot collide. */
function cache_key(string $key): string
{
    return substr(hash('sha256', APP_NAME . '|' . BASE_PATH) . ':' . $key, 0, 190);
}

/**
 * Read through every layer, computing only if all of them miss.
 *
 * @param callable():mixed $compute
 */
function cache_remember(string $key, int $ttlSeconds, callable $compute)
{
    $k = cache_key($key);

    if (array_key_exists($k, $GLOBALS['__cache_local'])) {
        return $GLOBALS['__cache_local'][$k];
    }

    if (cache_apcu()) {
        $hit = apcu_fetch($k, $ok);
        if ($ok) {
            return $GLOBALS['__cache_local'][$k] = $hit;
        }
    }

    [$shared, $fresh] = cache_shared_get($k);

    if ($shared !== null && $fresh) {
        if (cache_apcu()) {
            apcu_store($k, $shared, $ttlSeconds);
        }
        return $GLOBALS['__cache_local'][$k] = $shared;
    }

    /*  Stale, but still here.
     *
     *  Exactly one caller wins the right to recompute; the rest
     *  take the stale value and get on with their page. Without
     *  this, the moment a minute turns, everybody currently on the
     *  system runs the same expensive query at once — which is a
     *  stampede, and it makes the cache worse than no cache
     *  precisely when the most people are using the system.
     *
     *  Measured before the fix: 24 people, the receivables total
     *  expiring, p95 1,201ms. The query is 400ms and it was
     *  running two dozen times over.
     *
     *  The claim is one atomic statement, so there is no lock to
     *  release and nothing left broken if this process dies. */
    if ($shared !== null && !cache_claim_refresh($k, $ttlSeconds)) {
        // Somebody else is already refreshing it.
        if (cache_apcu()) {
            apcu_store($k, $shared, max(1, (int) ($ttlSeconds / 2)));
        }
        return $GLOBALS['__cache_local'][$k] = $shared;
    }

    $value = $compute();

    if (cache_apcu()) {
        apcu_store($k, $value, $ttlSeconds);
    }
    cache_shared_put($k, $value, $ttlSeconds);

    return $GLOBALS['__cache_local'][$k] = $value;
}

/**
 * Win the right to recompute one stale key, or don't.
 *
 * Pushing fresh_until forward is what claims it: the next caller
 * sees a fresh row and takes the stale value happily, while this
 * one goes and computes the real one. If the refresh then fails,
 * the row still expires on its own at expires_at.
 *
 * Returns true when there is no cache table to claim in, because
 * the caller must still compute — a broken cache makes a page slow,
 * never wrong.
 */
function cache_claim_refresh(string $k, int $ttlSeconds): bool
{
    try {
        return (bool) db_value(
            "UPDATE app_cache
                SET fresh_until = NOW() + (:t || ' seconds')::INTERVAL
              WHERE cache_key = :k
                AND (fresh_until IS NULL OR fresh_until <= NOW())
              RETURNING 1",
            [':k' => $k, ':t' => (string) max(1, $ttlSeconds)]
        );
    } catch (Throwable $e) {
        return true;
    }
}

/** Drop a key from every layer — call this when the data changes. */
function cache_forget(string $key): void
{
    $k = cache_key($key);
    unset($GLOBALS['__cache_local'][$k]);
    if (cache_apcu()) {
        apcu_delete($k);
    }
    try {
        db_run("DELETE FROM app_cache WHERE cache_key = :k", [':k' => $k]);
    } catch (Throwable $e) {
        // A cache that cannot be cleared is a correctness problem,
        // so it is worth a line in the log even though it is not
        // worth failing the request over.
        error_log('[CACHE] forget failed for ' . $key . ': ' . $e->getMessage());
    }
}

/** Drop everything. Used after a migration or a bulk import. */
function cache_flush(): void
{
    $GLOBALS['__cache_local'] = [];
    if (cache_apcu()) {
        apcu_clear_cache();
    }
    try {
        db_run("DELETE FROM app_cache");
    } catch (Throwable $e) {
        error_log('[CACHE] flush failed: ' . $e->getMessage());
    }
}

/* ── The shared layer ──────────────────────────────────────── */

/**
 * How long past its freshness a value stays usable.
 *
 * Long enough that a refresh has every chance to finish, short
 * enough that a figure nobody has looked at for an hour is not
 * still sitting there. It only ever matters for the one caller
 * that arrives while a refresh is in flight.
 */
const CACHE_GRACE_SECONDS = 600;

/** @return array{0: mixed, 1: bool}  the value (or null), and whether it is fresh */
function cache_shared_get(string $k): array
{
    try {
        $row = db_one(
            "SELECT payload,
                    (fresh_until IS NOT NULL AND fresh_until > NOW()) AS fresh
               FROM app_cache
              WHERE cache_key = :k AND expires_at > NOW()",
            [':k' => $k]
        );
    } catch (Throwable $e) {
        return [null, false];   // no cache table yet, or the database is busy
    }
    if (!$row) {
        return [null, false];
    }
    $value = json_decode((string) $row['payload'], true);
    if ($value === null && $row['payload'] !== 'null') {
        return [null, false];
    }
    // Postgres hands booleans back as 't'/'f' through PDO.
    $fresh = in_array($row['fresh'], [true, 't', '1', 1], true);
    return [$value, $fresh];
}

function cache_shared_put(string $k, $value, int $ttlSeconds): void
{
    // JSON rather than serialize(): a cache row must never be able
    // to instantiate a class when it is read back.
    $payload = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    try {
        db_run(
            "INSERT INTO app_cache (cache_key, payload, fresh_until, expires_at)
             VALUES (:k, :p,
                     NOW() + (:t || ' seconds')::INTERVAL,
                     NOW() + (:e || ' seconds')::INTERVAL)
             ON CONFLICT (cache_key) DO UPDATE
                SET payload     = EXCLUDED.payload,
                    fresh_until = EXCLUDED.fresh_until,
                    expires_at  = EXCLUDED.expires_at",
            [
                ':k' => $k,
                ':p' => $payload,
                ':t' => (string) max(1, $ttlSeconds),
                ':e' => (string) (max(1, $ttlSeconds) + CACHE_GRACE_SECONDS),
            ]
        );
    } catch (Throwable $e) {
        // Missing the cache is slower, never wrong.
    }
}
