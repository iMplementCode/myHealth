-- ============================================================
--  016 — Receivables, liabilities and capital
-- ------------------------------------------------------------
--  Migration 015 gave the business a cash book. This one gives
--  it the other three sides of a balance sheet, and corrects the
--  vocabulary of the report:
--
--    * money is banked, not only held — cash_accounts gains
--      'bank' and 'mobile_money' types
--    * invoice_payments — receipts against an invoice, each with
--      its proof of payment. What is left unpaid is a trade
--      receivable, so debtors stop being typed by hand
--    * employee_loans — requested, approved, disbursed, repaid,
--      with the outstanding amount carried on a loan account
--    * customer_advances — money taken before the invoice is
--      raised. Not income: a liability, until it is applied
--    * investments — equity that is never repaid, or an investor
--      loan that is
--
--  Cash always moves through the cash book. These tables record
--  what the cash movement *meant*, which is what the balance
--  sheet needs and the cash book alone cannot say.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ─── Money now lives in banks too ───────────────────────────

-- 'mobile_money' does not fit the original VARCHAR(10). Widen the
-- column before the constraint that would admit the value.
ALTER TABLE cash_accounts ALTER COLUMN account_type TYPE VARCHAR(20);

ALTER TABLE cash_accounts DROP CONSTRAINT IF EXISTS cash_accounts_account_type_check;
ALTER TABLE cash_accounts ADD CONSTRAINT cash_accounts_account_type_check
    CHECK (account_type IN ('cash', 'bank', 'mobile_money', 'loan', 'credit'));

-- Where a bank account is concerned, these are what people look
-- for. Left free-text and nullable: they are labels, not keys.
ALTER TABLE cash_accounts ADD COLUMN IF NOT EXISTS institution    VARCHAR(120);
ALTER TABLE cash_accounts ADD COLUMN IF NOT EXISTS account_number VARCHAR(60);

-- ─── More reasons for money to move ─────────────────────────

ALTER TABLE cash_transactions DROP CONSTRAINT IF EXISTS cash_transactions_category_check;
ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_category_check
    CHECK (category IN (
        'sale',              -- cash taken for a sale
        'credit_sale',       -- goods released on credit, no invoice raised
        'invoice_payment',   -- a receipt against an invoice
        'customer_advance',  -- paid before the invoice: a liability
        'investment',        -- capital or an investor loan coming in
        'other_income',
        'capital',           -- owner putting money in
        'purchase',
        'expense',
        'drawing',           -- owner taking money out
        'refund',            -- money given back to a customer
        'transfer',          -- between two accounts; staff loans use this
        'adjustment'
    ));

-- 'employee_loan_repayment' is 23 characters; the column was 20.
ALTER TABLE cash_transactions ALTER COLUMN source_type TYPE VARCHAR(30);

ALTER TABLE cash_transactions DROP CONSTRAINT IF EXISTS cash_transactions_source_type_check;
ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_source_type_check
    CHECK (source_type IS NULL OR source_type IN (
        'invoice', 'expense', 'grn', 'purchase_order',
        'invoice_payment', 'employee_loan', 'employee_loan_repayment',
        'customer_advance', 'investment', 'investment_repayment'
    ));

-- Sales, purchases and expenses must sit somewhere cash actually
-- is — which now includes banks and mobile money.
CREATE OR REPLACE FUNCTION cash_txn_check_account_type()
RETURNS TRIGGER AS $$
DECLARE
    acct_type TEXT;
    acct_name TEXT;
BEGIN
    SELECT account_type, name INTO acct_type, acct_name
      FROM cash_accounts WHERE cash_account_id = NEW.cash_account_id;

    IF NEW.category = 'credit_sale' AND acct_type <> 'credit' THEN
        RAISE EXCEPTION
            'Goods released on credit must be recorded against a credit account, not %.', acct_name;
    END IF;

    IF NEW.category IN ('sale', 'purchase', 'expense', 'invoice_payment',
                        'customer_advance', 'investment', 'refund')
       AND acct_type NOT IN ('cash', 'bank', 'mobile_money') THEN
        RAISE EXCEPTION
            'A % must be recorded against a cash, bank or mobile-money account, not %.',
            replace(NEW.category, '_', ' '), acct_name;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_cash_txn_account_type ON cash_transactions;
CREATE TRIGGER trg_cash_txn_account_type
    BEFORE INSERT OR UPDATE ON cash_transactions
    FOR EACH ROW EXECUTE FUNCTION cash_txn_check_account_type();

-- ─── Receipts against an invoice ────────────────────────────

CREATE TABLE IF NOT EXISTS invoice_payments (
    payment_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id      INTEGER NOT NULL REFERENCES invoices(invoice_id) ON DELETE CASCADE,
    payment_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    method          VARCHAR(20) NOT NULL DEFAULT 'cash'
        CHECK (method IN ('cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other')),
    -- The transaction code, cheque number or slip number. This is
    -- the first thing anyone asks for when a payment is disputed.
    reference       VARCHAR(160),
    -- Which account the money landed in. NULL only when the
    -- receipt is settled from an advance already banked.
    cash_account_id INTEGER REFERENCES cash_accounts(cash_account_id),
    -- Proof of payment: a photographed slip or a PDF statement.
    proof_url       TEXT,
    proof_filename  VARCHAR(255),
    -- Set when this receipt draws down a customer advance rather
    -- than bringing new money in.
    from_advance_id INTEGER,
    notes           TEXT,
    recorded_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    -- Either new money into an account, or a draw-down of an
    -- advance. Never both, never neither.
    CONSTRAINT ck_invoice_payment_origin CHECK (
        (cash_account_id IS NOT NULL AND from_advance_id IS NULL) OR
        (cash_account_id IS NULL     AND from_advance_id IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS ix_invoice_payments_invoice ON invoice_payments (invoice_id);
CREATE INDEX IF NOT EXISTS ix_invoice_payments_date    ON invoice_payments (payment_date);

-- ─── Rule: an invoice may not be over-paid ──────────────────

CREATE OR REPLACE FUNCTION invoice_payment_within_total()
RETURNS TRIGGER AS $$
DECLARE
    inv     RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT invoice_number, total_amount, status INTO inv
      FROM invoices WHERE invoice_id = NEW.invoice_id;

    IF inv.status = 'cancelled' THEN
        RAISE EXCEPTION 'Invoice % is cancelled and cannot take a payment.', inv.invoice_number;
    END IF;

    SELECT COALESCE(SUM(amount), 0) INTO already
      FROM invoice_payments
     WHERE invoice_id = NEW.invoice_id
       AND payment_id IS DISTINCT FROM NEW.payment_id;

    IF already + NEW.amount > inv.total_amount + 0.005 THEN
        RAISE EXCEPTION
            'Invoice % is for %, and % is already received. A further % would over-pay it.',
            inv.invoice_number, to_char(inv.total_amount, 'FM999,999,999.00'),
            to_char(already, 'FM999,999,999.00'), to_char(NEW.amount, 'FM999,999,999.00');
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_invoice_payment_within_total ON invoice_payments;
CREATE TRIGGER trg_invoice_payment_within_total
    BEFORE INSERT OR UPDATE ON invoice_payments
    FOR EACH ROW EXECUTE FUNCTION invoice_payment_within_total();

-- ─── amount_paid and status follow the payments ─────────────
--  The invoice's paid figure is no longer something anyone types.
--  It is the sum of its receipts, and the status follows from it.
--  Draft and cancelled invoices are left alone: they are not in
--  the payment lifecycle.

CREATE OR REPLACE FUNCTION invoice_sync_payment_state()
RETURNS TRIGGER AS $$
DECLARE
    inv_id  INTEGER := COALESCE(NEW.invoice_id, OLD.invoice_id);
    paid    NUMERIC(14,2);
    total   NUMERIC(14,2);
    current_status TEXT;
BEGIN
    SELECT COALESCE(SUM(amount), 0) INTO paid
      FROM invoice_payments WHERE invoice_id = inv_id;

    SELECT total_amount, status INTO total, current_status
      FROM invoices WHERE invoice_id = inv_id;

    UPDATE invoices
       SET amount_paid = paid,
           status = CASE
               WHEN current_status IN ('draft', 'cancelled') THEN current_status
               WHEN paid >= total - 0.005 AND total > 0        THEN 'paid'
               WHEN paid > 0                                   THEN 'partially_paid'
               -- Every payment reversed: back to owing, unless it
               -- was already past due.
               WHEN current_status = 'overdue'                 THEN 'overdue'
               ELSE 'issued'
           END,
           updated_at = NOW()
     WHERE invoice_id = inv_id;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_invoice_sync_payment_state ON invoice_payments;
CREATE TRIGGER trg_invoice_sync_payment_state
    AFTER INSERT OR UPDATE OR DELETE ON invoice_payments
    FOR EACH ROW EXECUTE FUNCTION invoice_sync_payment_state();

-- ─── Staff loans ────────────────────────────────────────────
--  A loan is money the company is owed by an employee. It is
--  requested, approved and only then disbursed — the money moves
--  on disbursement, not on approval, which is why those are two
--  separate dates.

CREATE TABLE IF NOT EXISTS employee_loans (
    loan_id        INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    loan_number    VARCHAR(50) UNIQUE NOT NULL,
    user_id        INTEGER NOT NULL REFERENCES users(user_id),
    principal      NUMERIC(14,2) NOT NULL CHECK (principal > 0),
    purpose        TEXT,
    requested_on   DATE NOT NULL DEFAULT CURRENT_DATE,
    status         VARCHAR(15) NOT NULL DEFAULT 'requested'
        CHECK (status IN ('requested', 'approved', 'rejected', 'disbursed', 'settled', 'cancelled')),
    approved_by    INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    approved_on    DATE,
    decision_note  TEXT,
    disbursed_on   DATE,
    -- Which account the money left, and the receivable account
    -- that now carries the balance.
    paid_from_account_id INTEGER REFERENCES cash_accounts(cash_account_id),
    loan_account_id      INTEGER REFERENCES cash_accounts(cash_account_id),
    repayment_terms TEXT,
    settled_on      DATE,
    created_by      INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_employee_loans_user   ON employee_loans (user_id);
CREATE INDEX IF NOT EXISTS ix_employee_loans_status ON employee_loans (status);

CREATE TABLE IF NOT EXISTS employee_loan_repayments (
    repayment_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    loan_id         INTEGER NOT NULL REFERENCES employee_loans(loan_id) ON DELETE CASCADE,
    paid_on         DATE NOT NULL DEFAULT CURRENT_DATE,
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    -- Where the repayment landed. NULL when it was deducted from
    -- salary rather than paid in.
    into_account_id INTEGER REFERENCES cash_accounts(cash_account_id),
    method          VARCHAR(20) NOT NULL DEFAULT 'cash'
        CHECK (method IN ('cash', 'bank_transfer', 'mobile_money', 'cheque', 'salary_deduction', 'other')),
    reference       VARCHAR(160),
    notes           TEXT,
    recorded_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_loan_repayments_loan ON employee_loan_repayments (loan_id);

-- A loan cannot be over-repaid, and only a disbursed loan can be
-- repaid at all.
CREATE OR REPLACE FUNCTION loan_repayment_within_principal()
RETURNS TRIGGER AS $$
DECLARE
    ln      RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT loan_number, principal, status INTO ln
      FROM employee_loans WHERE loan_id = NEW.loan_id;

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
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_loan_repayment_within_principal ON employee_loan_repayments;
CREATE TRIGGER trg_loan_repayment_within_principal
    BEFORE INSERT OR UPDATE ON employee_loan_repayments
    FOR EACH ROW EXECUTE FUNCTION loan_repayment_within_principal();

-- A fully repaid loan settles itself.
CREATE OR REPLACE FUNCTION loan_settle_when_repaid()
RETURNS TRIGGER AS $$
DECLARE
    ln_id INTEGER := COALESCE(NEW.loan_id, OLD.loan_id);
    paid  NUMERIC(14,2);
    ln    RECORD;
BEGIN
    SELECT COALESCE(SUM(amount), 0) INTO paid
      FROM employee_loan_repayments WHERE loan_id = ln_id;
    SELECT principal, status INTO ln FROM employee_loans WHERE loan_id = ln_id;

    IF ln.status IN ('disbursed', 'settled') THEN
        UPDATE employee_loans
           SET status      = CASE WHEN paid >= ln.principal - 0.005 THEN 'settled' ELSE 'disbursed' END,
               settled_on  = CASE WHEN paid >= ln.principal - 0.005 THEN COALESCE(settled_on, CURRENT_DATE) ELSE NULL END,
               updated_at  = NOW()
         WHERE loan_id = ln_id;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_loan_settle_when_repaid ON employee_loan_repayments;
CREATE TRIGGER trg_loan_settle_when_repaid
    AFTER INSERT OR UPDATE OR DELETE ON employee_loan_repayments
    FOR EACH ROW EXECUTE FUNCTION loan_settle_when_repaid();

-- ─── Customer advances ──────────────────────────────────────
--  Money taken before the invoice exists. It is not income and it
--  is not a sale: the business owes goods or services for it, so
--  it is a liability until an invoice draws it down.

CREATE TABLE IF NOT EXISTS customer_advances (
    advance_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    advance_number  VARCHAR(50) UNIQUE NOT NULL,
    customer_id     INTEGER NOT NULL,
    received_on     DATE NOT NULL DEFAULT CURRENT_DATE,
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    method          VARCHAR(20) NOT NULL DEFAULT 'cash'
        CHECK (method IN ('cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other')),
    reference       VARCHAR(160),
    cash_account_id INTEGER NOT NULL REFERENCES cash_accounts(cash_account_id),
    proof_url       TEXT,
    proof_filename  VARCHAR(255),
    notes           TEXT,
    status          VARCHAR(10) NOT NULL DEFAULT 'open'
        CHECK (status IN ('open', 'applied', 'refunded')),
    recorded_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'customers')
       AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_name = 'fk_customer_advances_customer')
    THEN
        ALTER TABLE customer_advances
            ADD CONSTRAINT fk_customer_advances_customer
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_customer_advances_customer ON customer_advances (customer_id);
CREATE INDEX IF NOT EXISTS ix_customer_advances_status   ON customer_advances (status);

-- The link back to invoice_payments, now that both tables exist.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name = 'fk_invoice_payment_advance') THEN
        ALTER TABLE invoice_payments
            ADD CONSTRAINT fk_invoice_payment_advance
            FOREIGN KEY (from_advance_id) REFERENCES customer_advances(advance_id);
    END IF;
END $$;

-- An advance cannot be drawn down past what was received.
CREATE OR REPLACE FUNCTION advance_draw_within_balance()
RETURNS TRIGGER AS $$
DECLARE
    adv     RECORD;
    already NUMERIC(14,2);
BEGIN
    IF NEW.from_advance_id IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT advance_number, amount, customer_id, status INTO adv
      FROM customer_advances WHERE advance_id = NEW.from_advance_id;

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
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_advance_draw_within_balance ON invoice_payments;
CREATE TRIGGER trg_advance_draw_within_balance
    BEFORE INSERT OR UPDATE ON invoice_payments
    FOR EACH ROW EXECUTE FUNCTION advance_draw_within_balance();

-- An advance drawn down to nothing is closed.
CREATE OR REPLACE FUNCTION advance_sync_status()
RETURNS TRIGGER AS $$
DECLARE
    adv_id INTEGER := COALESCE(NEW.from_advance_id, OLD.from_advance_id);
    used   NUMERIC(14,2);
    total  NUMERIC(14,2);
BEGIN
    IF adv_id IS NULL THEN
        RETURN NULL;
    END IF;

    SELECT COALESCE(SUM(amount), 0) INTO used
      FROM invoice_payments WHERE from_advance_id = adv_id;
    SELECT amount INTO total FROM customer_advances WHERE advance_id = adv_id;

    UPDATE customer_advances
       SET status = CASE WHEN status = 'refunded' THEN 'refunded'
                         WHEN used >= total - 0.005 THEN 'applied'
                         ELSE 'open' END,
           updated_at = NOW()
     WHERE advance_id = adv_id;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_advance_sync_status ON invoice_payments;
CREATE TRIGGER trg_advance_sync_status
    AFTER INSERT OR UPDATE OR DELETE ON invoice_payments
    FOR EACH ROW EXECUTE FUNCTION advance_sync_status();

-- ─── Investor capital ───────────────────────────────────────
--  Equity is bought into the business and never repaid; an
--  investor loan is borrowed and is a liability until it is. The
--  distinction changes which side of the balance sheet it lands
--  on, so it is recorded rather than assumed.

CREATE TABLE IF NOT EXISTS investments (
    investment_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reference_no    VARCHAR(50) UNIQUE NOT NULL,
    investor_name   VARCHAR(160) NOT NULL,
    investment_type VARCHAR(10) NOT NULL DEFAULT 'equity'
        CHECK (investment_type IN ('equity', 'loan')),
    received_on     DATE NOT NULL DEFAULT CURRENT_DATE,
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    cash_account_id INTEGER NOT NULL REFERENCES cash_accounts(cash_account_id),
    method          VARCHAR(20) NOT NULL DEFAULT 'bank_transfer'
        CHECK (method IN ('cash', 'bank_transfer', 'mobile_money', 'cheque', 'other')),
    reference       VARCHAR(160),
    -- Equity: the share of the business bought, if agreed.
    equity_percent  NUMERIC(6,3) CHECK (equity_percent IS NULL OR (equity_percent >= 0 AND equity_percent <= 100)),
    -- Loan: what it costs and when it is due.
    interest_rate   NUMERIC(6,3) CHECK (interest_rate IS NULL OR interest_rate >= 0),
    due_date        DATE,
    agreement_url   TEXT,
    agreement_filename VARCHAR(255),
    notes           TEXT,
    status          VARCHAR(10) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'repaid')),
    recorded_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    -- Equity is not repayable, so it never carries loan terms.
    CONSTRAINT ck_investment_terms CHECK (
        investment_type = 'loan' OR (interest_rate IS NULL AND due_date IS NULL)
    )
);

CREATE INDEX IF NOT EXISTS ix_investments_type   ON investments (investment_type);
CREATE INDEX IF NOT EXISTS ix_investments_status ON investments (status);

CREATE TABLE IF NOT EXISTS investment_repayments (
    repayment_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    investment_id   INTEGER NOT NULL REFERENCES investments(investment_id) ON DELETE CASCADE,
    paid_on         DATE NOT NULL DEFAULT CURRENT_DATE,
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    from_account_id INTEGER NOT NULL REFERENCES cash_accounts(cash_account_id),
    reference       VARCHAR(160),
    notes           TEXT,
    recorded_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_investment_repayments ON investment_repayments (investment_id);

-- Only an investor loan is repayable, and only up to what it was.
CREATE OR REPLACE FUNCTION investment_repayment_valid()
RETURNS TRIGGER AS $$
DECLARE
    inv     RECORD;
    already NUMERIC(14,2);
BEGIN
    SELECT reference_no, amount, investment_type INTO inv
      FROM investments WHERE investment_id = NEW.investment_id;

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
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_investment_repayment_valid ON investment_repayments;
CREATE TRIGGER trg_investment_repayment_valid
    BEFORE INSERT OR UPDATE ON investment_repayments
    FOR EACH ROW EXECUTE FUNCTION investment_repayment_valid();

CREATE OR REPLACE FUNCTION investment_sync_status()
RETURNS TRIGGER AS $$
DECLARE
    inv_id INTEGER := COALESCE(NEW.investment_id, OLD.investment_id);
    paid   NUMERIC(14,2);
    total  NUMERIC(14,2);
BEGIN
    SELECT COALESCE(SUM(amount), 0) INTO paid
      FROM investment_repayments WHERE investment_id = inv_id;
    SELECT amount INTO total FROM investments WHERE investment_id = inv_id;

    UPDATE investments
       SET status = CASE WHEN paid >= total - 0.005 THEN 'repaid' ELSE 'active' END,
           updated_at = NOW()
     WHERE investment_id = inv_id;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_investment_sync_status ON investment_repayments;
CREATE TRIGGER trg_investment_sync_status
    AFTER INSERT OR UPDATE OR DELETE ON investment_repayments
    FOR EACH ROW EXECUTE FUNCTION investment_sync_status();

-- ─── Placeholder banking accounts ───────────────────────────
--  Named so they are obviously placeholders. Rename them to the
--  real bank and account when those are known — the name is a
--  label, and renaming it changes no history.

INSERT INTO cash_accounts (name, account_type, sort_order, institution, notes)
SELECT v.name, v.t, v.ord, v.inst,
       'Placeholder created by migration 016 — rename to the real account.'
  FROM (VALUES
        ('Main Current Account',      'bank',         10, 'Bank A'),
        ('Secondary Current Account', 'bank',         11, 'Bank B'),
        ('Savings Account',           'bank',         12, 'Bank A'),
        ('Mobile Money Wallet',       'mobile_money', 20, 'Mobile money'),
        ('Petty Cash',                'cash',         30, NULL)
       ) AS v(name, t, ord, inst)
 WHERE NOT EXISTS (SELECT 1 FROM cash_accounts a WHERE LOWER(a.name) = LOWER(v.name));

-- ─── Backfill: existing paid invoices ───────────────────────
--  Invoices already carrying an amount_paid have no payment rows
--  behind them. One opening receipt per invoice keeps the ledger
--  and the invoice agreeing from day one, and marks it plainly as
--  migrated rather than inventing a payment method.

INSERT INTO invoice_payments (invoice_id, payment_date, amount, method, reference,
                              cash_account_id, notes)
SELECT i.invoice_id,
       COALESCE(i.issue_date, CURRENT_DATE),
       i.amount_paid,
       'other',
       'Opening balance',
       (SELECT cash_account_id FROM cash_accounts
         WHERE account_type IN ('cash', 'bank', 'mobile_money')
         ORDER BY sort_order, cash_account_id LIMIT 1),
       'Recorded by migration 016 from the invoice''s existing paid amount. No proof of payment is attached.'
  FROM invoices i
 WHERE i.amount_paid > 0
   AND i.status <> 'cancelled'
   AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.invoice_id);
