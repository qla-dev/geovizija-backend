<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Draws an article's social images (Instagram, Facebook) from the cover's original: the photo filling the frame (centre
 * crop, sharp) with a shade growing towards the bottom, where the logo (left), category (right) and
 * title sit.
 *  - story (1080x1920): also "Cijeli članak na geovizija.com / LINK U OPISU PROFILA" (the API cannot
 *    add link stickers), kept above Instagram's reply bar; saved under public/media/stories.
 *  - feed (1080x1350, 4:5): without those two lines; saved as the cover's -ig.jpg.
 *  - facebook (1200x630): smaller, title in at most 2 lines; saved as the cover's -fb.jpg (og:image).
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

    /**
     * The Facebook link image (1200x630, the size Facebook shows large), saved next to the cover
     * (PostImageGenerator::sharePath) and served to Facebook as og:image by og.php.
     */
    public function facebook(Post $post): string
    {
        $path = PostImageGenerator::sharePath($post->image_url);
        $this->card($post, 1200, 630, 56, false, public_path($path), 0.8, 2, 420);

        return $path;
    }

    /** $scale sizes the text and logo; $shadeFrom is how far above the text block the shade starts. */
    private function card(Post $post, int $width, int $height, int $bottom, bool $readOn, string $file, float $scale = 1, int $maxLines = 4, int $shadeFrom = 700): void
    {
        // From the cover's original when kept: a portrait crop of the published 16:9 cover is too small.
        $cover = $post->image_url && PostImageGenerator::isGenerated($post->image_url)
            ? PostImageGenerator::sourceImage($post->image_url)
            : false;
        if ($cover === false) {
            throw new RuntimeException('Naslovna slika članka nije dostupna za društvene mreže.');
        }

        $image = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, ...self::GREEN);
        $black = $this->font('Black');
        $regular = $this->font('Regular');

        // Text laid out upwards from $bottom, then drawn over the background, whose shade starts
        // above the text block wherever it ends up.
        $left = (int) round(80 * $scale);
        $px = fn (float $value) => (int) round($value * $scale);
        $draw = [];
        $baseline = $height - $bottom;
        if ($readOn) {
            $at = $baseline;
            $draw[] = fn () => imagettftext($image, 26, 0, $left, $at, $green, $black, 'LINK U OPISU PROFILA');
            $draw[] = fn () => imagettftext($image, 26, 0, $left, $at - 62, $white, $regular, 'Cijeli članak na geovizija.com');
            $baseline -= 172;
        }

        $lines = $this->wrap($post->title, $black, $px(46), $width - 2 * $left, $maxLines);
        $titleTop = $baseline - (count($lines) - 1) * $px(84);
        $draw[] = function () use ($image, $lines, $left, $titleTop, $white, $black, $px) {
            foreach ($lines as $i => $line) {
                imagettftext($image, $px(46), 0, $left, $titleTop + $i * $px(84), $white, $black, $line);
            }
        };

        // Logo left, category right, on one row above the title.
        $logoTop = $titleTop - $px(84) - $px(48);
        $draw[] = fn () => $this->logo($image, $left, $logoTop, $white, $green, $black, $scale);
        $post->loadMissing('category');
        if ($post->category) {
            $category = mb_strtoupper($post->category->name);
            $box = imagettfbbox($px(22), 0, $black, $category);
            $draw[] = fn () => imagettftext($image, $px(22), 0, $width - $left - ($box[2] - $box[0]), $logoTop + $px(36), $green, $black, $category);
        }

        $this->background($image, $cover, $logoTop - $shadeFrom);
        foreach ($draw as $step) {
            $step();
        }

        imageinterlace($image, true);
        imagejpeg($image, $file, 88);
        imagedestroy($image);
        imagedestroy($cover);
    }

    /**
     * The cover filling the frame (centre crop, sharp), with a shade that grows steadily (faster at first)
     * from $shadeTop to about 92 % black at the bottom edge, so the text reads on any photo.
     */
    private function background(\GdImage $image, \GdImage $cover, int $shadeTop): void
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $cropWidth = min(imagesx($cover), (int) round(imagesy($cover) * $width / $height));
        $cropHeight = min(imagesy($cover), (int) round($cropWidth * $height / $width));
        imagecopyresampled($image, $cover, 0, 0, intdiv(imagesx($cover) - $cropWidth, 2), intdiv(imagesy($cover) - $cropHeight, 2), $width, $height, $cropWidth, $cropHeight);

        imagealphablending($image, true);
        $from = max(0, $shadeTop);
        for ($y = $from; $y < $height; $y += 2) {
            $shade = ($y - $from) / ($height - $from);
            imagefilledrectangle($image, 0, $y, $width, $y + 1, imagecolorallocatealpha($image, 0, 0, 0, (int) round(127 - 120 * $shade ** 0.6 * min(1, $shade / 0.25))));
        }
    }

    /** The header logo (green outlined bar + GEOVIZIJA) with its top-left corner at ($left, $top). */
    private function logo(\GdImage $image, int $left, int $top, int $white, int $green, string $font, float $scale = 1): void
    {
        [$barWidth, $barHeight, $border, $gap] = array_map(fn ($v) => (int) round($v * $scale), [32, 48, 5, 14]);
        imagefilledrectangle($image, $left, $top, $left + $barWidth, $top + $border, $green);
        imagefilledrectangle($image, $left, $top + $barHeight - $border, $left + $barWidth, $top + $barHeight, $green);
        imagefilledrectangle($image, $left, $top, $left + $border, $top + $barHeight, $green);
        imagefilledrectangle($image, $left + $barWidth - $border, $top, $left + $barWidth, $top + $barHeight, $green);
        imagettftext($image, (int) round(30 * $scale), 0, $left + $barWidth + $gap, $top + (int) round(38 * $scale), $white, $font, 'GEOVIZIJA');
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
