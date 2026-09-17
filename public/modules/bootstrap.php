<?php

/**
 * ============================================================
 *  Module Bootstrap (shim)
 * ------------------------------------------------------------
 *  Every file under /modules/ includes this first. It wires the
 *  page into the shared application foundation:
 *
 *    • loads config, the central DB layer, helpers and auth
 *    • starts a secure session
 *    • enforces authentication (no module page is reachable
 *      by an unauthenticated visitor)
 *
 *  It also provides a single, central getDBConnection() so the
 *  ~25 duplicated copies that used to live in each module are
 *  no longer needed. Legacy module code that calls
 *  getDBConnection() keeps working unchanged, but now shares
 *  one pooled PDO connection via the Database layer.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

/**
 * Backwards-compatible accessor used throughout the legacy
 * modules. Delegates to the shared connection.
 */
if (!function_exists('getDBConnection')) {
    function getDBConnection(): PDO
    {
        return db();
    }
}
