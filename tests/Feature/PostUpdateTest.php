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
        ]);
        Http::fake();

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
        $generator->generate($post, self::PNG);
        $generator->generateNextInline($post, self::PNG);
        $generator->generateNextInline($post, self::PNG);

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
            'replaceInline' => [['number' => 2, 'image' => self::PNG, 'description' => 'Nova scena']],
        ]);

        $response->assertOk()->assertJsonPath('images', ['agent' => 2])->assertJsonPath('warnings', []);
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
        ])->assertOk()->assertJsonPath('images', ['agent' => 0]);

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

    private function newArticle(array $extra = []): \Illuminate\Testing\TestResponse
    {
        Category::firstOrCreate(['slug' => 'priroda'], ['name' => 'Priroda']);
        $body = str_repeat('Riječ ', 200)."\n\n[[SLIKA: Prva scena]]\n\n## Podnaslov\n\n[[SLIKA: Druga scena]]\n\n".str_repeat('Kraj ', 50);

        return $this->postJson('/api/publish', [
            'secret' => 'tajna', 'type' => 'article', 'category' => 'priroda',
            'title' => 'Novi članak', 'excerpt' => 'Sažetak', 'content' => $body,
            'publishedAt' => now()->addDay()->toIso8601String(),
        ] + $extra);
    }

    public function test_article_with_cover_is_scheduled_and_marker_without_photo_is_removed(): void
    {
        $this->newArticle(['cover' => self::PNG, 'inlineImages' => [self::PNG]])
            ->assertCreated()->assertJsonPath('status', 'scheduled')->assertJsonPath('images', ['agent' => 2]);

        $post = Post::sole();
        $this->assertNotNull($post->published_at);
        $this->assertNotNull($post->image_url);
        $this->assertCount(1, PostImageGenerator::inlinePaths($post->content));
        $this->assertSame(0, PostImageGenerator::pendingInline($post));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "openrouter"));
    }

    public function test_article_without_usable_cover_stays_draft_until_published_with_one(): void
    {
        $this->newArticle()->assertStatus(202)->assertJsonPath('status', 'draft');
        $this->newArticle(['cover' => 'ftp://nije-slika'])->assertStatus(202)->assertJsonPath('status', 'draft');
        $this->assertSame(0, Post::whereNotNull('published_at')->count());
        $this->assertSame(0, Post::whereNotNull('image_url')->count());

        $post = Post::first();
        $when = now()->addDay()->toIso8601String();

        // publishedAt alone does not publish a draft without a cover.
        $this->postJson('/api/publish', ['secret' => 'tajna', 'type' => 'article', 'id' => $post->id, 'publishedAt' => $when])
            ->assertJsonPath('status', 'draft');
        $this->assertNull($post->refresh()->published_at);

        $this->postJson('/api/publish', ['secret' => 'tajna', 'type' => 'article', 'id' => $post->id, 'cover' => self::PNG, 'publishedAt' => $when])
            ->assertOk()->assertJsonPath('status', 'scheduled');
        $this->assertNotNull($post->refresh()->published_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "openrouter"));
    }

    public function test_admin_cannot_publish_a_post_without_cover(): void
    {
        Category::create(['slug' => 'priroda', 'name' => 'Priroda']);
        $headers = ['Authorization' => 'Bearer admin-token'];

        $id = $this->postJson('/api/posts', ['category' => 'priroda', 'title' => 'Bez slike', 'content' => 'Tekst', 'published_at' => now()->toIso8601String()], $headers)
            ->assertCreated()->json('data.id');
        $this->assertNull(Post::find($id)->published_at);

        $this->patchJson("/api/posts/{$id}", ['published_at' => now()->toIso8601String()], $headers)->assertStatus(422);
        $this->assertNull(Post::find($id)->published_at);
    }

    public function test_admin_routes_accept_own_image_and_replace_by_number(): void
    {
        $post = $this->article();
        $headers = ['Authorization' => 'Bearer admin-token'];
        $this->travel(2)->seconds();

        $this->postJson("/api/posts/{$post->id}/generate-image", ['image' => self::PNG], $headers)->assertOk();

        $this->postJson("/api/posts/{$post->id}/generate-inline-image", ['number' => 1, 'image' => self::PNG], $headers)
            ->assertOk()->assertJsonPath('pending', 0);

        $this->postJson("/api/posts/{$post->id}/generate-inline-image", ['number' => 3, 'image' => self::PNG], $headers)->assertStatus(422);
        // Nothing is drawn: without an image the request is refused.
        $this->postJson("/api/posts/{$post->id}/generate-image", [], $headers)->assertStatus(422);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "openrouter"));
    }
}
