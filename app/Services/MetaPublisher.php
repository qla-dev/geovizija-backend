<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shares an article on the Geovizija Facebook Page through the Graph API.
 *
 * The post links to the article (SITE_URL/article/{slug}); the frontend's og.php
 * gives Facebook's crawler that article's Open Graph tags. An article published now is posted at once; a scheduled
 * one becomes a scheduled Page post (visible in the Business Suite Planner),
 * so no cron or queue is needed. Facebook only schedules 10 minutes to 30 days
 * ahead: closer times are moved to +10 minutes, later ones are not shared.
 * Facebook reads the link preview when the post is created, so og.php reads a
 * scheduled article from /posts/{slug}/preview (PostController::preview).
 *
 * Never throws: the result is returned and errors are stored in posts.meta_error.
 */
class MetaPublisher
{
    private const MIN_SCHEDULE_MINUTES = 10;

    private const MAX_SCHEDULE_DAYS = 30;

    public static function configured(): bool
    {
        return filled(config('services.meta.page_id')) && filled(config('services.meta.page_token'));
    }

    /**
     * @return array{status: 'posted'|'scheduled'|'skipped'|'failed', message: string, postId?: string, scheduledFor?: string}
     */
    public function share(Post $post, bool $force = false): array
    {
        if (! self::configured()) {
            return ['status' => 'skipped', 'message' => 'Facebook nije povezan (META_PAGE_ID / META_PAGE_TOKEN).'];
        }
        if ($post->meta_post_id && ! $force) {
            return ['status' => 'skipped', 'message' => 'Članak je već podijeljen na Facebooku.', 'postId' => $post->meta_post_id];
        }

        // A draft has no page to link to yet; publishing it (PostController::update) shares it.
        if ($post->published_at === null) {
            return ['status' => 'skipped', 'message' => 'Nacrt se dijeli tek kad dobije datum objave.'];
        }

        $publishAt = $post->published_at;
        if ($publishAt->greaterThan(now()->addDays(self::MAX_SCHEDULE_DAYS))) {
            return $this->fail($post, 'Facebook zakazuje najviše 30 dana unaprijed; podijelite članak kasnije.');
        }

        $params = [
            'message' => $this->message($post),
            'link' => self::articleUrl($post),
            'access_token' => config('services.meta.page_token'),
        ];

        $scheduledFor = null;
        if ($publishAt->isFuture()) {
            $scheduledFor = $publishAt->max(now()->addMinutes(self::MIN_SCHEDULE_MINUTES)->addMinute());
            $params['published'] = 'false';
            $params['scheduled_publish_time'] = $scheduledFor->getTimestamp();
        }

        // The link image with the current title, then let Facebook re-read the preview (it keeps one
        // for weeks) so a new cover or title shows.
        if (PostImageGenerator::isGenerated($post->image_url)) {
            try {
                app(InstagramStory::class)->facebook($post);
            } catch (Throwable $exception) {
                Log::warning("Facebook image for post {$post->id} not drawn: {$exception->getMessage()}");
            }
        }
        try {
            Http::asForm()->timeout(20)->post($this->graph(''), ['id' => $params['link'], 'scrape' => 'true', 'access_token' => $params['access_token']]);
        } catch (Throwable) {
            // The post still goes out, with whatever preview Facebook has.
        }

        try {
            $response = Http::asForm()->timeout(30)->post($this->endpoint(), $params);
        } catch (Throwable $exception) {
            return $this->fail($post, 'Facebook nije dostupan: '.$exception->getMessage());
        }

        if (! $response->successful() || ! $response->json('id')) {
            return $this->fail($post, 'Facebook je odbio objavu: '.($response->json('error.message') ?? "HTTP {$response->status()}"));
        }

        // A forced re-share replaces the earlier Page post (removed only once the new one exists).
        if ($force && $post->meta_post_id && $post->meta_post_id !== $response->json('id')) {
            try {
                Http::timeout(20)->delete($this->graph($post->meta_post_id).'?'.http_build_query(['access_token' => $params['access_token']]));
            } catch (Throwable $exception) {
                Log::warning("Old Facebook post {$post->meta_post_id} not deleted: {$exception->getMessage()}");
            }
        }

        $post->forceFill([
            'meta_post_id' => (string) $response->json('id'),
            'meta_shared_at' => now(),
            'meta_error' => null,
        ])->save();

        return $scheduledFor
            ? ['status' => 'scheduled', 'message' => 'Facebook objava je zakazana.', 'postId' => $post->meta_post_id, 'scheduledFor' => $scheduledFor->toIso8601String()]
            : ['status' => 'posted', 'message' => 'Članak je podijeljen na Facebooku.', 'postId' => $post->meta_post_id];
    }

    /** Built from SITE_URL, not APP_URL, so a CLI run on another machine still links to the live site. */
    public static function articleUrl(Post $post): string
    {
        return rtrim((string) config('services.meta.site_url'), '/').'/article/'.rawurlencode($post->slug);
    }

    /** The excerpt only: the title is already on the link image (InstagramStory::facebook) under it. */
    private function message(Post $post): string
    {
        return trim((string) $post->excerpt) ?: $post->title;
    }

    private function endpoint(): string
    {
        return $this->graph(config('services.meta.page_id').'/feed');
    }

    private function graph(string $path): string
    {
        return sprintf('https://graph.facebook.com/%s/%s', config('services.meta.graph_version'), $path);
    }

    private function fail(Post $post, string $message): array
    {
        Log::warning("Meta share failed for post {$post->id}: {$message}");
        $post->forceFill(['meta_error' => mb_substr($message, 0, 500)])->save();

        return ['status' => 'failed', 'message' => $message];
    }
}
