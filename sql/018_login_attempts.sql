-- 018_login_attempts.sql
-- Per-IP rate limiting for local login attempts. We only track FAILED attempts;
-- a successful login clears the IP's row so legitimate users aren't gated.
-- OAuth flows are not rate-limited here (the provider already does that).

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45)  NOT NULL,  -- IPv4 or IPv6
    username     VARCHAR(64)  NULL,      -- the username that was tried (for forensics)
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
