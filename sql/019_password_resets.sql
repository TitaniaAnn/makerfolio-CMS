-- 019_password_resets.sql
-- Single-use tokens for the /admin/auth/forgot-password.php flow.
--
-- Tokens are stored SHA-256-hashed so a leaked DB doesn't yield usable
-- tokens. used_at is stamped on successful reset (enforces single-use).
-- expires_at is checked at redeem time; rows are not auto-purged here —
-- the forgot-password handler can do opportunistic cleanup of expired rows.

CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT          NOT NULL,
    token_hash  CHAR(64)     NOT NULL UNIQUE,
    expires_at  TIMESTAMP    NOT NULL,
    used_at     TIMESTAMP    NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin (admin_id),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
