-- 011_webhook_processed_at.sql
-- Adds the processed_at column that the webhook now needs to distinguish
-- "received but handler crashed mid-flight" from "fully handled — duplicate".
--
-- Run before deploying the matching webhook.php change:
--     mysql -u <user> -p <db> < sql/011_webhook_processed_at.sql
--
-- Safe to re-apply: ADD COLUMN is gated on INFORMATION_SCHEMA and the
-- backfill UPDATE has WHERE processed_at IS NULL.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'stripe_webhook_events'
            AND COLUMN_NAME = 'processed_at');
SET @sql = IF(@c = 0,
    'ALTER TABLE stripe_webhook_events ADD COLUMN processed_at TIMESTAMP NULL DEFAULT NULL AFTER received_at',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Existing rows pre-date this column. They were processed under the old
-- "insert-then-handle" code path, so backfill them as processed to avoid
-- re-running their handlers on the next retry that happens to land here.
-- The WHERE clause makes this naturally idempotent.
UPDATE stripe_webhook_events SET processed_at = received_at WHERE processed_at IS NULL;
