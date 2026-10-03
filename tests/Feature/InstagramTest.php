<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Services\InstagramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class InstagramTest extends TestCase
{
    use RefreshDatabase;

    private const MEDIA = 'https://graph.facebook.com/v23.0/17841/media';

    private const PUBLISH = 'https://graph.facebook.com/v23.0/17841/media_publish';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.admin.token' => 'admin-token',
            'services.meta.page_id' => null,
            'services.meta.page_token' => 'page-token',
            'services.meta.ig_user_id' => '17841',
            'services.meta.graph_version' => 'v23.0',
        ]);
        Sleep::fake();
        Category::create(['slug' => 'priroda', 'name' => 'Priroda', 'color' => 'bg-green-500']);
    }

    private function fakeInstagram(): void
    {
        Http::fake([
            self::MEDIA => Http::response(['id' => 'c1']),
            'graph.facebook.com/v23.0/c1*' => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
            self::PUBLISH => Http::response(['id' => 'm1']),
        ]);
    }

    private function article(array $attributes = []): Post
    {
        return Post::forceCreate($attributes + [
            'category_id' => Category::value('id'), 'slug' => 'una', 'title' => 'Una, rijeka smaragdne boje',
            'excerpt' => 'Kratki uvod.', 'content' => 'Tekst', 'author' => 'A', 'read_time' => 1,
            'image_url' => 'media/posts/una-wm.jpg', 'published_at' => now()->subMinute(), 'ig_status' => 'pending',
        ]);
    }

    public function test_due_article_is_posted_with_cover_and_caption(): void
    {
        $this->fakeInstagram();
        $post = $this->article();

        $this->artisan('instagram:publish-due')->assertSuccessful();

        $post->refresh();
        $this->assertSame(['posted', 'm1'], [$post->ig_status, $post->ig_media_id]);
        Http::assertSent(fn (Request $r) => $r->url() === self::MEDIA
            && $r['image_url'] === asset('media/posts/una-wm.jpg')
            && str_starts_with($r['caption'], "Una, rijeka smaragdne boje\n\nKratki uvod.")
            && str_contains($r['caption'], '#geovizija #priroda'));
        Http::assertSent(fn (Request $r) => $r->url() === self::PUBLISH && $r['creation_id'] === 'c1');
    }

    public function test_post_is_followed_by_a_full_screen_story(): void
    {
        Http::fake([
            self::MEDIA => Http::response(['id' => 'c1']),
            'graph.facebook.com/v23.0/c1*' => Http::response(['status_code' => 'FINISHED']),
            self::PUBLISH => Http::response(['id' => 'm1']),
        ]);
        \Illuminate\Support\Facades\File::ensureDirectoryExists(public_path('media/posts'));
        imagejpeg(imagecreatetruecolor(1600, 900), public_path('media/posts/story-test-w3.jpg'));
        $post = $this->article(['image_url' => 'media/posts/story-test-w3.jpg']);

        $this->artisan('instagram:publish-due')->assertSuccessful();

        $this->assertSame('posted', $post->refresh()->ig_status);
        $this->assertNull($post->ig_error);
        $story = Http::recorded(fn (Request $r) => $r->url() === self::MEDIA && ($r->data()['media_type'] ?? null) === 'STORIES')->first()[0];
        $this->assertMatchesRegularExpression('#/media/stories/una-\d{14}\.jpg$#', $story['image_url']);
        $this->assertCount(2, Http::recorded(fn (Request $r) => $r->url() === self::PUBLISH));
        // The post image is the stable -ig.jpg with a changing query string (Instagram caches by URL).
        $feed = Http::recorded(fn (Request $r) => $r->url() === self::MEDIA && ! isset($r->data()['media_type']))->first()[0];
        $this->assertMatchesRegularExpression('#/media/posts/[^/]+-ig\.jpg\?v=\d{14}$#', $feed['image_url']);
        $this->assertSame([], glob(public_path('media/stories/una-*.jpg')));

        \Illuminate\Support\Facades\File::delete(public_path('media/posts/story-test-w3.jpg'));
    }

    public function test_stories_are_rationed_per_day_and_spaced(): void
    {
        config(['services.meta.ig_stories_per_day' => 2, 'services.meta.ig_story_gap_minutes' => 45]);
        $this->travelTo(now()->startOfDay()->addHours(8));
        $this->assertTrue(InstagramPublisher::storyDue());

        $this->storiesAt([now()->subMinutes(10)]);
        $this->assertFalse(InstagramPublisher::storyDue());

        $this->travel(40)->minutes();
        $this->assertTrue(InstagramPublisher::storyDue());

        $this->storiesAt([now()->subHours(3), now()->subHours(2)]);
        $this->assertFalse(InstagramPublisher::storyDue());

        $this->travel(22)->hours();
        $this->assertTrue(InstagramPublisher::storyDue());
    }

    private function storiesAt(array $times): void
    {
        file_put_contents(storage_path('app/instagram-stories.json'), json_encode(array_map(fn ($t) => $t->getTimestamp(), $times)));
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/instagram-stories.json'));
        parent::tearDown();
    }

    public function test_scheduled_unqueued_and_skipped_articles_wait(): void
    {
        $this->fakeInstagram();
        $this->article(['published_at' => now()->addHour()]);
        $this->article(['slug' => 'staro', 'ig_status' => null]);
        $this->article(['slug' => 'bez', 'ig_status' => 'skipped']);

        $this->artisan('instagram:publish-due')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_cron_run_is_recorded_and_status_endpoint_reports_it(): void
    {
        Http::fake();
        $this->artisan('instagram:publish-due')->assertSuccessful();

        $this->getJson('/api/instagram/status')->assertUnauthorized();
        $this->withToken('admin-token')->getJson('/api/instagram/status')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('igColumns', true)
            ->assertJsonPath('commandLastRun.due', 0);
    }

    public function test_admin_create_and_publishing_a_draft_queue_the_article(): void
    {
        Http::fake();
        $create = fn (array $extra) => $this->withToken('admin-token')->postJson('/api/posts', $extra + [
            'category' => 'priroda', 'title' => 'Naslov', 'excerpt' => 'Uvod', 'content' => 'Tekst', 'image_url' => 'https://geovizija.com/media/posts/x.jpg',
        ])->assertCreated()->json('data.id');

        $scheduled = $create(['published_at' => now()->addDay()->toIso8601String()]);
        $draft = $create(['published_at' => null]);
        $this->assertSame('pending', Post::find($scheduled)->ig_status);
        $this->assertNull(Post::find($draft)->ig_status);

        $this->withToken('admin-token')->patchJson("/api/posts/{$draft}", ['published_at' => now()->toIso8601String()])->assertOk();
        $this->assertSame('pending', Post::find($draft)->ig_status);

        config(['services.meta.ig_user_id' => null]);
        $off = $create(['published_at' => now()->toIso8601String()]);
        $this->assertSame('skipped', Post::find($off)->ig_status);
    }

    public function test_failure_is_stored_and_admin_can_retry(): void
    {
        Http::fake([
            self::MEDIA => Http::sequence()
                ->push(['error' => ['message' => 'Only photo or video can be accepted as media type.']], 400)
                ->push(['id' => 'c1']),
            'graph.facebook.com/v23.0/c1*' => Http::response(['status_code' => 'FINISHED']),
            self::PUBLISH => Http::response(['id' => 'm1']),
        ]);
        $post = $this->article();

        $this->artisan('instagram:publish-due')->assertSuccessful();
        $this->assertSame('failed', $post->refresh()->ig_status);
        $this->assertStringContainsString('Only photo or video', $post->ig_error);

        $this->withToken('admin-token')->postJson("/api/posts/{$post->id}/share-instagram")->assertOk()->assertJsonPath('status', 'posted');
    }
}
