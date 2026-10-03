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

`App\Services\PostImageGenerator` draws 16:9 cover images through OpenRouter (`OPENROUTER_IMAGE_MODEL`, default `google/gemini-2.5-flash-image`), saves them as JPEG in `public/media/posts` (every saved image, drawn or supplied by the agent, goes through `toJpeg()`: centre-cropped to 16:9 (Facebook then shows the large link preview), at most 1600 px wide, the header logo split like news photo agencies: `resources/images/watermark-frame.png` (green frame) top-left and `watermark-text.png` (white GEOVIZIJA) top-right, tops aligned, ~45 % opacity applied evenly, progressive JPEG at the highest quality that fits 250 KB, made smaller only below quality 60; such files end in `-w3.jpg`). Older images: admin `POST /api/images/reprocess?after=<next>` rewrites a few posts per call under new names until `next` is null; `-wm.jpg` and `-w2.jpg` files (earlier formats with the logo bottom-right, originals gone) lose the bottom strip with that logo first (gitignored, lives only on the server) and stores the relative path in `posts.image_url`; `PostResource` makes it absolute.

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
- Scheduled articles become scheduled Page posts (Business Suite Planner), so no cron or queue is needed. Facebook accepts 10 minutes to 30 days ahead. Facebook reads the link preview when the scheduled post is created, so `og.php` reads `GET /api/posts/{slug}/preview` (title, excerpt, cover and its `imageWidth`/`imageHeight` for `og:image:width`/`height` — also for scheduled articles; drafts 404, no body). Moving `published_at` later does not move the Facebook post.
- Posts link to `SITE_URL/article/{slug}`. The frontend repo's `.htaccess` sends crawlers (facebookexternalhit, WhatsApp, Twitterbot, Googlebot…) asking for `/article/{slug}` or `/category/{slug}` to its `og.php`, which serves the app HTML with that page's Open Graph tags from this API. `/share/{slug}` (`ShareController`) only keeps links from before clean URLs working.
- `posts.meta_post_id` prevents double posting; `posts.meta_error` keeps the last failure. Retry: `POST /api/posts/{id}/share-meta[?force=1]` (admin) or `php artisan posts:share-meta {ids...} [--force]` (writes to the database `.env` points at). Every share first asks Facebook to re-read the link preview (`scrape=true`); a forced re-share deletes the earlier Page post once the new one exists.
- A failure is logged and returned as `facebook.status = failed`; it never blocks publishing.

# Database safety — mandatory

`.env` may point at the live production database (`tagnetba_geovizija` on the remote host). Never assume a command is safe because of an environment name.

- Never run `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, destructive rollbacks, truncation or mass deletion unless the user explicitly requests that exact operation after being told which database will be affected.
- Never run `db:seed` or `migrate --seed` without the same explicit request.
- Before any command that can write to a database, verify the effective connection (driver, host, port, database) read-only, accounting for `.env`, process environment and cached config.
- Run automated tests only with `DB_CONNECTION=sqlite DB_DATABASE=:memory:` set for the test process.
- Never print secrets from `.env` (DB password, `ADMIN_API_TOKEN`, `OPENROUTER_API_KEY`).

# Instagram

`App\Services\InstagramPublisher` posts an article's cover with a caption (title, excerpt, "link u opisu profila", hashtags) on the Instagram account `META_IG_USER_ID`, through the Page token (needs `instagram_basic`, `instagram_content_publish`). Instagram cannot schedule posts, so `/api/publish`, admin `POST /api/posts` and a draft's first `published_at` only queue it (`posts.ig_status = pending`; `shareToInstagram: false` → `skipped`; older articles stay null and are never posted automatically). `php artisan instagram:publish-due` (every minute from `schedule:run`, which needs the server cron `* * * * * cd <endpoints dir> && php artisan schedule:run`) posts at most 3 due articles per run, each followed by a full-screen story (`AppServicesInstagramStory`: blurred cover background, logo, category, title, cover, "Cijeli članak na geovizija.com / LINK U OPISU PROFILA"; fonts in `resources/fonts`; drawn into `public/media/stories` and deleted once published). The API cannot add link stickers, so stories point to the bio link; post and story count as two of the 100 daily posts, so stories are rationed: at most `META_IG_STORIES_PER_DAY` (20) per 24 hours, `META_IG_STORY_GAP_MINUTES` (45) apart, counted in `storage/app/instagram-stories.json` (the cache is cleared on every redeploy). Retry or post an older article: admin `POST /api/posts/{id}/share-instagram[?force=1]`; `?only=story` posts just the story. Limit: 100 API posts per rolling 24 hours (`GET /{ig-user-id}/content_publishing_limit`). Columns `ig_*` come from migration `2026_10_02_000003`.

Every saved image also keeps its untouched original in `storage/app/originals/<name>.orig` (not public). A new cover additionally gets its Instagram feed image `<name>-ig.jpg` (`InstagramStory::feed`, 4:5 1080x1350: the original full-frame with a bottom shade, logo left, category right, title; drawn again when posted). The story (`InstagramStory::make`, 9:16) is the same design plus "Cijeli članak na geovizija.com / LINK U OPISU PROFILA". The Facebook link image `<name>-fb.jpg` (`InstagramStory::facebook`, square 1080x1080, the most upright shape a Facebook link preview shows whole) is the same design too; `/posts/{slug}/preview` returns it as `shareImageUrl` for og.php, and Facebook posts carry only the excerpt (the title is on the image). Older covers without an original fall back to the published file.
