-- ============================================================
--  Make the money ceilings hold when two people push at once
-- ------------------------------------------------------------
--  Seven triggers stand between this business and money that
--  does not add up. Each one reads a parent row, sums what the
--  children already come to, and refuses anything that would go
--  over:
--
--    invoice_payment_within_total     don't over-pay an invoice
--    purchase_payment_within_total    don't over-pay a supplier
--    credit_note_within_invoice       don't credit past the invoice
--    customer_refund_within_due       don't refund more than is due
--    advance_draw_within_balance      don't draw past the advance
--    loan_repayment_within_principal  don't repay past the loan
--    investment_repayment_valid       don't repay past the investment
--
--  Every one of them was reading the parent without locking it,
--  and a SUM is a snapshot. Two transactions that arrive together
--  each look at a table where the other's row is not yet visible,
--  so each of them is under the ceiling, and both are allowed.
--
--  Measured. One invoice for 10,000, six payments of 10,000 sent
--  at the same microsecond, which is what a double-clicked Record
--  Payment button sends:
--
--      6 accepted, 0 refused
--      invoice is for      10,000.00
--      actually received   60,000.00
--      invoice status:     paid
--
--  Not one of the six was refused. The guard never saw a problem,
--  because no two of them could see each other. Three of the seven
--  govern money leaving the business — supplier payments, customer
--  refunds and advance draws — and those fail the same way.
--
--  ── The fix is four words, seven times ──────────────────────
--  The parent read becomes a locking read:
--
--      SELECT ... FROM invoices WHERE invoice_id = NEW.invoice_id
--      FOR UPDATE;
--
--  The second transaction now waits at that line. When the first
--  commits, read committed gives the waiting statement a fresh
--  look, its SUM includes the payment that just landed, and the
--  ceiling does what it was written to do.
--
--  ── It costs nothing ────────────────────────────────────────
--  Which sounds too convenient, so: the AFTER trigger on the same
--  insert already runs invoice_refresh_money_state(), and that
--  does UPDATE invoices ... on the very same row. The exclusive
--  lock was always being taken. It was simply being taken after
--  the decision instead of before it. This moves it a few
--  microseconds earlier and adds no contention that was not
--  already there.
--
--  ── On deadlocks ────────────────────────────────────────────
--  One insert into invoice_payments fires two of these guards, so
--  it takes two locks: customer_advances and invoices. Postgres
--  fires triggers in alphabetical order by name, and
--  trg_advance_draw_within_balance sorts before
--  trg_invoice_payment_within_total, so that pair is always taken
--  in the same order by every writer. No other path here locks
--  both. If a future guard needs both, it must take them in that
--  order too.
--
--  The bodies below are the live definitions with FOR UPDATE
--  added and nothing else changed, so the business rules read
--  exactly as they did before.
-- ============================================================

-- invoice_payment_within_total
CREATE OR REPLACE FUNCTION invoice_payment_within_total()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    inv     RECORD;
    m       RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT invoice_number, total_amount, status INTO inv
      FROM invoices WHERE invoice_id = NEW.invoice_id FOR UPDATE;

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
$function$;

-- customer_refund_within_due
CREATE OR REPLACE FUNCTION customer_refund_within_due()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    inv       RECORD;
    credited  NUMERIC(14,2);
    refunded  NUMERIC(14,2);
    due       NUMERIC(14,2);
BEGIN
    SELECT invoice_number, total_amount, amount_paid INTO inv
      FROM invoices WHERE invoice_id = NEW.invoice_id FOR UPDATE;

    SELECT COALESCE(SUM(total_amount), 0) INTO credited
      FROM credit_notes WHERE invoice_id = NEW.invoice_id AND status = 'approved';

    SELECT COALESCE(SUM(amount), 0) INTO refunded
      FROM customer_refunds
     WHERE invoice_id = NEW.invoice_id
       AND refund_id IS DISTINCT FROM NEW.refund_id;

    -- Negative balance is what we owe them.
    due := -(inv.total_amount - inv.amount_paid - credited + refunded);

    IF due < 0.005 THEN
        RAISE EXCEPTION
            'Nothing is owed back on invoice %. A refund would be paying out money the customer never paid.',
            inv.invoice_number;
    END IF;

    IF NEW.amount > due + 0.005 THEN
        RAISE EXCEPTION
            'Invoice % owes the customer %, so % cannot be refunded.',
            inv.invoice_number,
            to_char(due, 'FM999,999,999.00'),
            to_char(NEW.amount, 'FM999,999,999.00');
    END IF;

    RETURN NEW;
END;
$function$;

-- credit_note_within_invoice
CREATE OR REPLACE FUNCTION credit_note_within_invoice()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    cn        RECORD;
    inv       RECORD;
    already   NUMERIC(14,2);
BEGIN
    IF NEW.status <> 'approved' OR (TG_OP = 'UPDATE' AND OLD.status = 'approved') THEN
        RETURN NEW;
    END IF;

    SELECT invoice_number, total_amount INTO inv
      FROM invoices WHERE invoice_id = NEW.invoice_id FOR UPDATE;

    SELECT COALESCE(SUM(total_amount), 0) INTO already
      FROM credit_notes
     WHERE invoice_id = NEW.invoice_id
       AND status = 'approved'
       AND credit_note_id IS DISTINCT FROM NEW.credit_note_id;

    IF already + NEW.total_amount > inv.total_amount + 0.005 THEN
        RAISE EXCEPTION
            'Invoice % is for %, and % is already credited. A further % would credit more than was sold.',
            inv.invoice_number,
            to_char(inv.total_amount, 'FM999,999,999.00'),
            to_char(already, 'FM999,999,999.00'),
            to_char(NEW.total_amount, 'FM999,999,999.00');
    END IF;

    RETURN NEW;
END;
$function$;

-- purchase_payment_within_total
CREATE OR REPLACE FUNCTION purchase_payment_within_total()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    po      RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT po_number, total_amount, status INTO po
      FROM purchase_orders WHERE po_id = NEW.po_id FOR UPDATE;

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
$function$;

-- advance_draw_within_balance
CREATE OR REPLACE FUNCTION advance_draw_within_balance()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    adv     RECORD;
    already NUMERIC(14,2);
BEGIN
    IF NEW.from_advance_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT advance_number, amount, customer_id, status INTO adv
      FROM customer_advances WHERE advance_id = NEW.from_advance_id FOR UPDATE;

    IF adv.status = 'refunded' THEN
        RAISE EXCEPTION 'Advance % has been refunded and cannot be applied.', adv.advance_number;
    END IF;

    -- The advance belongs to one customer; it cannot settle
    -- another customer's invoice.
    IF NOT EXISTS (SELECT 1 FROM invoices
                    WHERE invoice_id = NEW.invoice_id AND customer_id = adv.customer_id) THEN
        RAISE EXCEPTION 'Advance % belongs to a different customer than this invoice.', adv.advance_number;
    END IF;

    SELECT COALESCE(SUM(amount), 0) INTO already
      FROM invoice_payments
     WHERE from_advance_id = NEW.from_advance_id
       AND payment_id IS DISTINCT FROM NEW.payment_id;

    IF already + NEW.amount > adv.amount + 0.005 THEN
        RAISE EXCEPTION
            'Advance % has % of % left, so % cannot be applied.',
            adv.advance_number,
            to_char(adv.amount - already, 'FM999,999,999.00'),
            to_char(adv.amount, 'FM999,999,999.00'),
            to_char(NEW.amount, 'FM999,999,999.00');
    END IF;

    RETURN NEW;
END;
$function$;

-- loan_repayment_within_principal
CREATE OR REPLACE FUNCTION loan_repayment_within_principal()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    ln      RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT loan_number, principal, status INTO ln
      FROM employee_loans WHERE loan_id = NEW.loan_id FOR UPDATE;

    IF ln.status NOT IN ('disbursed', 'settled') THEN
        RAISE EXCEPTION 'Loan % has not been disbursed, so it cannot be repaid.', ln.loan_number;
    END IF;

    SELECT COALESCE(SUM(amount), 0) INTO already
      FROM employee_loan_repayments
     WHERE loan_id = NEW.loan_id
       AND repayment_id IS DISTINCT FROM NEW.repayment_id;

    IF already + NEW.amount > ln.principal + 0.005 THEN
        RAISE EXCEPTION
            'Loan % is for %, and % is already repaid. A further % would over-repay it.',
            ln.loan_number, to_char(ln.principal, 'FM999,999,999.00'),
            to_char(already, 'FM999,999,999.00'), to_char(NEW.amount, 'FM999,999,999.00');
    END IF;

    RETURN NEW;
END;
$function$;

-- investment_repayment_valid
CREATE OR REPLACE FUNCTION investment_repayment_valid()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE
    inv     RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT reference_no, amount, investment_type INTO inv
      FROM investments WHERE investment_id = NEW.investment_id FOR UPDATE;

    IF inv.investment_type <> 'loan' THEN
        RAISE EXCEPTION
            '% is equity, not a loan, so it is not repaid. Record a drawing or dividend instead.',
            inv.reference_no;
    END IF;

    SELECT COALESCE(SUM(amount), 0) INTO already
      FROM investment_repayments
     WHERE investment_id = NEW.investment_id
       AND repayment_id IS DISTINCT FROM NEW.repayment_id;

    IF already + NEW.amount > inv.amount + 0.005 THEN
        RAISE EXCEPTION
            'Investor loan % is for %, and % is already repaid.',
            inv.reference_no, to_char(inv.amount, 'FM999,999,999.00'),
            to_char(already, 'FM999,999,999.00');
    END IF;

    RETURN NEW;
END;
$function$;
