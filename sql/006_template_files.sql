-- 006_template_files.sql
-- Split pottery_templates into a parent row + a per-file child table.
-- Safe to re-apply: the INSERT and each DROP COLUMN are gated on whether
-- the legacy single-file columns still exist on pottery_templates.

-- Create per-file table
CREATE TABLE IF NOT EXISTS pottery_template_files (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    file_path   VARCHAR(500) NOT NULL,
    file_name   VARCHAR(255) NOT NULL,
    file_size   INT          DEFAULT 0,
    file_ext    VARCHAR(10)  DEFAULT '',
    label       VARCHAR(255) DEFAULT '',
    sort_order  INT          DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES pottery_templates(id) ON DELETE CASCADE
);

-- Migrate existing single-file rows into the new table — only if the
-- legacy file_path column is still present (i.e. this hasn't run yet).
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'pottery_templates'
            AND COLUMN_NAME = 'file_path');
SET @sql = IF(@c > 0,
    'INSERT INTO pottery_template_files (template_id, file_path, file_name, file_size, file_ext, sort_order) SELECT id, file_path, file_name, file_size, file_ext, 0 FROM pottery_templates WHERE file_path IS NOT NULL AND LENGTH(file_path) > 0',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop the legacy file columns one by one, each guarded by an
-- existence check so a re-run is a no-op.
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_templates' AND COLUMN_NAME = 'file_path');
SET @sql = IF(@c > 0, 'ALTER TABLE pottery_templates DROP COLUMN file_path', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_templates' AND COLUMN_NAME = 'file_name');
SET @sql = IF(@c > 0, 'ALTER TABLE pottery_templates DROP COLUMN file_name', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_templates' AND COLUMN_NAME = 'file_size');
SET @sql = IF(@c > 0, 'ALTER TABLE pottery_templates DROP COLUMN file_size', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery_templates' AND COLUMN_NAME = 'file_ext');
SET @sql = IF(@c > 0, 'ALTER TABLE pottery_templates DROP COLUMN file_ext', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
