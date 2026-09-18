-- ============================================================
--  Migration 065 — Writing off stock that cannot be sold
-- ------------------------------------------------------------
--  Expired stock stays in its batch on purpose: somebody has to
--  physically walk to the shelf and pull it, and zeroing it in
--  the system would mean nobody ever does. But there has been no
--  way to record that they did pull it, so expired stock sat in
--  the batch and in the valuation for ever. A shop's stock was
--  worth more on paper than on the shelf, by exactly the amount
--  it was legally forbidden to sell.
--
--  A disposal is not a stock take. A count says "the shelf and
--  the system disagree and the shelf wins"; a disposal says
--  "this named batch, this quantity, was destroyed on this date
--  for this reason". An inspector asking what happened to a
--  recalled batch needs the second answer, and a variance line
--  on a count sheet is not it.
--
--  witnessed_by is free text and optional. Destruction of a
--  controlled drug is supposed to be witnessed, and recording
--  the witness's name is the most this system can honestly
--  offer — it cannot verify that anybody stood there. Better a
--  name somebody typed than a field that pretends to more.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS stock_disposals (
    disposal_id  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    --  RESTRICT, not CASCADE. The disposal record is the reason
    --  the stock is gone; deleting the batch and taking the
    --  explanation with it is how stock goes missing quietly.
    batch_id     INTEGER NOT NULL REFERENCES product_batches(batch_id) ON DELETE RESTRICT,
    product_id   INTEGER NOT NULL REFERENCES products(product_id)     ON DELETE RESTRICT,

    quantity     NUMERIC(12,3) NOT NULL CHECK (quantity > 0),
    reason       VARCHAR(20)   NOT NULL
                 CHECK (reason IN ('expired', 'damaged', 'recalled', 'contaminated', 'other')),

    --  What it cost, captured at the moment of disposal. The
    --  batch's unit_cost can be corrected later and the value
    --  written off must not move when it is.
    unit_cost    NUMERIC(12,2),

    notes        TEXT,
    witnessed_by VARCHAR(160),

    disposed_by  INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
    disposed_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_stock_disposals_batch   ON stock_disposals (batch_id);
CREATE INDEX IF NOT EXISTS idx_stock_disposals_product ON stock_disposals (product_id, disposed_at);
CREATE INDEX IF NOT EXISTS idx_stock_disposals_date    ON stock_disposals (disposed_at);

--  ── The stock ledger has to accept the word ─────────────────
--
--  inventory_transactions.movement_type is a CHECK constraint
--  over a fixed list, and 'disposal' is not in it. Writing the
--  ledger entry would fail on the constraint and take the whole
--  disposal down with it.
--
--  This is the same pairing migration 061 documents for the
--  dosage forms: a list that lives in two languages has to be
--  widened in both, in one commit, or the half that was not
--  widened is found later by a user rather than by a test.
ALTER TABLE inventory_transactions
    DROP CONSTRAINT IF EXISTS inventory_transactions_movement_type_check;

ALTER TABLE inventory_transactions
    ADD CONSTRAINT inventory_transactions_movement_type_check
    CHECK (movement_type IN (
        'delivery', 'delivery_reversal', 'receipt', 'adjustment',
        'return', 'return_reversal', 'transfer', 'opening',
        'disposal'
    ));

COMMIT;
