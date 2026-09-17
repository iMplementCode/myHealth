-- ============================================================
--  Migration 013 — Manual fulfilment marking on delivery notes
-- ------------------------------------------------------------
--  `delivery_notes.delivery_status` is computed from the posted
--  quantities, which is right most of the time but cannot know
--  about the real world: a customer who accepts a short shipment
--  as complete, goods signed for that never arrived, a driver
--  who returned part of a load.
--
--  This adds an override the user can set by hand. When present
--  it wins; when cleared the note goes back to following the
--  quantities. The computed value is kept alongside it so the
--  two can always be compared.
--
--  Additive and re-runnable.
-- ============================================================

BEGIN;

ALTER TABLE delivery_notes
    ADD COLUMN IF NOT EXISTS delivery_status_override VARCHAR(25),
    ADD COLUMN IF NOT EXISTS fulfilment_note          VARCHAR(255),
    ADD COLUMN IF NOT EXISTS fulfilment_marked_at     TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS fulfilment_marked_by     INTEGER;

ALTER TABLE delivery_notes DROP CONSTRAINT IF EXISTS ck_dn_delivery_status_override;
ALTER TABLE delivery_notes ADD  CONSTRAINT ck_dn_delivery_status_override
    CHECK (delivery_status_override IS NULL
        OR delivery_status_override IN ('not_delivered','partially_delivered','fully_delivered'));

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_dn_fulfilment_marked_by') THEN
        ALTER TABLE delivery_notes ADD CONSTRAINT fk_dn_fulfilment_marked_by
            FOREIGN KEY (fulfilment_marked_by) REFERENCES users(user_id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS ix_delivery_notes_fulfilment_effective
    ON delivery_notes (COALESCE(delivery_status_override, delivery_status));

-- The computed refresh must leave a manual mark alone. Replaces the
-- migration 012 version: it still recomputes delivery_status, but the
-- override column is never touched here.

CREATE OR REPLACE FUNCTION trg_refresh_dn_fulfilment(
    p_quote_id INTEGER, p_so_id INTEGER
) RETURNS VOID AS $$
DECLARE
    src TEXT;
BEGIN
    IF p_so_id IS NOT NULL THEN
        SELECT delivery_status INTO src FROM sales_orders WHERE sales_order_id = p_so_id;
        UPDATE delivery_notes
           SET delivery_status = CASE WHEN status = 'posted'
                                      THEN COALESCE(src, 'not_delivered')
                                      ELSE 'not_delivered' END
         WHERE sales_order_id = p_so_id;
    END IF;

    IF p_quote_id IS NOT NULL THEN
        SELECT delivery_status INTO src FROM quotes WHERE quote_id = p_quote_id;
        UPDATE delivery_notes
           SET delivery_status = CASE WHEN status = 'posted'
                                      THEN COALESCE(src, 'not_delivered')
                                      ELSE 'not_delivered' END
         WHERE quote_id = p_quote_id
           AND sales_order_id IS NULL;
    END IF;
END;
$$ LANGUAGE plpgsql;

COMMIT;
