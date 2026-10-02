<?php

namespace Tests\Unit;

use App\Services\PostImageGenerator;
use Tests\TestCase;

class ImageOptimizeTest extends TestCase
{
    public function test_saved_image_is_a_watermarked_jpeg_under_100_kb_and_1600_wide(): void
    {
        $im = imagecreatetruecolor(2400, 1350);
        for ($x = 0; $x < 2400; $x += 8) {
            imagefilledrectangle($im, $x, 0, $x + 7, 1349, imagecolorallocate($im, $x % 256, ($x * 3) % 256, 120));
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();

        $jpeg = PostImageGenerator::toJpeg(['bytes' => $png, 'mime' => 'image/png', 'extension' => 'png']);
        [$width, $height] = getimagesizefromstring($jpeg['bytes']);

        $this->assertSame('jpg', $jpeg['extension']);
        $this->assertLessThanOrEqual(100_000, strlen($jpeg['bytes']));
        $this->assertSame([1600, 900], [$width, $height]);

        // The bottom-right corner now carries the white logo text.
        $out = imagecreatefromstring($jpeg['bytes']);
        $bright = 0;
        for ($x = 1300; $x < 1590; $x += 2) {
            for ($y = 820; $y < 890; $y += 2) {
                $bright += (imagecolorat($out, $x, $y) & 0xFF) > 230 ? 1 : 0;
            }
        }
        $this->assertGreaterThan(50, $bright);
    }

    /** @return array<string, array{int, int, int, int}> */
    public static function shapes(): array
    {
        return [
            'portrait' => [900, 1400, 900, 506],
            'square' => [1200, 1200, 1200, 675],
            '4:3' => [1280, 960, 1280, 720],
            'panorama' => [3000, 900, 1600, 900],
            'small' => [500, 400, 500, 281],
        ];
    }

    /** @dataProvider shapes */
    public function test_any_shape_is_cropped_to_16_9(int $width, int $height, int $expectedWidth, int $expectedHeight): void
    {
        ob_start();
        imagejpeg(imagecreatetruecolor($width, $height));
        $jpeg = PostImageGenerator::toJpeg(['bytes' => (string) ob_get_clean(), 'mime' => 'image/jpeg', 'extension' => 'jpg']);

        $this->assertSame([$expectedWidth, $expectedHeight], array_slice(getimagesizefromstring($jpeg['bytes']), 0, 2));
    }
}
