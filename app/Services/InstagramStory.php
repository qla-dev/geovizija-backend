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
 *  - facebook (1080x1080, square): like feed, title in at most 3 lines; saved as the cover's -fbsq.jpg (og:image).
 * Fonts: Merriweather (OFL) in resources/fonts.
 */
class InstagramStory
{
    public const DIRECTORY = 'media/stories';

    private const GREEN = [16, 185, 129];

    /** The site's category colours (Tailwind classes in categories.color) as RGB, for the category label. */
    private const CATEGORY_COLORS = [
        'bg-emerald-600' => [5, 150, 105], 'bg-teal-600' => [13, 148, 136], 'bg-blue-600' => [37, 99, 235],
        'bg-cyan-600' => [8, 145, 178], 'bg-purple-600' => [147, 51, 234], 'bg-orange-600' => [234, 88, 12],
        'bg-yellow-500' => [234, 179, 8], 'bg-indigo-600' => [79, 70, 229], 'bg-green-500' => [34, 197, 94],
        'bg-red-600' => [220, 38, 38], 'bg-rose-600' => [225, 29, 72], 'bg-stone-500' => [120, 113, 108],
    ];

    /** The story; returns the relative path of the saved JPEG. */
    public function make(Post $post): string
    {
        $path = self::DIRECTORY.'/'.$post->slug.'-'.now()->format('YmdHis').'.jpg';
        File::ensureDirectoryExists(public_path(self::DIRECTORY));
        // The text block ends as far from the bottom edge as it is from the left one (80 px).
        $this->card($post, 1080, 1920, 80, true, public_path($path));

        return $path;
    }

    /**
     * The 4:5 feed image, saved next to the cover (PostImageGenerator::portraitPath). Its text sits at
     * the top (90 px from the edge) under a shade from the top, clear of Instagram's overlays below.
     */
    public function feed(Post $post): string
    {
        $path = PostImageGenerator::portraitPath($post->image_url);
        $this->card($post, 1080, 1350, 90, false, public_path($path), shadeFrom: 320, atTop: true);

        return $path;
    }

    /**
     * The Facebook link image, saved next to the cover (PostImageGenerator::sharePath) and served to
     * Facebook as og:image by og.php. Square 1080x1080: the most upright shape a link preview shows
     * whole (taller images are cropped). Same design as the Instagram feed image, title at the top.
     */
    public function facebook(Post $post): string
    {
        $path = PostImageGenerator::sharePath($post->image_url);
        $this->card($post, 1080, 1080, 80, false, public_path($path), maxLines: 3, shadeFrom: 320, atTop: true);

        return $path;
    }

    /**
     * $edge is the text block's distance from the bottom (or, with $atTop, from the top) edge; $scale
     * sizes the text and logo; $shadeFrom is how far beyond the text block the shade reaches.
     */
    private function card(Post $post, int $width, int $height, int $edge, bool $readOn, string $file, float $scale = 1, int $maxLines = 4, int $shadeFrom = 700, bool $atTop = false): void
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

        // Text laid out upwards from the last baseline, then drawn over the background, whose shade
        // reaches $shadeFrom beyond the text block wherever it ends up.
        $left = (int) round(80 * $scale);
        $px = fn (float $value) => (int) round($value * $scale);
        $draw = [];
        $lines = $this->wrap($post->title, $black, $px(46), $width - 2 * $left, $maxLines);
        // At the top: the last baseline follows from the logo row starting $edge from the top edge.
        $baseline = $atTop
            ? $edge + $px(48) + $px(84) + (count($lines) - 1) * $px(84) + ($readOn ? 172 : 0)
            : $height - $edge;
        if ($readOn) {
            $at = $baseline;
            $draw[] = fn () => imagettftext($image, 26, 0, $left, $at, $green, $black, 'LINK U OPISU PROFILA');
            $draw[] = fn () => imagettftext($image, 26, 0, $left, $at - 62, $white, $regular, 'Cijeli članak na geovizija.com');
            $baseline -= 172;
        }

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
            // A label in the category's colour with white text, like the site's category pills,
            // vertically centred on the logo row and ending at the right margin.
            $category = mb_strtoupper($post->category->name);
            $box = imagettfbbox($px(20), 0, $black, $category);
            [$textWidth, $capHeight] = [$box[2] - $box[0], $box[1] - $box[7]];
            [$padX, $padY] = [$px(16), $px(12)];
            $pillRight = $width - $left;
            $pillLeft = $pillRight - $textWidth - 2 * $padX;
            $pillTop = $logoTop + intdiv($px(48) - ($capHeight + 2 * $padY), 2);
            $fill = imagecolorallocate($image, ...(self::CATEGORY_COLORS[$post->category->color] ?? self::GREEN));
            $draw[] = function () use ($image, $pillLeft, $pillTop, $pillRight, $capHeight, $padX, $padY, $fill, $white, $black, $category, $px) {
                imagefilledrectangle($image, $pillLeft, $pillTop, $pillRight, $pillTop + $capHeight + 2 * $padY, $fill);
                imagettftext($image, $px(20), 0, $pillLeft + $padX, $pillTop + $padY + $capHeight, $white, $black, $category);
            };
        }

        $atTop
            ? $this->background($image, $cover, $baseline + $px(40) + $shadeFrom, true)
            : $this->background($image, $cover, $logoTop - $shadeFrom);
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
     * from $shadeTop to about 92 % black at the bottom edge (with $fromTop: from $shadeTop up to the top
     * edge), so the text reads on any photo.
     */
    private function background(\GdImage $image, \GdImage $cover, int $shadeTop, bool $fromTop = false): void
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $cropWidth = min(imagesx($cover), (int) round(imagesy($cover) * $width / $height));
        $cropHeight = min(imagesy($cover), (int) round($cropWidth * $height / $width));
        imagecopyresampled($image, $cover, 0, 0, intdiv(imagesx($cover) - $cropWidth, 2), intdiv(imagesy($cover) - $cropHeight, 2), $width, $height, $cropWidth, $cropHeight);

        imagealphablending($image, true);
        $alpha = fn (float $shade) => imagecolorallocatealpha($image, 0, 0, 0, (int) round(127 - 120 * $shade ** 0.6 * min(1, $shade / 0.25)));
        if ($fromTop) {
            $to = min($height, $shadeTop);
            for ($y = 0; $y < $to; $y += 2) {
                imagefilledrectangle($image, 0, $y, $width, $y + 1, $alpha(($to - $y) / $to));
            }

            return;
        }
        $from = max(0, $shadeTop);
        for ($y = $from; $y < $height; $y += 2) {
            imagefilledrectangle($image, 0, $y, $width, $y + 1, $alpha(($y - $from) / ($height - $from)));
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
