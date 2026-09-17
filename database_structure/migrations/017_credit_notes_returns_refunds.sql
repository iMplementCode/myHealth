-- ============================================================
--  017 — Credit notes, goods returns and customer refunds
-- ------------------------------------------------------------
--  Undoing a sale after the goods have gone out. Three documents,
--  because three different things happen and they do not happen
--  at the same moment:
--
--    credit_notes       — cancels part or all of an invoice. This
--                         is the money side: it reduces what the
--                         customer owes, and where they had
--                         already paid it turns into a refund the
--                         business owes them.
--    goods_return_notes — the goods actually coming back. This is
--                         the stock side, and only what is
--                         physically received is put back.
--    customer_refunds   — paying the customer their money back.
--
--  The arithmetic that ties them together, per invoice:
--
--    balance    = total − paid − credited + refunded
--    receivable = max(balance, 0)     ← still owed to us
--    refund due = max(−balance, 0)    ← we owe the customer
--
--  One formula covers every case: unpaid and credited in full
--  (nothing owed either way), paid and credited in full (a refund
--  due), part-paid and credited, and partial credits.
--
--  Idempotent: safe to re-run.
-- ============================================================

-- ─── Credit notes ───────────────────────────────────────────

CREATE TABLE IF NOT EXISTS credit_notes (
    credit_note_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cn_number       VARCHAR(100) UNIQUE NOT NULL,
    invoice_id      INTEGER NOT NULL REFERENCES invoices(invoice_id),
    customer_id     INTEGER NOT NULL,
    dn_id           INTEGER REFERENCES delivery_notes(dn_id),
    issue_date      DATE NOT NULL DEFAULT CURRENT_DATE,
    reason          TEXT,
    -- What the business expects back. 'none' is legitimate: a
    -- price correction credits money without any goods moving.
    return_scope    VARCHAR(10) NOT NULL DEFAULT 'full'
        CHECK (return_scope IN ('full', 'partial', 'none')),
    subtotal        NUMERIC(14,2) NOT NULL DEFAULT 0,
    tax_amount      NUMERIC(14,2) NOT NULL DEFAULT 0,
    total_amount    NUMERIC(14,2) NOT NULL DEFAULT 0,
    status          VARCHAR(15) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'approved', 'cancelled')),
    approved_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    approved_at     TIMESTAMPTZ,
    notes           TEXT,
    created_by      INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name = 'fk_credit_notes_customer') THEN
        ALTER TABLE credit_notes
            ADD CONSTRAINT fk_credit_notes_customer
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_credit_notes_invoice  ON credit_notes (invoice_id);
CREATE INDEX IF NOT EXISTS ix_credit_notes_customer ON credit_notes (customer_id);
CREATE INDEX IF NOT EXISTS ix_credit_notes_status   ON credit_notes (status);

CREATE TABLE IF NOT EXISTS credit_note_items (
    cn_item_id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    credit_note_id INTEGER NOT NULL REFERENCES credit_notes(credit_note_id) ON DELETE CASCADE,
    product_id     INTEGER NOT NULL REFERENCES products(product_id),
    quantity       NUMERIC(12,3) NOT NULL CHECK (quantity > 0),
    unit_price     NUMERIC(14,2) NOT NULL CHECK (unit_price >= 0),
    discount       NUMERIC(14,2) NOT NULL DEFAULT 0,
    -- The cost the goods were carried at, so putting them back
    -- values inventory at cost rather than at what they sold for.
    unit_cost      NUMERIC(14,2) NOT NULL DEFAULT 0,
    subtotal       NUMERIC(14,2) GENERATED ALWAYS AS (quantity * unit_price - discount) STORED,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ux_credit_note_product UNIQUE (credit_note_id, product_id)
);

CREATE INDEX IF NOT EXISTS ix_cn_items_note ON credit_note_items (credit_note_id);

-- A credit note may not exceed what the invoice was for. Crediting
-- more than was sold would manufacture a refund out of nothing.
CREATE OR REPLACE FUNCTION credit_note_within_invoice()
RETURNS TRIGGER AS $$
DECLARE
    cn        RECORD;
    inv       RECORD;
    already   NUMERIC(14,2);
BEGIN
    IF NEW.status <> 'approved' OR (TG_OP = 'UPDATE' AND OLD.status = 'approved') THEN
        RETURN NEW;
    END IF;

    SELECT invoice_number, total_amount INTO inv
      FROM invoices WHERE invoice_id = NEW.invoice_id;

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
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_credit_note_within_invoice ON credit_notes;
CREATE TRIGGER trg_credit_note_within_invoice
    BEFORE INSERT OR UPDATE ON credit_notes
    FOR EACH ROW EXECUTE FUNCTION credit_note_within_invoice();

-- An approved credit note is a financial fact; its lines are then
-- history and cannot be edited underneath it.
CREATE OR REPLACE FUNCTION credit_note_items_guard()
RETURNS TRIGGER AS $$
DECLARE
    st  TEXT;
    num TEXT;
BEGIN
    SELECT status, cn_number INTO st, num
      FROM credit_notes
     WHERE credit_note_id = COALESCE(NEW.credit_note_id, OLD.credit_note_id);

    IF st IS NOT NULL AND st <> 'draft' THEN
        RAISE EXCEPTION 'Credit note % is % and its lines can no longer be changed.', num, st;
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_credit_note_items_guard ON credit_note_items;
CREATE TRIGGER trg_credit_note_items_guard
    BEFORE INSERT OR UPDATE OR DELETE ON credit_note_items
    FOR EACH ROW EXECUTE FUNCTION credit_note_items_guard();

-- ─── Goods return notes ─────────────────────────────────────
--  What the customer actually sends back. Expected quantities come
--  from the credit note; received quantities are counted at the
--  door, and the two need not agree — that difference is the whole
--  point of the document.

CREATE TABLE IF NOT EXISTS goods_return_notes (
    grn_return_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    return_number  VARCHAR(100) UNIQUE NOT NULL,
    credit_note_id INTEGER NOT NULL REFERENCES credit_notes(credit_note_id),
    invoice_id     INTEGER REFERENCES invoices(invoice_id),
    customer_id    INTEGER NOT NULL,
    warehouse_id   INTEGER REFERENCES warehouses(warehouse_id),
    return_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    status         VARCHAR(15) NOT NULL DEFAULT 'awaiting'
        CHECK (status IN ('awaiting', 'received', 'cancelled')),
    -- Set when what came back did not match what was expected, so
    -- the shortfall can be billed rather than quietly written off.
    has_shortfall  BOOLEAN NOT NULL DEFAULT FALSE,
    shortfall_invoice_id INTEGER REFERENCES invoices(invoice_id),
    received_by    INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    received_at    TIMESTAMPTZ,
    notes          TEXT,
    created_by     INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name = 'fk_goods_returns_customer') THEN
        ALTER TABLE goods_return_notes
            ADD CONSTRAINT fk_goods_returns_customer
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_goods_returns_cn       ON goods_return_notes (credit_note_id);
CREATE INDEX IF NOT EXISTS ix_goods_returns_status   ON goods_return_notes (status);
CREATE INDEX IF NOT EXISTS ix_goods_returns_customer ON goods_return_notes (customer_id);

CREATE TABLE IF NOT EXISTS goods_return_items (
    return_item_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    grn_return_id     INTEGER NOT NULL REFERENCES goods_return_notes(grn_return_id) ON DELETE CASCADE,
    product_id        INTEGER NOT NULL REFERENCES products(product_id),
    quantity_expected NUMERIC(12,3) NOT NULL CHECK (quantity_expected > 0),
    quantity_received NUMERIC(12,3) NOT NULL DEFAULT 0 CHECK (quantity_received >= 0),
    unit_cost         NUMERIC(14,2) NOT NULL DEFAULT 0,
    unit_price        NUMERIC(14,2) NOT NULL DEFAULT 0,
    -- Goods can come back damaged. Only resaleable stock is worth
    -- putting back on the shelf.
    condition         VARCHAR(15) NOT NULL DEFAULT 'good'
        CHECK (condition IN ('good', 'damaged')),
    notes             VARCHAR(255),
    created_at        TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ux_return_product UNIQUE (grn_return_id, product_id),
    CONSTRAINT ck_return_not_over CHECK (quantity_received <= quantity_expected)
);

CREATE INDEX IF NOT EXISTS ix_return_items_note ON goods_return_items (grn_return_id);

-- A received return is history; its counts cannot be edited after.
CREATE OR REPLACE FUNCTION goods_return_items_guard()
RETURNS TRIGGER AS $$
DECLARE
    st  TEXT;
    num TEXT;
BEGIN
    SELECT status, return_number INTO st, num
      FROM goods_return_notes
     WHERE grn_return_id = COALESCE(NEW.grn_return_id, OLD.grn_return_id);

    IF st = 'received' AND TG_OP <> 'UPDATE' THEN
        RAISE EXCEPTION 'Return note % has been received and its lines can no longer be changed.', num;
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_goods_return_items_guard ON goods_return_items;
CREATE TRIGGER trg_goods_return_items_guard
    BEFORE INSERT OR DELETE ON goods_return_items
    FOR EACH ROW EXECUTE FUNCTION goods_return_items_guard();

-- ─── Customer refunds ───────────────────────────────────────
--  Money going back to a customer who had already paid for goods
--  they have now returned. Until it is paid it is a liability.

CREATE TABLE IF NOT EXISTS customer_refunds (
    refund_id      INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    refund_number  VARCHAR(100) UNIQUE NOT NULL,
    credit_note_id INTEGER NOT NULL REFERENCES credit_notes(credit_note_id),
    invoice_id     INTEGER NOT NULL REFERENCES invoices(invoice_id),
    customer_id    INTEGER NOT NULL,
    refund_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    amount         NUMERIC(14,2) NOT NULL CHECK (amount > 0),
    method         VARCHAR(20) NOT NULL DEFAULT 'bank_transfer'
        CHECK (method IN ('cash', 'bank_transfer', 'mobile_money', 'cheque', 'card', 'other')),
    cash_account_id INTEGER NOT NULL REFERENCES cash_accounts(cash_account_id),
    reference      VARCHAR(160),
    proof_url      TEXT,
    proof_filename VARCHAR(255),
    notes          TEXT,
    paid_by        INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name = 'fk_customer_refunds_customer') THEN
        ALTER TABLE customer_refunds
            ADD CONSTRAINT fk_customer_refunds_customer
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_customer_refunds_cn      ON customer_refunds (credit_note_id);
CREATE INDEX IF NOT EXISTS ix_customer_refunds_invoice ON customer_refunds (invoice_id);

-- A refund may not exceed what the invoice actually owes back.
-- The formula is the one the whole module runs on:
--   balance = total − paid − credited + refunded
CREATE OR REPLACE FUNCTION customer_refund_within_due()
RETURNS TRIGGER AS $$
DECLARE
    inv       RECORD;
    credited  NUMERIC(14,2);
    refunded  NUMERIC(14,2);
    due       NUMERIC(14,2);
BEGIN
    SELECT invoice_number, total_amount, amount_paid INTO inv
      FROM invoices WHERE invoice_id = NEW.invoice_id;

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
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_customer_refund_within_due ON customer_refunds;
CREATE TRIGGER trg_customer_refund_within_due
    BEFORE INSERT OR UPDATE ON customer_refunds
    FOR EACH ROW EXECUTE FUNCTION customer_refund_within_due();

-- ─── Ledger wiring ──────────────────────────────────────────

ALTER TABLE cash_transactions DROP CONSTRAINT IF EXISTS cash_transactions_source_type_check;
ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_source_type_check
    CHECK (source_type IS NULL OR source_type IN (
        'invoice', 'expense', 'grn', 'purchase_order',
        'invoice_payment', 'employee_loan', 'employee_loan_repayment',
        'customer_advance', 'investment', 'investment_repayment',
        'customer_refund'
    ));

-- 'return' is already an accepted stock movement, and
-- 'goods_return_notes' is longer than the existing reference
-- column allowed for.
ALTER TABLE inventory_transactions ALTER COLUMN reference_table TYPE VARCHAR(60);

-- ─── Invoices know they were credited ───────────────────────
--  A cancelled invoice whose goods went out is not simply void:
--  it is waiting for a credit note. Recording that on the invoice
--  is what lets the dashboard show the queue.

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS credit_status VARCHAR(20) NOT NULL DEFAULT 'none';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name = 'invoices_credit_status_check') THEN
        ALTER TABLE invoices ADD CONSTRAINT invoices_credit_status_check
            CHECK (credit_status IN ('none', 'awaiting_credit', 'credited', 'part_credited'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_invoices_credit_status ON invoices (credit_status);

-- An invoice raised to bill goods a customer kept rather than
-- returned points back at the return note that revealed it.
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS from_return_id INTEGER;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints
                    WHERE constraint_name = 'fk_invoices_from_return') THEN
        ALTER TABLE invoices
            ADD CONSTRAINT fk_invoices_from_return
            FOREIGN KEY (from_return_id) REFERENCES goods_return_notes(grn_return_id);
    END IF;
END $$;

-- Keep credit_status in step with the credit notes themselves, so
-- no page has to remember to set it.
CREATE OR REPLACE FUNCTION invoice_sync_credit_status()
RETURNS TRIGGER AS $$
DECLARE
    inv_id   INTEGER := COALESCE(NEW.invoice_id, OLD.invoice_id);
    credited NUMERIC(14,2);
    total    NUMERIC(14,2);
    current  TEXT;
BEGIN
    SELECT COALESCE(SUM(total_amount), 0) INTO credited
      FROM credit_notes WHERE invoice_id = inv_id AND status = 'approved';

    SELECT total_amount, credit_status INTO total, current
      FROM invoices WHERE invoice_id = inv_id;

    UPDATE invoices
       SET credit_status = CASE
               WHEN credited >= total - 0.005 AND total > 0 THEN 'credited'
               WHEN credited > 0                            THEN 'part_credited'
               -- Nothing approved yet: keep an awaiting flag if the
               -- invoice was cancelled with goods already delivered.
               WHEN current = 'awaiting_credit'              THEN 'awaiting_credit'
               ELSE 'none'
           END,
           updated_at = NOW()
     WHERE invoice_id = inv_id;

    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_invoice_sync_credit_status ON credit_notes;
CREATE TRIGGER trg_invoice_sync_credit_status
    AFTER INSERT OR UPDATE OR DELETE ON credit_notes
    FOR EACH ROW EXECUTE FUNCTION invoice_sync_credit_status();
