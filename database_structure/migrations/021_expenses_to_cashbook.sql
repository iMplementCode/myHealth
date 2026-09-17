-- ============================================================
--  021 — Expenses reach the cash book
-- ------------------------------------------------------------
--  An expense was a note in a list. It recorded that money had
--  gone, but never which account it left, so nothing connected
--  it to the books: the Operating expenses line on the daily
--  position read the cash book, and no expense ever wrote to
--  it. The figure was always zero unless somebody typed the
--  movement in a second time by hand.
--
--  This is the same hole migration 020 closed on the buying
--  side. An expense now says where the money came from, and
--  saving one writes the cash-book entry with it.
--
--  Paying is separate from recording. An expense can be
--  entered before it is settled — an invoice from the landlord
--  that will be paid on Friday — so the account is optional
--  and only a settled expense moves cash.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- Which account the money left. NULL means recorded but not yet
-- paid: real, owed, and not yet a cash movement.
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS cash_account_id INTEGER
    REFERENCES cash_accounts(cash_account_id);

ALTER TABLE expenses ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20);

ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_payment_method_check;
ALTER TABLE expenses ADD CONSTRAINT expenses_payment_method_check
    CHECK (payment_method IS NULL OR payment_method IN
        ('cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other'));

-- When it was actually settled. Rent recorded on the 1st and
-- paid on the 5th belongs to the 1st in the profit and loss and
-- to the 5th in the cash book, and only two dates can say so.
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS paid_on DATE;

-- A supplier, where there is one. Makes an expense traceable to
-- a party the way an invoice or a purchase order is.
ALTER TABLE expenses ADD COLUMN IF NOT EXISTS supplier_id INTEGER
    REFERENCES suppliers(supplier_id) ON DELETE SET NULL;

-- An account without a date, or a date without an account, would
-- leave the books unable to say when the money moved.
ALTER TABLE expenses DROP CONSTRAINT IF EXISTS ck_expenses_payment_pair;
ALTER TABLE expenses ADD CONSTRAINT ck_expenses_payment_pair CHECK (
    (cash_account_id IS NULL AND paid_on IS NULL) OR
    (cash_account_id IS NOT NULL AND paid_on IS NOT NULL)
);

CREATE INDEX IF NOT EXISTS ix_expenses_paid_on ON expenses (paid_on)
    WHERE paid_on IS NOT NULL;

-- Expenses must sit on an account cash actually lives in. The
-- rule already exists for sales and purchases; 'expense' was in
-- the list from the start, so nothing to change there — this is
-- only a note that the guard now has something to guard.
