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
        // From the cover's original when kept: a 9:16 crop of the published 16:9 cover is too small.
        $cover = $post->image_url && PostImageGenerator::isGenerated($post->image_url)
            ? PostImageGenerator::sourceImage($post->image_url)
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

        // All text at the bottom, built upwards from just above Instagram's reply bar.
        $left = 80;
        $baseline = self::HEIGHT - 280;
        imagettftext($story, 26, 0, $left, $baseline, $green, $black, 'LINK U OPISU PROFILA');
        $baseline -= 62;
        imagettftext($story, 26, 0, $left, $baseline, $white, $regular, 'Cijeli članak na geovizija.com');

        $lines = $this->wrap($post->title, $black, 46, 920, 4);
        $baseline -= 110 + (count($lines) - 1) * 84;
        foreach ($lines as $i => $line) {
            imagettftext($story, 46, 0, $left, $baseline + $i * 84, $white, $black, $line);
        }
        $baseline -= 92;
        $post->loadMissing('category');
        if ($post->category) {
            imagettftext($story, 22, 0, $left, $baseline, $green, $black, mb_strtoupper($post->category->name));
            $baseline -= 70;
        }
        $this->logo($story, $left, $baseline - 48, $white, $green, $black);

        $path = self::DIRECTORY.'/'.$post->slug.'-'.now()->format('YmdHis').'.jpg';
        File::ensureDirectoryExists(public_path(self::DIRECTORY));
        imagejpeg($story, public_path($path), 88);
        imagedestroy($story);
        imagedestroy($cover);

        return $path;
    }

    /** The cover filling the screen (centre crop to 9:16, sharp), darkened towards the bottom for the text. */
    private function background(\GdImage $story, \GdImage $cover): void
    {
        $cropWidth = min(imagesx($cover), (int) round(imagesy($cover) * 9 / 16));
        $cropHeight = min(imagesy($cover), (int) round($cropWidth * 16 / 9));
        imagecopyresampled($story, $cover, 0, 0, intdiv(imagesx($cover) - $cropWidth, 2), intdiv(imagesy($cover) - $cropHeight, 2), self::WIDTH, self::HEIGHT, $cropWidth, $cropHeight);

        imagealphablending($story, true);
        $from = (int) (self::HEIGHT * 0.45);
        for ($y = $from; $y < self::HEIGHT; $y += 4) {
            $shade = ($y - $from) / (self::HEIGHT - $from);
            imagefilledrectangle($story, 0, $y, self::WIDTH, $y + 3, imagecolorallocatealpha($story, 0, 0, 0, (int) round(127 - 105 * min(1, $shade * 1.3))));
        }
    }

    /** The header logo (green outlined bar + GEOVIZIJA) with its top-left corner at ($left, $top). */
    private function logo(\GdImage $story, int $left, int $top, int $white, int $green, string $font): void
    {
        $size = 30;
        [$barWidth, $barHeight, $border, $gap] = [32, 48, 5, 14];
        imagesetthickness($story, 1);
        imagefilledrectangle($story, $left, $top, $left + $barWidth, $top + $border, $green);
        imagefilledrectangle($story, $left, $top + $barHeight - $border, $left + $barWidth, $top + $barHeight, $green);
        imagefilledrectangle($story, $left, $top, $left + $border, $top + $barHeight, $green);
        imagefilledrectangle($story, $left + $barWidth - $border, $top, $left + $barWidth, $top + $barHeight, $green);
        imagettftext($story, $size, 0, $left + $barWidth + $gap, $top + 38, $white, $font, 'GEOVIZIJA');
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
