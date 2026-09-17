-- ============================================================
--  Migration 005 — Default administrator account
-- ------------------------------------------------------------
--  Creates the default administrator required by the brief,
--  only if it does not already exist.
--
--    Username: implement
--    Password: implement@1725   (bcrypt hash below)
--    Role:     Administrator
--
--  The password is stored as a bcrypt hash produced with PHP's
--  password_hash(). It is NEVER stored in plain text. Change it
--  immediately after first login (Users → My profile).
--
--  Defensive against live databases whose tables predate this
--  refactor: supplies a placeholder mobile when users.mobile is
--  NOT NULL, and only mirrors into user_roles when that table
--  actually exists. Idempotent: re-running creates nothing.
-- ============================================================

BEGIN;

DO $seed_admin$
DECLARE
    admin_role_id integer;
    new_user_id   integer;
    mobile_required boolean;
BEGIN
    SELECT role_id INTO admin_role_id FROM roles WHERE name = 'Administrator' LIMIT 1;

    IF EXISTS (
        SELECT 1 FROM users
        WHERE LOWER(username) = 'implement' OR LOWER(email) = 'admin@implement.local'
    ) THEN
        NULL; -- admin already present; nothing to do
    ELSE
        -- Some live databases declare users.mobile NOT NULL.
        SELECT a.attnotnull INTO mobile_required
        FROM pg_attribute a
        WHERE a.attrelid = 'users'::regclass
          AND a.attname  = 'mobile'
          AND NOT a.attisdropped;

        IF COALESCE(mobile_required, FALSE) THEN
            INSERT INTO users (username, first_name, last_name, email, mobile, password_hash, role_id, is_active)
            VALUES ('implement', 'Implement', 'Administrator', 'admin@implement.local',
                    '0700000000',
                    '$2y$12$I/eWCLZH5bKFTvyteIU5o.TJUK2Nh/HpJZ4gT3bMzTRihZLgyBw4S',
                    admin_role_id, TRUE)
            RETURNING user_id INTO new_user_id;
        ELSE
            INSERT INTO users (username, first_name, last_name, email, password_hash, role_id, is_active)
            VALUES ('implement', 'Implement', 'Administrator', 'admin@implement.local',
                    '$2y$12$I/eWCLZH5bKFTvyteIU5o.TJUK2Nh/HpJZ4gT3bMzTRihZLgyBw4S',
                    admin_role_id, TRUE)
            RETURNING user_id INTO new_user_id;
        END IF;
    END IF;

    -- Mirror the role in the many-to-many user_roles table when it
    -- exists (older databases may not have it, or may lack the
    -- assigned_by column).
    IF to_regclass('user_roles') IS NOT NULL AND admin_role_id IS NOT NULL THEN
        IF EXISTS (
            SELECT 1 FROM pg_attribute
            WHERE attrelid = 'user_roles'::regclass
              AND attname  = 'assigned_by'
              AND NOT attisdropped
        ) THEN
            INSERT INTO user_roles (user_id, role_id, assigned_by)
            SELECT u.user_id, admin_role_id, u.user_id
            FROM users u
            WHERE LOWER(u.username) = 'implement'
              AND NOT EXISTS (
                  SELECT 1 FROM user_roles ur
                  WHERE ur.user_id = u.user_id AND ur.role_id = admin_role_id
              );
        ELSE
            INSERT INTO user_roles (user_id, role_id)
            SELECT u.user_id, admin_role_id
            FROM users u
            WHERE LOWER(u.username) = 'implement'
              AND NOT EXISTS (
                  SELECT 1 FROM user_roles ur
                  WHERE ur.user_id = u.user_id AND ur.role_id = admin_role_id
              );
        END IF;
    END IF;
END
$seed_admin$;

COMMIT;
