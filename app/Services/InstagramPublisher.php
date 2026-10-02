<?php

namespace App\Services;

use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Posts an article's cover with a caption on the Geovizija Instagram account (Graph API content
 * publishing: create a media container, wait until Instagram has fetched the image, publish it).
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

        $token = config('services.meta.page_token');
        $image = (new PostResource($post))->resolve()['imageUrl'];

        try {
            $container = Http::asForm()->timeout(60)->post($this->graph(config('services.meta.ig_user_id').'/media'), [
                'image_url' => $image,
                'caption' => $this->caption($post),
                'access_token' => $token,
            ]);
            if (! $container->successful() || ! $container->json('id')) {
                return $this->fail($post, 'Instagram je odbio sliku: '.($container->json('error.message') ?? "HTTP {$container->status()}"));
            }

            // Instagram downloads the image first; a JPEG is usually ready within a few seconds.
            for ($try = 0; $try < 10; $try++) {
                $status = Http::timeout(20)->get($this->graph($container->json('id')), ['fields' => 'status_code', 'access_token' => $token])->json('status_code');
                if ($status === 'FINISHED') {
                    break;
                }
                if ($status === 'ERROR' || $status === 'EXPIRED') {
                    return $this->fail($post, "Instagram nije obradio sliku ({$status}).");
                }
                Sleep::for(2)->seconds();
            }

            $published = Http::asForm()->timeout(60)->post($this->graph(config('services.meta.ig_user_id').'/media_publish'), [
                'creation_id' => $container->json('id'),
                'access_token' => $token,
            ]);
        } catch (Throwable $exception) {
            return $this->fail($post, 'Instagram nije dostupan: '.$exception->getMessage());
        }

        if (! $published->successful() || ! $published->json('id')) {
            return $this->fail($post, 'Instagram je odbio objavu: '.($published->json('error.message') ?? "HTTP {$published->status()}"));
        }

        $post->forceFill([
            'ig_status' => 'posted',
            'ig_media_id' => (string) $published->json('id'),
            'ig_shared_at' => now(),
            'ig_error' => null,
        ])->save();

        return ['status' => 'posted', 'message' => 'Članak je objavljen na Instagramu.', 'mediaId' => $post->ig_media_id];
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
