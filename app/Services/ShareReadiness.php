<?php

namespace App\Services;

use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Checks the public deployment, not just the database, before a Facebook share. */
class ShareReadiness
{
    public function check(Post $post): string
    {
        if (! $post->image_url) {
            throw new RuntimeException('Članak nema naslovnu sliku.');
        }

        $url = MetaPublisher::articleUrl($post);
        $publicBase = rtrim((string) config('services.meta.media_url'), '/');
        $api = $publicBase.'/api/posts/'.rawurlencode($post->slug);
        $generated = PostImageGenerator::isGenerated($post->image_url);
        // A CLI run's APP_URL can be localhost; verify the actual public media host instead.
        $cover = $generated ? $publicBase.'/'.$post->image_url : $post->image_url;
        $image = $generated ? $publicBase.'/'.PostImageGenerator::sharePath($post->image_url) : $cover;
        $preview = $this->get($api.'/preview')->json('data');

        if (! is_array($preview) || (string) ($preview['id'] ?? '') !== (string) $post->id
            || ($preview['slug'] ?? null) !== $post->slug || ($preview['title'] ?? null) !== $post->title
            || ($preview['imageUrl'] ?? null) !== $cover || ($preview['shareImageUrl'] ?? null) !== $image) {
            throw new RuntimeException('Javni pregled ne odgovara članku ili njegovoj naslovnoj slici.');
        }

        if ($post->isPublished()) {
            $live = $this->get($api)->json('data');
            if ((string) ($live['id'] ?? '') !== (string) $post->id || ($live['slug'] ?? null) !== $post->slug
                || empty($live['content']) || ($live['imageUrl'] ?? null) !== $cover) {
                throw new RuntimeException('Javni API ne vraća objavljeni članak i njegovu naslovnu sliku.');
            }
        }

        // This contract is emitted only by the frontend with direct article lookup.
        // An old deployment returning the SPA's generic HTTP 200 is not sufficient.
        $visitor = $this->html($this->get($url, 'Mozilla/5.0 GeovizijaShareCheck/1.0')->body());
        $this->expectMeta($visitor, 'name', 'geovizija:article-loader', 'direct-api-v1');

        $crawler = $this->html($this->get($url, 'facebookexternalhit/1.1')->body());
        $this->expectMeta($crawler, 'name', 'geovizija:post-id', (string) $post->id);
        $this->expectMeta($crawler, 'property', 'og:url', $url);
        $this->expectMeta($crawler, 'property', 'og:image', $image);
        $titles = $crawler->query('//title');
        if ($titles->length !== 1 || $titles->item(0)->textContent !== $post->title.' | Geovizija') {
            throw new RuntimeException('Javna stranica prikazuje naslov drugog članka.');
        }

        $this->image($cover, $generated ? public_path($post->image_url) : null);
        if ($image !== $cover) {
            $this->image($image, public_path(PostImageGenerator::sharePath($post->image_url)));
        }

        return $image;
    }

    private function get(string $url, string $agent = 'GeovizijaShareCheck/1.0'): Response
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Adresa za dijeljenje mora koristiti HTTPS.');
        }
        $response = Http::withUserAgent($agent)->withHeaders(['Cache-Control' => 'no-cache'])
            ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(15)->get($url);
        if ($response->status() !== 200) {
            throw new RuntimeException("Javna adresa nije dostupna (HTTP {$response->status()}): {$url}");
        }

        return $response;
    }

    private function html(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function expectMeta(DOMXPath $html, string $attribute, string $name, string $expected): void
    {
        $tags = $html->query('//meta[@'.$attribute.'="'.$name.'"]');
        if ($tags->length !== 1 || $tags->item(0)->getAttribute('content') !== $expected) {
            throw new RuntimeException("Javna stranica nema ispravne podatke za {$name}.");
        }
    }

    private function image(string $url, ?string $localPath): void
    {
        $response = $this->get($url, 'facebookexternalhit/1.1');
        $size = @getimagesizefromstring($response->body());
        if (! str_starts_with(strtolower($response->header('Content-Type')), 'image/')
            || ! $size || $size[0] < 200 || $size[1] < 200) {
            throw new RuntimeException('Naslovna slika nije dostupna kao ispravna javna slika.');
        }
        if ($localPath !== null && (! is_file($localPath)
            || ! hash_equals(hash_file('sha256', $localPath), hash('sha256', $response->body())))) {
            throw new RuntimeException('Javna slika se razlikuje od spremljene naslovne slike članka.');
        }
    }
}
