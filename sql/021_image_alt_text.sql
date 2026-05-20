-- 021_image_alt_text.sql
-- Admin-editable alt text for the primary image on the 3 image-heavy content
-- types. Public pages fall back to the row's title when alt_text is null,
-- preserving today's behaviour for existing rows.

ALTER TABLE pottery       ADD COLUMN alt_text  VARCHAR(500) NULL AFTER image_thumb;
ALTER TABLE products      ADD COLUMN alt_text  VARCHAR(500) NULL AFTER image_path;
ALTER TABLE announcements ADD COLUMN image_alt VARCHAR(500) NULL AFTER image_thumb;
