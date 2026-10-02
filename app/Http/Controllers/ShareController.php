<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\MetaPublisher;

/**
 * /share/{slug}: the link posted to Facebook. Crawlers read its Open Graph tags
 * (the site's #/article URLs cannot be previewed); people are sent on to the article.
 * Scheduled articles render too, because Facebook reads the preview when the
 * scheduled post is created.
 */
class ShareController extends Controller
{
    public function __invoke(Post $post)
    {
        $data = (new PostResource($post))->resolve();

        return response()->view('share', [
            'title' => $post->title,
            'description' => $post->excerpt,
            'image' => $data['imageUrl'],
            'shareUrl' => MetaPublisher::shareUrl($post),
            'articleUrl' => rtrim((string) config('services.meta.site_url'), '/')."/#/article/{$post->id}",
        ]);
    }
}
