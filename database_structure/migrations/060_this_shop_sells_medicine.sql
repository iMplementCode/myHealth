-- ============================================================
--  Migration 060 — Put down what the CCTV trade carried
-- ------------------------------------------------------------
--  This codebase grew up selling cameras. Three things it needed
--  there mean nothing in a pharmacy, and leaving them behind as
--  empty tables would be worse than useless: the next person to
--  read the schema would spend an afternoon working out whether
--  a dispensary is supposed to issue warranty notes.
--
--    product_serials        — a recorder is a specific box with a
--                             number on the back. A paracetamol
--                             tablet is not. Medicines are
--                             identified by BATCH, which is a
--                             different shape and already exists
--                             in product_batches.
--    warranty_*             — nobody warranties a course of
--                             antibiotics.
--    service_contracts      — a maintenance contract on an
--                             installation. Not this trade.
--
--  Dropped rather than kept "just in case". A hospital may later
--  want service contracts on its equipment; that is a different
--  table, about machines rather than about what was sold, and it
--  should be designed then rather than inherited now.
--
--  The earlier migrations that created these are left in place
--  and still run. Rewriting history to pretend they never existed
--  would mean re-testing the whole chain that builds a working
--  database, and that chain is the one thing here that is proven.
--  So: build as before, then put this down.
-- ============================================================

BEGIN;

-- Order matters: children first, then parents.
DROP TABLE IF EXISTS warranty_note_items       CASCADE;
DROP TABLE IF EXISTS warranty_notes            CASCADE;
DROP TABLE IF EXISTS warranty_template_sections CASCADE;
DROP TABLE IF EXISTS warranty_templates        CASCADE;
DROP TABLE IF EXISTS product_serials           CASCADE;
DROP TABLE IF EXISTS service_contracts         CASCADE;

-- The two columns on products that only those tables gave meaning
-- to. tracks_serials answered "is this a boxed unit"; a pharmacy
-- asks "is this a controlled drug", which migration 061 adds.
ALTER TABLE products DROP COLUMN IF EXISTS tracks_serials;
ALTER TABLE products DROP COLUMN IF EXISTS warranty_months;

-- Notifications already raised about the dropped subjects would
-- point at pages that no longer exist. The scanner rebuilds what
-- is still true on its next run.
DELETE FROM notifications
 WHERE subject_type IN ('serial', 'contract')
    OR kind IN ('warranty_expiring', 'contract_due');

-- notification_scan_state holds one row for the whole scanner, not
-- one per kind, so there is nothing to clean there. Its next run
-- rebuilds from the rules that remain.

COMMIT;
