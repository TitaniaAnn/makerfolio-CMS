-- 010_consolidation.sql
-- Brings an existing prod database up to the canonical schema in init.sql.
-- Run BEFORE deploying the matching code (Auth.php expects provider_user_id).
--
--     mysql -u <user> -p <db> < sql/010_consolidation.sql
--
-- Safe to re-apply: the column rename and every index add are gated on
-- INFORMATION_SCHEMA lookups, so re-runs are no-ops.

-- C7: rename admin_users.google_id → admin_users.provider_user_id
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'google_id');
SET @sql = IF(@c > 0,
    'ALTER TABLE admin_users CHANGE COLUMN google_id provider_user_id VARCHAR(255) NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- H2: indexes used by hot lookups
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_status');
SET @sql = IF(@c = 0, 'ALTER TABLE orders ADD INDEX idx_status (status)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_payment_intent');
SET @sql = IF(@c = 0, 'ALTER TABLE orders ADD INDEX idx_payment_intent (stripe_payment_intent)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_customer_email');
SET @sql = IF(@c = 0, 'ALTER TABLE orders ADD INDEX idx_customer_email (customer_email)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery' AND INDEX_NAME = 'idx_featured');
SET @sql = IF(@c = 0, 'ALTER TABLE pottery ADD INDEX idx_featured (featured)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery' AND INDEX_NAME = 'idx_sort_order');
SET @sql = IF(@c = 0, 'ALTER TABLE pottery ADD INDEX idx_sort_order (sort_order)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_images' AND INDEX_NAME = 'idx_pottery_sort');
SET @sql = IF(@c = 0, 'ALTER TABLE pottery_images ADD INDEX idx_pottery_sort (pottery_id, sort_order)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND INDEX_NAME = 'idx_featured');
SET @sql = IF(@c = 0, 'ALTER TABLE social_posts ADD INDEX idx_featured (featured)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
