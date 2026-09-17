-- ============================================================
--  027 — An invoice settles against its credit notes
-- ------------------------------------------------------------
--  Invoice for two items, KES 1,000 each. The customer pays
--  1,000, then returns one item. A credit note for 1,000 is
--  raised and approved. Nothing is owed by anybody.
--
--  The invoice went on reading "Partially paid", and would have
--  taken another 1,000 if somebody had offered it.
--
--  ── The principle ───────────────────────────────────────────
--  A payment is received against an invoice, not against a line
--  on it. There is no line-level allocation in this system and
--  there should not be: if there were, the same return would
--  settle differently depending on which item the customer said
--  he had paid for, which is a story, not a fact. One number
--  decides everything:
--
--      owed = invoiced − received − credited + refunded
--
--  That expression already existed — INVOICE_BALANCE_SQL — and
--  the receivables report, the customer statement and the
--  cash-book picker all used it, which is why the reports were
--  right while the invoice itself was wrong. Two places had
--  their own idea instead:
--
--    1. invoice_sync_payment_state decided the status from
--       paid vs total_amount, so an invoice settled by a credit
--       note stayed "partially paid" for ever, was chased, and
--       would eventually be called overdue.
--
--    2. invoice_payment_within_total capped receipts at
--       total_amount, so the invoice above would still have
--       accepted a second 1,000 — taking money the customer did
--       not owe and manufacturing a refund liability out of it.
--       That is the serious one: a wrong label wastes a phone
--       call, wrongly-taken money has to be given back.
--
--  Neither trigger ran when a credit note or a refund changed
--  either, only when a payment did. All three now refresh the
--  same state through one function, so the three cannot drift.
--
--  ── What "paid" means after this ────────────────────────────
--  Settled: nothing outstanding either way. That is what every
--  other ledger means by it, and credit_status still says
--  whether it was money or a credit note that settled it, so
--  the pair reads correctly:
--
--      status = paid, credit_status = none           paid for
--      status = paid, credit_status = part_credited  partly returned, rest paid
--      status = paid, credit_status = credited       returned in full
-- ============================================================

BEGIN;

-- ── What an invoice's money actually is ──────────────────────
--  One definition, so the guard and the status cannot disagree.
--
--    collectible  what may still be asked for in total
--    balance      what is outstanding right now
--                 positive → owed to us, negative → owed back
CREATE OR REPLACE FUNCTION invoice_money_state(
    inv_id INTEGER,
    OUT total       NUMERIC,
    OUT paid        NUMERIC,
    OUT credited    NUMERIC,
    OUT refunded    NUMERIC,
    OUT collectible NUMERIC,
    OUT balance     NUMERIC
) AS $$
BEGIN
    SELECT COALESCE(i.total_amount, 0) INTO total
      FROM invoices i WHERE i.invoice_id = inv_id;

    SELECT COALESCE(SUM(amount), 0) INTO paid
      FROM invoice_payments WHERE invoice_id = inv_id;

    SELECT COALESCE(SUM(total_amount), 0) INTO credited
      FROM credit_notes WHERE invoice_id = inv_id AND status = 'approved';

    SELECT COALESCE(SUM(amount), 0) INTO refunded
      FROM customer_refunds WHERE invoice_id = inv_id;

    collectible := total - credited + refunded;
    balance     := collectible - paid;
END;
$$ LANGUAGE plpgsql STABLE;

-- ── The invoice's own state, from that ───────────────────────
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
       SET amount_paid = m.paid,
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

-- ── The three things that move an invoice's money ────────────
CREATE OR REPLACE FUNCTION invoice_sync_payment_state()
RETURNS TRIGGER AS $$
BEGIN
    PERFORM invoice_refresh_money_state(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION invoice_sync_credit_status()
RETURNS TRIGGER AS $$
BEGIN
    PERFORM invoice_refresh_money_state(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- A refund changes the balance as surely as a receipt does, and
-- nothing was watching it at all.
CREATE OR REPLACE FUNCTION invoice_sync_refund_state()
RETURNS TRIGGER AS $$
BEGIN
    PERFORM invoice_refresh_money_state(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_invoice_sync_refund_state ON customer_refunds;
CREATE TRIGGER trg_invoice_sync_refund_state
    AFTER INSERT OR UPDATE OR DELETE ON customer_refunds
    FOR EACH ROW EXECUTE FUNCTION invoice_sync_refund_state();

-- ── A receipt cannot exceed what is collectible ──────────────
CREATE OR REPLACE FUNCTION invoice_payment_within_total()
RETURNS TRIGGER AS $$
DECLARE
    inv     RECORD;
    m       RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT invoice_number, total_amount, status INTO inv
      FROM invoices WHERE invoice_id = NEW.invoice_id;

    IF inv.status = 'cancelled' THEN
        RAISE EXCEPTION 'Invoice % is cancelled and cannot take a payment.', inv.invoice_number;
    END IF;

    SELECT * INTO m FROM invoice_money_state(NEW.invoice_id);

    SELECT COALESCE(SUM(amount), 0) INTO already
      FROM invoice_payments
     WHERE invoice_id = NEW.invoice_id
       AND payment_id IS DISTINCT FROM NEW.payment_id;

    IF already + NEW.amount > m.collectible + 0.005 THEN
        IF m.credited > 0.005 THEN
            RAISE EXCEPTION
                'Invoice % is for %, of which % has been credited back, so only % can be received. % is already in and a further % would over-pay it.',
                inv.invoice_number,
                to_char(inv.total_amount, 'FM999,999,999.00'),
                to_char(m.credited, 'FM999,999,999.00'),
                to_char(m.collectible, 'FM999,999,999.00'),
                to_char(already, 'FM999,999,999.00'),
                to_char(NEW.amount, 'FM999,999,999.00');
        ELSE
            RAISE EXCEPTION
                'Invoice % is for %, and % is already received. A further % would over-pay it.',
                inv.invoice_number,
                to_char(inv.total_amount, 'FM999,999,999.00'),
                to_char(already, 'FM999,999,999.00'),
                to_char(NEW.amount, 'FM999,999,999.00');
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ── Put every existing invoice on the new footing ────────────
--  Anything already settled by a credit note has been reading
--  the wrong status since it was credited.
DO $$
DECLARE r RECORD;
BEGIN
    FOR r IN SELECT invoice_id FROM invoices WHERE status NOT IN ('draft', 'cancelled') LOOP
        PERFORM invoice_refresh_money_state(r.invoice_id);
    END LOOP;
END $$;

COMMIT;
