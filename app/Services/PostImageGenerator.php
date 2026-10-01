<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Draws a cover image for a post through OpenRouter's image-output models
 * (same request shape as freightbook's OpenRouterImageGenerator).
 *
 * The model returns the picture as a base64 data URL in choices.0.message.images; it is saved under
 * public/media/posts and the post's image_url is set to that relative path (PostResource turns it
 * into an absolute URL).
 */
class PostImageGenerator
{
    public const DIRECTORY = 'media/posts';

    /** Placeholder the content generator leaves in the body: a line "[[SLIKA: description]]". */
    public const INLINE_MARKER = '/^\[\[SLIKA:\s*(.+?)\s*\]\]$/mu';

    /** Largest image accepted from an outside source (data URL or https link). */
    public const MAX_SOURCE_BYTES = 8 * 1024 * 1024;

    /**
     * Stores the cover image as the post's image_url: the supplied $source (data URL or https
     * link) when given, otherwise one drawn through OpenRouter.
     */
    public function generate(Post $post, ?string $source = null): string
    {
        $path = $source !== null
            ? $this->save($this->fetchSource($source), $post->slug)
            : $this->draw($this->prompt($post), $post->slug, $post->id);

        $previous = $post->image_url;
        $post->update(['image_url' => $path]);

        // Remove the image this one replaces, but only if it was one of ours.
        if ($previous && str_starts_with($previous, self::DIRECTORY.'/') && $previous !== $path) {
            File::delete(public_path($previous));
        }

        return $path;
    }

    /**
     * Draws the image for the first "[[SLIKA: ...]]" marker in the body and replaces the marker
     * with "![description](media/posts/...)". Returns null when no marker is left.
     * One image per call keeps each request well inside hosting time limits.
     */
    public function generateNextInline(Post $post, ?string $source = null): ?string
    {
        if (! preg_match(self::INLINE_MARKER, $post->content, $match)) {
            return null;
        }

        $description = $match[1];
        $number = preg_match_all('#!\[[^\]]*\]\('.preg_quote(self::DIRECTORY, '#').'/#', $post->content) + 1;
        $basename = "{$post->slug}-inline-{$number}";
        $path = $source !== null
            ? $this->save($this->fetchSource($source), $basename)
            : $this->draw($this->inlinePrompt($post, $description), $basename, $post->id);

        $caption = str_replace([']', '['], '', $description);
        $post->update(['content' => preg_replace(self::INLINE_MARKER, "![{$caption}]({$path})", $post->content, 1)]);

        return $path;
    }

    public static function pendingInline(Post $post): int
    {
        return preg_match_all(self::INLINE_MARKER, $post->content);
    }

    /** Relative paths of our images referenced from a post body. */
    public static function inlinePaths(string $content): array
    {
        preg_match_all('#\]\(('.preg_quote(self::DIRECTORY, '#').'/[^)\s]+)\)#', $content, $matches);

        return $matches[1];
    }

    /** Calls the image model and saves the result under public/media/posts; returns the relative path. */
    private function draw(string $prompt, string $basename, ?int $postId = null): string
    {
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $payload = [
            'model' => (string) config('services.openrouter.image_model'),
            'modalities' => ['image', 'text'],
            'image_config' => ['aspect_ratio' => '16:9'],
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => $prompt]],
            ]],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(120)
                ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'Geovizija post images'])
                ->post((string) config('services.openrouter.url'), $payload);
        } catch (ConnectionException $exception) {
            Log::warning('Post image generation failed to connect.', ['post_id' => $postId, 'error' => $exception->getMessage()]);

            throw new RuntimeException('The image generator is not available right now. Please try again.');
        }

        $image = self::decode(data_get($response->json(), 'choices.0.message.images.0.image_url.url'));
        if (! $response->successful() || ! $image) {
            $error = data_get($response->json(), 'error.message') ?: 'The image generator did not return an image.';
            Log::warning('Post image generation returned no image.', ['post_id' => $postId, 'http_status' => $response->status(), 'error' => $error]);

            throw new RuntimeException($error);
        }

        return $this->save($image, $basename);
    }

    /**
     * Turns an outside image (a "data:image/...;base64," URL or an https link) into image bytes.
     *
     * @return array{bytes: string, mime: string, extension: string}
     */
    private function fetchSource(string $source): array
    {
        $source = trim($source);

        if (str_starts_with($source, 'data:')) {
            $image = self::decode($source);
        } elseif (preg_match('#^https://#i', $source)) {
            try {
                $response = Http::timeout(30)->get($source);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Slika se ne može preuzeti: '.$exception->getMessage());
            }
            if (! $response->successful()) {
                throw new RuntimeException("Slika se ne može preuzeti (HTTP {$response->status()}).");
            }
            $bytes = $response->body();
            $info = @getimagesizefromstring($bytes);
            $type = $info ? image_type_to_extension($info[2], false) : null;
            $image = in_array($type, ['png', 'jpeg', 'webp', 'gif'], true)
                ? ['bytes' => $bytes, 'mime' => $info['mime'], 'extension' => $type === 'jpeg' ? 'jpg' : $type]
                : null;
        } else {
            throw new RuntimeException('Slika mora biti data:image/... URL ili https link.');
        }

        if (! $image || ! @getimagesizefromstring($image['bytes'])) {
            throw new RuntimeException('Poslana slika nije ispravna (PNG, JPEG, WebP ili GIF).');
        }
        if (strlen($image['bytes']) > self::MAX_SOURCE_BYTES) {
            throw new RuntimeException('Poslana slika je veća od 8 MB.');
        }

        return $image;
    }

    /** Saves image bytes (as JPEG when possible) under public/media/posts; returns the relative path. */
    private function save(array $image, string $basename): string
    {
        $image = self::toJpeg($image);
        $path = self::DIRECTORY.'/'.$basename.'-'.now()->format('YmdHis').'.'.$image['extension'];
        File::ensureDirectoryExists(public_path(self::DIRECTORY));
        File::put(public_path($path), $image['bytes']);

        return $path;
    }

    private function inlinePrompt(Post $post, string $description): string
    {
        return 'Create one photorealistic, landscape (16:9) editorial photograph that illustrates a scene inside a '
            .'Bosnian nature and geography magazine article. Natural light, realistic colours, National Geographic style. '
            .'It must look different from the article cover. Do not include any text, letters, logos, watermarks or captions.'
            ."\n\nArticle: {$post->title}"
            ."\nScene to show: {$description}";
    }

    public static function isGenerated(?string $imageUrl): bool
    {
        return $imageUrl !== null && str_starts_with($imageUrl, self::DIRECTORY.'/');
    }

    private function prompt(Post $post): string
    {
        $post->loadMissing('category');

        return 'Create one photorealistic, landscape (16:9) editorial cover photograph for a Bosnian nature and '
            .'geography magazine article. Natural light, rich but realistic colours, National Geographic style. '
            .'Do not include any text, letters, logos, watermarks or captions in the image.'
            ."\n\nCategory: ".($post->category?->name ?? 'General')
            ."\nTitle: {$post->title}"
            ."\nSummary: ".($post->excerpt ?: mb_substr(strip_tags($post->content), 0, 400));
    }

    /**
     * Re-encodes the (multi-megabyte PNG) model output as a JPEG when GD is available.
     *
     * @param  array{bytes: string, mime: string, extension: string}  $image
     * @return array{bytes: string, mime: string, extension: string}
     */
    private static function toJpeg(array $image): array
    {
        if ($image['extension'] === 'jpg' || ! function_exists('imagecreatefromstring')) {
            return $image;
        }

        $source = @imagecreatefromstring($image['bytes']);
        if ($source === false) {
            return $image;
        }

        ob_start();
        imagejpeg($source, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($source);

        return $bytes === '' ? $image : ['bytes' => $bytes, 'mime' => 'image/jpeg', 'extension' => 'jpg'];
    }

    /** @return array{bytes: string, mime: string, extension: string}|null */
    public static function decode(mixed $dataUrl): ?array
    {
        if (! is_string($dataUrl) || ! preg_match('#^data:(image/(png|jpeg|webp|gif));base64,(.+)$#s', $dataUrl, $match)) {
            return null;
        }
        $bytes = base64_decode($match[3], true);

        return $bytes === false || $bytes === ''
            ? null
            : ['bytes' => $bytes, 'mime' => $match[1], 'extension' => $match[2] === 'jpeg' ? 'jpg' : $match[2]];
    }
}
