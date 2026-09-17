-- ============================================================
--  Migration 058 — Keep the logo where it cannot be lost
-- ------------------------------------------------------------
--  The logo was a file under public/uploads/company/. On a
--  container host that folder is rebuilt from the repository on
--  every deploy, so every shop lost its logo every time the code
--  was pushed — and company_logo_fs_path() then fell through to
--  the logo that ships with the application, which is one real
--  company's. Ten shops were printing another company's brand on
--  their own invoices.
--
--  The database is the only thing a shop has that survives a
--  deploy, so the logo lives here now.
--
--  Its own table, not a column on company_settings: that row is
--  read on every single page load with SELECT *, and a two-megabyte
--  bytea column on it would be two megabytes read per request.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS company_logo (
    id         INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),   -- single row
    mime       VARCHAR(40)  NOT NULL,
    bytes      BYTEA        NOT NULL,
    updated_at TIMESTAMPTZ  NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE company_logo IS
    'The company logo, as bytes. Here rather than on disk because a container filesystem does not survive a deploy.';

COMMIT;
