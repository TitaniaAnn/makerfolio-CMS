-- 020_admin_activity.sql
-- Audit trail for admin actions. Designed to be cheap to insert and easy to
-- query "who flipped what?" / "show me everything admin X did this week."
--
-- admin_id is nullable so we can log anonymous events too (failed logins,
-- password reset requests where the username didn't match).
-- target_type / target_id are nullable so simple events (settings.save) don't
-- need to invent targets.
-- details is a small JSON blob for action-specific context (which setting
-- key changed, which provider got unlinked, etc.).

CREATE TABLE IF NOT EXISTS admin_activity (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT          NULL,
    action      VARCHAR(64)  NOT NULL,
    target_type VARCHAR(32)  NULL,
    target_id   VARCHAR(64)  NULL,
    details     JSON         NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_time   (admin_id, created_at),
    KEY idx_action_time  (action,   created_at),
    KEY idx_created      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
