<?php
// includes/ImageRotateHandler.php
//
// Shared logic for the pottery + shop "rotate one gallery image" JSON
// endpoints. Loads the stored original + thumb (LOCAL files under
// UPLOAD_PATH), turns each a quarter turn, and writes them under NEW filenames
// in the same subdir. The CMS serves /uploads/<path> with no cache-buster, so
// reusing the filename would show a stale browser-cached image — a new
// filename means a new URL means the rotated image shows immediately. The
// gallery row + (if this is the cover) the parent's cached image columns are
// repointed, and the superseded files are deleted best-effort. Mirrors
// ImageDeleteHandler's config shape + whitelist discipline.

class ImageRotateHandler {

    private const SAFE_IMAGES_TABLES  = ['piece_images', 'product_images'];
    private const SAFE_PARENT_TABLES  = ['piece',          'products'];
    private const SAFE_PARENT_ID_COLS = ['piece_id',     'product_id'];

    /**
     * @param array $cfg {
     *   imageId:           int
     *   parentId:          int
     *   imagesTable:       string  'piece_images' | 'product_images'
     *   parentIdColumn:    string  'piece_id' | 'product_id'
     *   parentTable:       string  'pottery' | 'products'
     *   parentThumbColumn: ?string 'image_thumb' (pottery) | null (products)
     *   direction:         string  'cw' (default) | 'ccw'
     * }
     * @return array ['success'=>bool, 'error'=>?string, 'image_url'=>?string]
     */
    public static function rotate(array $cfg): array {
        $imageId        = (int) ($cfg['imageId'] ?? 0);
        $parentId       = (int) ($cfg['parentId'] ?? 0);
        $imagesTable    = (string) ($cfg['imagesTable'] ?? '');
        $parentIdColumn = (string) ($cfg['parentIdColumn'] ?? '');
        $parentTable    = (string) ($cfg['parentTable'] ?? '');
        $parentThumbCol = $cfg['parentThumbColumn'] ?? null;
        $clockwise      = (($cfg['direction'] ?? 'cw') !== 'ccw');

        if (!in_array($imagesTable, self::SAFE_IMAGES_TABLES, true)
            || !in_array($parentTable, self::SAFE_PARENT_TABLES, true)
            || !in_array($parentIdColumn, self::SAFE_PARENT_ID_COLS, true)) {
            return ['success' => false, 'error' => 'Invalid handler config', 'image_url' => null];
        }
        if (!$imageId || !$parentId) {
            return ['success' => false, 'error' => 'Invalid parameters', 'image_url' => null];
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

        $newPath = self::rotateStored((string) $oldPath, $clockwise);
        if ($newPath === null) {
            return ['success' => false, 'error' => 'Could not rotate image', 'image_url' => null];
        }

        // Build the thumb under the matching 'thumb_<newbase>' name so the CMS's
        // cleanup convention holds (ImageUpload::delete removes the thumb_ sibling
        // of image_path; ImageDeleteHandler relies on that). Prefer rotating the
        // existing small thumb to preserve its size; fall back to regenerating a
        // fresh thumb from the rotated original, then to a plain copy.
        $newThumb  = self::thumbPathFor($newPath);
        $thumbFull = UPLOAD_PATH . $newThumb;
        $madeThumb = false;
        if (!empty($oldThumb) && is_file(UPLOAD_PATH . $oldThumb)) {
            $madeThumb = ImageUpload::rotateImageFile(UPLOAD_PATH . $oldThumb, $thumbFull, $clockwise);
        }
        if (!$madeThumb && !ImageUpload::writeThumbnail(UPLOAD_PATH . $newPath, $thumbFull)) {
            @copy(UPLOAD_PATH . $newPath, $thumbFull);
        }

        // Repoint the gallery row.
        Database::query(
            "UPDATE {$imagesTable} SET image_path = ?, image_thumb = ? WHERE id = ?",
            [$newPath, $newThumb, $imageId]
        );

        // If this is the cover image, repoint the parent's cached columns too.
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

        // Drop the superseded files best-effort (a failed cleanup must not undo
        // the successful rotate). ImageUpload::delete also removes the thumb_
        // sibling, so deleting the original path covers its thumb.
        ImageUpload::delete((string) $oldPath);
        if (!empty($oldThumb) && $oldThumb !== $oldPath) {
            ImageUpload::delete((string) $oldThumb);
        }

        return [
            'success'   => true,
            'error'     => null,
            'image_url' => UPLOAD_URL . ($newThumb ?? $newPath),
        ];
    }

    /**
     * Rotate the file at $oldPath (subdir-relative, under UPLOAD_PATH) a quarter
     * turn and write the result under a fresh filename (same subdir + extension,
     * new random basename). Returns the new subdir-relative path, or null on any
     * failure (leaving the original intact).
     */
    private static function rotateStored(string $oldPath, bool $clockwise): ?string {
        $srcFull = UPLOAD_PATH . $oldPath;
        if (!is_file($srcFull)) {
            return null;
        }
        $newPath = self::newPathFrom($oldPath);
        $dstFull = UPLOAD_PATH . $newPath;
        $dir     = dirname($dstFull);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (!ImageUpload::rotateImageFile($srcFull, $dstFull, $clockwise)) {
            if (is_file($dstFull)) @unlink($dstFull);
            return null;
        }
        return $newPath;
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
