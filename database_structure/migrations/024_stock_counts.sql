-- ============================================================
--  024 — Stock counts, and the adjustments they produce
-- ------------------------------------------------------------
--  Every other way stock moves is already recorded: goods come
--  in on a GRN, go out on a delivery note, come back on a return,
--  go back to the supplier on a debit note. Each writes to
--  inventory_transactions, so any figure can be traced.
--
--  What was missing is the one movement that has no document
--  behind it: the shelf disagreeing with the books. Nothing could
--  record that, so the only options were to leave the system
--  wrong or to edit the product and leave no trace of who changed
--  what or why. The ledger has had 'adjustment' in its list of
--  movement types since it was written; nothing ever wrote one.
--
--  A count is a document like any other. It is raised, lines are
--  entered against it, and it is posted — and posting is what
--  moves stock, writes the ledger and locks the count. Before
--  that it can be corrected; afterwards it cannot, because it is
--  the evidence for the adjustment.
--
--  ── On measuring the difference ─────────────────────────────
--  A count sheet is written at nine and posted at five, and stock
--  moves in between. Posting therefore applies the *difference*
--  the counter found — counted less what the books said when the
--  line was written — rather than setting the balance to the
--  counted figure. Otherwise every delivery made during the count
--  would be silently undone.
--
--  Idempotent: safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS stock_counts (
    count_id     INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    count_number VARCHAR(100) NOT NULL UNIQUE,
    -- Which store was counted. Stock is one figure per product
    -- today, so this is a record of where the counting happened;
    -- it becomes the thing that scopes the adjustment on the day
    -- stock is held per location.
    warehouse_id INTEGER REFERENCES warehouses(warehouse_id),
    counted_on   DATE NOT NULL DEFAULT CURRENT_DATE,
    status       VARCHAR(20) NOT NULL DEFAULT 'draft'
        CHECK (status IN ('draft', 'posted', 'cancelled')),
    notes        TEXT,
    counted_by   INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    posted_by    INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    posted_at    TIMESTAMPTZ,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS ix_stock_counts_status ON stock_counts (status);
CREATE INDEX IF NOT EXISTS ix_stock_counts_date   ON stock_counts (counted_on);

CREATE TABLE IF NOT EXISTS stock_count_items (
    count_item_id    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    count_id         INTEGER NOT NULL REFERENCES stock_counts(count_id) ON DELETE CASCADE,
    product_id       INTEGER NOT NULL REFERENCES products(product_id),
    -- What the books said when this line was written. Kept so the
    -- difference the counter found survives whatever happens to
    -- the balance afterwards.
    system_quantity  NUMERIC(12,3) NOT NULL DEFAULT 0,
    -- What was on the shelf. NULL until somebody counts it, which
    -- is what tells a half-finished sheet from one that counted
    -- zero.
    counted_quantity NUMERIC(12,3),
    -- The cost the variance is valued at, captured when the line
    -- is written: a price rise next month must not restate what
    -- last month's shrinkage was worth.
    unit_cost        NUMERIC(12,2) NOT NULL DEFAULT 0,
    reason           VARCHAR(20) NOT NULL DEFAULT 'miscount'
        CHECK (reason IN ('miscount', 'shrinkage', 'damage', 'found', 'expiry', 'opening', 'other')),
    notes            TEXT,
    UNIQUE (count_id, product_id)
);

CREATE INDEX IF NOT EXISTS ix_stock_count_items_count   ON stock_count_items (count_id);
CREATE INDEX IF NOT EXISTS ix_stock_count_items_product ON stock_count_items (product_id);

-- ─── Rule: a posted count is evidence, not a working document ──
--  Its lines are what justify an adjustment already made to
--  stock. Changing them afterwards would leave the ledger saying
--  one thing and the count saying another.

CREATE OR REPLACE FUNCTION stock_count_items_locked()
RETURNS TRIGGER AS $$
DECLARE
    st TEXT;
BEGIN
    SELECT status INTO st FROM stock_counts
     WHERE count_id = COALESCE(NEW.count_id, OLD.count_id);

    IF st = 'posted' THEN
        RAISE EXCEPTION
            'This count has been posted. Raise another to correct it.';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_stock_count_items_locked ON stock_count_items;
CREATE TRIGGER trg_stock_count_items_locked
    BEFORE INSERT OR UPDATE OR DELETE ON stock_count_items
    FOR EACH ROW EXECUTE FUNCTION stock_count_items_locked();

-- Posting is one way. A posted count may only be read.
CREATE OR REPLACE FUNCTION stock_count_status_forward_only()
RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status = 'posted' AND NEW.status <> 'posted' THEN
        RAISE EXCEPTION
            'Stock count % has been posted and cannot be reopened. Raise another count to correct it.',
            OLD.count_number;
    END IF;
    IF OLD.status = 'cancelled' AND NEW.status = 'posted' THEN
        RAISE EXCEPTION 'Stock count % was cancelled and cannot be posted.', OLD.count_number;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_stock_count_status ON stock_counts;
CREATE TRIGGER trg_stock_count_status
    BEFORE UPDATE OF status ON stock_counts
    FOR EACH ROW
    WHEN (OLD.status IS DISTINCT FROM NEW.status)
    EXECUTE FUNCTION stock_count_status_forward_only();

DROP TRIGGER IF EXISTS tg_stock_counts_touch ON stock_counts;
CREATE TRIGGER tg_stock_counts_touch
    BEFORE UPDATE ON stock_counts
    FOR EACH ROW EXECUTE FUNCTION trg_touch_updated_at();

-- ─── A default place to count ───────────────────────────────
--  A count needs somewhere to have happened, and a business with
--  one shop should not have to set that up before it can count.

INSERT INTO warehouses (name, code, is_default, is_active)
SELECT 'Main Store', 'MAIN', TRUE, TRUE
 WHERE NOT EXISTS (SELECT 1 FROM warehouses);

-- ─── Ledger index for the new movement type ─────────────────
CREATE INDEX IF NOT EXISTS ix_inv_txn_adjustment
    ON inventory_transactions (created_at)
    WHERE movement_type = 'adjustment';
