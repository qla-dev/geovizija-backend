<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.admin.token' => 'admin-token']);

        $category = Category::create(['slug' => 'priroda', 'name' => 'Priroda', 'color' => 'bg-green-500']);
        $this->post = Post::create([
            'category_id' => $category->id, 'slug' => 'test', 'title' => 'Test', 'excerpt' => 'E',
            'content' => 'C', 'author' => 'A', 'read_time' => 1, 'published_at' => now()->subDay(),
        ]);
    }

    public function test_comment_is_stored_and_listed_with_replies(): void
    {
        $id = $this->postJson("/api/posts/{$this->post->id}/comments", ['author' => 'Ana', 'body' => 'Odličan tekst!'])
            ->assertCreated()
            ->assertJsonPath('data.author', 'Ana')
            ->json('data.id');

        $this->postJson("/api/posts/{$this->post->slug}/comments", ['author' => 'Edo', 'body' => 'Slažem se.', 'parentId' => $id])
            ->assertCreated()
            ->assertJsonPath('data.parentId', $id);

        $this->getJson("/api/posts/{$this->post->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Odličan tekst!')
            ->assertJsonPath('data.0.replies.0.author', 'Edo')
            ->assertJsonMissingPath('data.0.ip_hash');
    }

    public function test_moderation_rejects_spam(): void
    {
        $url = "/api/posts/{$this->post->id}/comments";

        $this->postJson($url, ['author' => 'Bot', 'body' => 'Kupite', 'website' => 'x'])->assertUnprocessable();
        $this->postJson($url, ['author' => 'Bot', 'body' => 'http://a.com http://b.com'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($url, ['author' => '', 'body' => 'Tekst'])->assertJsonValidationErrors('author');

        $this->postJson($url, ['author' => 'Ana', 'body' => 'Isti tekst'])->assertCreated();
        $this->postJson($url, ['author' => 'Ana', 'body' => 'Isti tekst'])->assertJsonValidationErrors('body');

        $this->assertSame(1, Comment::count());
    }

    public function test_replies_only_one_level_deep(): void
    {
        $url = "/api/posts/{$this->post->id}/comments";
        $top = $this->postJson($url, ['author' => 'Ana', 'body' => 'Prvi'])->json('data.id');
        $reply = $this->postJson($url, ['author' => 'Edo', 'body' => 'Drugi', 'parentId' => $top])->json('data.id');

        $this->postJson($url, ['author' => 'Iva', 'body' => 'Treći', 'parentId' => $reply])->assertJsonValidationErrors('parentId');
    }

    public function test_likes_and_admin_delete(): void
    {
        $comment = Comment::create(['post_id' => $this->post->id, 'author' => 'Ana', 'body' => 'Tekst']);

        $this->postJson("/api/comments/{$comment->id}/like")->assertJsonPath('data.likes', 1);
        $this->deleteJson("/api/comments/{$comment->id}/like")->assertJsonPath('data.likes', 0);
        $this->deleteJson("/api/comments/{$comment->id}/like")->assertJsonPath('data.likes', 0);

        $this->deleteJson("/api/comments/{$comment->id}")->assertUnauthorized();
        $this->withToken('admin-token')->deleteJson("/api/comments/{$comment->id}")->assertNoContent();
        $this->assertSame(0, Comment::count());
    }

    public function test_unpublished_post_has_no_comments(): void
    {
        $this->post->update(['published_at' => now()->addDay()]);

        $this->getJson("/api/posts/{$this->post->id}/comments")->assertNotFound();
        $this->postJson("/api/posts/{$this->post->id}/comments", ['author' => 'Ana', 'body' => 'Tekst'])->assertNotFound();
    }
}
