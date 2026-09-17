-- ============================================================
--  028 — The invoice's balance stops being everyone's guess
-- ------------------------------------------------------------
--  Migration 027 taught the invoice's *status* and the
--  over-payment guard to count credit notes. It did not go far
--  enough: seven other places worked the balance out for
--  themselves, every one of them as
--
--      total_amount − amount_paid
--
--  which is blind to credit notes and refunds. So an invoice
--  with an item returned still showed its full balance on the
--  payments list, on the invoice list, on the printed invoice
--  the customer receives, and in the Excel export; the payment
--  filters still called it outstanding; and the outstanding
--  total still added it up.
--
--  Patching seven call sites would leave the eighth to be
--  written next month. The balance is a fact about the invoice,
--  so it belongs on the invoice:
--
--      amount_credited   approved credit notes against it
--      amount_refunded   money already handed back
--      balance_due       generated, always
--                        total − paid − credited + refunded
--
--  balance_due is GENERATED ALWAYS ... STORED, so it cannot be
--  set, cannot be stale, and cannot be got wrong: every page
--  that selects i.* now has the right number without joining
--  anything. amount_paid was already maintained this way and
--  nobody has computed *that* by hand since.
--
--  The sums stay derived, not typed — the same trigger that
--  already refreshed amount_paid now refreshes all three, on a
--  receipt, a credit note or a refund alike.
--
--  Sign, throughout:
--      positive → the customer owes us   (a trade receivable)
--      negative → we owe the customer    (a refund liability)
-- ============================================================

BEGIN;

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS amount_credited NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS amount_refunded NUMERIC(14,2) NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_name = 'invoices' AND column_name = 'balance_due'
    ) THEN
        ALTER TABLE invoices
            ADD COLUMN balance_due NUMERIC(14,2)
            GENERATED ALWAYS AS
                (total_amount - amount_paid - amount_credited + amount_refunded) STORED;
    END IF;
END $$;

COMMENT ON COLUMN invoices.balance_due IS
    'What is outstanding: total - paid - credited + refunded. Generated, so it '
    'cannot be set or go stale. Positive = owed to us, negative = owed back to '
    'the customer. Never compute this by hand.';

-- ── One refresh, now maintaining all three sums ──────────────
CREATE OR REPLACE FUNCTION invoice_refresh_money_state(inv_id INTEGER)
RETURNS VOID AS $$
DECLARE
    m       RECORD;
    current TEXT;
    ctotal  NUMERIC(14,2);
BEGIN
    SELECT * INTO m FROM invoice_money_state(inv_id);
    SELECT status, total_amount INTO current, ctotal
      FROM invoices WHERE invoice_id = inv_id;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    UPDATE invoices
       SET amount_paid     = m.paid,
           amount_credited = m.credited,
           amount_refunded = m.refunded,
           status = CASE
               -- A draft or a cancelled invoice is not in play.
               WHEN current IN ('draft', 'cancelled') THEN current
               -- Nothing outstanding either way. A negative balance
               -- is settled too: what we owe back is a refund, which
               -- the credit note and the refund page handle, not a
               -- receivable to keep chasing.
               WHEN m.balance <= 0.005 AND (m.paid > 0 OR m.credited > 0) THEN 'paid'
               WHEN m.paid > 0 THEN 'partially_paid'
               -- Every payment reversed: back to owing, unless it
               -- was already past due.
               WHEN current = 'overdue' THEN 'overdue'
               ELSE 'issued'
           END,
           credit_status = CASE
               WHEN m.credited >= ctotal - 0.005 AND ctotal > 0 THEN 'credited'
               WHEN m.credited > 0 THEN 'part_credited'
               WHEN credit_status = 'awaiting_credit' THEN 'awaiting_credit'
               ELSE 'none'
           END,
           updated_at = NOW()
     WHERE invoice_id = inv_id;
END;
$$ LANGUAGE plpgsql;

-- ── Bring every invoice up to date ───────────────────────────
DO $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT invoice_id FROM invoices LOOP
        PERFORM invoice_refresh_money_state(r.invoice_id);
    END LOOP;
END $$;

COMMIT;
