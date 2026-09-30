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

    public function generate(Post $post): string
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
                'content' => [['type' => 'text', 'text' => $this->prompt($post)]],
            ]],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(120)
                ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'Geovizija post images'])
                ->post((string) config('services.openrouter.url'), $payload);
        } catch (ConnectionException $exception) {
            Log::warning('Post image generation failed to connect.', ['post_id' => $post->id, 'error' => $exception->getMessage()]);

            throw new RuntimeException('The image generator is not available right now. Please try again.');
        }

        $image = self::decode(data_get($response->json(), 'choices.0.message.images.0.image_url.url'));
        if (! $response->successful() || ! $image) {
            $error = data_get($response->json(), 'error.message') ?: 'The image generator did not return an image.';
            Log::warning('Post image generation returned no image.', ['post_id' => $post->id, 'http_status' => $response->status(), 'error' => $error]);

            throw new RuntimeException($error);
        }

        $image = self::toJpeg($image);
        $path = self::DIRECTORY.'/'.$post->slug.'-'.now()->format('YmdHis').'.'.$image['extension'];
        File::ensureDirectoryExists(public_path(self::DIRECTORY));
        File::put(public_path($path), $image['bytes']);

        $previous = $post->image_url;
        $post->update(['image_url' => $path]);

        // Remove the image this one replaces, but only if it was one of ours.
        if ($previous && str_starts_with($previous, self::DIRECTORY.'/') && $previous !== $path) {
            File::delete(public_path($previous));
        }

        return $path;
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
