-- ============================================================
--  015 — Cash book and daily position
-- ------------------------------------------------------------
--  Reproduces the spreadsheet the business has been keeping by
--  hand, as data rather than as formulas:
--
--    * cash_accounts     — where money and near-money sits:
--                          tills/floats held by staff (cash),
--                          loans made to employees (loan), and
--                          goods released on credit (credit).
--    * cash_transactions — the cash book itself: one row per
--                          movement, IN or OUT, against one
--                          account. Running balances are never
--                          stored; they are derived, so they
--                          cannot drift.
--    * daily_positions   — the once-a-day snapshot of the things
--                          the cash book cannot know: stock
--                          value, creditors, debtors.
--
--  The report arithmetic, which the sheet did by hand:
--
--    cash at hand      = Σ balances of accounts of type 'cash'
--    operating revenue = Σ balances of ALL accounts
--                        (cash + loans out + credit given)
--    company valuation = stock value + operating revenue
--
--  Idempotent: safe to run on a database that already has some
--  or all of these objects.
-- ============================================================

-- ─── Accounts ───────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS cash_accounts (
    cash_account_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            VARCHAR(120) NOT NULL UNIQUE,
    account_type    VARCHAR(10)  NOT NULL DEFAULT 'cash'
        CHECK (account_type IN ('cash', 'loan', 'credit')),
    -- A float or a loan usually belongs to a member of staff;
    -- credit usually belongs to a customer. Both optional so an
    -- account can simply be a named box of money.
    holder_user_id  INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    customer_id     INTEGER,
    opening_balance NUMERIC(14,2) NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order      INTEGER NOT NULL DEFAULT 0,
    notes           TEXT,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- customers may not exist on very old databases; add the FK only
-- when it can be satisfied.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'customers')
       AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_name = 'fk_cash_accounts_customer')
    THEN
        ALTER TABLE cash_accounts
            ADD CONSTRAINT fk_cash_accounts_customer
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_cash_accounts_type   ON cash_accounts (account_type);
CREATE INDEX IF NOT EXISTS ix_cash_accounts_active ON cash_accounts (is_active);

-- ─── The cash book ──────────────────────────────────────────

CREATE SEQUENCE IF NOT EXISTS cash_transfer_group_seq;

CREATE TABLE IF NOT EXISTS cash_transactions (
    cash_txn_id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    txn_date        DATE NOT NULL DEFAULT CURRENT_DATE,
    cash_account_id INTEGER NOT NULL REFERENCES cash_accounts(cash_account_id),
    direction       VARCHAR(3) NOT NULL CHECK (direction IN ('in', 'out')),
    amount          NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    category        VARCHAR(20) NOT NULL DEFAULT 'expense'
        CHECK (category IN (
            'sale',          -- cash taken for a sale
            'credit_sale',   -- goods released on credit: no cash moved
            'other_income',  -- anything else coming in
            'capital',       -- owner putting money into the business
            'purchase',      -- stock bought
            'expense',       -- running cost: lunch, transport, rent…
            'drawing',       -- owner taking money out
            'transfer',      -- movement between two accounts
            'adjustment'     -- correction to a counted balance
        )),
    -- The name that appears in the "who" column of the sheet.
    party           VARCHAR(160),
    description     TEXT,
    customer_id     INTEGER,
    supplier_id     INTEGER,
    -- Where this row came from. A row posted out of an existing
    -- ERP document carries its type and id so it can never be
    -- posted twice (see the unique index below).
    source_type     VARCHAR(20)
        CHECK (source_type IS NULL OR source_type IN ('invoice', 'expense', 'grn', 'purchase_order')),
    source_id       INTEGER,
    -- The two legs of a transfer share one group number.
    transfer_group  BIGINT,
    created_by      INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'customers')
       AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_name = 'fk_cash_txn_customer')
    THEN
        ALTER TABLE cash_transactions
            ADD CONSTRAINT fk_cash_txn_customer
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE SET NULL;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'public' AND table_name = 'suppliers')
       AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                        WHERE constraint_name = 'fk_cash_txn_supplier')
    THEN
        ALTER TABLE cash_transactions
            ADD CONSTRAINT fk_cash_txn_supplier
            FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_cash_txn_date     ON cash_transactions (txn_date);
CREATE INDEX IF NOT EXISTS ix_cash_txn_account  ON cash_transactions (cash_account_id);
CREATE INDEX IF NOT EXISTS ix_cash_txn_category ON cash_transactions (category);
CREATE INDEX IF NOT EXISTS ix_cash_txn_group    ON cash_transactions (transfer_group);

-- One ERP document posts to the cash book exactly once. Recording
-- the same invoice twice was the easiest way to corrupt the old
-- spreadsheet; here the database refuses it.
CREATE UNIQUE INDEX IF NOT EXISTS ux_cash_txn_source
    ON cash_transactions (source_type, source_id)
    WHERE source_type IS NOT NULL;

-- ─── Rule: credit sales belong on credit accounts ───────────
--  Releasing goods on credit moves no cash. Booking it against a
--  till would wrongly reduce cash at hand, so the pairing is
--  enforced rather than left to the form.

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

    IF NEW.category IN ('sale', 'purchase', 'expense') AND acct_type <> 'cash' THEN
        RAISE EXCEPTION
            '% must be recorded against a cash account, not %.', NEW.category, acct_name;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_cash_txn_account_type ON cash_transactions;
CREATE TRIGGER trg_cash_txn_account_type
    BEFORE INSERT OR UPDATE ON cash_transactions
    FOR EACH ROW EXECUTE FUNCTION cash_txn_check_account_type();

-- ─── Daily snapshot ─────────────────────────────────────────
--  Cash figures are derived from the book above. These three are
--  not knowable from it, so they are captured once a day.

CREATE TABLE IF NOT EXISTS daily_positions (
    position_date DATE PRIMARY KEY,
    stock_value   NUMERIC(14,2) NOT NULL DEFAULT 0,
    creditors     NUMERIC(14,2) NOT NULL DEFAULT 0,
    debtors       NUMERIC(14,2) NOT NULL DEFAULT 0,
    notes         TEXT,
    closed_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    closed_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- ─── A starting account ─────────────────────────────────────
--  So the page is usable immediately. Real tills, staff loans and
--  customer credit lines are added from Finance → Cash Accounts.

INSERT INTO cash_accounts (name, account_type, sort_order, notes)
SELECT 'Main Till', 'cash', 0, 'Default cash account created by migration 015. Rename or deactivate it once real accounts are set up.'
WHERE NOT EXISTS (SELECT 1 FROM cash_accounts);
