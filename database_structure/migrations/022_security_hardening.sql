-- ============================================================
--  022 — Security hardening: sessions, throttling, rate limits
--        and a shared cache
-- ------------------------------------------------------------
--  Four tables, each answering a weakness that could not be
--  fixed in PHP alone.
--
--  1. sessions
--     PHP keeps sessions as files in one server's /tmp. Put a
--     second server behind a load balancer and half the requests
--     land on a machine that has never heard of your session, so
--     the balancer has to pin each visitor to one node — and that
--     node becomes both a bottleneck and a single point of
--     failure. Sessions in the database make the application tier
--     stateless: any node can serve any request, and a node can
--     be replaced mid-conversation without signing anyone out.
--
--     It also makes revocation real. Deactivate a user and their
--     sessions go with them, on every node at once.
--
--  2. login_attempts
--     Brute-force lockout was counted in the session, which means
--     it was counted in a cookie the attacker controls. Throwing
--     the cookie away reset the counter, so the lockout stopped
--     nobody. Attempts recorded here are counted per IP and per
--     account, where the attacker cannot reach them.
--
--  3. rate_limits
--     A fixed-window counter any endpoint can use — the API, the
--     sign-in form, password reset. Shared across nodes, so the
--     limit is the limit however many servers are running.
--
--  4. app_cache
--     The slow half of a report is the same for everyone who asks
--     for it. This is the shared tier of the cache: an in-process
--     cache in front of it, a CDN in front of that.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ─── Sessions ───────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sessions (
    session_id   VARCHAR(128) PRIMARY KEY,
    -- Who it belongs to, once they have signed in. The cascade is
    -- the point: deleting a user revokes every session they hold,
    -- everywhere, without a sweep job.
    user_id      INTEGER REFERENCES users(user_id) ON DELETE CASCADE,
    ip_address   VARCHAR(45),
    user_agent   TEXT,
    payload      TEXT NOT NULL,
    last_active  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_sessions_last_active ON sessions (last_active);
CREATE INDEX IF NOT EXISTS ix_sessions_user        ON sessions (user_id);

-- ─── Login attempts ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    -- What was typed in the box, lowercased. Not necessarily a
    -- real account: the whole point is to count attempts against
    -- names that do not exist too.
    identifier   VARCHAR(190),
    ip_address   VARCHAR(45) NOT NULL,
    successful   BOOLEAN NOT NULL DEFAULT FALSE,
    user_agent   TEXT,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS ix_login_attempts_ip
    ON login_attempts (ip_address, attempted_at);
CREATE INDEX IF NOT EXISTS ix_login_attempts_identifier
    ON login_attempts (identifier, attempted_at);

-- ─── Rate limits ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket            VARCHAR(190) PRIMARY KEY,
    hits              INTEGER NOT NULL DEFAULT 0,
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at        TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS ix_rate_limits_expiry ON rate_limits (expires_at);

-- ─── Shared cache ───────────────────────────────────────────

CREATE TABLE IF NOT EXISTS app_cache (
    cache_key  VARCHAR(190) PRIMARY KEY,
    payload    TEXT NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS ix_app_cache_expiry ON app_cache (expires_at);

-- ─── Remember-me tokens: when each was last replayed ────────
--  A token is now rotated every time it is used, so a cookie
--  lifted from a machine stops working the moment the real owner
--  comes back. This column is what makes that visible when
--  something goes wrong.

ALTER TABLE remember_tokens ADD COLUMN IF NOT EXISTS last_used_at TIMESTAMPTZ;
ALTER TABLE remember_tokens ADD COLUMN IF NOT EXISTS ip_address   VARCHAR(45);

-- ─── Housekeeping ───────────────────────────────────────────
--  Everything above expires. Called from the application on a
--  small fraction of requests, and safe to call from cron.

CREATE OR REPLACE FUNCTION security_gc(session_max_idle INTERVAL DEFAULT '12 hours')
RETURNS void AS $$
BEGIN
    DELETE FROM sessions       WHERE last_active < NOW() - session_max_idle;
    DELETE FROM rate_limits    WHERE expires_at  < NOW();
    DELETE FROM app_cache      WHERE expires_at  < NOW();
    DELETE FROM remember_tokens WHERE expires_at < NOW();
    -- Attempts are evidence, so they are kept longer than the
    -- window that uses them — but not forever.
    DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '30 days';
END;
$$ LANGUAGE plpgsql;
