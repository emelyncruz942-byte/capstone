<?php

namespace Tests\Unit;

use App\Support\SquareAvatarImage;
use Tests\TestCase;

class SquareAvatarImageTest extends TestCase
{
    public function test_saved_avatars_are_compressed_unity_compatible_jpegs_with_no_transparent_padding(): void
    {
        $source = imagecreatetruecolor(1200, 800);
        imagealphablending($source, false); imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 50, 80, 150, 64));
        $path = $this->path(); imagepng($source, $path); imagedestroy($source);
        $encoded = SquareAvatarImage::encode($path, 'image/png', 'image/jpeg');
        $this->assertIsString($encoded);
        $this->assertLessThanOrEqual(SquareAvatarImage::MAX_COMPRESSED_BYTES, strlen($encoded));
        $dimensions = getimagesizefromstring($encoded);
        $this->assertSame('image/jpeg', $dimensions['mime']);
        $this->assertSame([512, 512], [$dimensions[0], $dimensions[1]]);
        $decoded = imagecreatefromstring($encoded);
        $this->assertSame(0, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
        imagedestroy($decoded);
    }
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) @unlink($path);
        parent::tearDown();
    }

    private function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mathverse-avatar-');
        $this->paths[] = $path;
        return $path;
    }

    public function test_landscape_upload_is_centre_cropped_to_a_bounded_square_without_modifying_its_source(): void
    {
        $source = imagecreatetruecolor(1200, 800);
        imagefill($source, 0, 0, imagecolorallocate($source, 20, 30, 180));
        imagefilledrectangle($source, 200, 0, 999, 799, imagecolorallocate($source, 190, 40, 30));
        $path = $this->path(); imagepng($source, $path); imagedestroy($source);
        $original = hash_file('sha256', $path);
        $encoded = SquareAvatarImage::encode($path, 'image/png');
        $this->assertIsString($encoded);
        $dimensions = getimagesizefromstring($encoded);
        $this->assertSame(512, $dimensions[0]); $this->assertSame(512, $dimensions[1]);
        $square = imagecreatefromstring($encoded);
        $pixel = imagecolorsforindex($square, imagecolorat($square, 0, 0));
        $this->assertGreaterThan(180, $pixel['red']); $this->assertLessThan(50, $pixel['blue']);
        imagedestroy($square);
        $this->assertSame($original, hash_file('sha256', $path));
    }

    public function test_png_alpha_is_preserved_and_small_photos_are_not_upscaled(): void
    {
        $source = imagecreatetruecolor(80, 160);
        imagealphablending($source, false); imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 255, 255, 255, 127));
        $path = $this->path(); imagepng($source, $path); imagedestroy($source);
        $encoded = SquareAvatarImage::encode($path, 'image/png');
        $square = imagecreatefromstring($encoded);
        $this->assertSame(80, imagesx($square)); $this->assertSame(80, imagesy($square));
        $this->assertSame(127, imagecolorsforindex($square, imagecolorat($square, 20, 20))['alpha']);
        imagedestroy($square);
    }

    public function test_jpeg_exif_orientation_is_applied_before_the_square_crop(): void
    {
        $source = imagecreatetruecolor(80, 120);
        imagefilledrectangle($source, 0, 0, 39, 119, imagecolorallocate($source, 220, 20, 20));
        imagefilledrectangle($source, 40, 0, 79, 119, imagecolorallocate($source, 20, 20, 220));
        ob_start(); imagejpeg($source, null, 95); $jpeg = ob_get_clean(); imagedestroy($source);
        $exif = "Exif\0\0II\x2A\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0\x06\0\0\0\0\0\0\0";
        $path = $this->path();
        file_put_contents($path, substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        $square = imagecreatefromstring(SquareAvatarImage::encode($path, 'image/jpeg'));
        $this->assertSame(80, imagesx($square)); $this->assertSame(80, imagesy($square));
        $top = imagecolorsforindex($square, imagecolorat($square, 40, 10));
        $bottom = imagecolorsforindex($square, imagecolorat($square, 40, 70));
        $this->assertGreaterThan(180, $top['red']); $this->assertGreaterThan(180, $bottom['blue']);
        imagedestroy($square);
    }

    public function test_webp_uploads_are_also_square(): void
    {
        if (!(imagetypes() & IMG_WEBP)) $this->markTestSkipped('GD WebP support is required for this format.');
        $source = imagecreatetruecolor(160, 80); $path = $this->path();
        imagewebp($source, $path); imagedestroy($source);
        $encoded = SquareAvatarImage::encode($path, 'image/webp');
        $dimensions = getimagesizefromstring($encoded);
        $this->assertSame(80, $dimensions[0]); $this->assertSame(80, $dimensions[1]);
        $this->assertSame('image/webp', $dimensions['mime']);
    }

    public function test_high_detail_photos_stay_within_the_compressed_download_budget(): void
    {
        $source = imagecreatetruecolor(600, 800);
        $seed = 1729;
        for ($y = 0; $y < 800; $y++) {
            for ($x = 0; $x < 600; $x++) {
                $seed = (1103515245 * $seed + 12345) & 0x7fffffff;
                imagesetpixel($source, $x, $y, $seed & 0xffffff);
            }
        }
        $path = $this->path(); imagepng($source, $path); imagedestroy($source);
        $encoded = SquareAvatarImage::encode($path, 'image/png', 'image/jpeg');
        $this->assertIsString($encoded);
        $this->assertLessThanOrEqual(SquareAvatarImage::MAX_COMPRESSED_BYTES, strlen($encoded));
        $this->assertLessThan(filesize($path) / 2, strlen($encoded));
        $dimensions = getimagesizefromstring($encoded);
        $this->assertSame([512, 512], [$dimensions[0], $dimensions[1]]);
    }
}
