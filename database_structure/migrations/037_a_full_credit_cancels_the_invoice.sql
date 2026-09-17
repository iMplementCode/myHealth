-- ============================================================
--  037 — A full credit cancels the invoice
-- ------------------------------------------------------------
--  Raising a credit note "for the whole invoice" left the invoice
--  reading "part credited", and some credit notes could not be
--  approved at all.
--
--  ── What was happening ──────────────────────────────────────
--  A full credit note was not credited for what the invoice was
--  for. It was rebuilt from the invoice's line items, at quantity
--  × unit price, with the line discount thrown away. That equals
--  the invoice total only when the header happens to be exactly
--  the sum of the undiscounted lines, and there are four ordinary
--  ways for it not to be:
--
--    • a line carries a discount — the note came out ABOVE the
--      invoice, and credit_note_within_invoice() refused it, so
--      it could never be approved and no goods return ever opened
--    • the header sits above the sum of its lines (a converted
--      quote, a rounded total) — the note came out short, and the
--      invoice stayed at "part credited" with a balance nobody
--      owed
--    • the header sits below its lines — refused, as above
--    • the invoice has no lines at all, raised for a lump sum —
--      the note came out at 0.00, and "a credit note with no
--      value cannot be approved"
--
--  The application now takes a full credit note's value from the
--  invoice and carries each line's discount through, so all four
--  land on the invoice total exactly. This migration repairs the
--  notes already recorded under the old arithmetic.
--
--  ── What is repaired, and what is left alone ────────────────
--  Only where the intent is unambiguous:
--
--    drafts marked "full"      → set to what is left creditable.
--                                Nothing has moved yet; a draft is
--                                not a financial fact.
--    approved, marked "full",  → set to the invoice total. It said
--    the only one on its         the whole sale was cancelled, so
--    invoice, crediting less     that is what it should have
--    than the invoice            credited.
--
--  Partial credit notes are NOT touched. A partial note's value is
--  its lines, which is what it always was; where an old one lost a
--  discount the difference is a real historical figure somebody
--  agreed with a customer, and quietly moving approved money is
--  worse than leaving it visible.
--
--  Every invoice touched is then re-derived, which is what moves
--  it from "part credited" to "credited".
-- ============================================================

BEGIN;

DO $$
DECLARE
    r          RECORD;
    drafts     INTEGER := 0;
    approved   INTEGER := 0;
    sub        NUMERIC(14,2);
    tax        NUMERIC(14,2);
    target     NUMERIC(14,2);
BEGIN
    -- ── Drafts marked "full": worth what is left on the invoice ──
    FOR r IN
        SELECT cn.credit_note_id, cn.tax_rate, cn.total_amount,
               i.invoice_id, i.total_amount AS invoice_total,
               COALESCE((SELECT SUM(o.total_amount) FROM credit_notes o
                          WHERE o.invoice_id = cn.invoice_id
                            AND o.status = 'approved'), 0) AS already
          FROM credit_notes cn
          JOIN invoices i ON i.invoice_id = cn.invoice_id
         WHERE cn.status = 'draft'
           AND cn.return_scope = 'full'
    LOOP
        target := GREATEST(r.invoice_total - r.already, 0);
        IF ABS(target - r.total_amount) < 0.005 THEN
            CONTINUE;
        END IF;
        sub := CASE WHEN COALESCE(r.tax_rate, 0) > 0
                    THEN ROUND(target / (1 + r.tax_rate), 2) ELSE target END;
        tax := ROUND(target - sub, 2);
        UPDATE credit_notes
           SET subtotal = sub, tax_amount = tax, total_amount = sub + tax,
               updated_at = NOW()
         WHERE credit_note_id = r.credit_note_id;
        drafts := drafts + 1;
    END LOOP;

    -- ── Approved, marked "full", alone on the invoice, short ─────
    FOR r IN
        SELECT cn.credit_note_id, cn.tax_rate,
               i.invoice_id, i.total_amount AS invoice_total
          FROM credit_notes cn
          JOIN invoices i ON i.invoice_id = cn.invoice_id
         WHERE cn.status = 'approved'
           AND cn.return_scope = 'full'
           AND cn.total_amount < i.total_amount - 0.005
           AND i.total_amount > 0
           AND NOT EXISTS (
                SELECT 1 FROM credit_notes o
                 WHERE o.invoice_id = cn.invoice_id
                   AND o.status = 'approved'
                   AND o.credit_note_id <> cn.credit_note_id
           )
    LOOP
        sub := CASE WHEN COALESCE(r.tax_rate, 0) > 0
                    THEN ROUND(r.invoice_total / (1 + r.tax_rate), 2)
                    ELSE r.invoice_total END;
        tax := ROUND(r.invoice_total - sub, 2);
        UPDATE credit_notes
           SET subtotal = sub, tax_amount = tax, total_amount = sub + tax,
               updated_at = NOW()
         WHERE credit_note_id = r.credit_note_id;
        approved := approved + 1;
    END LOOP;

    RAISE NOTICE 'Full credit notes corrected: % draft, % approved.', drafts, approved;
END $$;

-- ── Carry the discount onto lines that lost it ───────────────
--  Draft notes only, and only where the matching invoice line has
--  a discount the credit line does not. The header is what the
--  money comes from now, but the lines are what prints on the
--  credit note the customer is handed, and a line showing full
--  price against a discounted sale is wrong on its face.
--
--  Approved notes are left alone: credit_note_items_guard()
--  refuses to let their lines change, and rightly so.
UPDATE credit_note_items ci
   SET discount = ROUND(ii.discount * (ci.quantity / NULLIF(ii.quantity, 0)), 2)
  FROM credit_notes cn
  JOIN invoice_items ii ON ii.invoice_id = cn.invoice_id
 WHERE ci.credit_note_id = cn.credit_note_id
   AND ii.product_id = ci.product_id
   AND cn.status = 'draft'
   AND ii.discount > 0
   AND ci.discount = 0
   AND ci.quantity <= ii.quantity;

-- ── Re-derive every invoice that carries a credit note ───────
--  This is the step that moves a fully credited invoice off
--  "part credited". It reads the notes; it does not change them.
DO $$
DECLARE r RECORD; n INTEGER := 0;
BEGIN
    FOR r IN SELECT DISTINCT invoice_id FROM credit_notes WHERE invoice_id IS NOT NULL
    LOOP
        PERFORM invoice_refresh_money_state(r.invoice_id);
        n := n + 1;
    END LOOP;
    RAISE NOTICE 'Re-derived % invoice(s) carrying credit notes.', n;
END $$;

COMMIT;
