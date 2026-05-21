-- 021_image_alt_text.sql
-- Admin-editable alt text for the primary image on the 3 image-heavy content
-- types. Public pages fall back to the row's title when alt_text is null,
-- preserving today's behaviour for existing rows.
--
-- Safe to re-apply: each ADD COLUMN is gated on INFORMATION_SCHEMA.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pottery' AND COLUMN_NAME = 'alt_text');
SET @sql = IF(@c = 0,
    'ALTER TABLE pottery ADD COLUMN alt_text VARCHAR(500) NULL AFTER image_thumb',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'alt_text');
SET @sql = IF(@c = 0,
    'ALTER TABLE products ADD COLUMN alt_text VARCHAR(500) NULL AFTER image_path',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'image_alt');
SET @sql = IF(@c = 0,
    'ALTER TABLE announcements ADD COLUMN image_alt VARCHAR(500) NULL AFTER image_thumb',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
