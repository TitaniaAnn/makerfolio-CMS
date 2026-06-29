<?php

namespace Tests;

use ImageUpload;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure file->file GD helpers backing the admin image rotate + crop
 * feature: ImageUpload::rotateImageFile and ImageUpload::cropImageFile. These
 * touch no DB / storage, so they unit-test cleanly. Each GD-dependent test
 * skips (rather than fails) when GD or a given codec is unavailable.
 */
final class ImageTransformTest extends TestCase {

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

    private function makeSizedImage(int $w, int $h, string $ext): string {
        $path = tempnam(sys_get_temp_dir(), 'imgxf_') . '.' . $ext;
        $img  = \imagecreatetruecolor($w, $h);
        // A distinct top-left pixel + fill so the turn direction is observable.
        \imagesetpixel($img, 0, 0, \imagecolorallocate($img, 250, 10, 10));
        \imagefill($img, 1, 1, \imagecolorallocate($img, 20, 20, 240));
        switch ($ext) {
            case 'png':  \imagepng($img, $path);  break;
            case 'webp': \imagewebp($img, $path); break;
            default:     \imagejpeg($img, $path); break;
        }
        \imagedestroy($img);
        $this->tempFiles[] = $path;
        return $path;
    }

    // -- rotateImageFile --

    public function test_rotate_swaps_dimensions_clockwise_jpeg(): void {
        $this->requireGd('imagejpeg');
        $src = $this->makeSizedImage(6, 2, 'jpg');           // 6 wide, 2 tall
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_out_') . '.jpg';
        $this->tempFiles[] = $dst;

        $this->assertTrue(ImageUpload::rotateImageFile($src, $dst, true));
        [$w, $h] = \getimagesize($dst);
        $this->assertSame(2, $w, 'a quarter turn swaps width/height');
        $this->assertSame(6, $h);
    }

    public function test_rotate_swaps_dimensions_counterclockwise_png(): void {
        $this->requireGd('imagepng');
        $src = $this->makeSizedImage(2, 8, 'png');           // 2 wide, 8 tall
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_out_') . '.png';
        $this->tempFiles[] = $dst;

        $this->assertTrue(ImageUpload::rotateImageFile($src, $dst, false));
        [$w, $h] = \getimagesize($dst);
        $this->assertSame(8, $w);
        $this->assertSame(2, $h);
    }

    public function test_rotate_in_place_overwrites_source(): void {
        $this->requireGd('imagejpeg');
        $path = $this->makeSizedImage(10, 4, 'jpg');
        $this->assertTrue(ImageUpload::rotateImageFile($path, $path, true));
        [$w, $h] = \getimagesize($path);
        $this->assertSame(4, $w);
        $this->assertSame(10, $h);
    }

    public function test_rotate_twice_returns_to_original_dimensions(): void {
        $this->requireGd('imagejpeg');
        $path = $this->makeSizedImage(9, 3, 'jpg');
        ImageUpload::rotateImageFile($path, $path, true);    // 3x9
        ImageUpload::rotateImageFile($path, $path, true);    // back to 9x3
        [$w, $h] = \getimagesize($path);
        $this->assertSame(9, $w);
        $this->assertSame(3, $h);
    }

    public function test_rotate_returns_false_for_missing_file(): void {
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_out_');
        $this->tempFiles[] = $dst;
        $this->assertFalse(ImageUpload::rotateImageFile('/tmp/not-a-real-image.jpg', $dst, true));
    }

    public function test_rotate_returns_false_for_non_image(): void {
        $src = tempnam(sys_get_temp_dir(), 'imgxf_txt_');
        file_put_contents($src, 'this is not an image');
        $this->tempFiles[] = $src;
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_out_');
        $this->tempFiles[] = $dst;
        $this->assertFalse(ImageUpload::rotateImageFile($src, $dst, true));
    }

    // -- cropImageFile --

    public function test_crop_produces_exact_region_dimensions(): void {
        $this->requireGd('imagejpeg');
        $src = $this->makeSizedImage(20, 16, 'jpg');
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_crop_') . '.jpg';
        $this->tempFiles[] = $dst;

        $this->assertTrue(ImageUpload::cropImageFile($src, $dst, 4, 3, 8, 6));
        [$w, $h] = \getimagesize($dst);
        $this->assertSame(8, $w);
        $this->assertSame(6, $h);
    }

    public function test_crop_clamps_region_to_image_bounds(): void {
        $this->requireGd('imagejpeg');
        $src = $this->makeSizedImage(10, 10, 'jpg');
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_crop_') . '.jpg';
        $this->tempFiles[] = $dst;

        // Asking for 8x8 starting at (5,5) exceeds the image — clamps to 5x5.
        $this->assertTrue(ImageUpload::cropImageFile($src, $dst, 5, 5, 8, 8));
        [$w, $h] = \getimagesize($dst);
        $this->assertSame(5, $w);
        $this->assertSame(5, $h);
    }

    public function test_crop_preserves_png_format(): void {
        $this->requireGd('imagepng');
        $src = $this->makeSizedImage(12, 12, 'png');
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_crop_') . '.png';
        $this->tempFiles[] = $dst;

        $this->assertTrue(ImageUpload::cropImageFile($src, $dst, 2, 2, 6, 6));
        $info = \getimagesize($dst);
        $this->assertSame(IMAGETYPE_PNG, $info[2], 'crop keeps the source format');
        $this->assertSame(6, $info[0]);
    }

    public function test_crop_returns_false_for_zero_area(): void {
        $this->requireGd('imagejpeg');
        $src = $this->makeSizedImage(10, 10, 'jpg');
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_crop_') . '.jpg';
        $this->tempFiles[] = $dst;
        $this->assertFalse(ImageUpload::cropImageFile($src, $dst, 0, 0, 0, 5));
    }

    public function test_crop_returns_false_for_negative_area(): void {
        $this->requireGd('imagejpeg');
        $src = $this->makeSizedImage(10, 10, 'jpg');
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_crop_') . '.jpg';
        $this->tempFiles[] = $dst;
        $this->assertFalse(ImageUpload::cropImageFile($src, $dst, 0, 0, 5, -3));
    }

    public function test_crop_returns_false_for_non_image(): void {
        $src = tempnam(sys_get_temp_dir(), 'imgxf_txt_');
        file_put_contents($src, 'not an image');
        $this->tempFiles[] = $src;
        $dst = tempnam(sys_get_temp_dir(), 'imgxf_crop_');
        $this->tempFiles[] = $dst;
        $this->assertFalse(ImageUpload::cropImageFile($src, $dst, 0, 0, 4, 4));
    }
}
