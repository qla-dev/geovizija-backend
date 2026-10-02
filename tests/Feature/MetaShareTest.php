<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Services\MetaPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaShareTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://graph.facebook.com/v23.0/123/feed';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.admin.token' => 'admin-token',
            'services.meta.page_id' => '123',
            'services.meta.page_token' => 'page-token',
            'services.meta.graph_version' => 'v23.0',
            'services.meta.site_url' => 'https://geovizija.com',
        ]);
        Category::create(['slug' => 'priroda', 'name' => 'Priroda', 'color' => 'bg-green-500']);
    }

    /** Facebook answers with this unless a test fakes its own response first. */
    private function fakeFacebook(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123_456'])]);
    }

    private function article(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'category_id' => Category::value('id'), 'slug' => 'berat-grad', 'title' => 'Berat, grad hiljadu prozora',
            'excerpt' => 'Kratki uvod.', 'content' => 'Tekst', 'author' => 'A', 'read_time' => 1,
            'image_url' => 'media/posts/berat.jpg', 'published_at' => now()->subMinute(),
        ]);
    }

    public function test_published_article_is_posted_with_share_link(): void
    {
        $this->fakeFacebook();
        $result = app(MetaPublisher::class)->share($post = $this->article());

        $this->assertSame('posted', $result['status']);
        $this->assertSame('123_456', $post->refresh()->meta_post_id);
        Http::assertSent(fn (Request $r) => $r->url() === self::FEED
            && $r['link'] === 'https://geovizija.com/endpoints/share/berat-grad'
            && $r['message'] === "Berat, grad hiljadu prozora\n\nKratki uvod."
            && $r['access_token'] === 'page-token'
            && ! isset($r['published']));
    }

    public function test_scheduled_article_becomes_scheduled_page_post(): void
    {
        $this->fakeFacebook();
        $at = now()->addHours(2)->startOfMinute();
        $result = app(MetaPublisher::class)->share($this->article(['published_at' => $at]));

        $this->assertSame('scheduled', $result['status']);
        Http::assertSent(fn (Request $r) => $r['published'] === 'false' && (int) $r['scheduled_publish_time'] === $at->getTimestamp());
    }

    public function test_schedule_closer_than_ten_minutes_moves_to_facebook_minimum(): void
    {
        $this->fakeFacebook();
        $this->travelTo(now()->startOfMinute());
        app(MetaPublisher::class)->share($this->article(['published_at' => now()->addMinutes(3)]));

        Http::assertSent(fn (Request $r) => (int) $r['scheduled_publish_time'] === now()->addMinutes(11)->getTimestamp());
    }

    public function test_more_than_thirty_days_ahead_is_not_shared(): void
    {
        $this->fakeFacebook();
        $result = app(MetaPublisher::class)->share($post = $this->article(['published_at' => now()->addDays(40)]));

        $this->assertSame('failed', $result['status']);
        $this->assertNotNull($post->refresh()->meta_error);
        Http::assertNothingSent();
    }

    public function test_already_shared_is_skipped_unless_forced(): void
    {
        $this->fakeFacebook();
        $post = $this->article();
        $post->forceFill(['meta_post_id' => '123_1'])->save();

        $this->assertSame('skipped', app(MetaPublisher::class)->share($post)['status']);
        Http::assertNothingSent();

        $this->assertSame('posted', app(MetaPublisher::class)->share($post, force: true)['status']);
        $this->assertSame('123_456', $post->refresh()->meta_post_id);
    }

    public function test_facebook_error_is_stored_not_thrown(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400)]);

        $result = app(MetaPublisher::class)->share($post = $this->article());

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Invalid OAuth access token.', $post->refresh()->meta_error);
        $this->assertNull($post->meta_post_id);
    }

    public function test_without_token_nothing_is_sent(): void
    {
        $this->fakeFacebook();
        config(['services.meta.page_token' => null]);

        $this->assertSame('skipped', app(MetaPublisher::class)->share($this->article())['status']);
        Http::assertNothingSent();
    }

    public function test_share_page_has_open_graph_tags_and_redirects(): void
    {
        $this->fakeFacebook();
        $post = $this->article(['published_at' => now()->addDay()]);

        $this->get('/share/berat-grad')
            ->assertOk()
            ->assertSee('<meta property="og:title" content="Berat, grad hiljadu prozora">', false)
            ->assertSee('<meta property="og:image" content="'.asset('media/posts/berat.jpg').'">', false)
            ->assertSee('<meta property="og:url" content="https://geovizija.com/endpoints/share/berat-grad">', false)
            ->assertSee("https://geovizija.com/#/article/{$post->id}", false);

        $this->get('/share/ne-postoji')->assertNotFound();
    }

    public function test_admin_create_and_share_endpoint(): void
    {
        $this->fakeFacebook();
        $this->withToken('admin-token')->postJson('/api/posts', [
            'category' => 'priroda', 'title' => 'Novi članak', 'excerpt' => 'Uvod', 'content' => 'Tekst', 'published_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('facebook.status', 'posted');

        $post = $this->article(['slug' => 'drugi']);
        $this->flushHeaders()->postJson("/api/posts/{$post->id}/share-meta")->assertUnauthorized();
        $this->withToken('admin-token')->postJson("/api/posts/{$post->id}/share-meta")->assertOk()->assertJsonPath('status', 'posted');
        $this->withToken('admin-token')->postJson("/api/posts/{$post->id}/share-meta")->assertOk()->assertJsonPath('status', 'skipped');
    }
}
