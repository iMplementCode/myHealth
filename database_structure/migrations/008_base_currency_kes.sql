-- ============================================================
--  Migration 008 — Base currency correction (KES)
-- ------------------------------------------------------------
--  Some documents were created in a non-base currency because
--  the app picked the "first active currency by id" and a USD
--  row happened to sort first. For this KES-based business the
--  amounts are Kenyan Shillings; this repoints existing sales
--  documents to KES so they no longer print as USD.
--
--  The stored monetary amounts are NOT changed — only the
--  currency label. New documents now default to KES in code
--  (includes/documents.php::default_currency_id). Idempotent.
-- ============================================================

BEGIN;

-- Ensure a KES currency exists.
INSERT INTO currencies (name, code, symbol, exchange_rate, is_active)
SELECT 'Kenyan Shilling', 'KES', 'KSh', 1.0000, TRUE
WHERE NOT EXISTS (SELECT 1 FROM currencies WHERE UPPER(code) = 'KES');

DO $fix_currency$
DECLARE
    kes_id INTEGER;
BEGIN
    SELECT currency_id INTO kes_id FROM currencies WHERE UPPER(code) = 'KES' ORDER BY currency_id LIMIT 1;
    IF kes_id IS NULL THEN
        RETURN;
    END IF;

    -- Repoint sales documents pointing at a non-KES currency (or NULL).
    UPDATE quotes             SET currency_id = kes_id WHERE currency_id IS DISTINCT FROM kes_id;
    UPDATE sales_orders       SET currency_id = kes_id WHERE currency_id IS DISTINCT FROM kes_id;

    IF to_regclass('proforma_invoices') IS NOT NULL THEN
        UPDATE proforma_invoices SET currency_id = kes_id WHERE currency_id IS DISTINCT FROM kes_id;
    END IF;
    IF to_regclass('invoices') IS NOT NULL THEN
        UPDATE invoices SET currency_id = kes_id WHERE currency_id IS DISTINCT FROM kes_id;
    END IF;

    -- Products default currency (added in 007) — align too.
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='products' AND column_name='currency_id') THEN
        UPDATE products SET currency_id = kes_id WHERE currency_id IS DISTINCT FROM kes_id;
    END IF;
END
$fix_currency$;

COMMIT;
