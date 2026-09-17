-- ============================================================
--  043 — The bill the supplier sent
-- ------------------------------------------------------------
--  Migration 042 let the business buy a service: order it, accept
--  the work, owe for it, pay it. What it never gave anywhere to
--  put was the supplier's own invoice — the piece of paper that
--  arrives afterwards saying INV-4471, 12 August, KES 52,200.
--
--  For goods that document has a home. The GRN carries
--  `supplier_invoice_no`, because a receipt and an invoice arrive
--  together. A service has no GRN and never will, so the invoice
--  had nowhere to go, and two things followed from that.
--
--  ── One: a payment nobody can match ─────────────────────────
--  Payments are recorded against a purchase order. When the
--  designer asks three months later which of their invoices was
--  settled, the honest answer from this system was the order
--  number — which is OUR reference, not theirs, and means nothing
--  to them.
--
--  ── Two: the VAT was never claimed ──────────────────────────
--  This is the one that costs money every month.
--
--      report_vat()  →  SUM(grns.tax_amount)
--
--  Input tax came from goods receipts and from nothing else. A
--  logo design billed at 45,000 + 7,200 VAT contributed zero to
--  the input side of the return, so the business declared 7,200
--  more payable than it owed, every time it bought a service.
--
--  ── What is stored, and why on the line ─────────────────────
--  On the item, not the order, because that is where `accepted_at`
--  already lives and the two are recorded in the same breath: the
--  work is accepted BECAUSE the bill arrived. An order can also
--  mix goods and services, and only the service lines carry a bill
--  of their own.
--
--  `tax_amount` is what the supplier ACTUALLY charged, not what
--  the order estimated — the principle migration 029 established
--  for goods. NULL means nobody has recorded it yet, which is
--  deliberately different from zero: zero is a supplier who
--  charged no VAT, NULL is a claim that may be going unmade, and
--  the VAT report distinguishes them.
-- ============================================================

BEGIN;

-- ─── Their reference for the work ───────────────────────────
ALTER TABLE purchase_order_items
    ADD COLUMN IF NOT EXISTS supplier_invoice_no VARCHAR(100);

COMMENT ON COLUMN purchase_order_items.supplier_invoice_no IS
    'For a service line: the invoice number the supplier put on '
    'their bill. Does for a service what grns.supplier_invoice_no '
    'does for goods — the reference THEY will quote.';

ALTER TABLE purchase_order_items
    ADD COLUMN IF NOT EXISTS supplier_invoice_date DATE;

COMMENT ON COLUMN purchase_order_items.supplier_invoice_date IS
    'The date on the supplier''s invoice, which is not always the '
    'day the work was accepted and is the date a VAT return is '
    'defended on.';

-- ─── The VAT they actually charged ──────────────────────────
ALTER TABLE purchase_order_items
    ADD COLUMN IF NOT EXISTS tax_amount NUMERIC(12,2);

COMMENT ON COLUMN purchase_order_items.tax_amount IS
    'For a service line: the VAT the supplier charged, from their '
    'invoice. NULL = not recorded, and possibly an unclaimed '
    'input credit; 0 = they charged none. The two are different '
    'and the VAT report shows them differently.';

ALTER TABLE purchase_order_items
    DROP CONSTRAINT IF EXISTS ck_poi_tax_not_negative;
ALTER TABLE purchase_order_items
    ADD CONSTRAINT ck_poi_tax_not_negative
    CHECK (tax_amount IS NULL OR tax_amount >= 0);

-- ─── A bill belongs to work that was accepted ───────────────
--  Recording the supplier's invoice against a line nobody has
--  accepted would put an unclaimable figure on the VAT return and
--  an amount on the payables report that nothing owes.
CREATE OR REPLACE FUNCTION trg_poi_bill_needs_acceptance()
RETURNS TRIGGER AS $$
BEGIN
    IF (NEW.supplier_invoice_no IS NOT NULL
        OR NEW.supplier_invoice_date IS NOT NULL
        OR NEW.tax_amount IS NOT NULL)
       AND NEW.accepted_at IS NULL THEN
        RAISE EXCEPTION
            'A supplier bill can only be recorded against service work that has been accepted.';
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS poi_bill_needs_acceptance ON purchase_order_items;
CREATE TRIGGER poi_bill_needs_acceptance
    BEFORE INSERT OR UPDATE ON purchase_order_items
    FOR EACH ROW EXECUTE FUNCTION trg_poi_bill_needs_acceptance();

-- ─── The VAT report walks these ─────────────────────────────
CREATE INDEX IF NOT EXISTS idx_po_items_supplier_invoice
    ON purchase_order_items (supplier_invoice_date)
    WHERE supplier_invoice_no IS NOT NULL;

COMMIT;
