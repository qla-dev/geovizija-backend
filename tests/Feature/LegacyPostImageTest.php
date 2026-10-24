<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Services\PostImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LegacyPostImageTest extends TestCase
{
    use RefreshDatabase;

    private const COVER = 'media/posts/legacy-image-test-current-w3.jpg';

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        $category = Category::create(['slug' => 'priroda', 'name' => 'Priroda']);
        $this->post = Post::create([
            'category_id' => $category->id, 'slug' => 'legacy-image-test', 'title' => 'Test', 'excerpt' => 'E',
            'content' => 'Article', 'author' => 'A', 'read_time' => 1, 'image_url' => self::COVER,
            'published_at' => now()->subMinute(),
        ]);
        File::ensureDirectoryExists(public_path('media/posts'));
        foreach ([self::COVER, PostImageGenerator::sharePath(self::COVER)] as $path) {
            $image = imagecreatetruecolor(640, 360);
            imagejpeg($image, public_path($path));
            imagedestroy($image);
        }
    }

    protected function tearDown(): void
    {
        File::delete([public_path(self::COVER), public_path(PostImageGenerator::sharePath(self::COVER))]);
        parent::tearDown();
    }

    public function test_an_old_facebook_image_url_serves_the_same_articles_current_preview(): void
    {
        $response = $this->get('/media/posts/legacy-image-test-20261022190817-w3-fbw.jpg');

        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Geovizija-Image-Fallback', 'same-article')->assertHeaderMissing('Set-Cookie');
        $this->assertSame(realpath(public_path(PostImageGenerator::sharePath(self::COVER))),
            $response->baseResponse->getFile()->getRealPath());
    }

    public function test_an_old_cover_url_serves_only_that_articles_cover(): void
    {
        $response = $this->get('/media/posts/legacy-image-test-20261022190817-w2.jpg')->assertOk();

        $this->assertSame(realpath(public_path(self::COVER)), $response->baseResponse->getFile()->getRealPath());
        $this->get('/media/posts/different-article-20261022190817-w3-fbw.jpg')->assertNotFound();
        $this->get('/media/posts/legacy-image-test-inline-1-20261022190817-w3.jpg')->assertNotFound();
        $this->get('/media/posts/not-a-cover.jpg')->assertNotFound();
    }

    public function test_missing_current_images_and_drafts_do_not_get_a_different_fallback(): void
    {
        File::delete(public_path(PostImageGenerator::sharePath(self::COVER)));
        $this->get('/media/posts/legacy-image-test-20261022190817-w3-fbw.jpg')->assertNotFound();

        $this->post->update(['published_at' => null]);
        $this->get('/media/posts/legacy-image-test-20261022190817-w3.jpg')->assertNotFound();
    }
}
