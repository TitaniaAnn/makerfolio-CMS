<?php
// includes/ImageUpload.php

class ImageUpload {

    public static function upload(array $file, string $subdir = ''): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error: ' . $file['error']);
        }
        if ($file['size'] > MAX_IMAGE_SIZE) {
            throw new RuntimeException('File too large (max 10MB)');
        }

        // GIF is intentionally not allowed: createThumbnail has no IMAGETYPE_GIF
        // branch, and pottery photos don't need animation. The admin UI also
        // advertises only JPG/PNG/WebP.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            throw new RuntimeException('Invalid file type. Use JPG, PNG, or WebP.');
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('pottery_', true) . '.' . strtolower($ext);
        $dir      = UPLOAD_PATH . ($subdir ? rtrim($subdir, '/') . '/' : '');

        if (!is_dir($dir)) {
            // Mode 0755 (not 0750) is intentional. On most shared hosts the
            // PHP-FPM worker runs as the FTP user, but static assets in
            // /uploads/ are served by Apache running as `www-data`/`nobody`,
            // a *different* user that doesn't share the FTP user's group.
            // Tightening to 0750 (group-only) breaks image serving on those
            // hosts because the webserver can no longer read the directory.
            // The .htaccess in public/uploads/ blocks .php execution as the
            // real defense; the directory mode just controls who can read.
            mkdir($dir, 0755, true);
        }

        $destination = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Failed to move uploaded file');
        }

        // Resize original in place if it exceeds MAX_ORIGINAL_DIMENSION on the
        // longer edge. iPhone-sized originals (4 MB JPEG) become ~400 KB without
        // visible loss for portfolio display. Defined in config.php; defaults
        // to 1600px when missing for backwards compat.
        if (defined('MAX_ORIGINAL_DIMENSION') && MAX_ORIGINAL_DIMENSION > 0) {
            self::resizeOriginalIfLarger($destination, (int)MAX_ORIGINAL_DIMENSION);
        }

        // Generate thumbnail
        $thumbFilename = 'thumb_' . $filename;
        $thumbPath     = $dir . $thumbFilename;
        self::createThumbnail($destination, $thumbPath);

        $urlBase = UPLOAD_URL . ($subdir ? rtrim($subdir, '/') . '/' : '');
        return [
            'path'  => ($subdir ? rtrim($subdir, '/') . '/' : '') . $filename,
            'thumb' => ($subdir ? rtrim($subdir, '/') . '/' : '') . $thumbFilename,
            'url'   => $urlBase . $filename,
            'thumb_url' => $urlBase . $thumbFilename,
        ];
    }

    private static function createThumbnail(string $src, string $dest): void {
        [$origW, $origH, $type] = getimagesize($src);

        $ratio  = min(THUMB_WIDTH / $origW, THUMB_HEIGHT / $origH);
        $newW   = (int) ($origW * $ratio);
        $newH   = (int) ($origH * $ratio);

        $thumb = imagecreatetruecolor($newW, $newH);

        switch ($type) {
            case IMAGETYPE_JPEG:
                $src_img = imagecreatefromjpeg($src);
                break;
            case IMAGETYPE_PNG:
                $src_img = imagecreatefrompng($src);
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                break;
            case IMAGETYPE_WEBP:
                $src_img = imagecreatefromwebp($src);
                break;
            default:
                $src_img = imagecreatefromjpeg($src);
        }

        imagecopyresampled($thumb, $src_img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        switch ($type) {
            case IMAGETYPE_PNG:
                imagepng($thumb, $dest, 8);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($thumb, $dest, 85);
                break;
            default:
                imagejpeg($thumb, $dest, 85);
        }

        imagedestroy($thumb);
        imagedestroy($src_img);
    }

    /**
     * Resize an image file in place if its longer edge exceeds $maxDimension.
     * Preserves the original format (JPG/PNG/WebP). Aspect ratio kept. Silent
     * no-op for already-small images or unsupported formats. Public + static
     * so it can be unit-tested.
     */
    public static function resizeOriginalIfLarger(string $path, int $maxDimension): bool
    {
        if ($maxDimension <= 0 || !is_file($path)) return false;
        $info = @getimagesize($path);
        if (!$info) return false;
        [$w, $h, $type] = $info;

        $longer = max($w, $h);
        if ($longer <= $maxDimension) return false;

        $ratio = $maxDimension / $longer;
        $newW  = (int)round($w * $ratio);
        $newH  = (int)round($h * $ratio);

        switch ($type) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($path); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($path);  break;
            case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($path); break;
            default: return false; // Unsupported — leave the original alone.
        }
        if (!$src) return false;

        $dst = imagecreatetruecolor($newW, $newH);
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

        switch ($type) {
            case IMAGETYPE_PNG:  imagepng($dst,  $path, 8);  break;
            case IMAGETYPE_WEBP: imagewebp($dst, $path, 85); break;
            default:             imagejpeg($dst, $path, 88);
        }
        imagedestroy($dst);
        imagedestroy($src);
        return true;
    }

    /**
     * Rotate an image file a quarter turn, preserving its format (JPG/PNG/WebP)
     * and alpha. $clockwise=true turns it 90° clockwise, false 90° counter-
     * clockwise. Reads $src, writes $dst (may equal $src for in-place). Returns
     * false (leaving $dst untouched) for unreadable / unsupported files.
     * Public + static so it can be unit-tested without GD-less paths.
     */
    public static function rotateImageFile(string $src, string $dst, bool $clockwise): bool
    {
        if (!is_file($src)) return false;
        $info = @getimagesize($src);
        if (!$info) return false;
        $type = $info[2];

        switch ($type) {
            case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($src); break;
            case IMAGETYPE_PNG:  $img = @imagecreatefrompng($src);  break;
            case IMAGETYPE_WEBP: $img = @imagecreatefromwebp($src); break;
            default: return false; // Unsupported — leave the file alone.
        }
        if (!$img) return false;

        // GD's imagerotate angle is counter-clockwise, so a clockwise quarter
        // turn is 270°. Quarter turns add no new canvas area, so the fill
        // colour (0) is never visible.
        $rotated = imagerotate($img, $clockwise ? 270 : 90, 0);
        imagedestroy($img);
        if (!$rotated) return false;

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
        }

        switch ($type) {
            case IMAGETYPE_PNG:  $ok = imagepng($rotated,  $dst, 8);  break;
            case IMAGETYPE_WEBP: $ok = imagewebp($rotated, $dst, 85); break;
            default:             $ok = imagejpeg($rotated, $dst, 88);
        }
        imagedestroy($rotated);
        return (bool) $ok;
    }

    /**
     * Crop an image to the rectangle (x, y, w, h) given in SOURCE pixels,
     * preserving format (JPG/PNG/WebP) + alpha. The rect is clamped to the
     * image bounds, so out-of-range coordinates degrade gracefully rather than
     * failing. Reads $src, writes $dst (may equal $src). Returns false on
     * unreadable / unsupported input or a zero-area rect. Public + static for
     * unit testing.
     */
    public static function cropImageFile(string $src, string $dst, int $x, int $y, int $w, int $h): bool
    {
        if ($w <= 0 || $h <= 0 || !is_file($src)) return false;
        $info = @getimagesize($src);
        if (!$info) return false;
        [$iw, $ih, $type] = $info;

        // Clamp to bounds.
        $x = max(0, min($x, $iw - 1));
        $y = max(0, min($y, $ih - 1));
        $w = min($w, $iw - $x);
        $h = min($h, $ih - $y);
        if ($w <= 0 || $h <= 0) return false;

        switch ($type) {
            case IMAGETYPE_JPEG: $srcImg = @imagecreatefromjpeg($src); break;
            case IMAGETYPE_PNG:  $srcImg = @imagecreatefrompng($src);  break;
            case IMAGETYPE_WEBP: $srcImg = @imagecreatefromwebp($src); break;
            default: return false;
        }
        if (!$srcImg) return false;

        $dstImg = imagecreatetruecolor($w, $h);
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
        }
        imagecopy($dstImg, $srcImg, 0, 0, $x, $y, $w, $h);
        imagedestroy($srcImg);

        switch ($type) {
            case IMAGETYPE_PNG:  $ok = imagepng($dstImg,  $dst, 8);  break;
            case IMAGETYPE_WEBP: $ok = imagewebp($dstImg, $dst, 85); break;
            default:             $ok = imagejpeg($dstImg, $dst, 88);
        }
        imagedestroy($dstImg);
        return (bool) $ok;
    }

    /**
     * (Re)generate a thumbnail for $src into $dst at the configured THUMB_*
     * size. Public wrapper around the upload path's private thumbnailer so the
     * crop handler can rebuild a thumb after the aspect ratio changes. Returns
     * false on bad input rather than throwing.
     */
    public static function writeThumbnail(string $src, string $dst): bool
    {
        if (!is_file($src) || !@getimagesize($src)) return false;
        try {
            self::createThumbnail($src, $dst);
        } catch (\Throwable $e) {
            return false;
        }
        return is_file($dst);
    }

    public static function delete(string $path): void {
        $full = UPLOAD_PATH . $path;
        if (file_exists($full)) unlink($full);

        // Also delete thumb
        $dir      = dirname($full);
        $filename = basename($full);
        $thumb    = $dir . '/thumb_' . $filename;
        if (file_exists($thumb)) unlink($thumb);
    }
}
