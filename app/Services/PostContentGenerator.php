<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Rewrites a post's excerpt and body into a longer magazine article through OpenRouter's text models.
 *
 * The title (and so the slug) stays as it is. The body uses blank-line separated paragraphs and
 * "## " subheadings, which is what the frontend's ArticlePage renders.
 */
class PostContentGenerator
{
    public const MAX_WORDS = 750;

    public function generate(Post $post): Post
    {
        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured.');
        }

        $models = array_values(array_unique(array_filter([
            (string) config('services.openrouter.model'),
            (string) config('services.openrouter.fallback_model'),
        ])));

        $errors = [];

        // The primary model occasionally returns a short or malformed article: retry it, then try the fallback.
        foreach ([$models[0], $models[0], $models[0], $models[0], ...array_slice($models, 1)] as $model) {
            try {
                $article = $this->request($post, $model, $apiKey);
            } catch (RuntimeException $exception) {
                $errors[] = "{$model}: {$exception->getMessage()}";
                Log::warning('Post content generation failed.', ['post_id' => $post->id, 'model' => $model, 'error' => $exception->getMessage()]);

                continue;
            }

            // Inline images of the text being replaced are no longer referenced.
            $oldImages = PostImageGenerator::inlinePaths((string) $post->content);

            $post->update([
                'excerpt' => $article['excerpt'],
                'content' => $article['content'],
                'read_time' => max(1, (int) ceil(self::words(self::textOnly($article['content'])) / 200)),
            ]);

            foreach ($oldImages as $path) {
                File::delete(public_path($path));
            }

            return $post;
        }

        throw new RuntimeException(implode(' | ', $errors));
    }

    /** @return array{excerpt: string, content: string} */
    private function request(Post $post, string $model, string $apiKey): array
    {
        $post->loadMissing('category');

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(180)
                ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'Geovizija post content'])
                ->post((string) config('services.openrouter.url'), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->instructions()],
                        ['role' => 'user', 'content' => 'Kategorija: '.($post->category?->name ?? 'Općenito')
                            ."\nNaslov: {$post->title}"
                            ."\nPostojeći uvod: {$post->excerpt}"
                            ."\nPostojeći tekst:\n{$post->content}"],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The text generator is not available right now: '.$exception->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException(data_get($response->json(), 'error.message') ?: "The text generator returned HTTP {$response->status()}.");
        }

        // Plain-text answer: "UVOD: <excerpt>", a "---" line, then the article. (JSON mode broke on
        // Gemini putting raw newlines inside the content string.)
        $raw = str_replace("\r\n", "\n", trim((string) data_get($response->json(), 'choices.0.message.content')));
        $raw = trim((string) preg_replace('/^```\w*\s*|\s*```$/', '', $raw));
        $finish = (string) data_get($response->json(), 'choices.0.finish_reason');

        $parts = preg_split('/^\s*-{3,}\s*$/m', $raw, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException("The text generator did not use the expected format (finish: {$finish}).");
        }

        $excerpt = trim((string) preg_replace('/^\s*(UVOD|EXCERPT)\s*:\s*/iu', '', $parts[0]));
        $content = trim($parts[1]);

        $markers = preg_match_all(PostImageGenerator::INLINE_MARKER, $content);
        if ($markers !== 2) {
            throw new RuntimeException("The text generator returned {$markers} image markers instead of 2 (finish: {$finish}).");
        }

        $words = self::words(self::textOnly($content));
        if ($excerpt === '' || $words < 300) {
            throw new RuntimeException("The text generator returned an incomplete article ({$words} words, finish: {$finish}).");
        }

        // The model tends to overshoot the requested length; reject and retry rather than store it.
        if ($words > self::MAX_WORDS) {
            throw new RuntimeException("The text generator returned {$words} words (max ".self::MAX_WORDS.').');
        }

        return ['excerpt' => $excerpt, 'content' => $content];
    }

    /** Body text without image markers or image lines. */
    public static function textOnly(string $content): string
    {
        return (string) preg_replace(['/^\[\[SLIKA:.*\]\]$/mu', '/^!\[[^\]]*\]\([^)]*\)$/mu'], '', $content);
    }

    /** Word count that handles non-ASCII letters (str_word_count splits on č, š, ž...). */
    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    private function instructions(): string
    {
        return <<<'TXT'
Ti si urednik Geovizije, bosanskohercegovačkog magazina o prirodi, geografiji, putovanjima, društvu, kulturi i tehnologiji u stilu National Geographica.
Na osnovu naslova i postojećeg kratkog teksta napiši potpun, zanimljiv i informativan članak na bosanskom jeziku (ijekavica, latinica).

Zahtjevi:
- Dužina teksta: 450 do 600 riječi — strogo, nikako duže. Najviše 6 pasusa, svaki pasus 3 do 4 rečenice.
- Počni snažnim uvodnim pasusom koji uvlači čitaoca (scena, pitanje ili upečatljiva činjenica), bez ponavljanja naslova.
- Tačno 2 podnaslova; svaki podnaslov je zaseban red koji počinje sa "## ".
- Pasusi i podnaslovi su odvojeni praznim redom. Bez markdowna osim "## " (bez podebljanja, lista, linkova, emojija).
- Konkretni detalji o mjestima, vrstama, procesima i historijskom kontekstu, ali samo općepoznate i provjerljive činjenice.
- Ne izmišljaj citate stvarnih osoba, imena stručnjaka, institucija ni precizne statistike koje nisu općepoznate.
- Završi pasusom koji povezuje temu s čitaocem ili budućnošću.
- Ubaci tačno 2 fotografije u tekst: svaka je zaseban red oblika [[SLIKA: kratak opis scene na bosanskom]]. Prvu stavi nakon drugog pasusa, drugu prije posljednjeg podnaslova. Opis je konkretna scena koja se razlikuje od naslovne fotografije (npr. "Mrki medvjed u bukovoj šumi Sutjeske u zoru").
- Uvod (excerpt): 1 do 2 rečenice (do 220 znakova) koje najavljuju članak.

Odgovori isključivo u ovom formatu, bez ikakvog drugog teksta:
UVOD: <uvod u jednom redu>
---
<cijeli tekst članka>
TXT;
    }
}
