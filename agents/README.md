# Geovizija content agent

Instructions for a Claude agent (Claude Code routine / scheduled run) that writes Geovizija's content through the admin API. Claude writes the text; the backend only draws images (OpenRouter), because Claude cannot generate images.

| Task | Playbook | Suggested schedule |
| --- | --- | --- |
| Daily quiz (10 questions) | [daily-quiz.md](daily-quiz.md) | every day, shortly after 00:00 Europe/Sarajevo |
| New article (text + cover + 2 in-text images) | [new-article.md](new-article.md) | e.g. 1–2 per day |
| Rewrite an existing article | [new-article.md](new-article.md#rewriting-an-existing-article) | on demand |

A ready-to-paste prompt for the scheduled run is in [routine-prompt.md](routine-prompt.md).

## Publishing without the admin token

`POST /publish` (public, 10 requests/minute) publishes one article or quiz when the JSON itself contains the publish secret (only its bcrypt hash is on the server, `services.publish.secret_hash`). Wrong or missing secret → 403, nothing stored. The same is available as a paste form at `https://geovizija.com/#/objavi`.

```json
{"secret": "…", "type": "article", "category": "priroda", "title": "…", "excerpt": "…", "content": "…body with two [[SLIKA: …]] lines…"}
{"secret": "…", "type": "quiz", "date": "2026-10-02", "title": "…", "intro": "…", "questions": [{"topic": "…", "question": "…", "options": ["…","…","…","…"], "correct": 0, "explanation": "…"}]}
```

An article is published immediately and its cover and in-text images are drawn in the same request (~1 minute). Add `"publishedAt": "2026-10-01T14:20:00+02:00"` (ISO 8601) to schedule it: until then it is hidden from the site and from `/posts` (the response says `"scheduled": true`). A quiz for a date that already has one is refused (409). Never write the secret into this repository.

## Environment

The run needs two environment variables (set them as secrets of the routine / cloud environment, never in the repo):

```
GEOVIZIJA_API=https://geovizija.com/endpoints/api
GEOVIZIJA_TOKEN=<value of ADMIN_API_TOKEN in the server's .env>
```

Every admin call sends `Authorization: Bearer $GEOVIZIJA_TOKEN`, `Accept: application/json` and, with a body, `Content-Type: application/json; charset=utf-8`. Send UTF-8 JSON (Bosnian letters č ć š ž đ); build bodies with a JSON-aware tool (`jq`, Python, PHP), not hand-escaped shell strings.

## Always start with the context

```
curl -s "$GEOVIZIJA_API/agent/context" -H "Authorization: Bearer $GEOVIZIJA_TOKEN" -H "Accept: application/json"
```

Returns `today` (Y-m-d, Europe/Sarajevo), `hasQuizToday`, `categories` (slug, name, post count), `recentPosts` (title, category, date, word count, whether the cover and in-text images are done) and `recentQuizQuestions`. Use it to pick topics that are not covered yet, to balance categories, and to avoid repeating quiz questions.

## API reference (admin)

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/agent/context` | state described above |
| POST | `/quizzes` | store a quiz — see daily-quiz.md |
| POST | `/posts` | create an article (draft when `published_at` is null) |
| PATCH | `/posts/{id}` | update fields, e.g. publish with `published_at` |
| POST | `/posts/{id}/generate-image` | draw the cover image (~20 s) |
| POST | `/posts/{id}/generate-inline-image` | draw the next `[[SLIKA: …]]` image (~20 s); repeat until `"pending": 0` |
| DELETE | `/posts/{id}` | delete an article |

Public reads: `GET /posts`, `/posts/{id or slug}`, `/categories`, `/quizzes`, `/quizzes/today`.

## Rules

- Write in Bosnian (ijekavica, latinica), National Geographic tone, only well-known verifiable facts. No invented quotes, experts, institutions or statistics.
- Never delete or overwrite existing content unless the task explicitly says so.
- If a call fails, read the JSON `message`, fix the payload and retry once; do not loop.
- Finish with a short report: what was created (titles, ids, URLs `https://geovizija.com/#/article/{id}` / `#/quiz/{date}`) and anything that failed.
