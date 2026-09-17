-- ============================================================
--  040 — A discount cannot exceed its line
-- ------------------------------------------------------------
--  Every document line stores its own arithmetic:
--
--      subtotal = quantity × unit_price − discount   (generated)
--
--  and `discount` is an AMOUNT, not a percentage. Nothing stopped
--  that amount being larger than the line it discounts.
--
--  ── What that produced ──────────────────────────────────────
--  A quote for one item at 1,000 with a discount of 999,999 was
--  accepted. The line stored a subtotal of −998,999. The header
--  stored 0.00, because the page floors each line at zero when it
--  adds them up:
--
--      $subtotal += max(0, $qty * $price - $disc);
--
--  So the document disagreed with itself — its lines summed to
--  minus a million and its total said nothing. Convert that quote
--  to a proforma and then an invoice and the line travels with
--  it, and since migration 037 a credit note takes its discount
--  from the invoice line, so the nonsense reaches the customer's
--  refund too.
--
--  ── Why a constraint and not a check on the page ────────────
--  There are three pages that capture a line discount (quotes,
--  sales orders, proformas) and two more paths that copy lines
--  between documents. Fixing the pages fixes today; the rule
--  belongs with the data, where no future path can miss it.
--
--  Those pages now also refuse it in words, because a database
--  error is a worse way to learn you typed a percentage into a
--  box that wanted shillings.
--
--  ── A free line is still allowed ────────────────────────────
--  discount = quantity × unit_price is legitimate: an item given
--  away, priced so the customer can see what it is worth. Only
--  MORE than the line is refused. The half-cent of slack absorbs
--  the rounding of a discount worked out as a percentage.
-- ============================================================

BEGIN;

-- ─── Anything already stored the old way ────────────────────
--  Capped rather than deleted: the line is a real thing somebody
--  put on a document, and the intent of "discount larger than the
--  line" is unambiguously "this line is free".
DO $$
DECLARE
    t TEXT;
    n INTEGER;
    fixed INTEGER := 0;
BEGIN
    FOREACH t IN ARRAY ARRAY['quote_items','sales_order_items','proforma_invoice_items',
                             'invoice_items','credit_note_items']
    LOOP
        EXECUTE format(
            'UPDATE %I SET discount = quantity * unit_price
              WHERE discount > quantity * unit_price + 0.005', t);
        GET DIAGNOSTICS n = ROW_COUNT;
        fixed := fixed + n;
        IF n > 0 THEN
            RAISE NOTICE 'Capped % over-discounted line(s) in %.', n, t;
        END IF;
    END LOOP;
    RAISE NOTICE 'Over-discounted lines corrected: %.', fixed;
END $$;

-- ─── And the rule, from here on ─────────────────────────────
DO $$
DECLARE t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['quote_items','sales_order_items','proforma_invoice_items',
                             'invoice_items','credit_note_items']
    LOOP
        -- Idempotent: re-running the migration must not fail on a
        -- constraint it added last time.
        IF NOT EXISTS (
            SELECT 1 FROM pg_constraint
             WHERE conname = 'ck_' || t || '_discount_fits'
               AND conrelid = t::regclass
        ) THEN
            EXECUTE format(
                'ALTER TABLE %I ADD CONSTRAINT %I
                 CHECK (discount >= 0 AND discount <= quantity * unit_price + 0.005)',
                t, 'ck_' || t || '_discount_fits');
        END IF;
    END LOOP;
END $$;

COMMIT;
