-- ============================================================
--  Migration 066 — A chart of accounts, and books that balance
-- ------------------------------------------------------------
--  Everything financial in this application is single entry. A
--  sale writes an invoice, a payment writes a cash transaction,
--  an expense writes an expense, and the profit and loss adds
--  those three up when somebody asks. It works, it is tested,
--  and it is what the shop has been running on.
--
--  What it cannot do is prove itself. There is no trial balance,
--  so nothing ever has to reconcile; no account an auditor can
--  trace a figure through; no way to record anything that is not
--  one of the four documents the application already knows —
--  depreciation, an accrual, an owner's drawing, an opening
--  balance on the day the shop started using this.
--
--  So: a real chart of accounts, and a journal of balanced
--  entries behind it.
--
--  The decision that matters, and the reason this is safe to add
--  to a working system: the journal does not become a second
--  source of truth. Documents stay authoritative. Entries carry
--  the document they came from, and a partial unique index means
--  one document can post exactly once however many times the
--  posting routine runs. The P&L keeps reading documents. The
--  trial balance reads the journal. When those two disagree,
--  something is wrong and the screen says so, rather than the
--  shop quietly owning two sets of books.
--
--  Manual entries — the ones no document will ever produce —
--  carry no source and are typed by somebody who signs for them.
-- ============================================================

BEGIN;

-- ─── The chart of accounts ──────────────────────────────────

CREATE TABLE IF NOT EXISTS accounts (
    account_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    --  The code is what people say out loud and what sorts the
    --  report. Text, not a number: 1110 sorts beside 1120 either
    --  way, but a chart that grows sprouts 1110.1 soon enough and
    --  an integer column cannot hold it.
    code         VARCHAR(20)  UNIQUE NOT NULL,
    name         VARCHAR(160) NOT NULL,

    type         VARCHAR(12)  NOT NULL
                 CHECK (type IN ('asset', 'liability', 'equity', 'income', 'expense')),

    --  Where a figure belongs on the face of the report. Cost of
    --  sales and operating expenses are both expenses to the
    --  ledger and must not be added together on a P&L, because
    --  the difference between them is the gross margin — the one
    --  number a pharmacy actually watches.
    subtype      VARCHAR(24),

    parent_id    INTEGER REFERENCES accounts(account_id) ON DELETE RESTRICT,

    --  A heading, not a place to post. "Current assets" totals
    --  its children and must never itself hold a balance.
    is_header    BOOLEAN NOT NULL DEFAULT FALSE,

    --  Seeded accounts the posting routine names directly. They
    --  can be renamed; deleting one would leave the code posting
    --  into nothing, so they cannot be deleted.
    is_system    BOOLEAN NOT NULL DEFAULT FALSE,

    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    description  TEXT,

    created_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_accounts_type   ON accounts (type, code);
CREATE INDEX IF NOT EXISTS idx_accounts_parent ON accounts (parent_id);

-- ─── The journal ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS journal_entries (
    entry_id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    entry_number VARCHAR(40) UNIQUE NOT NULL,
    entry_date   DATE NOT NULL,
    memo         TEXT,

    --  Where this came from. NULL means somebody typed it.
    source_table VARCHAR(40),
    source_id    INTEGER,

    --  A reversal, and what it reverses. Entries are never
    --  edited or deleted once posted — the correction is another
    --  entry, which is the whole point of a journal.
    reverses_id  INTEGER REFERENCES journal_entries(entry_id) ON DELETE RESTRICT,

    created_by   INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at   TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

--  One document, one entry, however many times posting runs.
--  Partial, so the manual entries (source_table NULL) are not all
--  fighting over a single NULL slot.
CREATE UNIQUE INDEX IF NOT EXISTS idx_journal_source
    ON journal_entries (source_table, source_id)
    WHERE source_table IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_journal_date ON journal_entries (entry_date, entry_id);

CREATE TABLE IF NOT EXISTS journal_lines (
    line_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    entry_id   INTEGER NOT NULL REFERENCES journal_entries(entry_id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(account_id)      ON DELETE RESTRICT,

    debit      NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (debit  >= 0),
    credit     NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (credit >= 0),

    --  One side or the other, never both, never neither. A line
    --  carrying both is somebody's arithmetic, not a posting.
    CONSTRAINT journal_line_one_side CHECK (
        (debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)
    ),

    memo       TEXT,
    line_no    INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_journal_lines_entry   ON journal_lines (entry_id, line_no);
CREATE INDEX IF NOT EXISTS idx_journal_lines_account ON journal_lines (account_id);

-- ─── The invariant, in the database ─────────────────────────
--
--  Debits equal credits. Every accounting system says this and
--  most enforce it in whichever function happens to write the
--  entry, which holds exactly until somebody writes a second one
--  — an import, a fix-up script, a later feature.
--
--  A constraint trigger holds it for every writer there will
--  ever be. DEFERRABLE INITIALLY DEFERRED because the lines go
--  in one at a time: checked after the first line, every entry
--  in history would fail. Checked at COMMIT, the transaction
--  either leaves balanced books or does not happen.

CREATE OR REPLACE FUNCTION journal_entry_must_balance() RETURNS TRIGGER AS $$
DECLARE
    v_entry   INTEGER := COALESCE(NEW.entry_id, OLD.entry_id);
    v_debit   NUMERIC(14,2);
    v_credit  NUMERIC(14,2);
BEGIN
    SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
      INTO v_debit, v_credit
      FROM journal_lines
     WHERE entry_id = v_entry;

    IF v_debit <> v_credit THEN
        RAISE EXCEPTION
            'Journal entry % does not balance: debits %, credits %',
            v_entry, v_debit, v_credit;
    END IF;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS journal_lines_must_balance ON journal_lines;
CREATE CONSTRAINT TRIGGER journal_lines_must_balance
    AFTER INSERT OR UPDATE OR DELETE ON journal_lines
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance();

--  Nothing posts to a heading. Same reasoning as the balance
--  rule: enforced where it cannot be forgotten.
CREATE OR REPLACE FUNCTION journal_line_account_postable() RETURNS TRIGGER AS $$
DECLARE
    v_header BOOLEAN;
    v_name   TEXT;
BEGIN
    SELECT is_header, name INTO v_header, v_name
      FROM accounts WHERE account_id = NEW.account_id;

    IF v_header THEN
        RAISE EXCEPTION
            '% is a heading and totals its children; post to one of those instead',
            v_name;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS journal_lines_postable_account ON journal_lines;
CREATE TRIGGER journal_lines_postable_account
    BEFORE INSERT OR UPDATE ON journal_lines
    FOR EACH ROW EXECUTE FUNCTION journal_line_account_postable();

-- ─── A chart shaped like a pharmacy ─────────────────────────
--
--  Not a generic chart with "Sales" on it. The accounts a
--  pharmacy actually needs are here by name — expired stock
--  written off, PPB and licence fees, input and output VAT kept
--  apart because they are two different obligations — so that
--  the first month's figures mean something without somebody
--  having to design a chart before they can take any money.
--
--  ON CONFLICT DO NOTHING: re-running must not disturb a chart
--  somebody has since edited.

INSERT INTO accounts (code, name, type, subtype, is_header, is_system) VALUES
    ('1000', 'Assets',                        'asset',     NULL,              TRUE,  TRUE),
    ('1100', 'Current assets',                'asset',     'current',         TRUE,  TRUE),
    ('1110', 'Cash in hand',                  'asset',     'cash',            FALSE, TRUE),
    ('1120', 'Bank',                          'asset',     'cash',            FALSE, TRUE),
    ('1130', 'Mobile money',                  'asset',     'cash',            FALSE, TRUE),
    ('1200', 'Accounts receivable',           'asset',     'receivable',      FALSE, TRUE),
    ('1300', 'Inventory — medicines',         'asset',     'inventory',       FALSE, TRUE),
    ('1400', 'Input VAT',                     'asset',     'tax',             FALSE, TRUE),
    ('1500', 'Prepayments and deposits paid', 'asset',     'current',         FALSE, TRUE),
    ('1800', 'Non-current assets',            'asset',     'fixed',           TRUE,  TRUE),
    ('1810', 'Fixed assets at cost',          'asset',     'fixed',           FALSE, TRUE),
    ('1820', 'Accumulated depreciation',      'asset',     'fixed_contra',    FALSE, TRUE),

    ('2000', 'Liabilities',                   'liability', NULL,              TRUE,  TRUE),
    ('2100', 'Accounts payable',              'liability', 'payable',         FALSE, TRUE),
    ('2200', 'Output VAT',                    'liability', 'tax',             FALSE, TRUE),
    ('2300', 'Customer deposits',             'liability', 'payable',         FALSE, TRUE),
    ('2400', 'Accruals',                      'liability', 'payable',         FALSE, TRUE),
    ('2500', 'Loans',                         'liability', 'loan',            FALSE, TRUE),

    ('3000', 'Equity',                        'equity',    NULL,              TRUE,  TRUE),
    ('3100', 'Capital introduced',            'equity',    'capital',         FALSE, TRUE),
    ('3200', 'Retained earnings',             'equity',    'retained',        FALSE, TRUE),
    ('3300', 'Drawings',                      'equity',    'drawings',        FALSE, TRUE),

    ('4000', 'Income',                        'income',    NULL,              TRUE,  TRUE),
    ('4100', 'Medicine sales',                'income',    'trading',         FALSE, TRUE),
    ('4200', 'Services and consultation',     'income',    'trading',         FALSE, TRUE),
    ('4900', 'Other income',                  'income',    'other',           FALSE, TRUE),

    ('5000', 'Cost of sales',                 'expense',   'cost_of_sales',   TRUE,  TRUE),
    ('5100', 'Cost of medicines sold',        'expense',   'cost_of_sales',   FALSE, TRUE),
    ('5200', 'Expired and damaged stock',     'expense',   'cost_of_sales',   FALSE, TRUE),
    ('5300', 'Stock adjustments',             'expense',   'cost_of_sales',   FALSE, TRUE),

    ('6000', 'Operating expenses',            'expense',   'operating',       TRUE,  TRUE),
    ('6100', 'Salaries and wages',            'expense',   'operating',       FALSE, TRUE),
    ('6200', 'Rent',                          'expense',   'operating',       FALSE, TRUE),
    ('6300', 'Electricity and water',         'expense',   'operating',       FALSE, TRUE),
    ('6400', 'Licences and regulatory fees',  'expense',   'operating',       FALSE, TRUE),
    ('6500', 'Transport and delivery',        'expense',   'operating',       FALSE, TRUE),
    ('6600', 'Repairs and maintenance',       'expense',   'operating',       FALSE, TRUE),
    ('6700', 'Bank and transaction charges',  'expense',   'operating',       FALSE, TRUE),
    ('6800', 'Professional fees',             'expense',   'operating',       FALSE, TRUE),
    ('6900', 'Depreciation',                  'expense',   'operating',       FALSE, TRUE),
    ('6950', 'Other expenses',                'expense',   'operating',       FALSE, TRUE)
ON CONFLICT (code) DO NOTHING;

--  Hang each account under its heading by code, so the chart has
--  a shape without the seed having to carry ids it cannot know.
UPDATE accounts c SET parent_id = p.account_id
  FROM accounts p
 WHERE c.parent_id IS NULL
   AND c.is_header = FALSE
   AND p.is_header = TRUE
   AND p.code = CASE
        WHEN c.code LIKE '11%' OR c.code LIKE '12%' OR c.code LIKE '13%'
          OR c.code LIKE '14%' OR c.code LIKE '15%' THEN '1100'
        WHEN c.code LIKE '18%'                      THEN '1800'
        WHEN c.code LIKE '2%'                       THEN '2000'
        WHEN c.code LIKE '3%'                       THEN '3000'
        WHEN c.code LIKE '4%'                       THEN '4000'
        WHEN c.code LIKE '5%'                       THEN '5000'
        WHEN c.code LIKE '6%'                       THEN '6000'
   END;

UPDATE accounts SET parent_id = (SELECT account_id FROM accounts WHERE code = '1000')
 WHERE code IN ('1100', '1800') AND parent_id IS NULL;

COMMIT;
