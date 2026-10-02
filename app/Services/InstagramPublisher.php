<?php

namespace App\Services;

use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Posts an article's cover with a caption on the Geovizija Instagram account (Graph API content
 * publishing: create a media container, wait until Instagram has fetched the image, publish it),
 * then the full-screen story from InstagramStory (the API cannot add link stickers, so the story
 * points to the link in the profile bio). Post and story count as two of the 100 daily posts.
 *
 * Instagram cannot schedule posts and captions have no clickable links, so new articles are queued
 * (ig_status = pending) and `instagram:publish-due` posts them once published_at has passed; it runs
 * every minute from the server cron. Limit: 100 API posts per rolling 24 hours.
 *
 * Never throws: the result is returned and errors are stored in posts.ig_error.
 */
class InstagramPublisher
{
    public static function configured(): bool
    {
        return filled(config('services.meta.ig_user_id')) && filled(config('services.meta.page_token'));
    }

    /** Marks a new article for Instagram (or not), for the hooks that also share on Facebook. */
    public static function queue(Post $post, bool $share = true): void
    {
        // Never fails the publish (e.g. before the ig_* migration has run on the server).
        try {
            $post->forceFill(['ig_status' => $share && self::configured() ? 'pending' : 'skipped'])->save();
        } catch (Throwable $exception) {
            Log::warning("Instagram queue failed for post {$post->id}: {$exception->getMessage()}");
            $post->offsetUnset('ig_status');
        }
    }

    /**
     * @return array{status: 'posted'|'pending'|'skipped'|'failed', message: string, mediaId?: string}
     */
    public function publish(Post $post, bool $force = false): array
    {
        if (! self::configured()) {
            return ['status' => 'skipped', 'message' => 'Instagram nije povezan (META_IG_USER_ID / META_PAGE_TOKEN).'];
        }
        if ($post->ig_media_id && ! $force) {
            return ['status' => 'skipped', 'message' => 'Članak je već na Instagramu.', 'mediaId' => $post->ig_media_id];
        }
        if (! $post->isPublished()) {
            return ['status' => 'pending', 'message' => 'Članak još nije objavljen; ide na Instagram u vrijeme objave.'];
        }
        if (! $post->image_url) {
            return $this->fail($post, 'Članak nema naslovnu sliku.');
        }

        try {
            $container = $this->container([
                'image_url' => $this->feedImage($post),
                'caption' => $this->caption($post),
            ]);
            if (isset($container['error'])) {
                return $this->fail($post, $container['error']);
            }
            $published = $this->publishContainer($container['id']);
        } catch (Throwable $exception) {
            return $this->fail($post, 'Instagram nije dostupan: '.$exception->getMessage());
        }

        if (! $published->successful() || ! $published->json('id')) {
            return $this->fail($post, 'Instagram je odbio objavu: '.($published->json('error.message') ?? "HTTP {$published->status()}"));
        }

        // Then the full-screen story when the daily story budget allows; a failed story keeps the
        // post and is noted in ig_error.
        $withStory = self::storyDue();
        $story = $withStory ? $this->story($post) : null;

        $post->forceFill([
            'ig_status' => 'posted',
            'ig_media_id' => (string) $published->json('id'),
            'ig_shared_at' => now(),
            'ig_error' => $story === null ? null : mb_substr("Story: {$story}", 0, 500),
        ])->save();

        return [
            'status' => 'posted',
            'message' => 'Članak je objavljen na Instagramu'.match (true) {
                ! $withStory => ' (bez storyja, dnevni raspored storyja).',
                $story === null => ' (i story).',
                default => "; story nije: {$story}",
            },
            'mediaId' => $post->ig_media_id,
        ];
    }

    /**
     * Stories are rationed so posts keep room in Instagram's 100 per 24 hours: at most
     * services.meta.ig_stories_per_day in 24 hours, at least ig_story_gap_minutes apart, which
     * spreads them over the day.
     */
    public static function storyDue(): bool
    {
        $recent = self::recentStories();

        return count($recent) < (int) config('services.meta.ig_stories_per_day', 20)
            && (! $recent || max($recent) <= now()->subMinutes((int) config('services.meta.ig_story_gap_minutes', 45))->getTimestamp());
    }

    /** @return list<int> timestamps of stories in the last 24 hours */
    private static function recentStories(): array
    {
        $since = now()->subDay()->getTimestamp();

        $saved = json_decode((string) @file_get_contents(storage_path(self::STORIES_FILE)), true);

        return array_values(array_filter(is_array($saved) ? $saved : [], fn ($at) => $at > $since));
    }

    /** Kept outside the cache, which every redeploy clears. */
    private const STORIES_FILE = 'app/instagram-stories.json';

    /** Publishes the article's story (see InstagramStory); returns null or why it failed. */
    public function story(Post $post): ?string
    {
        $error = $this->publishStory($post);
        if ($error === null) {
            File::put(storage_path(self::STORIES_FILE), json_encode([...self::recentStories(), now()->getTimestamp()]));
        }

        return $error;
    }

    private function publishStory(Post $post): ?string
    {
        try {
            $path = app(InstagramStory::class)->make($post);
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        try {
            $container = $this->container(['image_url' => asset($path), 'media_type' => 'STORIES']);
            if (isset($container['error'])) {
                return $container['error'];
            }
            $published = $this->publishContainer($container['id']);

            return $published->successful() && $published->json('id')
                ? null
                : 'Instagram je odbio story: '.($published->json('error.message') ?? "HTTP {$published->status()}");
        } catch (Throwable $exception) {
            return 'Instagram nije dostupan: '.$exception->getMessage();
        } finally {
            // Instagram has its copy once the container is processed.
            File::delete(public_path($path));
        }
    }

    /**
     * Creates a media container and waits until Instagram has downloaded the image (a JPEG is usually
     * ready within a few seconds).
     *
     * @return array{id: string}|array{error: string}
     */
    private function container(array $params): array
    {
        $token = config('services.meta.page_token');
        $container = Http::asForm()->timeout(60)->post($this->graph(config('services.meta.ig_user_id').'/media'), $params + ['access_token' => $token]);
        if (! $container->successful() || ! $container->json('id')) {
            return ['error' => 'Instagram je odbio sliku: '.($container->json('error.message') ?? "HTTP {$container->status()}")];
        }
        $id = (string) $container->json('id');
        for ($try = 0; $try < 10; $try++) {
            $status = Http::timeout(20)->get($this->graph($id), ['fields' => 'status_code', 'access_token' => $token])->json('status_code');
            if ($status === 'FINISHED') {
                break;
            }
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                return ['error' => "Instagram nije obradio sliku ({$status})."];
            }
            Sleep::for(2)->seconds();
        }

        return ['id' => $id];
    }

    private function publishContainer(string $id): Response
    {
        return Http::asForm()->timeout(60)->post($this->graph(config('services.meta.ig_user_id').'/media_publish'), [
            'creation_id' => $id,
            'access_token' => config('services.meta.page_token'),
        ]);
    }

    /** The 4:5 feed image (InstagramStory::feed), drawn now with the current title; else the cover itself. */
    private function feedImage(Post $post): string
    {
        if (PostImageGenerator::isGenerated($post->image_url)) {
            try {
                return asset(app(InstagramStory::class)->feed($post));
            } catch (Throwable $exception) {
                Log::warning("Instagram image for post {$post->id} not drawn: {$exception->getMessage()}");
            }
        }

        return (new PostResource($post))->resolve()['imageUrl'];
    }

    /** Title, excerpt, where to read on (captions have no links) and a few hashtags. */
    public function caption(Post $post): string
    {
        $post->loadMissing('category');
        $tags = collect(['geovizija', $post->category?->slug, 'balkan', 'priroda', 'geografija'])
            ->filter()->unique()->map(fn ($tag) => '#'.str_replace('-', '', $tag))->implode(' ');

        return mb_substr(trim($post->title."\n\n".$post->excerpt)
            ."\n\nCijeli članak na geovizija.com (link u opisu profila).\n\n".$tags, 0, 2200);
    }

    private function graph(string $path): string
    {
        return sprintf('https://graph.facebook.com/%s/%s', config('services.meta.graph_version'), $path);
    }

    private function fail(Post $post, string $message): array
    {
        Log::warning("Instagram publish failed for post {$post->id}: {$message}");
        $post->forceFill(['ig_status' => 'failed', 'ig_error' => mb_substr($message, 0, 500)])->save();

        return ['status' => 'failed', 'message' => $message];
    }
}
