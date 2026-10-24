<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Services\PostImageGenerator;

/** Repairs old cover URLs deleted before shared images were retained. */
class LegacyPostImageController extends Controller
{
    public function __invoke(string $filename)
    {
        abort_unless(preg_match('/^([a-z0-9-]+)-\d{14}(?:-[a-f0-9]{8})?(?:-w[m23])?(-fbw|-fb|-ig)?\.jpg$/D', $filename, $parts), 404);
        $post = Post::where('slug', $parts[1])->whereNotNull('published_at')->firstOrFail();
        abort_unless(PostImageGenerator::isGenerated($post->image_url), 404);

        // Resolve only a cover for this exact article, never a different article's photo.
        $path = match ($parts[2] ?? '') {
            '-fbw', '-fb' => PostImageGenerator::sharePath($post->image_url),
            '-ig' => PostImageGenerator::portraitPath($post->image_url),
            default => $post->image_url,
        };
        abort_unless(is_file(public_path($path)), 404);

        return response()->file(public_path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=300',
            'X-Geovizija-Image-Fallback' => 'same-article',
        ]);
    }
}
