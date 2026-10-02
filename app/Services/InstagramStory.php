<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Draws an article's Instagram images from the cover's original: the photo filling the frame (centre
 * crop, sharp) with a shade growing towards the bottom, where the logo (left), category (right) and
 * title sit.
 *  - story (1080x1920): also "Cijeli članak na geovizija.com / LINK U OPISU PROFILA" (the API cannot
 *    add link stickers), kept above Instagram's reply bar; saved under public/media/stories.
 *  - feed (1080x1350, 4:5): without those two lines; saved as the cover's -ig.jpg.
 * Fonts: Merriweather (OFL) in resources/fonts.
 */
class InstagramStory
{
    public const DIRECTORY = 'media/stories';

    private const GREEN = [16, 185, 129];

    /** The story; returns the relative path of the saved JPEG. */
    public function make(Post $post): string
    {
        $path = self::DIRECTORY.'/'.$post->slug.'-'.now()->format('YmdHis').'.jpg';
        File::ensureDirectoryExists(public_path(self::DIRECTORY));
        $this->card($post, 1080, 1920, 280, true, public_path($path));

        return $path;
    }

    /** The 4:5 feed image, saved next to the cover (PostImageGenerator::portraitPath). */
    public function feed(Post $post): string
    {
        $path = PostImageGenerator::portraitPath($post->image_url);
        $this->card($post, 1080, 1350, 110, false, public_path($path));

        return $path;
    }

    private function card(Post $post, int $width, int $height, int $bottom, bool $readOn, string $file): void
    {
        // From the cover's original when kept: a portrait crop of the published 16:9 cover is too small.
        $cover = $post->image_url && PostImageGenerator::isGenerated($post->image_url)
            ? PostImageGenerator::sourceImage($post->image_url)
            : false;
        if ($cover === false) {
            throw new RuntimeException('Naslovna slika članka nije dostupna za Instagram.');
        }

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, ...self::GREEN);
        $black = $this->font('Black');
        $regular = $this->font('Regular');

        // Text laid out upwards from $bottom, then drawn over the background, whose shade starts
        // above the text block wherever it ends up.
        $left = 80;
        $draw = [];
        $baseline = $height - $bottom;
        if ($readOn) {
            $at = $baseline;
            $draw[] = fn () => imagettftext($image, 26, 0, $left, $at, $green, $black, 'LINK U OPISU PROFILA');
            $draw[] = fn () => imagettftext($image, 26, 0, $left, $at - 62, $white, $regular, 'Cijeli članak na geovizija.com');
            $baseline -= 172;
        }

        $lines = $this->wrap($post->title, $black, 46, $width - 2 * $left, 4);
        $titleTop = $baseline - (count($lines) - 1) * 84;
        $draw[] = function () use ($image, $lines, $left, $titleTop, $white, $black) {
            foreach ($lines as $i => $line) {
                imagettftext($image, 46, 0, $left, $titleTop + $i * 84, $white, $black, $line);
            }
        };

        // Logo left, category right, on one row above the title.
        $logoTop = $titleTop - 84 - 48;
        $draw[] = fn () => $this->logo($image, $left, $logoTop, $white, $green, $black);
        $post->loadMissing('category');
        if ($post->category) {
            $category = mb_strtoupper($post->category->name);
            $box = imagettfbbox(22, 0, $black, $category);
            $draw[] = fn () => imagettftext($image, 22, 0, $width - $left - ($box[2] - $box[0]), $logoTop + 36, $green, $black, $category);
        }

        $this->background($image, $cover, $logoTop);
        foreach ($draw as $step) {
            $step();
        }

        imageinterlace($image, true);
        imagejpeg($image, $file, 88);
        imagedestroy($image);
        imagedestroy($cover);
    }

    /**
     * The cover filling the frame (centre crop, sharp), with a shade that grows steadily (faster at first) from 700 px
     * above the text block to about 92 % black at the bottom edge, so the text reads on any photo.
     */
    private function background(\GdImage $image, \GdImage $cover, int $textTop): void
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $cropWidth = min(imagesx($cover), (int) round(imagesy($cover) * $width / $height));
        $cropHeight = min(imagesy($cover), (int) round($cropWidth * $height / $width));
        imagecopyresampled($image, $cover, 0, 0, intdiv(imagesx($cover) - $cropWidth, 2), intdiv(imagesy($cover) - $cropHeight, 2), $width, $height, $cropWidth, $cropHeight);

        imagealphablending($image, true);
        $from = max(0, $textTop - 700);
        for ($y = $from; $y < $height; $y += 2) {
            $shade = ($y - $from) / ($height - $from);
            imagefilledrectangle($image, 0, $y, $width, $y + 1, imagecolorallocatealpha($image, 0, 0, 0, (int) round(127 - 120 * $shade ** 0.6 * min(1, $shade / 0.25))));
        }
    }

    /** The header logo (green outlined bar + GEOVIZIJA) with its top-left corner at ($left, $top). */
    private function logo(\GdImage $image, int $left, int $top, int $white, int $green, string $font): void
    {
        [$barWidth, $barHeight, $border, $gap] = [32, 48, 5, 14];
        imagefilledrectangle($image, $left, $top, $left + $barWidth, $top + $border, $green);
        imagefilledrectangle($image, $left, $top + $barHeight - $border, $left + $barWidth, $top + $barHeight, $green);
        imagefilledrectangle($image, $left, $top, $left + $border, $top + $barHeight, $green);
        imagefilledrectangle($image, $left + $barWidth - $border, $top, $left + $barWidth, $top + $barHeight, $green);
        imagettftext($image, 30, 0, $left + $barWidth + $gap, $top + 38, $white, $font, 'GEOVIZIJA');
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
