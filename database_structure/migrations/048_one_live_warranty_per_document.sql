-- ============================================================
--  One live warranty note per document
-- ------------------------------------------------------------
--  Two warranty notes standing against one invoice is not a
--  generous mistake, it is an unanswerable one. The customer holds
--  two pieces of paper; one says 24 months from March, the other
--  12 months from June. Whichever the business honours, it is
--  arguing with its own document.
--
--  It happens by accident: somebody raises a note, does not find
--  it in the list, and raises another. Migration 046 warned about
--  this on the create screen and let it through — but a warning is
--  just a rule everybody is allowed to break.
--
--  A second note is allowed once the first is CANCELLED, which is
--  how a note raised with the wrong dates or against the wrong
--  invoice gets corrected: cancel it, raise the right one. The
--  cancelled note stays on record because the customer may still
--  be holding a copy of it.
--
--  ── Two partial unique indexes, not a trigger ───────────────
--  The whole fact — which document, and whether the note still
--  stands — lives on warranty_notes, so the database can enforce
--  it directly. A unique index is also the only version of this
--  that survives two people pressing Issue at the same moment: a
--  check-then-insert cannot see an uncommitted row, however
--  carefully it is written.
-- ============================================================

CREATE UNIQUE INDEX IF NOT EXISTS uq_warranty_note_live_invoice
    ON warranty_notes (invoice_id)
    WHERE status = 'issued' AND invoice_id IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_warranty_note_live_pi
    ON warranty_notes (pi_id)
    WHERE status = 'issued' AND pi_id IS NOT NULL;

-- ── Cancelling has to say why ────────────────────────────────
--  A cancelled warranty is the one document a customer is most
--  likely to argue about, and "cancelled" on its own answers
--  nothing six months later. Wrong invoice? Goods returned?
--  Re-issued with the right serials? Each has a different answer
--  to "so what is this customer actually owed", and the person who
--  knows is the person pressing the button.
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS cancel_reason TEXT;

-- NOT VALID on purpose. Notes cancelled before this migration have
-- no reason recorded and inventing one for them would be a lie in
-- the audit trail. The rule binds every cancellation from here on;
-- the old rows stay as they are, visibly empty.
ALTER TABLE warranty_notes DROP CONSTRAINT IF EXISTS ck_warranty_cancel_reason;
ALTER TABLE warranty_notes ADD CONSTRAINT ck_warranty_cancel_reason
    CHECK (status <> 'cancelled' OR btrim(COALESCE(cancel_reason, '')) <> '')
    NOT VALID;

-- ── Editing an issued note leaves a mark ─────────────────────
--  A warranty is a document somebody may be holding a copy of, so
--  an amendment is worth more than a row in the audit log: the
--  note itself should say it was changed, and when, so a reprint
--  and the copy in the customer's file can be told apart.
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS amended_at TIMESTAMPTZ;
ALTER TABLE warranty_notes ADD COLUMN IF NOT EXISTS amended_by INTEGER
    REFERENCES users (user_id);

-- The create and edit screens both ask "what is already covered".
CREATE INDEX IF NOT EXISTS idx_warranty_items_product
    ON warranty_note_items (product_id) WHERE product_id IS NOT NULL;
