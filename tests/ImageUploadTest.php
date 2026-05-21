<?php

namespace Tests;

use ImageUpload;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the GIF-rejection fix in ImageUpload::upload(). Before the fix, a GIF
 * passed the MIME allowlist but then crashed in createThumbnail() because the
 * image-type switch had no IMAGETYPE_GIF branch and fell through to
 * imagecreatefromjpeg(), which returns false on a real GIF and triggers a
 * fatal TypeError inside imagecopyresampled().
 */
final class ImageUploadTest extends TestCase {

    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $f) {
            if (\file_exists($f)) @\unlink($f);
        }
        $this->tempFiles = [];
    }

    private function requireGd(?string $function = null): void {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not loaded');
        }
        if ($function !== null && !\function_exists($function)) {
            $this->markTestSkipped("GD lacks $function support in this build");
        }
    }

    private function makeGifFile(): string {
        $path = tempnam(sys_get_temp_dir(), 'imgupload_gif_');
        $img  = \imagecreatetruecolor(2, 2);
        \imagegif($img, $path);
        \imagedestroy($img);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function makeJpegFile(): string {
        $path = tempnam(sys_get_temp_dir(), 'imgupload_jpg_');
        $img  = \imagecreatetruecolor(2, 2);
        \imagejpeg($img, $path);
        \imagedestroy($img);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function fakeUploadArray(string $tmpPath, string $name, ?int $sizeOverride = null): array {
        return [
            'name'     => $name,
            'type'     => '',
            'tmp_name' => $tmpPath,
            'error'    => UPLOAD_ERR_OK,
            'size'     => $sizeOverride ?? \filesize($tmpPath),
        ];
    }

    public function test_gif_upload_is_rejected_by_mime_allowlist(): void {
        $this->requireGd('imagegif');
        $file = $this->fakeUploadArray($this->makeGifFile(), 'evil.gif');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid file type/i');
        ImageUpload::upload($file);
    }

    public function test_jpeg_passes_mime_allowlist(): void {
        // JPEG should clear MIME validation. We can't actually exercise
        // move_uploaded_file() in a unit test (it only accepts files moved by
        // the SAPI), so reaching the "Failed to move uploaded file" branch
        // proves we got past every validation gate.
        $this->requireGd('imagejpeg');
        $file = $this->fakeUploadArray($this->makeJpegFile(), 'ok.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to move uploaded file/i');
        ImageUpload::upload($file);
    }

    public function test_oversized_file_is_rejected_before_mime_check(): void {
        // The size guard fires before finfo_open, so we don't need GD or even
        // a real file to exercise it.
        $file = [
            'name'     => 'big.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => '/tmp/does-not-matter',
            'error'    => UPLOAD_ERR_OK,
            'size'     => MAX_IMAGE_SIZE + 1,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/too large/i');
        ImageUpload::upload($file);
    }

    public function test_upload_error_code_is_propagated(): void {
        $file = [
            'name'     => 'x.jpg',
            'type'     => 'image/jpeg',
            'tmp_name' => '',
            'error'    => UPLOAD_ERR_INI_SIZE,
            'size'     => 0,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Upload error/i');
        ImageUpload::upload($file);
    }

    private function makeLargeJpegFile(int $w, int $h): string {
        $path = tempnam(sys_get_temp_dir(), 'imgupload_large_') . '.jpg';
        $img  = \imagecreatetruecolor($w, $h);
        // Fill with a non-uniform pattern so resize artifacts are detectable.
        $colors = [
            \imagecolorallocate($img, 200, 80, 40),
            \imagecolorallocate($img, 40, 80, 200),
        ];
        for ($x = 0; $x < $w; $x += 100) {
            \imagefilledrectangle($img, $x, 0, $x + 50, $h, $colors[($x / 100) % 2]);
        }
        \imagejpeg($img, $path, 85);
        \imagedestroy($img);
        $this->tempFiles[] = $path;
        return $path;
    }

    public function test_resize_shrinks_oversized_jpeg_in_place(): void {
        $this->requireGd('imagejpeg');
        $path = $this->makeLargeJpegFile(3200, 2400);
        [$origW, $origH] = \getimagesize($path);
        $this->assertSame(3200, $origW);

        $changed = ImageUpload::resizeOriginalIfLarger($path, 1600);
        $this->assertTrue($changed);

        [$newW, $newH] = \getimagesize($path);
        $this->assertSame(1600, $newW, 'longer edge should become exactly 1600');
        $this->assertSame(1200, $newH, 'aspect ratio preserved (3200×2400 → 1600×1200)');
    }

    public function test_resize_leaves_already_small_image_alone(): void {
        $this->requireGd('imagejpeg');
        $path = $this->makeLargeJpegFile(800, 600);
        $mtimeBefore = filemtime($path);
        clearstatcache(true, $path);

        $changed = ImageUpload::resizeOriginalIfLarger($path, 1600);
        $this->assertFalse($changed, 'small image should not be touched');

        clearstatcache(true, $path);
        $this->assertSame($mtimeBefore, filemtime($path), 'file should not have been rewritten');
    }

    public function test_resize_returns_false_for_missing_file(): void {
        $this->assertFalse(ImageUpload::resizeOriginalIfLarger('/tmp/definitely-not-a-real-file.jpg', 1600));
    }

    public function test_resize_returns_false_for_zero_max_dimension(): void {
        $this->requireGd('imagejpeg');
        $path = $this->makeJpegFile();
        $this->assertFalse(ImageUpload::resizeOriginalIfLarger($path, 0));
    }
}
