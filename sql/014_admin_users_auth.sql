-- 014_admin_users_auth.sql
-- Evolve admin_users from "single OAuth provider per user" to a unified table
-- that supports: local username/password, GitHub OAuth, and Google OAuth — any
-- combination per row (a row is valid if at least one of username, github_id,
-- google_sub is non-null).
--
-- Notes:
--   * MySQL allows multiple NULLs in a UNIQUE column, so we can keep the unique
--     constraints on the new identifier columns without losing rows.
--   * Existing rows store the GitHub user id in `provider_user_id` (despite
--     the column comment in init.sql saying "was google_id"). We copy them
--     into `github_id`. `provider_user_id` is kept for one release as a safety
--     net, then dropped in a future migration.
--   * `email` becomes nullable because local-auth users may not provide one.
--
-- Safe to re-apply: each ADD COLUMN and the legacy-data copy are gated on
-- INFORMATION_SCHEMA. The MODIFY is naturally idempotent (re-running with
-- the same definition is a no-op).

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'username');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN username VARCHAR(64) NULL UNIQUE AFTER id',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'password_hash');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN password_hash VARCHAR(255) NULL AFTER username',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'github_id');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN github_id VARCHAR(64) NULL UNIQUE AFTER password_hash',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'google_sub');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN google_sub VARCHAR(255) NULL UNIQUE AFTER github_id',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'google_email');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN google_email VARCHAR(255) NULL AFTER google_sub',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migrate existing rows: provider_user_id was the GitHub numeric user id.
-- Guarded on the legacy column still existing, and only touches rows whose
-- github_id is still NULL so re-runs don't clobber edited values.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'provider_user_id');
SET @sql = IF(@c > 0,
    'UPDATE admin_users SET github_id = provider_user_id WHERE provider_user_id IS NOT NULL AND github_id IS NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Allow nullable email so OAuth-only or local-only users can exist without one.
ALTER TABLE admin_users MODIFY email VARCHAR(255) NULL;
