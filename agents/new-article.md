# New article

Goal: a short magazine article with a cover image and two in-text images, published only when complete.

## Steps

1. `GET /agent/context`. Pick a category (prefer ones with fewer posts) and a topic not covered by `recentPosts`.
2. Write the article (rules below).
3. Create it as a **draft** (`published_at: null`, invisible to readers):

   ```
   POST /posts
   {
     "category": "priroda",
     "title": "Povratak risa u Dinaride",
     "excerpt": "1–2 rečenice, do 220 znakova.",
     "content": "…body, format below…",
     "published_at": null
   }
   ```

   The response `data.id` is the post id. `author` defaults to "Kulašin"; `read_time` is computed.
4. `POST /posts/{id}/generate-image` — draws the cover (~20 s).
5. `POST /posts/{id}/generate-inline-image` — draws the next `[[SLIKA: …]]` image. Call it again until the response shows `"pending": 0` (two calls for two markers).
6. Publish: `PATCH /posts/{id}` with `{"published_at": "<now, ISO 8601>"}`.
7. Report the title and `https://geovizija.com/#/article/{id}`.

If an image call fails, retry it once. If it still fails, leave the post as a draft and report it; do not publish an article without its cover.

## Body format

One block per line, blocks separated by a blank line:

- a paragraph of plain text (3–4 sentences),
- a subheading line starting with `## `,
- an image marker line `[[SLIKA: concrete scene description in Bosnian]]`.

No other markdown (no bold, lists, links, emoji).

```
Uvodni pasus koji uvlači čitaoca scenom, pitanjem ili upečatljivom činjenicom.

Drugi pasus s konkretnim detaljima.

[[SLIKA: Mrki medvjed u bukovoj šumi Sutjeske u zoru]]

## Prvi podnaslov

Pasus…

## Drugi podnaslov

[[SLIKA: Tragovi risa u snijegu na rubu šume]]

Završni pasus koji povezuje temu s čitaocem ili budućnošću.
```

## Content rules

- 450–600 words of text (image lines not counted), at most 6 paragraphs, exactly 2 subheadings.
- Exactly 2 image markers: the first after the second paragraph, the second before the last subheading's text. Each describes a concrete scene that differs from the cover (the cover is drawn from title and excerpt).
- Bosnian (ijekavica, latinica), National Geographic tone; open with a strong first paragraph that does not repeat the title; end by connecting the topic to the reader or the future.
- Only well-known, verifiable facts. No invented quotes, experts, institutions or statistics.
- Categories: use a `slug` from the context (`priroda`, `putovanja`, `drustvo`, `tehnologija`, `kultura`, `stanovnistvo`, `zanimljivosti`, `skolstvo`).

## Rewriting an existing article

Only when the task names the article. `PATCH /posts/{id}` with new `excerpt` and `content` (same format, with two new `[[SLIKA: …]]` markers), then run step 5. In-text images the new body no longer references are deleted by the server; the cover is kept unless you also call step 4.
