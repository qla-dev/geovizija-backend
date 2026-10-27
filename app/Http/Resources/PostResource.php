<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Field names match the frontend `Article` type. */
class PostResource extends JsonResource
{
    private const MONTHS = [
        1 => 'Januar', 'Februar', 'Mart', 'April', 'Maj', 'Juni',
        'Juli', 'August', 'Septembar', 'Oktobar', 'Novembar', 'Decembar',
    ];

    public function toArray(Request $request): array
    {
        $publishedAt = $this->published_at;

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            // In-text images are "![caption](media/posts/...)" lines: make their paths absolute and
            // hide markers whose image has not been generated yet.
            'content' => preg_replace_callback(
                '#^!\[([^\]]*)\]\(((?!https?://)[^)\s]+)\)$#m',
                fn ($m) => "![{$m[1]}](".asset($m[2]).')',
                (string) preg_replace('/^\[\[SLIKA:.*\]\]\R?/mu', '', (string) $this->content),
            ),
            'categoryId' => $this->category?->slug,
            'category' => new CategoryResource($this->whenLoaded('category')),
            // Generated images are stored as paths relative to public/ (see PostImageGenerator).
            'imageUrl' => $this->image_url && ! preg_match('#^https?://#', $this->image_url)
                ? asset($this->image_url)
                : $this->image_url,
            // Photo credits, shown as text under the images: the cover's, and the in-text ones by absolute URL.
            'imageCredit' => ($this->image_credits ?? [])[$this->image_url] ?? null,
            'imageCredits' => (object) collect($this->image_credits ?? [])
                ->except([$this->image_url])
                ->mapWithKeys(fn ($credit, $path) => [asset($path) => $credit])
                ->all(),
            'author' => $this->author,
            'date' => $publishedAt
                ? $publishedAt->format('d').'. '.self::MONTHS[$publishedAt->month].' '.$publishedAt->format('Y')
                : null,
            'publishedAt' => $publishedAt?->toIso8601String(),
            'readTime' => $this->read_time,
            'featured' => $this->featured,
        ];
    }
}
