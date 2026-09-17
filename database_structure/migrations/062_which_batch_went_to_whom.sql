-- ============================================================
--  Migration 062 — Which batch went out, and on what
-- ------------------------------------------------------------
--  product_batches already knew what came IN: batch number,
--  expiry, what remains. Nothing recorded what went OUT of a
--  particular batch, so quantity_remaining was written once at
--  goods-receipt and then read only by the expiry warnings. It
--  never moved.
--
--  That gap is the difference between a shop and a pharmacy.
--  When a manufacturer recalls batch B-2291, somebody has to
--  answer "who did we give it to" — and if the only record is
--  "we sold 400 boxes of amoxicillin this quarter", the honest
--  answer is to telephone every customer.
--
--  So: one row per batch per line dispensed.
--
--  Signed quantity rather than a direction column. A return puts
--  stock back into the batch it came from, and a negative row is
--  the same arithmetic in reverse — SUM(quantity) over a batch is
--  always what left it, and no reader has to remember to branch
--  on a flag. Zero is meaningless and is refused.
-- ============================================================

BEGIN;

CREATE TABLE IF NOT EXISTS batch_allocations (
    allocation_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    batch_id        INTEGER NOT NULL REFERENCES product_batches(batch_id),
    product_id      INTEGER NOT NULL REFERENCES products(product_id),

    -- Negative returns stock to the batch. See the header.
    quantity        NUMERIC(14,3) NOT NULL CHECK (quantity <> 0),

    /*  What consumed it. Deliberately loose rather than a foreign
     *  key to invoices: the same allocation shape has to serve a
     *  counter sale, a ward issue and a write-off, and three
     *  nullable FK columns to say one thing is worse than a name
     *  and a number. */
    reference_table VARCHAR(40)  NOT NULL,
    reference_id    INTEGER      NOT NULL,

    dispensed_by    INTEGER REFERENCES users(user_id),
    dispensed_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- "What did batch B-2291 go out on?" — the recall question.
CREATE INDEX IF NOT EXISTS idx_batch_alloc_batch
    ON batch_allocations (batch_id);

-- "What batches are on this sale?" — the receipt, and the reversal.
CREATE INDEX IF NOT EXISTS idx_batch_alloc_ref
    ON batch_allocations (reference_table, reference_id);

CREATE INDEX IF NOT EXISTS idx_batch_alloc_product_time
    ON batch_allocations (product_id, dispensed_at DESC);

/*  A batch cannot go below zero — but that constraint is already
 *  there. product_batches_quantity_remaining_check came with the
 *  table, and adding a second identical CHECK only leaves the next
 *  reader wondering which one matters.
 *
 *  So the backstop for a hand-typed correction is already in
 *  place, and the allocation code's job is to lock the row and
 *  refuse politely before the database has to refuse rudely. */

-- Dispensing reads "the batches for this drug, oldest expiry
-- first, that still have something in them". Without this it is a
-- scan of every batch the shop has ever received, on every line of
-- every sale.
CREATE INDEX IF NOT EXISTS idx_batches_fefo
    ON product_batches (product_id, expiry_date)
 WHERE quantity_remaining > 0;

COMMENT ON TABLE batch_allocations IS
    'One row per batch per dispensed line. Negative quantity returns stock to the batch.';

COMMIT;
