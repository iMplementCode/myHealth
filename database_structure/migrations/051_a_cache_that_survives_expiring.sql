-- ============================================================
--  Let a cached figure go stale without everybody recomputing it
-- ------------------------------------------------------------
--  app_cache had one clock: expires_at. A value was either there
--  or it was not, and the moment it was not, EVERY request that
--  wanted it computed it.
--
--  That is a cache stampede, and it is worst exactly when the
--  cache matters most. Measured with 24 people on the system: the
--  aged-receivables total takes 400ms, its minute expires, and
--  twenty-four requests all run the same 400ms query at once. On
--  four cores that is two and a half seconds of work to produce
--  one number that one request could have produced. The p95 went
--  from 160ms to 1,200ms every time the minute turned — the cache
--  was making the tail worse, not better.
--
--  ── Two clocks instead of one ───────────────────────────────
--  fresh_until  after this, the value is stale but still usable
--  expires_at   after this, it is gone
--
--  A reader that finds a stale value takes it AND returns it
--  immediately. Exactly one of them also wins the right to
--  recompute, by an atomic UPDATE that pushes fresh_until forward
--  — the same trick notifications_scan_if_due() uses, and for the
--  same reason: one statement, one winner, nothing left locked if
--  the process dies holding it.
--
--  So nobody ever waits for a refresh. The first request after a
--  minute gets a value that is a moment out of date, which for
--  "what has this business made since it started" is no different
--  from the right answer.
--
--  Existing rows get fresh_until = expires_at, which is exactly
--  the old behaviour: stale and usable are the same instant, so
--  nothing changes until the row is next written.
-- ============================================================

ALTER TABLE app_cache ADD COLUMN IF NOT EXISTS fresh_until TIMESTAMPTZ;

UPDATE app_cache SET fresh_until = expires_at WHERE fresh_until IS NULL;

-- The claim below reads WHERE cache_key = ? AND fresh_until <= NOW(),
-- and cache_key is already the primary key, so this index earns its
-- place only for the sweep that deletes expired rows — which
-- ix_app_cache_expiry already covers. No new index.
