<?php

/**
 * ============================================================
 *  Database Access Layer
 * ------------------------------------------------------------
 *  A single, shared PDO connection for the whole application.
 *  This replaces the ~25 duplicated `getDBConnection()` copies
 *  that previously lived in every module.
 *
 *  Usage:
 *      $pdo = db();                       // shared PDO handle
 *      $rows = db_all("SELECT ...", [...]);
 *      $row  = db_one("SELECT ...", [...]);
 *      $val  = db_value("SELECT count(*) ...");
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


final class Database
{
    private static ?PDO $instance = null;

    /**
     * Open the shared connection, or throw.
     *
     * The only place in the application where a connection is built.
     * Callers that can do something sensible about a database being
     * unreachable — the migration runner waiting for a database
     * container to finish starting — use this. Everything else uses
     * pdo(), which turns the failure into a 503.
     */
    public static function connect(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        /*  How long to wait for a connection that will never arrive.
         *
         *  Left alone, libpq waits on the operating system's TCP
         *  timeout — minutes, on a host that is simply not answering.
         *  A request that hangs for minutes is worse than one that
         *  fails in five seconds: there are four PHP workers, and
         *  four hung requests are a site that has stopped altogether
         *  rather than a site showing an error.
         *
         *  It matters again at deploy time, now that migrations run
         *  from the start command: every second spent waiting is a
         *  second before the web server starts listening.
         *
         *  The knob is PDO::ATTR_TIMEOUT, *not* connect_timeout in
         *  the DSN, and the difference is not cosmetic. pdo_pgsql
         *  builds its connection string by appending
         *  "connect_timeout=<ATTR_TIMEOUT>" to whatever the DSN says,
         *  and libpq takes the last occurrence of a keyword — so a
         *  connect_timeout written into the DSN is silently
         *  overridden by pdo_pgsql's default of 30. Measured: with
         *  connect_timeout=3 in the DSN an unreachable host still
         *  took 30.0s; with ATTR_TIMEOUT=3 it took 3.0s.            */
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => max(1, (int) env('DB_CONNECT_TIMEOUT', '5')),
        ];

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Align the database session with the application timezone
        // so CURRENT_DATE and timestamptz::date agree with PHP's
        // idea of "today". Without this a sale recorded at 01:00
        // EAT lands on the previous day when the server runs UTC,
        // which silently skews the daily dashboard figures.
        $tz   = date_default_timezone_get();
        $stmt = $pdo->prepare('SET TIME ZONE ' . $pdo->quote($tz));
        $stmt->execute();

        return self::$instance = $pdo;
    }

    /** Return the shared PDO connection, creating it on first use. */
    public static function pdo(): PDO
    {
        try {
            return self::connect();
        } catch (PDOException $e) {
            error_log('[DB] Connection failed: ' . $e->getMessage());

            /*  Name the cause, because this one is unrecognisable.
             *
             *  An empty password reaches libpq as no password at all
             *  and comes back as "fe_sendauth: no password supplied"
             *  — which says nothing about WHICH setting is missing,
             *  and the browser shows only "temporarily unavailable".
             *  That cost this application a day of downtime once.
             *
             *  It happens when the host sets DB_PASSWORD (a hosting
             *  panel's PHP preset, Laravel's name) and nothing reads
             *  it. Both names work now; this says so if neither is
             *  set.                                                */
            if (DB_PASS === '') {
                error_log('[DB] No database password is set. The application reads DB_PASS '
                        . '(or DB_PASSWORD). Check the environment with: php deploy/check-env.php');
            }
            if (DB_NAME === '') {
                error_log('[DB] No database name is set — DB_NAME (or DB_DATABASE).');
            }
            // Never expose connection internals to the client.
            http_response_code(503);
            if (APP_DEBUG) {
                die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
            }
            die('The service is temporarily unavailable. Please try again later.');
        }
    }
}

/** Shorthand accessor for the shared PDO connection. */
function db(): PDO
{
    return Database::pdo();
}

/**
 * Run a prepared statement and return it. Central choke point so
 * every query in the app is parameterised (SQL-injection safe).
 */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch all rows for a query. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** Fetch a single row (or null). */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch a single scalar value (or null). */
function db_value(string $sql, array $params = [])
{
    $val = db_run($sql, $params)->fetchColumn();
    return $val === false ? null : $val;
}
