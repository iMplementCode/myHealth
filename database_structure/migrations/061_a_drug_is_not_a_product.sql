-- ============================================================
--  Migration 061 — What a medicine has that a product does not
-- ------------------------------------------------------------
--  `products` describes a thing with a name, a price and a
--  quantity. That is most of what a pharmacy needs and none of
--  what makes it a pharmacy.
--
--  The columns here exist because somebody at a counter has to
--  answer a question:
--
--    generic_name        "Do you have something for pain?" The
--                        customer asks for Panadol; the shelf may
--                        only have Hedex. Both are paracetamol.
--                        Searching by brand alone loses the sale
--                        and, worse, sells them a second box of
--                        the same drug they already took at home.
--
--    strength + form     "Amoxil 250" and "Amoxil 500" are not
--                        the same medicine and must never be the
--                        same row. Kept as free text because the
--                        real world writes 500 mg, 5 mg/ml and
--                        500 mg/125 mg, and a numeric column would
--                        force somebody to lie.
--
--    route               A gentamicin vial given by the wrong
--                        route is a serious event. Cheap to
--                        record, expensive to omit.
--
--    requires_rx         The line between "hand it over" and "not
--                        without a prescription". This is the
--                        column the dispensing screen asks.
--
--    controlled_schedule Narcotics and psychotropics. Null means
--                        ordinary stock. Non-null means the
--                        controlled register applies, and that is
--                        a legal obligation rather than a
--                        preference.
--
--    storage             Cold-chain stock that spent a night at
--                        room temperature is waste, and nobody can
--                        tell by looking at it.
--
--    ppb_registration_no Kenya's Pharmacy and Poisons Board
--                        registration. An inspector asks for it.
--
--  All nullable. A shop mid-way through entering its shelves must
--  keep working, and a half-described drug is better than a
--  refusal to save one.
-- ============================================================

BEGIN;

ALTER TABLE products ADD COLUMN IF NOT EXISTS generic_name        VARCHAR(160);
ALTER TABLE products ADD COLUMN IF NOT EXISTS strength            VARCHAR(60);
ALTER TABLE products ADD COLUMN IF NOT EXISTS dosage_form         VARCHAR(30);
ALTER TABLE products ADD COLUMN IF NOT EXISTS route               VARCHAR(20);
ALTER TABLE products ADD COLUMN IF NOT EXISTS requires_rx         BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE products ADD COLUMN IF NOT EXISTS controlled_schedule VARCHAR(20);
ALTER TABLE products ADD COLUMN IF NOT EXISTS storage             VARCHAR(20);
ALTER TABLE products ADD COLUMN IF NOT EXISTS ppb_registration_no VARCHAR(40);

/*  Constrained rather than free text, because these drive
 *  behaviour — the dispensing screen groups by form and warns on
 *  cold chain. A typo'd 'tablets' would silently fall out of
 *  every one of those. NULL stays legal throughout: a shop
 *  entering its shelves is allowed to be half-finished.
 *
 *  Dropped first so the migration can be re-run after the list is
 *  widened, which it will be — nobody gets a list like this right
 *  the first time. */
ALTER TABLE products DROP CONSTRAINT IF EXISTS products_dosage_form_ck;
ALTER TABLE products ADD CONSTRAINT products_dosage_form_ck CHECK (
    dosage_form IS NULL OR dosage_form IN (
        'tablet', 'capsule', 'syrup', 'suspension', 'solution',
        'injection', 'infusion', 'cream', 'ointment', 'gel',
        'drops', 'inhaler', 'suppository', 'pessary', 'patch',
        'sachet', 'powder', 'lozenge', 'spray', 'device', 'other'
    )
);

ALTER TABLE products DROP CONSTRAINT IF EXISTS products_route_ck;
ALTER TABLE products ADD CONSTRAINT products_route_ck CHECK (
    route IS NULL OR route IN (
        'oral', 'topical', 'iv', 'im', 'sc', 'rectal', 'vaginal',
        'ophthalmic', 'otic', 'nasal', 'inhaled', 'sublingual',
        'transdermal', 'other'
    )
);

ALTER TABLE products DROP CONSTRAINT IF EXISTS products_storage_ck;
ALTER TABLE products ADD CONSTRAINT products_storage_ck CHECK (
    storage IS NULL OR storage IN ('room', 'cool', 'cold', 'frozen')
);

/*  The search the counter actually runs. Somebody types "parac"
 *  and both the brand and the generic have to match, so the index
 *  covers the lowered generic name the same way the product name
 *  is already searched.
 *
 *  A plain b-tree on lower(generic_name) serves prefix matching,
 *  which is what a type-ahead does. Full-text would be heavier and
 *  would not help "parac" find "Paracetamol" without extra work. */
CREATE INDEX IF NOT EXISTS idx_products_generic_lower
    ON products (LOWER(generic_name) varchar_pattern_ops);

-- Asked on every dispense of a restricted item, and by the
-- controlled-stock report.
CREATE INDEX IF NOT EXISTS idx_products_controlled
    ON products (controlled_schedule)
 WHERE controlled_schedule IS NOT NULL;

COMMENT ON COLUMN products.generic_name IS
    'International non-proprietary name. Panadol and Hedex are both paracetamol.';
COMMENT ON COLUMN products.requires_rx IS
    'Prescription-only. The dispensing screen refuses to sell these without one.';
COMMENT ON COLUMN products.controlled_schedule IS
    'Null for ordinary stock. Non-null puts the item in the controlled register.';

COMMIT;
