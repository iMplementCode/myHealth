-- ============================================================
--  How a customer is supposed to pay you
-- ------------------------------------------------------------
--  An invoice that asks for money and does not say where to send
--  it makes the customer phone and ask, and the answer arrives by
--  WhatsApp with a digit missing. The paybill belongs on the
--  document, printed by the system that knows it.
--
--  Kept as separate columns rather than one block of text because
--  they are labelled separately on the document — "Paybill" and
--  "Account" are two fields on the M-Pesa menu, and a customer
--  keying them in is reading them one at a time.
--
--  ── tax_pin ─────────────────────────────────────────────────
--  Added here too, and it is not scope creep: company_letterhead()
--  in includes/company.php has always printed a "PIN:" line when
--  the value is set, and there has never been a column to set it
--  in. The rendering code has been dead since it was written. A
--  Kenyan tax invoice needs the KRA PIN on it, so this is the
--  column that was missing rather than a new feature.
-- ============================================================

ALTER TABLE company_settings
    ADD COLUMN IF NOT EXISTS mpesa_paybill VARCHAR(20),
    ADD COLUMN IF NOT EXISTS mpesa_account VARCHAR(60),
    ADD COLUMN IF NOT EXISTS bank_details  TEXT,
    ADD COLUMN IF NOT EXISTS tax_pin       VARCHAR(30);

COMMENT ON COLUMN company_settings.mpesa_paybill IS
    'M-Pesa paybill or till number, as the customer keys it in.';
COMMENT ON COLUMN company_settings.mpesa_account IS
    'The account number quoted alongside the paybill. Often the
     invoice number instead, in which case leave this empty and
     the document says so.';
COMMENT ON COLUMN company_settings.bank_details IS
    'Free text: bank, branch, account name and number, SWIFT.
     Free text rather than columns because every bank asks for a
     different set and a customer paying by transfer copies the
     block as a whole.';
COMMENT ON COLUMN company_settings.tax_pin IS
    'KRA PIN. Printed on every document by company_letterhead().';
