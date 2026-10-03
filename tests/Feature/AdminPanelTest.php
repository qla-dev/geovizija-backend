<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.publish.secret_hash' => Hash::make('tajna'), 'services.admin.token' => 'admin-token']);
    }

    private function login(): string
    {
        return $this->postJson('/api/admin/login', ['username' => 'QLA.dev', 'password' => 'password123'])->assertOk()->json('token');
    }

    private function track(string $path, string $visitor, string $agent = self::BROWSER): void
    {
        $this->call('POST', '/api/track', [], [], [], ['HTTP_USER_AGENT' => $agent, 'CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '203.0.113.7'],
            json_encode(['p' => $path, 'r' => 'https://www.google.com/search?q=x', 'v' => $visitor]))->assertNoContent();
    }

    public function test_login_gives_a_token_for_admin_routes(): void
    {
        $this->postJson('/api/admin/login', ['username' => 'qla.dev', 'password' => '123'])->assertStatus(422);
        $this->getJson('/api/admin/posts')->assertUnauthorized();
        $this->withToken('panel.1.'.str_repeat('a', 64))->getJson('/api/admin/posts')->assertUnauthorized();

        $this->withToken($this->login())->getJson('/api/admin/posts')->assertOk();
    }

    public function test_page_views_are_counted_without_bots_and_admin_pages(): void
    {
        $category = Category::create(['slug' => 'priroda', 'name' => 'Priroda']);
        $post = Post::create([
            'category_id' => $category->id, 'slug' => 'una', 'title' => 'Una', 'excerpt' => 'E', 'content' => 'T',
            'author' => 'A', 'read_time' => 1, 'image_url' => 'media/posts/una.jpg', 'published_at' => now()->subHour(),
        ]);

        $this->track('/article/una', 'a1');
        $this->track('/article/una', 'a1');
        $this->track('/', 'b2');
        $this->track('/article/una', 'c3', 'facebookexternalhit/1.1');
        $this->track('/admin', 'a1');

        $this->assertSame(3, DB::table('page_views')->count());
        $this->assertSame('203.0.113.7', DB::table('page_views')->value('ip'));
        $this->assertSame('google.com', DB::table('page_views')->value('referrer'));

        $token = $this->login();
        $this->withToken($token)->getJson('/api/admin/stats')->assertOk()
            ->assertJsonPath('today.views', 3)->assertJsonPath('today.visitors', 2)
            ->assertJsonPath('topArticles.0.id', $post->id)->assertJsonPath('topArticles.0.views', 2)
            ->assertJsonPath('devices.mobile', 3)->assertJsonCount(3, 'recent');
        $this->withToken($token)->getJson('/api/admin/posts')->assertJsonPath('data.0.views', 2);
        $this->withToken($token)->getJson("/api/admin/posts/{$post->id}")->assertOk()->assertJsonPath('data.views', 2)
            ->assertJsonPath('data.category', 'Priroda')->assertJsonPath('data.content', 'T');
    }

    public function test_suggestions_from_the_panel_reach_the_agent(): void
    {
        $token = $this->login();
        $id = $this->withToken($token)->postJson('/api/admin/suggestions', ['text' => 'Tara i Drina', 'source' => 'voice'])
            ->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/admin/suggestions', ['text' => 'Ris u Dinaridima']);

        $this->postJson('/api/publish', ['secret' => 'tajna', 'type' => 'suggestions'])->assertOk()->assertJsonCount(2, 'suggestions');
        $this->postJson('/api/publish', ['secret' => 'kriva', 'type' => 'suggestions'])->assertForbidden();

        $this->postJson('/api/publish', ['secret' => 'tajna', 'type' => 'suggestions', 'used' => [$id]])
            ->assertJsonPath('marked', 1)->assertJsonCount(1, 'suggestions');
        $this->withToken($token)->getJson('/api/admin/suggestions')->assertJsonCount(1, 'open')->assertJsonCount(1, 'used');
    }

    public function test_migrate_needs_the_static_token(): void
    {
        $this->withToken($this->login())->postJson('/api/admin/migrate?pretend=1')->assertForbidden();
        $this->withToken('admin-token')->postJson('/api/admin/migrate?pretend=1')->assertOk();
    }
}
