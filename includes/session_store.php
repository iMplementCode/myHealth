<?php

/**
 * ============================================================
 *  Sessions in the database
 * ------------------------------------------------------------
 *  PHP's default session handler writes a file per session into
 *  one server's temp directory. That is fine for one server and
 *  fatal for two: the second machine has never seen the file, so
 *  a visitor balanced onto it is simply logged out. The usual
 *  workaround — pinning each visitor to the node they first hit —
 *  turns the load balancer into a seating plan, wastes capacity,
 *  and drops every pinned session when a node is replaced.
 *
 *  Holding sessions in PostgreSQL makes the application tier
 *  stateless. Any node can serve any request; nodes can be added,
 *  restarted or lost mid-conversation and nobody is signed out.
 *
 *  Two things fall out of it for free:
 *
 *    - revocation. Deleting a user's rows ends their sessions on
 *      every node at once, which is what "deactivate this
 *      account" ought to mean.
 *    - visibility. You can see who is signed in, from where, and
 *      when they were last active.
 *
 *  The handler degrades rather than breaks: if the sessions table
 *  is missing — a fresh clone, mid-migration — PHP's own file
 *  handler stays in charge and the application still runs.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

final class PostgresSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** What was read, so an unchanged session is not rewritten. */
    private string $original = '';

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        try {
            $row = db_one(
                "SELECT payload FROM sessions
                  WHERE session_id = :id AND last_active > NOW() - (:idle || ' seconds')::INTERVAL",
                [':id' => $id, ':idle' => (string) (SESSION_ABSOLUTE_LIFETIME + 3600)]
            );
        } catch (Throwable $e) {
            error_log('[SESSION] read failed: ' . $e->getMessage());
            return '';
        }
        return $this->original = (string) ($row['payload'] ?? '');
    }

    public function write(string $id, string $data): bool
    {
        try {
            // The user id is lifted out of the live session rather
            // than parsed from the serialised blob: it is what makes
            // "sign this person out everywhere" a single statement.
            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

            db_run(
                "INSERT INTO sessions (session_id, user_id, ip_address, user_agent, payload, last_active)
                 VALUES (:id, :uid, :ip, :ua, :payload, NOW())
                 ON CONFLICT (session_id) DO UPDATE
                    SET user_id     = EXCLUDED.user_id,
                        ip_address  = EXCLUDED.ip_address,
                        user_agent  = EXCLUDED.user_agent,
                        payload     = EXCLUDED.payload,
                        last_active = NOW()",
                [
                    ':id'      => $id,
                    ':uid'     => $userId,
                    ':ip'      => substr(function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                    ':ua'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
                    ':payload' => $data,
                ]
            );
            return true;
        } catch (Throwable $e) {
            // Losing a session write loses a login, not data. Log it
            // and let the request finish.
            error_log('[SESSION] write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            db_run("DELETE FROM sessions WHERE session_id = :id", [':id' => $id]);
        } catch (Throwable $e) {
            error_log('[SESSION] destroy failed: ' . $e->getMessage());
        }
        return true;
    }

    /** @return int|false */
    #[\ReturnTypeWillChange]
    public function gc(int $maxLifetime)
    {
        try {
            db_run(
                "DELETE FROM sessions WHERE last_active < NOW() - (:idle || ' seconds')::INTERVAL",
                [':idle' => (string) max($maxLifetime, SESSION_ABSOLUTE_LIFETIME)]
            );
        } catch (Throwable $e) {
            error_log('[SESSION] gc failed: ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Called when the session is unchanged. Only the timestamp
     * needs moving, which keeps an idle-but-open tab from being
     * a full rewrite on every poll.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            db_run("UPDATE sessions SET last_active = NOW() WHERE session_id = :id", [':id' => $id]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Required by the interface; a collision here is not survivable anyway. */
    public function validateId(string $id): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9,\-]{22,128}$/', $id);
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }
}

/**
 * Install the handler, if the table is there to hold it.
 *
 * Called before session_start(). Returns true when sessions are
 * in the database, false when PHP's files are still in charge —
 * which is the honest answer a health check wants to report.
 */
function session_store_install(): bool
{
    static $installed = null;
    if ($installed !== null) {
        return $installed;
    }
    if (!filter_var(env('SESSION_DRIVER_DB', 'true'), FILTER_VALIDATE_BOOL)) {
        return $installed = false;
    }
    if (!function_exists('table_exists') || !table_exists('sessions')) {
        return $installed = false;
    }
    try {
        session_set_save_handler(new PostgresSessionHandler(), true);
        return $installed = true;
    } catch (Throwable $e) {
        error_log('[SESSION] handler not installed: ' . $e->getMessage());
        return $installed = false;
    }
}

/**
 * End every session a user holds, everywhere.
 *
 * What deactivating an account has to do to mean anything, and
 * what a password change should do to every other browser.
 */
function session_store_revoke_user(int $userId, ?string $exceptSessionId = null): int
{
    try {
        $sql = "DELETE FROM sessions WHERE user_id = :u";
        $params = [':u' => $userId];
        if ($exceptSessionId !== null) {
            $sql .= " AND session_id <> :keep";
            $params[':keep'] = $exceptSessionId;
        }
        return db_run($sql, $params)->rowCount();
    } catch (Throwable $e) {
        error_log('[SESSION] revoke failed: ' . $e->getMessage());
        return 0;
    }
}
