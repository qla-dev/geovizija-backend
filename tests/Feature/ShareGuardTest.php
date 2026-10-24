<?php

namespace Tests\Feature;

use App\Http\Resources\PostResource;
use App\Models\Category;
use App\Models\Post;
use App\Services\MetaPublisher;
use App\Services\PostImageGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShareGuardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://geovizija.com/article/berat-grad';

    private const API = 'https://geovizija.com/endpoints/api/posts/berat-grad';

    private const COVER = 'https://geovizija.com/covers/berat.jpg';

    private const FEED = 'https://graph.facebook.com/v23.0/123/feed';

    private Post $post;

    private string $jpeg;

    private array $fault = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.meta.page_id' => '123', 'services.meta.page_token' => 'test-token',
            'services.meta.graph_version' => 'v23.0', 'services.meta.site_url' => 'https://geovizija.com',
            'services.meta.media_url' => 'https://geovizija.com/endpoints',
        ]);
        URL::forceRootUrl('https://geovizija.com/endpoints');
        URL::forceScheme('https');
        $category = Category::create(['slug' => 'priroda', 'name' => 'Priroda']);
        $this->post = Post::create([
            'category_id' => $category->id, 'slug' => 'berat-grad', 'title' => 'Berat, grad hiljadu prozora',
            'excerpt' => 'Uvod.', 'content' => 'Sadržaj članka.', 'author' => 'A', 'read_time' => 1,
            'image_url' => self::COVER, 'published_at' => now()->subMinute(),
        ]);
        $image = imagecreatetruecolor(1200, 630);
        ob_start();
        imagejpeg($image);
        $this->jpeg = ob_get_clean();
        imagedestroy($image);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            $coverUrl = (new PostResource($this->post))->resolve()['imageUrl'];
            $generated = PostImageGenerator::isGenerated($this->post->image_url);
            $shareUrl = $generated ? asset(PostImageGenerator::sharePath($this->post->image_url)) : $coverUrl;
            if ($url === self::API.'/preview' || $url === self::API) {
                $data = (new PostResource($this->post->load('category')))->resolve();
                $data['shareImageUrl'] = $shareUrl;
                if ($url === self::API.'/preview') {
                    $data = array_replace($data, $this->fault['preview'] ?? []);
                } else {
                    $data = array_replace($data, $this->fault['live'] ?? []);
                }

                return Http::response(['data' => $data], $this->fault['apiStatus'] ?? 200);
            }
            if ($url === self::URL) {
                $crawler = str_contains($request->header('User-Agent')[0] ?? '', 'facebookexternalhit');
                if (! $crawler) {
                    return Http::response($this->fault['visitorHtml'] ?? '<meta name="geovizija:article-loader" content="direct-api-v1">', $this->fault['visitorStatus'] ?? 200);
                }
                $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html = '<html><head><title>'.$e($this->fault['title'] ?? $this->post->title.' | Geovizija').'</title>';
                $html .= '<meta name="geovizija:post-id" content="'.$e($this->fault['postId'] ?? $this->post->id).'">';
                $html .= '<meta property="og:url" content="'.$e($this->fault['canonical'] ?? self::URL).'">';
                if (empty($this->fault['omitImage'])) {
                    $html .= '<meta property="og:image" content="'.$e($this->fault['imageUrl'] ?? $shareUrl).'">';
                }
                if (! empty($this->fault['duplicateImage'])) {
                    $html .= '<meta property="og:image" content="'.self::COVER.'">';
                }

                return Http::response($html.'</head></html>', $this->fault['crawlerStatus'] ?? 200);
            }
            if ($url === $coverUrl || $url === $shareUrl) {
                if (! empty($this->fault['network'])) {
                    return Http::failedConnection();
                }

                $bytes = $generated ? File::get(public_path($url === $shareUrl
                    ? PostImageGenerator::sharePath($this->post->image_url) : $this->post->image_url)) : $this->jpeg;

                return Http::response($this->fault['imageBody'] ?? $bytes, $this->fault['imageStatus'] ?? 200,
                    ['Content-Type' => $this->fault['imageType'] ?? 'image/jpeg']);
            }
            if ($url === 'https://graph.facebook.com/v23.0/') {
                return Http::response($this->fault['scrapeBody'] ?? ['id' => 'preview-id'], $this->fault['scrapeStatus'] ?? 200);
            }
            if ($url === self::FEED) {
                return Http::response(['id' => '123_456']);
            }
            if ($request->method() === 'DELETE') {
                return Http::response(['success' => true]);
            }

            throw new \RuntimeException('Unexpected request: '.$url);
        });
    }

    public function test_only_a_verified_public_article_and_cover_are_shared(): void
    {
        $result = app(MetaPublisher::class)->share($this->post);

        $this->assertSame('posted', $result['status']);
        Http::assertSent(fn (Request $r) => $r->url() === self::API);
        Http::assertSent(fn (Request $r) => $r->url() === self::COVER && str_contains($r->header('User-Agent')[0], 'facebookexternalhit'));
        Http::assertSent(fn (Request $r) => $r->url() === self::FEED && $r['link'] === self::URL);
        $this->assertNull($this->post->refresh()->meta_error);
    }

    public static function failures(): array
    {
        return [
            'public API unavailable' => [['apiStatus' => 404]],
            'wrong article in preview' => [['preview' => ['id' => '999']]],
            'wrong cover in preview' => [['preview' => ['imageUrl' => 'https://geovizija.com/wrong.jpg']]],
            'wrong share image in preview' => [['preview' => ['shareImageUrl' => 'https://geovizija.com/wrong.jpg']]],
            'wrong article in public API' => [['live' => ['slug' => 'wrong-article']]],
            'missing body' => [['live' => ['content' => '']]],
            'visitor link broken' => [['visitorStatus' => 404]],
            'old frontend with 100 article limit' => [['visitorHtml' => '<div id="root"></div>']],
            'crawler link broken' => [['crawlerStatus' => 503]],
            'wrong preview identity' => [['postId' => '999']],
            'wrong canonical link' => [['canonical' => 'https://geovizija.com/article/wrong']],
            'wrong title' => [['title' => 'Wrong article | Geovizija']],
            'stale preview cover' => [['imageUrl' => 'https://geovizija.com/old.jpg']],
            'missing preview image' => [['omitImage' => true]],
            'ambiguous preview images' => [['duplicateImage' => true]],
            'deleted image' => [['imageStatus' => 404]],
            'HTML pretending to be an image' => [['imageBody' => '<html>404</html>', 'imageType' => 'image/jpeg']],
            'wrong image content type' => [['imageType' => 'text/html']],
            'image connection failure' => [['network' => true]],
            'image redirects elsewhere' => [['imageStatus' => 302]],
            'Facebook rejects refresh' => [['scrapeStatus' => 400, 'scrapeBody' => ['error' => ['message' => 'Scrape failed']]]],
            'Facebook gives an invalid refresh response' => [['scrapeBody' => []]],
        ];
    }

    #[DataProvider('failures')]
    public function test_invalid_public_links_or_covers_block_sharing(array $fault): void
    {
        $this->fault = $fault;
        $result = app(MetaPublisher::class)->share($this->post);

        $this->assertSame('failed', $result['status']);
        $this->assertNotEmpty($this->post->refresh()->meta_error);
        $this->assertNull($this->post->meta_post_id);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::FEED);
    }

    public function test_failed_verification_never_deletes_an_existing_facebook_post(): void
    {
        $this->post->forceFill(['meta_post_id' => '123_old'])->save();
        $this->fault = ['imageStatus' => 404];

        $this->assertSame('failed', app(MetaPublisher::class)->share($this->post, force: true)['status']);
        $this->assertSame('123_old', $this->post->refresh()->meta_post_id);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' || $r->url() === self::FEED);
    }

    public function test_scheduled_article_is_verified_through_its_preview_without_publishing_it_early(): void
    {
        $this->post->update(['published_at' => now()->addDay()]);

        $this->assertSame('scheduled', app(MetaPublisher::class)->share($this->post)['status']);
        Http::assertNotSent(fn (Request $r) => $r->url() === self::API);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/preview');
        Http::assertSent(fn (Request $r) => $r->url() === self::FEED && $r['published'] === 'false');
    }

    public function test_a_failed_share_can_be_retried_after_the_problem_is_fixed(): void
    {
        $this->fault = ['imageStatus' => 404];
        $this->assertSame('failed', app(MetaPublisher::class)->share($this->post)['status']);
        $this->fault = [];

        $this->assertSame('posted', app(MetaPublisher::class)->share($this->post)['status']);
        $this->assertNull($this->post->refresh()->meta_error);
        $this->assertSame('123_456', $this->post->meta_post_id);
    }

    public function test_missing_source_for_a_generated_cover_blocks_sharing(): void
    {
        $this->post->update(['image_url' => 'media/posts/does-not-exist-w3.jpg']);

        $this->assertSame('failed', app(MetaPublisher::class)->share($this->post)['status']);
        Http::assertNothingSent();
    }

    public function test_public_image_bytes_must_match_the_current_stored_cover(): void
    {
        $path = app(PostImageGenerator::class)->generate($this->post, 'data:image/jpeg;base64,'.base64_encode($this->jpeg));
        try {
            // A valid JPEG at the correct URL is still wrong if a CDN serves different pixels.
            $this->fault = ['imageBody' => $this->jpeg];

            $result = app(MetaPublisher::class)->share($this->post);

            $this->assertSame('failed', $result['status']);
            $this->assertStringContainsString('razlikuje', $result['message']);
            Http::assertNotSent(fn (Request $r) => $r->url() === self::FEED);
        } finally {
            File::delete([public_path($path), public_path(PostImageGenerator::sharePath($path)),
                public_path(PostImageGenerator::portraitPath($path)), PostImageGenerator::originalPath($path)]);
        }
    }
}
