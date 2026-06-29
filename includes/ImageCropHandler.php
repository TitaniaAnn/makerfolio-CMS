<?php
// includes/ImageCropHandler.php
//
// Shared logic for the pottery + shop "crop one gallery image" JSON endpoints.
// Crops the stored original (a LOCAL file under UPLOAD_PATH) to the caller's
// rectangle (in source pixels), REGENERATES the thumbnail from the cropped
// original (crop changes the aspect ratio, so the old thumb can't just be
// re-cropped), and writes both under NEW filenames in the same subdir. The CMS
// serves /uploads/<path> with no cache-buster, so a new filename is the
// cleanest way to make the edit show immediately. Repoints the gallery row +
// the parent's cached cover columns when this is the primary, and deletes the
// superseded files best-effort. Mirrors ImageRotateHandler / ImageDeleteHandler.

class ImageCropHandler {

    private const SAFE_IMAGES_TABLES  = ['pottery_images', 'product_images'];
    private const SAFE_PARENT_TABLES  = ['pottery',        'products'];
    private const SAFE_PARENT_ID_COLS = ['pottery_id',     'product_id'];

    /**
     * @param array $cfg {
     *   imageId, parentId, imagesTable, parentIdColumn, parentTable,
     *   parentThumbColumn ('image_thumb' for pottery, null for products),
     *   x, y, w, h (crop rect in SOURCE pixels of the original image)
     * }
     * @return array ['success'=>bool, 'error'=>?string, 'image_url'=>?string, 'full_url'=>?string]
     */
    public static function crop(array $cfg): array {
        $imageId        = (int) ($cfg['imageId'] ?? 0);
        $parentId       = (int) ($cfg['parentId'] ?? 0);
        $imagesTable    = (string) ($cfg['imagesTable'] ?? '');
        $parentIdColumn = (string) ($cfg['parentIdColumn'] ?? '');
        $parentTable    = (string) ($cfg['parentTable'] ?? '');
        $parentThumbCol = $cfg['parentThumbColumn'] ?? null;
        $x = (int) ($cfg['x'] ?? 0);
        $y = (int) ($cfg['y'] ?? 0);
        $w = (int) ($cfg['w'] ?? 0);
        $h = (int) ($cfg['h'] ?? 0);

        if (!in_array($imagesTable, self::SAFE_IMAGES_TABLES, true)
            || !in_array($parentTable, self::SAFE_PARENT_TABLES, true)
            || !in_array($parentIdColumn, self::SAFE_PARENT_ID_COLS, true)) {
            return ['success' => false, 'error' => 'Invalid handler config', 'image_url' => null];
        }
        if (!$imageId || !$parentId) {
            return ['success' => false, 'error' => 'Invalid parameters', 'image_url' => null];
        }
        if ($w <= 0 || $h <= 0) {
            return ['success' => false, 'error' => 'Invalid crop region', 'image_url' => null];
        }
        if (!function_exists('imagecreatetruecolor')) {
            return ['success' => false, 'error' => 'Image processing not available', 'image_url' => null];
        }

        $img = Database::fetchOne(
            "SELECT * FROM {$imagesTable} WHERE id = ? AND {$parentIdColumn} = ?",
            [$imageId, $parentId]
        );
        if (!$img) {
            return ['success' => false, 'error' => 'Image not found', 'image_url' => null];
        }

        $oldPath  = $img['image_path']  ?? null;
        $oldThumb = $img['image_thumb'] ?? null;
        if (empty($oldPath)) {
            return ['success' => false, 'error' => 'Image has no stored file', 'image_url' => null];
        }

        $written = self::cropStored((string) $oldPath, $x, $y, $w, $h);
        if ($written === null) {
            return ['success' => false, 'error' => 'Could not crop image', 'image_url' => null];
        }
        [$newPath, $newThumb] = $written;

        Database::query(
            "UPDATE {$imagesTable} SET image_path = ?, image_thumb = ? WHERE id = ?",
            [$newPath, $newThumb, $imageId]
        );

        if ((int) ($img['is_primary'] ?? 0) === 1) {
            if ($parentThumbCol) {
                Database::query(
                    "UPDATE {$parentTable} SET image_path = ?, {$parentThumbCol} = ? WHERE id = ?",
                    [$newPath, $newThumb, $parentId]
                );
            } else {
                Database::query(
                    "UPDATE {$parentTable} SET image_path = ? WHERE id = ?",
                    [$newPath, $parentId]
                );
            }
        }

        ImageUpload::delete((string) $oldPath);
        if (!empty($oldThumb) && $oldThumb !== $oldPath) {
            ImageUpload::delete((string) $oldThumb);
        }

        return [
            'success'   => true,
            'error'     => null,
            'image_url' => UPLOAD_URL . ($newThumb ?? $newPath), // thumb, for the gallery tile
            'full_url'  => UPLOAD_URL . $newPath,                 // new original, for a follow-up crop
        ];
    }

    /**
     * Crop the file at $oldPath (subdir-relative, under UPLOAD_PATH) to the
     * pixel rect and regenerate its thumbnail. Writes both under fresh filenames
     * in the same subdir. Returns [newPath, newThumb] (subdir-relative) or null
     * on any failure (leaving the original intact).
     */
    private static function cropStored(string $oldPath, int $x, int $y, int $w, int $h): ?array {
        $srcFull = UPLOAD_PATH . $oldPath;
        if (!is_file($srcFull)) {
            return null;
        }

        $newPath  = self::newPathFrom($oldPath);
        $newThumb = self::thumbPathFor($newPath);
        $cropFull = UPLOAD_PATH . $newPath;
        $thumbFull = UPLOAD_PATH . $newThumb;
        $dir      = dirname($cropFull);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!ImageUpload::cropImageFile($srcFull, $cropFull, $x, $y, $w, $h)) {
            if (is_file($cropFull)) @unlink($cropFull);
            return null;
        }

        // Rebuild the thumb from the cropped original; fall back to copying the
        // cropped original itself if thumbnailing fails (better than a stale
        // thumb pointing at the old aspect ratio).
        if (!ImageUpload::writeThumbnail($cropFull, $thumbFull)) {
            @copy($cropFull, $thumbFull);
        }

        return [$newPath, $newThumb];
    }

    /**
     * Same directory + extension as $oldPath, new random basename matching the
     * CMS upload naming (uniqid('pottery_', true)).
     */
    private static function newPathFrom(string $oldPath): string {
        $slash = strrpos($oldPath, '/');
        $dir   = $slash === false ? '' : substr($oldPath, 0, $slash + 1);
        $ext   = pathinfo($oldPath, PATHINFO_EXTENSION);
        $base  = uniqid('pottery_', true);
        return $dir . $base . ($ext !== '' ? '.' . strtolower($ext) : '');
    }

    /** 'thumb_' sibling path for a freshly written original. */
    private static function thumbPathFor(string $path): string {
        $slash = strrpos($path, '/');
        $dir   = $slash === false ? '' : substr($path, 0, $slash + 1);
        $file  = $slash === false ? $path : substr($path, $slash + 1);
        return $dir . 'thumb_' . $file;
    }
}
