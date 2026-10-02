# Geovizija backend

Laravel 12 API for posts and categories, deployed at `https://geovizija.com/endpoints/api/...`. See `README.md` for endpoints and layout.

# Deployment

The frontend repo is the web root; this repo is cloned inside it as `endpoints/`.

After pushing, redeploy by opening (plain-text streamed output):

- Backend: https://geovizija.com/endpoints/redeploy.php — `git pull --ff-only`, `composer install --no-dev`, clears caches, `config:cache`
- Frontend: https://geovizija.com/redeploy.php — `git pull --ff-only`, `npm ci`, `npm run build`

The backend redeploy does not run migrations. A release that adds a migration needs `php artisan migrate` run explicitly, after the user approves it.

The host's MySQL defaults to MyISAM with a 1000-byte key limit; `config/database.php` forces InnoDB and `AppServiceProvider` sets `Schema::defaultStringLength(191)`. Keep both.

# Content agent

Articles and daily quizzes are meant to be written by a scheduled Claude agent through the admin API; the playbooks are in `agents/` (README, daily-quiz, new-article, routine-prompt). Claude writes all text; the backend's OpenRouter services only draw images. `QUIZ_AUTO_GENERATE=false` (default) turns off OpenRouter quiz generation: a day without a quiz serves the latest one. The OpenRouter text generators (`posts:generate-content`, `quizzes:generate`) remain as a fallback.

Keep `agents/*.md` in sync when changing the endpoints, validation or body format they describe.

# Post images

`App\Services\PostImageGenerator` draws 16:9 cover images through OpenRouter (`OPENROUTER_IMAGE_MODEL`, default `google/gemini-2.5-flash-image`), saves them as JPEG in `public/media/posts` (every saved image, drawn or supplied by the agent, goes through `toJpeg()`: at most 1600 px wide, `resources/images/watermark.png` (the header logo) in the bottom-right corner, progressive JPEG at the highest quality that fits 100 KB, made smaller only below quality 42) (gitignored, lives only on the server) and stores the relative path in `posts.image_url`; `PostResource` makes it absolute.

- On the server: `POST https://geovizija.com/endpoints/api/posts/{id}/generate-image` with `Authorization: Bearer <ADMIN_API_TOKEN>`
- CLI: `php artisan posts:generate-images [ids...] [--force]` — writes files to the machine it runs on, so running it locally against the production DB points posts at images that do not exist on the server.

Each generation is a paid OpenRouter call.

# Post content

`App\Services\PostContentGenerator` rewrites a post's excerpt and body into a 450–650 word Bosnian (ijekavica) magazine article through `OPENROUTER_MODEL`; title and slug are kept. Body format (rendered by the frontend's `ArticlePage`): one block per line — paragraphs, subheadings starting with `## `, and exactly two in-text images. The generator writes those as `[[SLIKA: scene description]]` marker lines (validated); `PostImageGenerator::generateNextInline()` draws one marker per call and replaces it with `![caption](media/posts/...)`. `PostResource` makes those paths absolute and hides pending markers. Regenerating the text deletes the previous inline images. The primary model is tried three times before `OPENROUTER_FALLBACK_MODEL`.

- On the server: `POST https://geovizija.com/endpoints/api/posts/{id}/generate-content` (admin token)
- Then `POST .../posts/{id}/generate-inline-image` until the response has `"pending": 0` (one image per call)
- CLI: `php artisan posts:generate-content {ids...}` — writes to whatever database `.env` points at.

Generated text can contain inaccurate specifics; the prompt forbids invented quotes and statistics, but review before relying on figures. The original short texts remain in `database/seeders/data/posts.json`.

# Facebook sharing

`App\Services\MetaPublisher` shares each new article on the Geovizija Facebook Page through the Graph API (`/{page}/feed`). It is off until `META_PAGE_ID` and `META_PAGE_TOKEN` (a long-lived Page access token with `pages_manage_posts` and `pages_read_engagement`) are set in the server `.env`, then `config:cache` (the backend redeploy does it).

- Hooks: `/api/publish` (after the images), admin `POST /api/posts`, and admin `PATCH /api/posts/{id}` when it first gives a draft a `published_at`. Drafts are never shared; other edits are not re-shared. `shareToMeta: false` in the publish JSON opts out.
- Scheduled articles become scheduled Page posts (Business Suite Planner), so no cron or queue is needed. Facebook accepts 10 minutes to 30 days ahead. Facebook reads the link preview when the scheduled post is created, so `og.php` reads `GET /api/posts/{slug}/preview` (title, excerpt, cover — also for scheduled articles; drafts 404, no body). Moving `published_at` later does not move the Facebook post.
- Posts link to `SITE_URL/article/{slug}`. The frontend repo's `.htaccess` sends crawlers (facebookexternalhit, WhatsApp, Twitterbot, Googlebot…) asking for `/article/{slug}` or `/category/{slug}` to its `og.php`, which serves the app HTML with that page's Open Graph tags from this API. `/share/{slug}` (`ShareController`) only keeps links from before clean URLs working.
- `posts.meta_post_id` prevents double posting; `posts.meta_error` keeps the last failure. Retry: `POST /api/posts/{id}/share-meta[?force=1]` (admin) or `php artisan posts:share-meta {ids...} [--force]` (writes to the database `.env` points at).
- A failure is logged and returned as `facebook.status = failed`; it never blocks publishing.

# Database safety — mandatory

`.env` may point at the live production database (`tagnetba_geovizija` on the remote host). Never assume a command is safe because of an environment name.

- Never run `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, destructive rollbacks, truncation or mass deletion unless the user explicitly requests that exact operation after being told which database will be affected.
- Never run `db:seed` or `migrate --seed` without the same explicit request.
- Before any command that can write to a database, verify the effective connection (driver, host, port, database) read-only, accounting for `.env`, process environment and cached config.
- Run automated tests only with `DB_CONNECTION=sqlite DB_DATABASE=:memory:` set for the test process.
- Never print secrets from `.env` (DB password, `ADMIN_API_TOKEN`, `OPENROUTER_API_KEY`).
