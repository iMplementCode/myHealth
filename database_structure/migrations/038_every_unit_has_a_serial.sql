-- ============================================================
--  038 — Every unit has a serial
-- ------------------------------------------------------------
--  A camera is not a quantity. It is a specific box with a
--  number stamped on it, fitted to a specific wall, covered
--  until a specific date.
--
--  The system knew none of that. `products.warranty_months` says
--  how long the MODEL is covered; nothing recorded which unit
--  went where or when its cover started. So the question the
--  counter is actually asked —
--
--      "this DVR has failed, is it still under warranty?"
--
--  — had no answer here, and was settled by going through paper
--  delivery notes.
--
--  ── Not every product needs this ────────────────────────────
--  Cable, connectors and screws are a quantity and nothing more.
--  Making somebody type a serial for 100 m of coax would get the
--  whole feature switched off. So tracking is opt-in per product,
--  and off by default: `products.tracks_serials`.
--
--  ── The warranty clock starts on delivery, not on receipt ────
--  A unit sitting in the store is not under warranty to anybody;
--  the customer's cover starts the day it is fitted. So the
--  months are copied onto the unit AT DELIVERY and the expiry is
--  derived from that date.
--
--  Copied, not looked up: a model's warranty can be renegotiated
--  with the supplier next year, and the unit already on a wall in
--  Thika keeps the cover it was sold with. A warranty that
--  changes retroactively is not a warranty.
--
--  ── One unit is in one place ────────────────────────────────
--  The constraints below say a delivered unit cannot be delivered
--  again without coming back first, and that a unit at a customer
--  knows which customer. Those are the rules that make the
--  register worth trusting; without them it is a list of strings.
-- ============================================================

BEGIN;

-- ─── Which products are worth tracking one by one ───────────
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS tracks_serials BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN products.tracks_serials IS
    'Whether each unit of this product is registered individually by '
    'serial number. Off by default: cable and connectors are a quantity, '
    'cameras and recorders are not.';

CREATE INDEX IF NOT EXISTS idx_products_tracks_serials
    ON products (tracks_serials) WHERE tracks_serials;

-- ─── The register itself ────────────────────────────────────
CREATE TABLE IF NOT EXISTS product_serials (
    serial_id       INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    product_id      INTEGER NOT NULL REFERENCES products (product_id) ON DELETE RESTRICT,
    serial_number   VARCHAR(120) NOT NULL,

    -- Where the unit stands right now.
    --   in_stock    on the shelf, ours, not under anybody's warranty
    --   delivered   at a customer, warranty running
    --   returned    came back to us; sellable again after checking
    --   faulty      came back broken, not to be sold
    --   written_off gone: lost, scrapped, or swallowed by a claim
    status          VARCHAR(20) NOT NULL DEFAULT 'in_stock'
                    CHECK (status IN ('in_stock','delivered','returned','faulty','written_off')),

    -- Where it came from.
    grn_id          INTEGER REFERENCES grns (grn_id) ON DELETE SET NULL,
    supplier_id     INTEGER REFERENCES suppliers (supplier_id) ON DELETE SET NULL,
    received_at     DATE,

    -- Where it went.
    dn_id           INTEGER REFERENCES delivery_notes (dn_id) ON DELETE SET NULL,
    customer_id     INTEGER REFERENCES customers (customer_id) ON DELETE SET NULL,
    delivered_at    DATE,

    -- The cover this unit was sold with, copied at delivery.
    warranty_months INTEGER NOT NULL DEFAULT 0 CHECK (warranty_months >= 0),
    warranty_expires_at DATE GENERATED ALWAYS AS (
        CASE WHEN delivered_at IS NULL THEN NULL
             ELSE (delivered_at + make_interval(months => warranty_months))::date
        END
    ) STORED,

    -- Where it is fitted. A customer with four sites needs to know
    -- which one, and the delivery note's address is not always it.
    installed_at    TEXT,

    notes           TEXT,
    created_by      INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- The same string can legitimately belong to two manufacturers.
    -- Within one product it is the unit's identity.
    CONSTRAINT ux_product_serial UNIQUE (product_id, serial_number),

    -- A unit at a customer knows which customer and since when.
    -- Without this the register fills up with "delivered" rows that
    -- cannot answer the only question it exists for.
    CONSTRAINT ck_serial_delivered_is_placed CHECK (
        status <> 'delivered'
        OR (customer_id IS NOT NULL AND delivered_at IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_serials_product   ON product_serials (product_id);
CREATE INDEX IF NOT EXISTS idx_serials_status    ON product_serials (status);
CREATE INDEX IF NOT EXISTS idx_serials_customer  ON product_serials (customer_id);
CREATE INDEX IF NOT EXISTS idx_serials_grn       ON product_serials (grn_id);
CREATE INDEX IF NOT EXISTS idx_serials_dn        ON product_serials (dn_id);
CREATE INDEX IF NOT EXISTS idx_serials_expiry    ON product_serials (warranty_expires_at);

-- Somebody at the counter types the number off the box and nothing
-- else. That lookup has to be fast and has to ignore case.
CREATE INDEX IF NOT EXISTS idx_serials_number_lower
    ON product_serials (LOWER(serial_number));

COMMENT ON TABLE product_serials IS
    'One row per physical unit: where it came from, where it went, and '
    'until when it is covered. The warranty clock starts on delivery.';

-- ─── A delivered unit cannot be delivered again ─────────────
--  The register is only worth trusting if one unit is in one
--  place. Sending out a serial that is already at a customer
--  means somebody mistyped, or the unit came back and nobody
--  recorded it — either way the answer is to stop, not to
--  overwrite where it was.
CREATE OR REPLACE FUNCTION serial_moves_one_way()
RETURNS TRIGGER AS $$
DECLARE
    who TEXT;
BEGIN
    IF NEW.status = 'delivered'
       AND TG_OP = 'UPDATE'
       AND OLD.status = 'delivered'
       AND OLD.customer_id IS DISTINCT FROM NEW.customer_id
    THEN
        SELECT COALESCE(NULLIF(TRIM(c.company_name), ''),
                        NULLIF(TRIM(CONCAT(c.first_name, ' ', c.last_name)), ''),
                        'another customer')
          INTO who
          FROM customers c WHERE c.customer_id = OLD.customer_id;

        RAISE EXCEPTION
            'Serial % is already with %. Record it as returned before sending it out again.',
            NEW.serial_number, COALESCE(who, 'another customer');
    END IF;

    -- Coming back clears the customer's claim on it, so the register
    -- never shows a unit as ours AND theirs at the same time.
    IF NEW.status IN ('in_stock', 'returned', 'faulty', 'written_off') THEN
        NEW.customer_id  := NULL;
        NEW.dn_id        := NULL;
        NEW.delivered_at := NULL;
    END IF;

    NEW.updated_at := NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_serial_moves_one_way ON product_serials;
CREATE TRIGGER trg_serial_moves_one_way
    BEFORE INSERT OR UPDATE ON product_serials
    FOR EACH ROW EXECUTE FUNCTION serial_moves_one_way();

-- ─── Only track what is worth tracking ──────────────────────
--  A serial against a service (an installation, a site survey) is
--  meaningless — there is no box. And registering units of a
--  product nobody marked as tracked is how the register quietly
--  becomes half-complete and stops being believed.
CREATE OR REPLACE FUNCTION serial_belongs_to_a_tracked_product()
RETURNS TRIGGER AS $$
DECLARE
    p RECORD;
BEGIN
    SELECT name, product_type, tracks_serials INTO p
      FROM products WHERE product_id = NEW.product_id;

    IF p.product_type = 'service' THEN
        RAISE EXCEPTION
            '% is a service, so there is no unit to give a serial number to.', p.name;
    END IF;

    IF NOT p.tracks_serials THEN
        RAISE EXCEPTION
            '% is not tracked by serial number. Turn that on for the product first.', p.name;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_serial_tracked_product ON product_serials;
CREATE TRIGGER trg_serial_tracked_product
    BEFORE INSERT ON product_serials
    FOR EACH ROW EXECUTE FUNCTION serial_belongs_to_a_tracked_product();

COMMIT;
