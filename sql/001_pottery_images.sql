-- ============================================================
-- Patch 001: Multiple images per pottery piece
-- Safe to re-apply: the CREATE uses IF NOT EXISTS and the INSERT
-- has a NOT EXISTS guard so re-runs don't duplicate the primary
-- image row for any pottery piece.
-- ============================================================

-- 1. New images table
CREATE TABLE IF NOT EXISTS pottery_images (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    pottery_id  INT NOT NULL,
    image_path  TEXT NOT NULL,
    image_thumb TEXT,
    sort_order  INT DEFAULT 0,
    is_primary  TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pottery_id) REFERENCES pottery(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Migrate all existing primary images into the new table.
--    The NOT EXISTS subquery skips any pottery row that already
--    has a primary image, so re-running this migration is a no-op.
INSERT INTO pottery_images (pottery_id, image_path, image_thumb, sort_order, is_primary)
SELECT p.id, p.image_path, p.image_thumb, 0, 1
FROM pottery p
WHERE p.image_path IS NOT NULL
  AND p.image_path != ''
  AND NOT EXISTS (
      SELECT 1 FROM pottery_images pi
      WHERE pi.pottery_id = p.id AND pi.is_primary = 1
  );
