-- ============================================================
--  041 — More than one company email
-- ------------------------------------------------------------
--  `company_settings.email` held exactly one address, and a
--  business does not have one. It has sales@ for quotes,
--  accounts@ for invoices and chasing money, info@ on the
--  website, and often a personal one for whoever actually reads
--  the replies.
--
--  Printing one of those on every document sends a customer's
--  payment query to the person who wrote the quote.
--
--  ── Why a text column and not a table ───────────────────────
--  A `company_emails` table would be the textbook answer, and it
--  would be the wrong size for the problem. There is exactly one
--  company, the list is three or four entries long, it is
--  displayed as a block on a letterhead, and nothing joins to it.
--  A table buys referential integrity nobody needs and costs a
--  join on every document.
--
--  So: additional addresses, one per line, in the order they
--  should be printed. The existing `email` column stays exactly
--  as it is and stays the primary — nothing that reads it today
--  has to change, and a business that only has one address sees
--  no difference at all.
--
--  Validation lives in the application (`company_emails()` in
--  includes/company.php), which drops anything that is not an
--  address rather than printing rubbish on a letterhead.
-- ============================================================

BEGIN;

ALTER TABLE company_settings
    ADD COLUMN IF NOT EXISTS extra_emails TEXT;

COMMENT ON COLUMN company_settings.email IS
    'The company''s main email address — the one printed first on every '
    'document. See extra_emails for the rest.';

COMMENT ON COLUMN company_settings.extra_emails IS
    'Additional company email addresses, one per line, in the order they '
    'should be printed. The main one is `email`; these follow it.';

-- ─── Where the logo really is ───────────────────────────────
--  The settings page used to store a path that had been glued
--  together out of two constants — "/uploadscompany_logo.jpg" —
--  because it re-defined UPLOAD_URL, which bootstrap.php had
--  already set, and PHP kept the first definition. Nothing could
--  resolve that, so the documents quietly kept the old logo.
--
--  The file itself was written somewhere else again, so this
--  cannot repair the path by guessing; company_logo_fs_path()
--  finds the file by looking for it. What this does is clear the
--  value that can never resolve, so the resolver is not led off
--  by it first.
UPDATE company_settings
   SET logo_path = NULL
 WHERE logo_path IS NOT NULL
   AND logo_path <> ''
   -- A path with no directory separator after the first segment is
   -- the signature of the concatenation bug.
   AND logo_path ~ '^/?uploads[^/]';

COMMIT;
