<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Draws the full-screen (1080x1920) Instagram Story for an article: the cover blurred as background,
 * the logo, category, title, the cover itself, and where to read on. Instagram's API cannot add link
 * stickers, so the story points to the link in the profile bio. Fonts: Merriweather (OFL) in
 * resources/fonts. Saved under public/media/stories, where Instagram fetches it.
 */
class InstagramStory
{
    public const DIRECTORY = 'media/stories';

    private const WIDTH = 1080;

    private const HEIGHT = 1920;

    private const GREEN = [16, 185, 129];

    /** Returns the relative path of the saved JPEG. */
    public function make(Post $post): string
    {
        $cover = $post->image_url && PostImageGenerator::isGenerated($post->image_url)
            ? @imagecreatefromjpeg(public_path($post->image_url))
            : false;
        if ($cover === false) {
            throw new RuntimeException('Naslovna slika članka nije dostupna za story.');
        }

        $story = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $this->background($story, $cover);

        $white = imagecolorallocate($story, 255, 255, 255);
        $green = imagecolorallocate($story, ...self::GREEN);
        $black = $this->font('Black');
        $regular = $this->font('Regular');

        $this->logo($story, 150, $white, $green, $black);

        // The cover, centred, a little above the middle.
        $coverWidth = 960;
        $coverHeight = (int) round($coverWidth * imagesy($cover) / imagesx($cover));
        $coverTop = 980;
        imagecopyresampled($story, $cover, (self::WIDTH - $coverWidth) / 2, $coverTop, 0, 0, $coverWidth, $coverHeight, imagesx($cover), imagesy($cover));

        // Title (up to 4 lines) ending just above the cover, category above it.
        $lines = $this->wrap($post->title, $black, 46, 920, 4);
        $lineHeight = 84;
        $titleTop = $coverTop - 60 - count($lines) * $lineHeight;
        foreach ($lines as $i => $line) {
            imagettftext($story, 46, 0, 80, $titleTop + ($i + 1) * $lineHeight - 18, $white, $black, $line);
        }
        $post->loadMissing('category');
        if ($post->category) {
            imagettftext($story, 22, 0, 80, $titleTop - 30, $green, $black, mb_strtoupper($post->category->name));
        }

        // Where to read on, above the area Instagram covers with its reply bar.
        $bottom = $coverTop + $coverHeight + 150;
        $this->centred($story, 'Cijeli članak na geovizija.com', $regular, 28, $bottom, $white);
        $this->centred($story, 'LINK U OPISU PROFILA', $black, 28, $bottom + 70, $green);

        $path = self::DIRECTORY.'/'.$post->slug.'-'.now()->format('YmdHis').'.jpg';
        File::ensureDirectoryExists(public_path(self::DIRECTORY));
        imagejpeg($story, public_path($path), 88);
        imagedestroy($story);
        imagedestroy($cover);

        return $path;
    }

    /** The cover cropped to 9:16, blurred at a small size, scaled up smoothly and darkened. */
    private function background(\GdImage $story, \GdImage $cover): void
    {
        $cropWidth = min(imagesx($cover), (int) round(imagesy($cover) * 9 / 16));
        $small = imagecreatetruecolor(135, 240);
        imagecopyresampled($small, $cover, 0, 0, intdiv(imagesx($cover) - $cropWidth, 2), 0, 135, 240, $cropWidth, imagesy($cover));
        for ($i = 0; $i < 25; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagesetinterpolation($small, IMG_BICUBIC);
        $large = imagescale($small, self::WIDTH, self::HEIGHT, IMG_BICUBIC);
        imagecopy($story, $large, 0, 0, 0, 0, self::WIDTH, self::HEIGHT);
        imagedestroy($small);
        imagedestroy($large);
        imagealphablending($story, true);
        imagefilledrectangle($story, 0, 0, self::WIDTH, self::HEIGHT, imagecolorallocatealpha($story, 0, 0, 0, 50));
    }

    /** The header logo (green outlined bar + GEOVIZIJA), centred at $top. */
    private function logo(\GdImage $story, int $top, int $white, int $green, string $font): void
    {
        $size = 30;
        $box = imagettfbbox($size, 0, $font, 'GEOVIZIJA');
        $textWidth = $box[2] - $box[0];
        [$barWidth, $barHeight, $border, $gap] = [32, 48, 5, 14];
        $left = (int) ((self::WIDTH - $barWidth - $gap - $textWidth) / 2);
        imagesetthickness($story, 1);
        imagefilledrectangle($story, $left, $top, $left + $barWidth, $top + $border, $green);
        imagefilledrectangle($story, $left, $top + $barHeight - $border, $left + $barWidth, $top + $barHeight, $green);
        imagefilledrectangle($story, $left, $top, $left + $border, $top + $barHeight, $green);
        imagefilledrectangle($story, $left + $barWidth - $border, $top, $left + $barWidth, $top + $barHeight, $green);
        imagettftext($story, $size, 0, $left + $barWidth + $gap, $top + 38, $white, $font, 'GEOVIZIJA');
    }

    private function centred(\GdImage $story, string $text, string $font, int $size, int $baseline, int $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        imagettftext($story, $size, 0, (int) ((self::WIDTH - ($box[2] - $box[0])) / 2), $baseline, $color, $font, $text);
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, int $size, int $width, int $maxLines): array
    {
        $lines = [''];
        foreach (preg_split('/\s+/u', trim($text)) as $word) {
            $candidate = trim(end($lines).' '.$word);
            $box = imagettfbbox($size, 0, $font, $candidate);
            if ($box[2] - $box[0] <= $width || end($lines) === '') {
                $lines[count($lines) - 1] = $candidate;
            } else {
                $lines[] = $word;
            }
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim($lines[$maxLines - 1], ' ,.;:').'…';
        }

        return $lines;
    }

    private function font(string $weight): string
    {
        return resource_path("fonts/Merriweather-{$weight}.ttf");
    }
}
