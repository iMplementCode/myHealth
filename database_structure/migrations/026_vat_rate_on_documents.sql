-- ============================================================
--  026 — Documents remember which VAT rate they were charged at
-- ------------------------------------------------------------
--  Kenya charges VAT at two rates. 16% is the general rate; 8%
--  applies to petroleum products. Every document in this system
--  stored only tax_amount — the money — and threw the rate away
--  the moment it had multiplied by it.
--
--  With one rate in use that was survivable. With two it is not:
--
--    * Nothing can tell an 8% document from a 16% one afterwards.
--      The sales-order screen had to *guess* — "tax above zero,
--      so assume 16%" — which quietly turned an 8% quote into a
--      16% order and overcharged the customer eight points.
--    * A VAT return has to declare output tax by rate. Derived
--      from the money alone that is arithmetic on a rounded
--      figure, and it stops working the moment a document mixes
--      rates or carries a rounding difference.
--    * Reprinting a document a year later cannot say what rate
--      it was raised at, which is the first thing anybody asks
--      of an old invoice.
--
--  ── What this does ──────────────────────────────────────────
--  Adds tax_rate to the six document tables that carry tax, and
--  backfills it from the figures already there.
--
--  The backfill is a derivation, not a guess: tax_amount divided
--  by the base each document actually taxed. Quotes, sales
--  orders, proformas and invoices tax the subtotal after the
--  header discount; purchase orders and credit notes tax the
--  subtotal. Where there was no tax the rate is zero. Every
--  existing row will come out at 0.1600 because 16% is all this
--  system has ever offered — which is the point: the figure is
--  read out of the data, not assumed.
--
--  Rounded to four places, so 0.16 stays 0.16 rather than
--  0.15999999 from a rounded tax figure.
--
--  There is deliberately no CHECK pinning the rate to today's
--  16 and 8. Rates are set by a Finance Act, not by this schema,
--  and a document raised under an old rate must stay readable
--  after a new one arrives. The only constraint is that a rate
--  is a fraction between nothing and everything.
-- ============================================================

BEGIN;

-- ── The column, on each document that carries tax ────────────
DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'quotes', 'sales_orders', 'proforma_invoices',
        'invoices', 'purchase_orders', 'credit_notes'
    ] LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format(
                'ALTER TABLE %I ADD COLUMN IF NOT EXISTS tax_rate NUMERIC(6,4) NOT NULL DEFAULT 0', t);

            -- Named so a re-run finds it rather than adding a second.
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                 WHERE conname = 'ck_' || t || '_tax_rate'
            ) THEN
                EXECUTE format(
                    'ALTER TABLE %I ADD CONSTRAINT %I CHECK (tax_rate >= 0 AND tax_rate <= 1)',
                    t, 'ck_' || t || '_tax_rate');
            END IF;
        END IF;
    END LOOP;
END $$;

-- ── Backfill: taxed after the header discount ────────────────
--  Only rows still at the column default are touched, so running
--  this again cannot overwrite a rate somebody has since set.
DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'quotes', 'sales_orders', 'proforma_invoices', 'invoices'
    ] LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format($f$
                UPDATE %I
                   SET tax_rate = ROUND(
                           tax_amount / NULLIF(subtotal - COALESCE(discount_amount, 0), 0), 4)
                 WHERE tax_rate = 0
                   AND COALESCE(tax_amount, 0) > 0
                   AND COALESCE(subtotal, 0) - COALESCE(discount_amount, 0) > 0
                   AND ROUND(tax_amount / NULLIF(subtotal - COALESCE(discount_amount, 0), 0), 4)
                       BETWEEN 0 AND 1
            $f$, t);
        END IF;
    END LOOP;
END $$;

-- ── Backfill: taxed on the subtotal ──────────────────────────
DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['purchase_orders', 'credit_notes'] LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format($f$
                UPDATE %I
                   SET tax_rate = ROUND(tax_amount / NULLIF(subtotal, 0), 4)
                 WHERE tax_rate = 0
                   AND COALESCE(tax_amount, 0) > 0
                   AND COALESCE(subtotal, 0) > 0
                   AND ROUND(tax_amount / NULLIF(subtotal, 0), 4) BETWEEN 0 AND 1
            $f$, t);
        END IF;
    END LOOP;
END $$;

COMMIT;
