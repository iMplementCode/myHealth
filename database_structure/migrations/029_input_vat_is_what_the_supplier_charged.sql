-- ============================================================
--  029 — Input VAT is what the supplier charged, not what the
--        purchase order guessed
-- ------------------------------------------------------------
--  The VAT summary was claiming input tax off the *purchase
--  order*. A purchase order is our own document. The 16% on it
--  was typed by us, on a form that opens on 16% by default, and
--  it is a forecast of what we expect to be billed — not
--  evidence that anybody billed us.
--
--  So a receipt against a supplier who charges no VAT still
--  produced input tax on the return, because the order behind
--  it had been left on the default. That is a claim for money
--  never paid, and the person filing has no way to see where it
--  came from: the report showed one total and nothing behind it.
--
--  ── The rule ────────────────────────────────────────────────
--  Input VAT is what the supplier actually charged, evidenced
--  by their tax invoice, recorded when the goods are received —
--  which is the moment that invoice is in somebody's hand.
--
--  The goods received note is therefore where the tax belongs.
--  It already records the supplier's invoice number; it now
--  records the tax on it as well, and the VAT return reads
--  that and nothing else.
--
--  ── What this does ──────────────────────────────────────────
--  Adds tax_rate and tax_amount to grns, and backfills them.
--
--  The backfill applies the order's apportioned VAT **only to
--  receipts that recorded a supplier invoice number**, and zero
--  to the rest. That is deliberate and it is the safe
--  direction:
--
--    * Over-claiming input VAT is what gets assessed in an
--      audit. Under-claiming costs nothing that cannot be
--      recovered by entering the invoice.
--    * A receipt with no supplier invoice number recorded has
--      no document to claim against. Whether the invoice exists
--      on paper is not something a migration can know.
--    * Nothing is lost quietly. Every receipt zeroed this way
--      is listed on the VAT summary, with the order's expected
--      VAT beside it and a link to correct it, so the figure
--      can be put back in one edit where a tax invoice really
--      does exist.
--
--  total_amount on a GRN stays net of tax, as it always was.
--  It is what the goods cost, and stock is valued from it.
-- ============================================================

BEGIN;

-- ── The columns ──────────────────────────────────────────────
DO $$
BEGIN
    IF to_regclass('public.grns') IS NULL THEN
        RETURN;
    END IF;

    ALTER TABLE grns ADD COLUMN IF NOT EXISTS tax_rate   NUMERIC(6,4)  NOT NULL DEFAULT 0;
    ALTER TABLE grns ADD COLUMN IF NOT EXISTS tax_amount NUMERIC(14,2) NOT NULL DEFAULT 0;

    -- Named, so a re-run finds it rather than adding a second.
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_grns_tax_rate') THEN
        ALTER TABLE grns ADD CONSTRAINT ck_grns_tax_rate
            CHECK (tax_rate >= 0 AND tax_rate <= 1);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_grns_tax_amount') THEN
        ALTER TABLE grns ADD CONSTRAINT ck_grns_tax_amount
            CHECK (tax_amount >= 0);
    END IF;
END $$;

COMMENT ON COLUMN grns.tax_rate IS
    'VAT rate the supplier charged on this receipt, as a fraction. Zero where they charged none.';
COMMENT ON COLUMN grns.tax_amount IS
    'VAT the supplier charged on this receipt. The only source of input tax on the VAT return.';

-- ── Backfill: only where a tax invoice was recorded ──────────
--  The order's VAT, apportioned by the share of the order this
--  receipt represents — the same arithmetic the report used to
--  do on the fly, now written down once and correctable.
--
--  Only rows still at the column default are touched, so a
--  re-run cannot overwrite a figure somebody has since set.
DO $$
BEGIN
    IF to_regclass('public.grns') IS NULL
       OR to_regclass('public.purchase_orders') IS NULL THEN
        RETURN;
    END IF;

    UPDATE grns g
       SET tax_rate   = po.tax_rate,
           tax_amount = LEAST(
                            ROUND(po.tax_amount * (g.total_amount / NULLIF(po.subtotal, 0)), 2),
                            po.tax_amount)
      FROM purchase_orders po
     WHERE po.po_id = g.po_id
       AND g.tax_amount = 0
       AND g.tax_rate = 0
       AND COALESCE(po.tax_amount, 0) > 0
       AND COALESCE(po.subtotal, 0) > 0
       AND g.status <> 'cancelled'
       AND COALESCE(TRIM(g.supplier_invoice_no), '') <> '';
END $$;

COMMIT;
