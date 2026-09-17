-- ============================================================
--  020 — Purchase order lifecycle, supplier payments, and a
--        purchase ledger the daily position can read
-- ------------------------------------------------------------
--  Three things were missing on the buying side.
--
--  1. A purchase order had no way to record what the supplier
--     said. It could be sent and it could be cancelled, and the
--     only middle ground was "acknowledged", which does not
--     distinguish a supplier who accepted the order from one who
--     turned it down.
--
--  2. Nothing recorded paying a supplier. An order was for an
--     amount and that was the end of it — there was no paid
--     figure, no balance, and no proof of payment, so the trade
--     payables line on the daily position had to be typed in by
--     hand every time a day was closed.
--
--  3. The Purchases line on the daily position read the cash
--     book, and nothing ever wrote a purchase into it. Goods
--     received were recorded against stock and against the
--     purchase order, but never against the books, so the line
--     was always zero.
--
--  This migration answers all three:
--
--    * the order lifecycle gains accepted, rejected and closed,
--      with the decision, who made it and when
--    * purchase_payments — disbursements against an order, each
--      with its proof, mirroring invoice_payments on the sales
--      side. amount_paid and payment_status follow by trigger,
--      so neither is ever typed
--    * goods received (from GRNs) and money paid (from these
--      payments) together give trade payables, which stops
--      being a hand-entered figure
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ─── What the supplier said ─────────────────────────────────
--  'acknowledged' becomes 'accepted': it is the same event, said
--  plainly, and it leaves room for its opposite. Existing rows
--  are migrated before the constraint that would reject them.

ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_status_check;

UPDATE purchase_orders SET status = 'accepted' WHERE status = 'acknowledged';

ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check
    CHECK (status IN (
        'draft',               -- being written
        'sent',                -- with the supplier, no answer yet
        'accepted',            -- the supplier will fulfil it
        'rejected',            -- the supplier will not
        'partially_received',  -- some goods have arrived
        'received',            -- all goods have arrived
        'closed',              -- settled and done with
        'cancelled'            -- withdrawn by us
    ));

-- Who decided, when, and why. A rejection nobody can explain a
-- month later is worth very little.
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS status_note       TEXT;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS status_changed_at TIMESTAMPTZ;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS status_changed_by INTEGER
    REFERENCES users(user_id) ON DELETE SET NULL;

-- ─── What has been paid on it ───────────────────────────────
--  Both derived from purchase_payments by trigger. They live on
--  the order so a list of orders can show the balance without a
--  correlated subquery per row.

ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS amount_paid NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid';

ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_payment_status_check;
ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_payment_status_check
    CHECK (payment_status IN ('unpaid', 'partially_paid', 'paid'));

-- ─── Money paid to a supplier ───────────────────────────────

CREATE TABLE IF NOT EXISTS purchase_payments (
    payment_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    po_id           INTEGER NOT NULL REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    payment_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    method          VARCHAR(20) NOT NULL DEFAULT 'cash'
        CHECK (method IN ('cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other')),
    -- The transaction code, cheque number or slip number — the
    -- first thing anyone asks for when a payment is disputed.
    reference       VARCHAR(160),
    -- Which account the money left. Required: unlike a customer
    -- receipt, there is no advance to draw a supplier payment from.
    cash_account_id INTEGER NOT NULL REFERENCES cash_accounts(cash_account_id),
    -- Proof of payment: a photographed slip or a PDF statement.
    proof_url       TEXT,
    proof_filename  VARCHAR(255),
    notes           TEXT,
    recorded_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_purchase_payments_po   ON purchase_payments (po_id);
CREATE INDEX IF NOT EXISTS ix_purchase_payments_date ON purchase_payments (payment_date);

-- The cash book needs to be able to say where a disbursement
-- came from, so it can be unposted with the payment it records.
ALTER TABLE cash_transactions DROP CONSTRAINT IF EXISTS cash_transactions_source_type_check;
ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_source_type_check
    CHECK (source_type IS NULL OR source_type IN (
        'invoice', 'expense', 'grn', 'purchase_order', 'purchase_payment',
        'invoice_payment', 'employee_loan', 'employee_loan_repayment',
        'customer_advance', 'investment', 'investment_repayment',
        'customer_refund'
    ));

-- ─── Rule: an order may not be over-paid ────────────────────

CREATE OR REPLACE FUNCTION purchase_payment_within_total()
RETURNS TRIGGER AS $$
DECLARE
    po      RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT po_number, total_amount, status INTO po
      FROM purchase_orders WHERE po_id = NEW.po_id;

    IF po.status IN ('cancelled', 'rejected') THEN
        RAISE EXCEPTION 'Purchase order % is % and cannot take a payment.',
            po.po_number, po.status;
    END IF;
    IF po.status = 'draft' THEN
        RAISE EXCEPTION
            'Purchase order % is still a draft. Send it before paying against it.',
            po.po_number;
    END IF;

    SELECT COALESCE(SUM(amount), 0) INTO already
      FROM purchase_payments
     WHERE po_id = NEW.po_id
       AND payment_id IS DISTINCT FROM NEW.payment_id;

    IF already + NEW.amount > po.total_amount + 0.005 THEN
        RAISE EXCEPTION
            'Purchase order % is for %, and % is already paid. A further % would over-pay it.',
            po.po_number, to_char(po.total_amount, 'FM999,999,999.00'),
            to_char(already, 'FM999,999,999.00'), to_char(NEW.amount, 'FM999,999,999.00');
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_purchase_payment_within_total ON purchase_payments;
CREATE TRIGGER trg_purchase_payment_within_total
    BEFORE INSERT OR UPDATE ON purchase_payments
    FOR EACH ROW EXECUTE FUNCTION purchase_payment_within_total();

-- ─── amount_paid and payment_status follow the payments ─────
--  Neither is ever typed. The balance still to pay is
--  total_amount − amount_paid, and that one subtraction is the
--  whole of the "how much is left" question.

CREATE OR REPLACE FUNCTION po_sync_payment_state()
RETURNS TRIGGER AS $$
DECLARE
    target INTEGER := COALESCE(NEW.po_id, OLD.po_id);
    paid   NUMERIC(14,2);
    total  NUMERIC(14,2);
BEGIN
    SELECT COALESCE(SUM(amount), 0) INTO paid
      FROM purchase_payments WHERE po_id = target;

    SELECT total_amount INTO total FROM purchase_orders WHERE po_id = target;

    UPDATE purchase_orders
       SET amount_paid = paid,
           payment_status = CASE
               WHEN paid <= 0.005                        THEN 'unpaid'
               WHEN paid >= COALESCE(total, 0) - 0.005    THEN 'paid'
               ELSE 'partially_paid'
           END,
           updated_at = NOW()
     WHERE po_id = target;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_po_sync_payment_state ON purchase_payments;
CREATE TRIGGER trg_po_sync_payment_state
    AFTER INSERT OR UPDATE OR DELETE ON purchase_payments
    FOR EACH ROW EXECUTE FUNCTION po_sync_payment_state();

-- Editing an order changes what "paid in full" means, so the
-- status has to be reconsidered when the total moves.
CREATE OR REPLACE FUNCTION po_resync_on_total_change()
RETURNS TRIGGER AS $$
BEGIN
    UPDATE purchase_orders
       SET payment_status = CASE
               WHEN NEW.amount_paid <= 0.005                     THEN 'unpaid'
               WHEN NEW.amount_paid >= NEW.total_amount - 0.005   THEN 'paid'
               ELSE 'partially_paid'
           END
     WHERE po_id = NEW.po_id;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_po_resync_on_total_change ON purchase_orders;
CREATE TRIGGER trg_po_resync_on_total_change
    AFTER UPDATE OF total_amount ON purchase_orders
    FOR EACH ROW
    WHEN (OLD.total_amount IS DISTINCT FROM NEW.total_amount)
    EXECUTE FUNCTION po_resync_on_total_change();

-- ─── Bring existing orders in line ──────────────────────────

UPDATE purchase_orders po
   SET amount_paid = COALESCE(p.paid, 0),
       payment_status = CASE
           WHEN COALESCE(p.paid, 0) <= 0.005                        THEN 'unpaid'
           WHEN COALESCE(p.paid, 0) >= po.total_amount - 0.005       THEN 'paid'
           ELSE 'partially_paid'
       END
  FROM (SELECT po_id, SUM(amount) AS paid FROM purchase_payments GROUP BY po_id) p
 WHERE p.po_id = po.po_id;

-- ─── Reporting index ────────────────────────────────────────
--  The daily position sums goods received by date, every day in
--  the range it shows.

CREATE INDEX IF NOT EXISTS ix_grns_receipt_date ON grns (receipt_date) WHERE status <> 'cancelled';
