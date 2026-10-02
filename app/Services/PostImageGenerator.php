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

    /** Saved images (see toJpeg): width limit, size limit, lowest JPEG quality before shrinking. */
    private const MAX_WIDTH = 1600;

    private const MAX_BYTES = 250 * 1000;

    private const QUALITY_FLOOR = 60;

    /** Ends the file name of every image saved through toJpeg(), e.g. media/posts/una-20261002180000-wm.jpg. */
    private const PROCESSED_MARK = '-w3';

    /** Originals of saved images, under storage/ (see originalPath). */
    private const ORIGINALS = 'app/originals';

    /**
     * Stores the cover image as the post's image_url: the supplied $source (data URL or https
     * link) when given, otherwise one drawn through OpenRouter.
     */
    public function generate(Post $post, ?string $source = null): string
    {
        $path = $source !== null
            ? $this->save($this->fetchSource($source), $post->slug)
            : $this->draw($this->prompt($post), $post->slug, $post->id);

        self::makePortrait($path);

        $previous = $post->image_url;
        $post->update(['image_url' => $path]);

        // Remove the image this one replaces, but only if it was one of ours.
        if ($previous && str_starts_with($previous, self::DIRECTORY.'/') && $previous !== $path) {
            self::deleteCover($previous);
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

    /**
     * Replaces the existing in-text image number $number (1-based, in body order) with the supplied
     * $source or a new drawing of $description (default: the old caption). The old file is deleted
     * only after the new one is stored, so a failure leaves the article unchanged.
     */
    public function replaceInline(Post $post, int $number, ?string $source = null, ?string $description = null): string
    {
        $images = self::inlineImages((string) $post->content);
        if (! isset($images[$number - 1])) {
            throw new RuntimeException("Članak nema sliku u tekstu broj {$number} (ima ih ".count($images).').');
        }

        [$tag, $caption, $previous] = $images[$number - 1];
        $description = trim((string) $description) ?: $caption;
        $basename = "{$post->slug}-inline-{$number}";
        $path = $source !== null
            ? $this->save($this->fetchSource($source), $basename)
            : $this->draw($this->inlinePrompt($post, $description), $basename, $post->id);

        $caption = str_replace([']', '['], '', $description);
        $post->update(['content' => str_replace($tag, "![{$caption}]({$path})", $post->content)]);

        if ($previous !== $path) {
            File::delete(public_path($previous));
        }

        return $path;
    }

    /** Whether a stored path is one of ours from before toJpeg() cropped and watermarked images. */
    public static function needsReprocess(?string $path): bool
    {
        return self::isGenerated($path) && ! str_contains((string) $path, self::PROCESSED_MARK.'.');
    }

    /**
     * Runs the post's older cover and in-text images through toJpeg() (16:9, logo, 100 KB) under new
     * names, so Facebook and browsers do not keep the old copies. Missing files are left as they are.
     * Returns how many images were rewritten.
     */
    public function reprocess(Post $post): int
    {
        $done = 0;
        $rewrite = function (string $path) use (&$done): ?string {
            if (! File::exists(public_path($path))) {
                return null;
            }
            $basename = preg_replace('/-\d{14}(-w[m2])?$/', '', pathinfo($path, PATHINFO_FILENAME));
            $bytes = File::get(public_path($path));
            foreach (array_keys(self::OLD_LOGOS) as $mark) {
                if (str_contains($path, $mark.'.')) {
                    $bytes = self::withoutOldLogo($bytes, $mark);
                }
            }
            $new = $this->save(['bytes' => $bytes, 'mime' => 'image/jpeg', 'extension' => pathinfo($path, PATHINFO_EXTENSION)], $basename);
            $done++;

            return $new;
        };

        $cover = self::needsReprocess($post->image_url) ? $rewrite($post->image_url) : null;
        $content = (string) $post->content;
        $old = [];
        foreach (self::inlinePaths($content) as $path) {
            if (self::needsReprocess($path) && ($new = $rewrite($path))) {
                $content = str_replace("]({$path})", "]({$new})", $content);
                $old[] = $path;
            }
        }

        $previousCover = $post->image_url;
        $post->update(array_filter(['image_url' => $cover, 'content' => $old ? $content : null]));
        if ($cover) {
            File::delete(public_path($previousCover));
        }
        foreach ($old as $path) {
            File::delete(public_path($path));
        }

        return $done;
    }

    /**
     * Earlier formats put the logo bottom-right and their originals are gone, so that logo is cut
     * off: the image keeps everything above it, centred at 16:9, and toJpeg() then adds the
     * current logo. Per mark: [min logo width px, logo width share, logo height/width, margin share].
     */
    private const OLD_LOGOS = [
        '-wm' => [150, 0.2, 304 / 739, 0.012],   // large, with a shadow
        '-w2' => [110, 0.11, 208 / 643, 0.02],   // small green
    ];

    private static function withoutOldLogo(string $bytes, string $mark): string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return $bytes;
        }
        [$minWidth, $share, $ratio, $marginShare] = self::OLD_LOGOS[$mark];
        [$width, $height] = [imagesx($source), imagesy($source)];
        $cropHeight = (int) floor($height - $width * $marginShare - max($minWidth, $width * $share) * $ratio - 4);
        $cropWidth = min($width, (int) round($cropHeight * 16 / 9));
        $canvas = imagecreatetruecolor($cropWidth, $cropHeight);
        imagecopy($canvas, $source, 0, 0, intdiv($width - $cropWidth, 2), 0, $cropWidth, $cropHeight);
        ob_start();
        imagejpeg($canvas, null, 95);
        imagedestroy($source);
        imagedestroy($canvas);

        return (string) ob_get_clean();
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

    /**
     * Our in-text images in body order, each as [full "![caption](path)" tag, caption, path].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function inlineImages(string $content): array
    {
        preg_match_all('#!\[([^\]]*)\]\(('.preg_quote(self::DIRECTORY, '#').'/[^)\s]+)\)#', $content, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => [$match[0], $match[1], $match[2]], $matches);
    }

    /**
     * Turns the absolute image URLs PostResource hands out back into stored relative paths, so a
     * body read from the API and sent back unchanged keeps (and does not delete) its images.
     */
    public static function relativeContent(string $content): string
    {
        return str_replace('('.asset(self::DIRECTORY).'/', '('.self::DIRECTORY.'/', $content);
    }

    /** Deletes the files of in-text images that were in $before but are no longer referenced by $content. */
    public static function pruneInline(array $before, string $content): void
    {
        foreach (array_diff($before, self::inlinePaths($content)) as $path) {
            File::delete(public_path($path));
        }
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
        $original = $image['bytes'];
        $image = self::toJpeg($image);
        $mark = $image['bytes'] !== $original ? self::PROCESSED_MARK : '';
        $path = self::DIRECTORY.'/'.$basename.'-'.now()->format('YmdHis').$mark.'.'.$image['extension'];
        File::ensureDirectoryExists(public_path(self::DIRECTORY));
        File::put(public_path($path), $image['bytes']);

        // The untouched original, so other formats (Instagram) and later changes start from full
        // quality instead of recompressing the published JPEG.
        if ($mark !== '') {
            File::ensureDirectoryExists(storage_path(self::ORIGINALS));
            File::put(self::originalPath($path), $original);
        }

        return $path;
    }

    /** Where the original of a saved image is kept (storage/app/originals, not public). */
    public static function originalPath(string $path): string
    {
        return storage_path(self::ORIGINALS.'/'.pathinfo($path, PATHINFO_FILENAME).'.orig');
    }

    /** The original of a saved image when kept, else the saved image itself (older articles). */
    public static function sourceImage(string $path): \GdImage|false
    {
        $file = File::exists(self::originalPath($path)) ? self::originalPath($path) : public_path($path);

        return File::exists($file) ? @imagecreatefromstring((string) File::get($file)) : false;
    }

    /** media/posts/x-...-w3.jpg → media/posts/x-...-w3-ig.jpg: the Instagram feed version of a cover. */
    public static function portraitPath(string $path): string
    {
        return preg_replace('/\.\w+$/', '-ig.jpg', $path);
    }

    /**
     * The Instagram feed version of a cover: 4:5 (1080 x 1350 when the original is large enough),
     * from the original, with the logo in the same places as the landscape version.
     */
    public static function makePortrait(string $path): ?string
    {
        $source = self::sourceImage($path);
        if ($source === false) {
            return null;
        }
        $width = min(1080, imagesx($source), (int) round(imagesy($source) * 4 / 5));
        $canvas = self::watermarked($source, $width, 4 / 5);
        imageinterlace($canvas, true);
        imagejpeg($canvas, public_path(self::portraitPath($path)), 90);
        imagedestroy($canvas);
        imagedestroy($source);

        return self::portraitPath($path);
    }

    /** Deletes a saved cover with its Instagram version and original. */
    private static function deleteCover(string $path): void
    {
        File::delete([public_path($path), public_path(self::portraitPath($path)), self::originalPath($path)]);
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
     * Every saved image (drawn or supplied): at most MAX_WIDTH wide, the Geovizija logo in the
     * bottom-right corner, and a progressive JPEG at the highest quality that fits MAX_BYTES
     * (smaller sizes only when even QUALITY_FLOOR does not fit). Without GD the image is kept as is.
     *
     * @param  array{bytes: string, mime: string, extension: string}  $image
     * @return array{bytes: string, mime: string, extension: string}
     */
    public static function toJpeg(array $image): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return $image;
        }

        $source = @imagecreatefromstring($image['bytes']);
        if ($source === false) {
            return $image;
        }

        $width = min(imagesx($source), (int) round(imagesy($source) * 16 / 9), self::MAX_WIDTH);
        do {
            $canvas = self::watermarked($source, $width);
            $bytes = self::bestJpeg($canvas);
            imagedestroy($canvas);
            $width = (int) round($width * 0.9);
        } while (strlen($bytes) > self::MAX_BYTES && $width >= 640);
        imagedestroy($source);

        return ['bytes' => $bytes, 'mime' => 'image/jpeg', 'extension' => 'jpg'];
    }

    /**
     * A $width-wide copy of $source at $ratio (centre crop; 16:9 so Facebook shows the large link
     * preview whatever shape the agent or model sent, 4:5 for the Instagram feed) with the logo like news photo agencies: green frame
     * top-left, GEOVIZIJA top-right, semi-transparent.
     */
    private static function watermarked(\GdImage $source, int $width, float $ratio = 16 / 9): \GdImage
    {
        [$sourceWidth, $sourceHeight] = [imagesx($source), imagesy($source)];
        $cropWidth = min($sourceWidth, (int) round($sourceHeight * $ratio));
        $cropHeight = min($sourceHeight, (int) round($sourceWidth / $ratio));
        $height = (int) round($width / $ratio);
        $canvas = imagecreatetruecolor($width, $height);
        imagecopyresampled($canvas, $source, 0, 0, intdiv($sourceWidth - $cropWidth, 2), intdiv($sourceHeight - $cropHeight, 2), $width, $height, $cropWidth, $cropHeight);

        // Frame top-left, text top-right, tops aligned; both drawn 4x (frame 128 px wide, text 463 px).
        $scale = max(700, $width) * 0.2 / 643;
        $margin = (int) round($width * 0.035);
        imagealphablending($canvas, true);
        foreach (['frame' => 'left', 'text' => 'right'] as $part => $side) {
            $logo = @imagecreatefrompng(resource_path("images/watermark-{$part}.png"));
            if ($logo === false) {
                continue;
            }
            $logoWidth = (int) round(imagesx($logo) * $scale);
            $logoHeight = (int) round(imagesy($logo) * $scale);
            $x = $side === 'left' ? $margin : $width - $margin - $logoWidth;
            imagecopyresampled($canvas, $logo, $x, $margin, 0, 0, $logoWidth, $logoHeight, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
        }

        return $canvas;
    }

    /** Highest JPEG quality (binary search, QUALITY_FLOOR..90) whose output fits MAX_BYTES, else the floor. */
    private static function bestJpeg(\GdImage $canvas): string
    {
        imageinterlace($canvas, true);
        $encode = function (int $quality) use ($canvas): string {
            ob_start();
            imagejpeg($canvas, null, $quality);

            return (string) ob_get_clean();
        };

        $best = $encode(self::QUALITY_FLOOR);
        for ($low = self::QUALITY_FLOOR + 1, $high = 90; $low <= $high;) {
            $quality = intdiv($low + $high, 2);
            $bytes = $encode($quality);
            if (strlen($bytes) <= self::MAX_BYTES) {
                [$best, $low] = [$bytes, $quality + 1];
            } else {
                $high = $quality - 1;
            }
        }

        return $best;
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
