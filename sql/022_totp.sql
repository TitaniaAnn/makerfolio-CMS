-- 022_totp.sql
-- TOTP-based 2FA for local-login admins. Columns are nullable / zero-default
-- so existing rows keep working without 2FA.
--
--   totp_secret           — base32-encoded 20-byte secret. Null until the
--                           admin starts enrollment; populated during enroll
--                           BEFORE totp_enabled flips so a half-finished
--                           enrollment can be rolled back.
--   totp_enabled          — 1 only after the admin successfully verified the
--                           first code. Until then, the secret is unverified
--                           and login does not challenge.
--   recovery_codes_hash   — JSON array of password_hash()'d single-use recovery
--                           codes (10 by default). NULL when no codes have
--                           been generated. A used code gets nulled out in the
--                           array (so the array length stays stable but the
--                           hash is replaced with an empty string).
--
-- Safe to re-apply: each ADD COLUMN is gated on INFORMATION_SCHEMA.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'totp_secret');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER password_hash',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'totp_enabled');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'recovery_codes_hash');
SET @sql = IF(@c = 0,
    'ALTER TABLE admin_users ADD COLUMN recovery_codes_hash TEXT NULL AFTER totp_enabled',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
