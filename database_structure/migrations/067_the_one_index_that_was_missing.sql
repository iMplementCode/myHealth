-- ============================================================
--  The one index that was missing
-- ------------------------------------------------------------
--  A full index audit of the schema found 102 foreign keys with
--  no supporting index. Adding 102 indexes would have been the
--  wrong answer, and a popular one: PostgreSQL does not index a
--  foreign key automatically, so "every FK needs an index" reads
--  like a rule, and following it buys a slower INSERT on every
--  table in exchange for speeding up queries nobody writes.
--
--  Nearly all 102 are "who did it" columns — created_by,
--  issued_by, recorded_by, dispensed_by. Nothing in the
--  application filters on them. They are there so an auditor can
--  trace one row back to one person, which is a lookup through
--  the row, not through an index on the user.
--
--  What was left after that was one real gap, and it is the kind
--  worth having a migration for.
--
--  Every line-item table in this schema indexes the document it
--  belongs to. invoice_items has invoice_id, quote_items has
--  quote_id, grn_items has grn_id, delivery_note_items has dn_id,
--  credit_note_items has credit_note_id, and so on for all ten.
--  debit_note_items did not: its only index was the primary key
--  on debit_item_id. So "show me the lines on this debit note" —
--  which runs when a supplier return is viewed and again when it
--  is posted, to put the stock back — read the entire table every
--  time.
--
--  Measured on 4,000 debit notes and 48,000 line items, which is
--  a few years of returns for one shop:
--
--      without:  Seq Scan, 2.901 ms, 495 buffers,
--                47,988 rows read and thrown away to find 12
--      with:     Bitmap Index Scan, 0.088 ms, 14 buffers
--
--  Thirty-three times faster, and it stays that way as the table
--  grows, which a sequential scan does not.
--
--  Checked and deliberately NOT added:
--
--  * remember_tokens.user_id, which looked like an obvious win
--    because the token-family burn on a stolen cookie deletes by
--    user_id. Measured, and the planner was right to ignore an
--    index: that table holds one row per member of staff per
--    device and is swept by expiry, so it has tens of rows, and a
--    scan of tens of rows beats an index lookup. An index there
--    would cost writes and buy nothing.
--
--  * product_id on six line-item tables. Nothing queries them,
--    and the only thing that needs them is DELETE FROM products,
--    which an administrator does approximately never.
-- ============================================================

CREATE INDEX IF NOT EXISTS ix_debit_note_items_debit_note
    ON debit_note_items (debit_note_id);

COMMENT ON INDEX ix_debit_note_items_debit_note IS
    'Lines belonging to one debit note. Every other line-item table had '
    'the equivalent; this one was missed.';
