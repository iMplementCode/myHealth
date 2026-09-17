-- ============================================================
--  033 — Goods sent back to a supplier are not owed for
-- ------------------------------------------------------------
--  Forty thousand of goods arrived. Eight thousand of it was
--  damaged and went straight back on a debit note. The stock
--  came off the shelf that afternoon — and the system went on
--  saying the full forty thousand was owed to the supplier.
--
--  ── Why ─────────────────────────────────────────────────────
--  Trade payables were worked out per order as
--
--      owed = goods received − paid
--
--  with nothing in that sentence about goods that went back.
--  So the daily position carried a liability for stock the
--  business demonstrably no longer holds, and a payment run
--  built off the payables report would have paid for it.
--
--  This is the purchasing side of the same sentence migration
--  031 wrote for sales. There it was:
--
--      outstanding = invoiced − delivered − credited
--
--  and here it is:
--
--      owed = received − returned − paid
--
--  A credit note cancels a customer's obligation; a debit note
--  cancels ours. They are the same document pointing the other
--  way.
--
--  ── Why a draft debit note counts ───────────────────────────
--  Raising the note takes the stock off the shelf immediately,
--  before anyone marks it sent. Counting only "sent" notes
--  would leave a window — sometimes a permanent one, since
--  nothing forces the status on — where the goods are gone and
--  the debt is not. Every note that has not been cancelled
--  counts, which is exactly the set whose stock has left.
--
--  ── What this migration itself does ─────────────────────────
--  The netting is arithmetic and lives in the queries
--  (po_debited_sql() in includes/cashbook.php). Two things have
--  to change in the database for it to hold together:
--
--  1. Cancelling a debit note now puts the goods back on the
--     shelf, because otherwise cancelling would restore the
--     debt while the stock stayed gone. That reversal needs a
--     movement type of its own — 'return_reversal', the mirror
--     of the 'delivery_reversal' that already exists — so the
--     stock ledger says what happened rather than logging it as
--     an anonymous adjustment.
--
--  2. Every payables and prepayment figure now joins debit
--     notes to their receipt, so debit_notes.grn_id wants an
--     index. It had none: the table has only a primary key and
--     the number's unique constraint.
--
--  Idempotent, like every migration here.
-- ============================================================

BEGIN;

-- ── 1. A stock movement for a debit note that was cancelled ──
DO $$
DECLARE
    con_name text;
    def      text;
BEGIN
    SELECT conname, pg_get_constraintdef(oid)
      INTO con_name, def
      FROM pg_constraint
     WHERE conrelid = to_regclass('inventory_transactions')
       AND contype  = 'c'
       AND pg_get_constraintdef(oid) LIKE '%movement_type%';

    -- Only rewrite it when the new value is genuinely absent, so
    -- running this twice is a no-op rather than an error.
    IF con_name IS NOT NULL AND def NOT LIKE '%return_reversal%' THEN
        EXECUTE format('ALTER TABLE inventory_transactions DROP CONSTRAINT %I', con_name);
        ALTER TABLE inventory_transactions
            ADD CONSTRAINT inventory_transactions_movement_type_check
            CHECK (movement_type IN ('delivery', 'delivery_reversal',
                                     'receipt', 'adjustment',
                                     'return', 'return_reversal',
                                     'transfer', 'opening'));
    END IF;
END $$;

-- ── 2. The join every payables figure now makes ─────────────
CREATE INDEX IF NOT EXISTS idx_debit_notes_grn ON debit_notes (grn_id);

-- Notes are also read per supplier, per date, on the supplier
-- statement.
CREATE INDEX IF NOT EXISTS idx_debit_notes_supplier_date
    ON debit_notes (supplier_id, issue_date);

COMMIT;
