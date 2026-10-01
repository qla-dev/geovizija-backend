<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Services\PostImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 PNG */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.publish.secret_hash' => Hash::make('tajna'),
            'services.admin.token' => 'admin-token',
            'services.openrouter.api_key' => 'test',
            'services.openrouter.url' => 'https://openrouter.test/chat',
        ]);
        Http::fake(['openrouter.test/*' => Http::response(['choices' => [['message' => ['images' => [['image_url' => ['url' => self::PNG]]]]]]])]);

        $this->created = File::glob(public_path(PostImageGenerator::DIRECTORY.'/*'));
    }

    protected function tearDown(): void
    {
        foreach (array_diff(File::glob(public_path(PostImageGenerator::DIRECTORY.'/*')), $this->created) as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    private function article(): Post
    {
        $category = Category::create(['slug' => 'priroda', 'name' => 'Priroda']);
        Category::create(['slug' => 'kultura', 'name' => 'Kultura']);
        $body = str_repeat('Riječ ', 200)."\n\n[[SLIKA: Prva scena]]\n\n## Podnaslov\n\n[[SLIKA: Druga scena]]\n\n".str_repeat('Kraj ', 50);

        $post = Post::create([
            'category_id' => $category->id, 'slug' => 'test', 'title' => 'Test', 'excerpt' => 'Sažetak',
            'content' => $body, 'author' => 'Kulašin', 'read_time' => 2, 'featured' => false, 'published_at' => now(),
        ]);
        $generator = app(PostImageGenerator::class);
        $generator->generate($post);
        $generator->generateNextInline($post);
        $generator->generateNextInline($post);

        return $post->refresh();
    }

    public function test_publish_with_id_replaces_cover_and_one_inline_image(): void
    {
        $post = $this->article();
        [, [, , $second]] = PostImageGenerator::inlineImages($post->content);
        [[, , $first]] = PostImageGenerator::inlineImages($post->content);
        $oldCover = $post->image_url;
        $this->travel(2)->seconds();

        $response = $this->postJson('/api/publish', [
            'secret' => 'tajna', 'type' => 'article', 'id' => $post->id,
            'title' => 'Novi naslov',
            'cover' => self::PNG,
            'replaceInline' => [['number' => 2, 'description' => 'Nova scena']],
        ]);

        $response->assertOk()->assertJsonPath('images', ['agent' => 1, 'api' => 1])->assertJsonPath('warnings', []);
        $post->refresh();
        $this->assertSame('Novi naslov', $post->title);
        $this->assertSame('test', $post->slug);
        $this->assertNotSame($oldCover, $post->image_url);
        $this->assertFileDoesNotExist(public_path($oldCover));

        $images = PostImageGenerator::inlineImages($post->content);
        $this->assertSame($first, $images[0][2]);
        $this->assertNotSame($second, $images[1][2]);
        $this->assertSame('Nova scena', $images[1][1]);
        $this->assertFileDoesNotExist(public_path($second));
        $this->assertFileExists(public_path($first));
        $this->assertSame(1, Post::count());
    }

    public function test_content_read_from_api_and_sent_back_keeps_images(): void
    {
        $post = $this->article();
        $paths = PostImageGenerator::inlinePaths($post->content);
        $apiContent = $this->getJson("/api/posts/{$post->id}")->json('data.content');

        $this->postJson('/api/publish', [
            'secret' => 'tajna', 'type' => 'article', 'id' => $post->id,
            'content' => $apiContent."\n\nJoš jedan pasus.",
        ])->assertOk()->assertJsonPath('images', ['agent' => 0, 'api' => 0]);

        $this->assertSame($paths, PostImageGenerator::inlinePaths($post->refresh()->content));
        foreach ($paths as $path) {
            $this->assertFileExists(public_path($path));
        }
    }

    public function test_rejected_image_keeps_the_old_one_and_wrong_secret_changes_nothing(): void
    {
        $post = $this->article();
        $cover = $post->image_url;

        $this->postJson('/api/publish', ['secret' => 'kriva', 'type' => 'article', 'id' => $post->id, 'title' => 'X'])->assertForbidden();

        $this->postJson('/api/publish', ['secret' => 'tajna', 'type' => 'article', 'id' => $post->id, 'cover' => 'ftp://nije-slika'])
            ->assertStatus(422)->assertJsonCount(1, 'warnings');

        $this->assertSame($cover, $post->refresh()->image_url);
        $this->assertSame('Test', $post->title);
    }

    public function test_admin_routes_accept_own_image_and_replace_by_number(): void
    {
        $post = $this->article();
        $headers = ['Authorization' => 'Bearer admin-token'];
        $this->travel(2)->seconds();

        $this->postJson("/api/posts/{$post->id}/generate-image", ['image' => self::PNG], $headers)->assertOk();
        Http::assertSentCount(3); // only the three drawings from article()

        $this->postJson("/api/posts/{$post->id}/generate-inline-image", ['number' => 1, 'image' => self::PNG], $headers)
            ->assertOk()->assertJsonPath('pending', 0);
        Http::assertSentCount(3);

        $this->postJson("/api/posts/{$post->id}/generate-inline-image", ['number' => 3], $headers)->assertStatus(502);
    }
}
