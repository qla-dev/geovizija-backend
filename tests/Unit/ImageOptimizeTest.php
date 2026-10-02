<?php

namespace Tests\Unit;

use App\Services\PostImageGenerator;
use Tests\TestCase;

class ImageOptimizeTest extends TestCase
{
    public function test_saved_image_is_a_watermarked_jpeg_under_250_kb_and_1600_wide(): void
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
        $this->assertLessThanOrEqual(250_000, strlen($jpeg['bytes']));
        $this->assertSame([1600, 900], [$width, $height]);

        // The top-right corner now carries the semi-transparent white GEOVIZIJA.
        $out = imagecreatefromstring($jpeg['bytes']);
        $bright = 0;
        for ($x = 1300; $x < 1545; $x += 2) {
            for ($y = 60; $y < 150; $y += 2) {
                $bright += (imagecolorat($out, $x, $y) & 0xFF) > 170 ? 1 : 0;
            }
        }
        $this->assertGreaterThan(50, $bright);
    }

    public function test_reprocess_rewrites_old_cover_and_inline_images_once(): void
    {
        $this->artisan('migrate');
        $category = \App\Models\Category::create(['slug' => 'priroda', 'name' => 'Priroda', 'color' => 'x']);
        \Illuminate\Support\Facades\File::ensureDirectoryExists(public_path('media/posts'));
        foreach (['t-cover-20260101000000.jpg', 't-inline-1-20260101000000.jpg'] as $name) {
            imagejpeg(imagecreatetruecolor(1200, 900), public_path("media/posts/{$name}"));
        }
        $post = \App\Models\Post::create([
            'category_id' => $category->id, 'slug' => 't', 'title' => 'T', 'excerpt' => 'E', 'author' => 'A', 'read_time' => 1,
            'image_url' => 'media/posts/t-cover-20260101000000.jpg',
            'content' => "Pasus.\n\n![Opis](media/posts/t-inline-1-20260101000000.jpg)\n\nKraj.",
        ]);

        config(['services.admin.token' => 'admin-token']);
        $this->withToken('admin-token')
            ->postJson('/api/images/reprocess')->assertOk()->assertJsonPath("processed.{$post->id}", 2)->assertJsonPath('next', null);

        $post->refresh();
        $this->assertMatchesRegularExpression('#^media/posts/t-cover-\d{14}-w3.jpg$#', $post->image_url);
        $this->assertSame([1200, 675], array_slice(getimagesize(public_path($post->image_url)), 0, 2));
        [$inline] = PostImageGenerator::inlinePaths($post->content);
        $this->assertStringEndsWith('-w3.jpg', $inline);
        $this->assertFileDoesNotExist(public_path('media/posts/t-cover-20260101000000.jpg'));

        $this->postJson('/api/images/reprocess')->assertJsonPath('processed', []);

        \Illuminate\Support\Facades\File::delete([public_path($post->image_url), public_path($inline)]);
    }

    public function test_new_cover_keeps_its_original_and_gets_an_instagram_portrait(): void
    {
        $this->artisan('migrate');
        $category = \App\Models\Category::create(['slug' => 'priroda', 'name' => 'Priroda', 'color' => 'x']);
        $post = \App\Models\Post::create([
            'category_id' => $category->id, 'slug' => 'orig', 'title' => 'T', 'excerpt' => 'E', 'author' => 'A',
            'read_time' => 1, 'content' => 'Tekst',
        ]);
        ob_start();
        imagejpeg(imagecreatetruecolor(2400, 1600), null, 95);
        $source = 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());

        $path = app(PostImageGenerator::class)->generate($post, $source);

        $this->assertFileExists(PostImageGenerator::originalPath($path));
        $this->assertSame([2400, 1600], array_slice(getimagesize(PostImageGenerator::originalPath($path)), 0, 2));
        $this->assertSame([1080, 1350], array_slice(getimagesize(public_path(PostImageGenerator::portraitPath($path))), 0, 2));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path(PostImageGenerator::sharePath($path))), 0, 2));

        // A new cover removes the old one with its portrait and original.
        $next = app(PostImageGenerator::class)->generate($post, $source);
        $this->assertFileDoesNotExist(PostImageGenerator::originalPath($path));
        $this->assertFileDoesNotExist(public_path(PostImageGenerator::portraitPath($path)));

        \Illuminate\Support\Facades\File::delete([public_path($next), public_path(PostImageGenerator::portraitPath($next)), public_path(PostImageGenerator::sharePath($next)), PostImageGenerator::originalPath($next)]);
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
