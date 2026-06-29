-- 024_rename_pottery_to_piece.sql
-- Renames the pottery* tables to piece* (mirrors the SaaS PR #63 rename), the
-- pottery_id foreign-key columns to piece_id, and migrates the
-- announcement_links.entity_type value 'pottery' -> 'piece'. init.sql ships the
-- new names for fresh installs; this migrates existing databases.
--
-- Intentionally UNCHANGED: the upload subdir 'pottery' (filesystem paths in
-- image_path stay valid), the pottery_show / pottery_sale event_type ENUM
-- values, and the pottery_portfolio database name.
--
-- Every step is guarded on INFORMATION_SCHEMA (table/column existence or the
-- ENUM definition), so re-applying the file is a harmless no-op. MySQL 8 InnoDB
-- auto-updates foreign-key references when a referenced table or a local FK
-- column is renamed, so the FKs follow without being dropped/recreated.

-- --- Rename tables (only when the old name exists and the new one does not) ---
SET @old = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery');
SET @new = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piece');
SET @sql = IF(@old = 1 AND @new = 0, 'RENAME TABLE pottery TO piece', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_images');
SET @new = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piece_images');
SET @sql = IF(@old = 1 AND @new = 0, 'RENAME TABLE pottery_images TO piece_images', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_pottery');
SET @new = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_piece');
SET @sql = IF(@old = 1 AND @new = 0, 'RENAME TABLE event_pottery TO event_piece', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_templates');
SET @new = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piece_templates');
SET @sql = IF(@old = 1 AND @new = 0, 'RENAME TABLE pottery_templates TO piece_templates', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @old = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_template_files');
SET @new = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piece_template_files');
SET @sql = IF(@old = 1 AND @new = 0, 'RENAME TABLE pottery_template_files TO piece_template_files', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- Rename the pottery_id FK columns to piece_id (guard on old column) ---
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piece_images' AND COLUMN_NAME = 'pottery_id');
SET @sql = IF(@c = 1, 'ALTER TABLE piece_images RENAME COLUMN pottery_id TO piece_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_piece' AND COLUMN_NAME = 'pottery_id');
SET @sql = IF(@c = 1, 'ALTER TABLE event_piece RENAME COLUMN pottery_id TO piece_id', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- Migrate announcement_links.entity_type 'pottery' -> 'piece' ---
-- Guard the whole block on the ENUM still allowing 'pottery'. Widen the ENUM,
-- repoint the rows, then narrow it. No-op once the ENUM no longer lists 'pottery'.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcement_links' AND COLUMN_NAME = 'entity_type' AND COLUMN_TYPE LIKE '%''pottery''%');
SET @sql = IF(@c = 1, "ALTER TABLE announcement_links MODIFY COLUMN entity_type ENUM('event','pottery','piece') NOT NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@c = 1, "UPDATE announcement_links SET entity_type = 'piece' WHERE entity_type = 'pottery'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = IF(@c = 1, "ALTER TABLE announcement_links MODIFY COLUMN entity_type ENUM('event','piece') NOT NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
