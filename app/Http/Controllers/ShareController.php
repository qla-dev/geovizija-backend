<?php

namespace App\Http\Controllers;

use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\MetaPublisher;

/**
 * /share/{slug}: links shared before clean URLs. Facebook now links straight to
 * /article/{slug} (previewed by the frontend's og.php); this page keeps the old
 * links working: Open Graph tags for crawlers, a redirect for people.
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
            'shareUrl' => MetaPublisher::articleUrl($post),
            'articleUrl' => MetaPublisher::articleUrl($post),
        ]);
    }
}
