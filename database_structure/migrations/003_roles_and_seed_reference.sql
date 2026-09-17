-- ============================================================
--  Migration 003 — Roles + reference data
-- ------------------------------------------------------------
--  Reconciles the application roles (Administrator, Manager,
--  Salesperson) with the roles table and fixes the broken
--  reference seeds from the original db.sql.
--
--  Idempotency uses WHERE NOT EXISTS guards rather than
--  ON CONFLICT, so it works against databases whose reference
--  tables were created without the exact unique constraints
--  (e.g. tables from the original db.sql). No constraint is
--  assumed to exist.
--
--  Only columns common to both the original db.sql and the
--  refactored schema are populated (e.g. the UoM seed omits
--  `description`, which the original table doesn't have).
-- ============================================================

BEGIN;

-- Application roles used by role-based access control.
INSERT INTO roles (name, description, is_system_role)
SELECT v.name, v.description, v.is_system
FROM (VALUES
    ('Administrator', 'Full access to all system features and settings', TRUE),
    ('Manager',       'Manage inventory, purchasing, sales and reports', FALSE),
    ('Salesperson',   'Create quotes and sales orders, manage customers', FALSE)
) AS v(name, description, is_system)
WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.name = v.name);

-- Baseline permissions (extend as modules grow).
INSERT INTO permissions (name, module, description)
SELECT v.name, v.module, v.description
FROM (VALUES
    ('export_report', 'Reports', 'Allows exporting reports to external formats (CSV, PDF, etc.)'),
    ('view_reports',  'Reports', 'Grants access to view system-generated reports'),
    ('assign_roles',  'Users',   'Ability to assign or change roles for users'),
    ('manage_users',  'Users',   'Create, edit and deactivate user accounts')
) AS v(name, module, description)
WHERE NOT EXISTS (SELECT 1 FROM permissions p WHERE p.name = v.name);

-- Units of measurement. Live databases differ in whether the table
-- has a `description` column (and whether it is NOT NULL), so detect
-- the column and include it only when present.
DO $seed_uom$
DECLARE
    has_desc boolean;
BEGIN
    SELECT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name   = 'units_of_measurement'
          AND column_name  = 'description'
    ) INTO has_desc;

    IF has_desc THEN
        INSERT INTO units_of_measurement (name, abbreviation, allow_decimals, description)
        SELECT v.name, v.abbr, v.dec, v.descr
        FROM (VALUES
            ('kilogram',   'kg',  TRUE,  'Standard weight unit for bulk goods'),
            ('gram',       'g',   TRUE,  'Smaller weight unit for precision'),
            ('liter',      'L',   TRUE,  'Standard volume unit for liquids'),
            ('milliliter', 'mL',  TRUE,  'Small volume unit for liquids'),
            ('piece',      'pc',  FALSE, 'Discrete countable item'),
            ('box',        'box', FALSE, 'Packaged carton of items')
        ) AS v(name, abbr, dec, descr)
        WHERE NOT EXISTS (
            SELECT 1 FROM units_of_measurement u
            WHERE u.name = v.name OR u.abbreviation = v.abbr
        );
    ELSE
        INSERT INTO units_of_measurement (name, abbreviation, allow_decimals)
        SELECT v.name, v.abbr, v.dec
        FROM (VALUES
            ('kilogram',   'kg',  TRUE),
            ('gram',       'g',   TRUE),
            ('liter',      'L',   TRUE),
            ('milliliter', 'mL',  TRUE),
            ('piece',      'pc',  FALSE),
            ('box',        'box', FALSE)
        ) AS v(name, abbr, dec)
        WHERE NOT EXISTS (
            SELECT 1 FROM units_of_measurement u
            WHERE u.name = v.name OR u.abbreviation = v.abbr
        );
    END IF;
END
$seed_uom$;

-- A sensible default currency so sales/quotes/PO forms work out of the box.
INSERT INTO currencies (name, code, symbol, exchange_rate, is_active)
SELECT 'Kenyan Shilling', 'KES', 'KSh', 1.0000, TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM currencies c WHERE c.code = 'KES' OR c.symbol = 'KSh'
);

COMMIT;
