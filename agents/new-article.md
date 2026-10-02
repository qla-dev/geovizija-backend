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

   The response `data.id` is the post id. `author` defaults to "Kulašin"; `read_time` is computed. A draft is not shared on Facebook.
4. `POST /posts/{id}/generate-image` — draws the cover (~20 s).
5. `POST /posts/{id}/generate-inline-image` — draws the next `[[SLIKA: …]]` image. Call it again until the response shows `"pending": 0` (two calls for two markers).
6. Publish: `PATCH /posts/{id}` with `{"published_at": "<ISO 8601>"}` — now, or a later time to schedule it (e.g. `2026-10-03T08:00:00+02:00`; it stays hidden until then). This also shares it on the Facebook Page, posted at once or as a scheduled Page post at that time (see `facebook` in the response: `posted`, `scheduled` or `failed`; a failure does not undo the publish).
7. Report the title, `https://geovizija.com/article/{slug}`, the publication time and the `facebook` status.

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

## Editing an existing article

Only when the task names the article. Read it first with `GET /posts/{id}`; change only what the task asks for. Without the admin token use `POST /publish` with `"id"` (see [README.md](README.md#editing-an-existing-article)); with it:

- **Text** (title, excerpt, body): `PATCH /posts/{id}` with just those fields. To keep the in-text images, keep their `![…](…)` lines; the `content` from `GET /posts/{id}` can be edited and sent back as is. Images whose lines you remove are deleted by the server.
- **Rewrite with new images**: `PATCH` a body with two new `[[SLIKA: …]]` markers instead of the old image lines, then run step 5.
- **Cover**: `POST /posts/{id}/generate-image` redraws it; send `{"image": "data:…" or "https://…"}` to use your own picture.
- **One in-text image**: `POST /posts/{id}/generate-inline-image` with `{"number": 2, "description": "nova scena"}` (1 = first image in the body); add `"image"` to use your own picture instead of drawing.

A failed image call leaves the old image in place. Report what changed and the article URL.
