-- ============================================================
--  Migration 059 — Password reset tokens
-- ------------------------------------------------------------
--  A split token, not one secret.
--
--  `selector` is stored as it is and is what the row is found by.
--  `verifier_hash` is a hash of the other half, and the other half
--  exists only in the email. So a copy of this table — a backup on
--  a laptop, a careless SELECT — yields nothing that opens an
--  account, and the lookup is still a single indexed read rather
--  than a scan of every live token running password_verify().
--
--  One secret, hashed, would force that scan. One secret, stored
--  plainly, would mean the table IS the keys.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS password_resets (
    reset_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,

    selector      VARCHAR(32) NOT NULL UNIQUE,
    verifier_hash TEXT        NOT NULL,

    expires_at    TIMESTAMPTZ NOT NULL,
    consumed_at   TIMESTAMPTZ,

    -- Who asked. Not to punish anybody, but because "somebody kept
    -- requesting resets for the accountant" is the first sign of an
    -- attack and is otherwise invisible.
    requested_ip  VARCHAR(45),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_password_resets_user   ON password_resets (user_id);
CREATE INDEX IF NOT EXISTS idx_password_resets_expiry ON password_resets (expires_at);

COMMENT ON TABLE password_resets IS
    'Single-use links for somebody who has forgotten their password. Selector is public; the verifier lives only in the email.';

COMMIT;
