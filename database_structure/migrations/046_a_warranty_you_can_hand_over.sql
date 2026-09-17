-- ============================================================
--  A warranty the customer can hold in their hand
-- ------------------------------------------------------------
--  Selling a camera with "one year warranty" said out loud is
--  worth nothing eleven months later, when the customer remembers
--  two years and there is no paper either way. The invoice proves
--  what was bought and when; it says nothing about what is
--  covered, for how long, or what voids it.
--
--  So: a warranty note, raised from the invoice or the proforma
--  that sold the goods, printed on the company letterhead, with
--  its own number.
--
--  ── Three tables, and why ───────────────────────────────────
--
--  warranty_templates  the words. Editable, because the terms a
--                      business offers are its own and change —
--                      and because a camera and an installation
--                      are not covered on the same terms, so
--                      there can be more than one.
--
--  warranty_notes      the document. One per issue, numbered,
--                      pointing back at what it was raised from.
--
--  warranty_note_items what is covered, line by line, each with
--                      its own period. A camera at 24 months and
--                      a power supply at 6 go on one note, and
--                      each line carries its own expiry — the
--                      alternative is one period for the note and
--                      an argument about which item it applied to.
--
--  The text is COPIED onto the note at issue, not referenced.
--  Change the template next year and a note issued today must
--  still say what the customer was actually promised. A warranty
--  whose terms can be edited after the fact is not a warranty.
-- ============================================================

CREATE TABLE IF NOT EXISTS warranty_templates (
    template_id   SERIAL PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,

    -- The default period, used for any product that does not carry
    -- its own products.warranty_months.
    default_months INTEGER NOT NULL DEFAULT 12
        CHECK (default_months >= 0 AND default_months <= 600),

    -- The three things a warranty has to say. Separate columns
    -- rather than one blob so the printed note can head them and a
    -- customer can find the exclusions without reading the lot.
    cover_text      TEXT NOT NULL,
    exclusions_text TEXT,
    claim_text      TEXT,

    -- Exactly one default, enforced by a partial unique index below.
    is_default    BOOLEAN NOT NULL DEFAULT FALSE,
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,

    created_by    INTEGER REFERENCES users (user_id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- One default and no more. A second row claiming the default would
-- make "which terms did we issue?" a matter of row order.
CREATE UNIQUE INDEX IF NOT EXISTS uq_warranty_template_default
    ON warranty_templates (is_default) WHERE is_default;

CREATE TABLE IF NOT EXISTS warranty_notes (
    warranty_id   SERIAL PRIMARY KEY,
    warranty_number VARCHAR(40) NOT NULL UNIQUE,

    customer_id   INTEGER NOT NULL REFERENCES customers (customer_id),

    -- What it was raised from. Exactly one of these, which the
    -- check below insists on: a note that belongs to nothing cannot
    -- be traced back to what was sold.
    invoice_id    INTEGER REFERENCES invoices (invoice_id) ON DELETE SET NULL,
    pi_id         INTEGER REFERENCES proforma_invoices (pi_id) ON DELETE SET NULL,

    issue_date    DATE NOT NULL DEFAULT CURRENT_DATE,
    -- Cover usually starts the day the goods were delivered rather
    -- than the day the paperwork was raised.
    start_date    DATE NOT NULL DEFAULT CURRENT_DATE,

    template_id   INTEGER REFERENCES warranty_templates (template_id),

    -- The terms as they stood when this note was issued. Copied,
    -- never looked up: see the note at the top of this file.
    cover_text      TEXT NOT NULL,
    exclusions_text TEXT,
    claim_text      TEXT,

    notes         TEXT,
    status        VARCHAR(20) NOT NULL DEFAULT 'issued'
        CHECK (status IN ('issued', 'cancelled')),
    cancelled_at  TIMESTAMPTZ,
    cancelled_by  INTEGER REFERENCES users (user_id),

    created_by    INTEGER REFERENCES users (user_id),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT ck_warranty_source CHECK (
        (invoice_id IS NOT NULL AND pi_id IS NULL)
     OR (invoice_id IS NULL AND pi_id IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_warranty_notes_customer
    ON warranty_notes (customer_id, issue_date DESC);
CREATE INDEX IF NOT EXISTS idx_warranty_notes_invoice
    ON warranty_notes (invoice_id);
CREATE INDEX IF NOT EXISTS idx_warranty_notes_pi
    ON warranty_notes (pi_id);

CREATE TABLE IF NOT EXISTS warranty_note_items (
    warranty_item_id SERIAL PRIMARY KEY,
    warranty_id   INTEGER NOT NULL
                  REFERENCES warranty_notes (warranty_id) ON DELETE CASCADE,
    product_id    INTEGER REFERENCES products (product_id),

    -- The description is copied too. A product renamed next year
    -- must not silently rewrite a warranty already issued.
    description   VARCHAR(255) NOT NULL,
    sku           VARCHAR(60),
    serial_number VARCHAR(120),

    quantity      NUMERIC(12,3) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    months        INTEGER NOT NULL CHECK (months >= 0 AND months <= 600),

    -- Stored rather than computed on the fly. The start date can be
    -- edited on the note; the expiry a customer was handed must not
    -- move because somebody changed a setting.
    expires_on    DATE NOT NULL,

    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_warranty_items_note
    ON warranty_note_items (warranty_id);
CREATE INDEX IF NOT EXISTS idx_warranty_items_expiry
    ON warranty_note_items (expires_on);

-- ── A template to start from ─────────────────────────────────
--  Written for a CCTV and security installer, because that is what
--  this system is for. Every word of it is editable.
INSERT INTO warranty_templates
    (name, default_months, cover_text, exclusions_text, claim_text, is_default)
SELECT
    'Standard equipment warranty',
    12,
    'We warrant the equipment listed on this note against defects in '
 || 'materials and workmanship for the period shown against each item, '
 || 'starting from the date of delivery or installation.' || chr(10) || chr(10)
 || 'Within that period we will repair or replace any item that fails '
 || 'in normal use, at no charge for parts or labour. Where an item is '
 || 'no longer available it will be replaced with one of equal or '
 || 'better specification. Repaired or replaced items carry the '
 || 'remainder of the original period, not a new one.',

    'This warranty does not cover:' || chr(10)
 || '  •  Physical damage, misuse, or use outside the equipment''s rating.' || chr(10)
 || '  •  Damage from lightning, power surges, flooding, fire or theft.' || chr(10)
 || '  •  Equipment installed, moved, opened or repaired by anyone other '
 || 'than ourselves or a technician we have authorised.' || chr(10)
 || '  •  Consumable parts and batteries.' || chr(10)
 || '  •  Faults caused by third-party equipment, cabling or software '
 || 'not supplied by us.' || chr(10)
 || '  •  Any item whose serial number has been removed or defaced.',

    'To make a claim, contact us with this warranty note number and a '
 || 'description of the fault. Please do not attempt a repair first: '
 || 'equipment opened by somebody else is no longer covered.' || chr(10) || chr(10)
 || 'Equipment must be returned to our premises unless the fault is with '
 || 'a fixed installation, in which case we will attend on site.',
    TRUE
WHERE NOT EXISTS (SELECT 1 FROM warranty_templates);
