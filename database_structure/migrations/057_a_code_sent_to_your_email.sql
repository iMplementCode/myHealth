-- ============================================================
--  A second thing to know, besides the password
-- ------------------------------------------------------------
--  A password is one secret, and the ways it leaves a person's
--  hands are ordinary: reused on a site that was breached,
--  typed on a borrowed laptop, read over a shoulder at a
--  counter, guessed because it is the company name and a year.
--  None of those are exotic and none of them are noticed at the
--  time.
--
--  So signing in now asks for something else as well: a six
--  digit code, sent to the address on the account, good for a
--  few minutes and for one use.
--
--  ── The code is a credential, so it is hashed ───────────────
--  Not stored, hashed — the same reason passwords are. Anyone
--  who can read this table can read the users table beside it,
--  and a live sign-in code sitting in plain text is a sign-in.
--  Six digits is a small space, so a fast hash would be worth
--  attacking; this uses the same password_hash() the passwords
--  use, which is deliberately slow.
--
--  ── Why the attempts column ─────────────────────────────────
--  A million guesses finds a six-digit code. Five guesses does
--  not, and five is more than anybody needs to type a number
--  they are reading off a screen. The count lives with the code
--  rather than in a session, because a session is the attacker's
--  to discard and start again.
--
--  ── Why consumed_at, rather than deleting the row ───────────
--  A used code must never work twice, and "the row is gone" and
--  "the code was never issued" are the same state when you are
--  trying to work out what happened to an account. The sweep at
--  the bottom clears out what is long past.
-- ============================================================

CREATE TABLE IF NOT EXISTS login_codes (
    code_id     SERIAL PRIMARY KEY,
    user_id     INTEGER     NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    code_hash   TEXT        NOT NULL,
    attempts    INTEGER     NOT NULL DEFAULT 0,
    expires_at  TIMESTAMPTZ NOT NULL,
    consumed_at TIMESTAMPTZ,
    sent_to     VARCHAR(255),
    ip_address  VARCHAR(45),
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT login_codes_attempts_sane CHECK (attempts >= 0)
);

COMMENT ON TABLE login_codes IS
    'One-time sign-in codes emailed during two-factor authentication. '
    'Hashed, single use, short lived.';
COMMENT ON COLUMN login_codes.sent_to IS
    'The address the code went to, recorded so an audit can answer '
    '"where was it sent" without guessing from the user row, which '
    'may have been edited since.';

-- The live code for a user is looked up on every verification
-- attempt: newest first, unconsumed, unexpired.
CREATE INDEX IF NOT EXISTS ix_login_codes_user
    ON login_codes (user_id, created_at DESC);

-- The sweep below, and the one security_gc() runs.
CREATE INDEX IF NOT EXISTS ix_login_codes_expiry
    ON login_codes (expires_at);

-- Nothing here is worth keeping for a day. Codes older than that
-- are cleared on the same schedule as spent rate-limit windows and
-- dead sessions.
DELETE FROM login_codes WHERE expires_at < NOW() - INTERVAL '1 day';
