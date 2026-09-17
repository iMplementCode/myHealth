-- ============================================================
--  Migration 002 — Auth hardening
-- ------------------------------------------------------------
--  Adds username / last_login to users (for installs where the
--  table pre-existed without them) and the remember_tokens
--  table used by the "remember me" feature.
-- ============================================================

BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS username   VARCHAR(50);
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login TIMESTAMPTZ;

-- Enforce unique usernames without failing on existing NULLs.
CREATE UNIQUE INDEX IF NOT EXISTS ux_users_username
    ON users (LOWER(username)) WHERE username IS NOT NULL;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id             INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    selector       VARCHAR(24)  UNIQUE NOT NULL,
    validator_hash VARCHAR(64)  NOT NULL,
    user_id        INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    expires_at     TIMESTAMPTZ NOT NULL,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_remember_tokens_expiry ON remember_tokens (expires_at);

COMMIT;
