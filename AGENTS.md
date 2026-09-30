# Geovizija backend

Laravel 12 API for posts and categories, deployed at `https://geovizija.com/endpoints/api/...`. See `README.md` for endpoints and layout.

# Deployment

The frontend repo is the web root; this repo is cloned inside it as `endpoints/`.

After pushing, redeploy by opening (plain-text streamed output):

- Backend: https://geovizija.com/endpoints/redeploy.php — `git pull --ff-only`, `composer install --no-dev`, clears caches, `config:cache`
- Frontend: https://geovizija.com/redeploy.php — `git pull --ff-only`, `npm ci`, `npm run build`

The backend redeploy does not run migrations. A release that adds a migration needs `php artisan migrate` run explicitly, after the user approves it.

The host's MySQL defaults to MyISAM with a 1000-byte key limit; `config/database.php` forces InnoDB and `AppServiceProvider` sets `Schema::defaultStringLength(191)`. Keep both.

# Post images

`App\Services\PostImageGenerator` draws 16:9 cover images through OpenRouter (`OPENROUTER_IMAGE_MODEL`, default `google/gemini-2.5-flash-image`), saves them as JPEG in `public/media/posts` (gitignored, lives only on the server) and stores the relative path in `posts.image_url`; `PostResource` makes it absolute.

- On the server: `POST https://geovizija.com/endpoints/api/posts/{id}/generate-image` with `Authorization: Bearer <ADMIN_API_TOKEN>`
- CLI: `php artisan posts:generate-images [ids...] [--force]` — writes files to the machine it runs on, so running it locally against the production DB points posts at images that do not exist on the server.

Each generation is a paid OpenRouter call.

# Post content

`App\Services\PostContentGenerator` rewrites a post's excerpt and body into a 900–1300 word Bosnian (ijekavica) magazine article through `OPENROUTER_MODEL`; title and slug are kept. Body format: paragraphs separated by blank lines, subheadings as lines starting with `## ` (rendered by the frontend's `ArticlePage`). It retries the primary model once before `OPENROUTER_FALLBACK_MODEL`.

- On the server: `POST https://geovizija.com/endpoints/api/posts/{id}/generate-content` (admin token)
- CLI: `php artisan posts:generate-content {ids...}` — writes to whatever database `.env` points at.

Generated text can contain inaccurate specifics; the prompt forbids invented quotes and statistics, but review before relying on figures. The original short texts remain in `database/seeders/data/posts.json`.

# Database safety — mandatory

`.env` may point at the live production database (`tagnetba_geovizija` on the remote host). Never assume a command is safe because of an environment name.

- Never run `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, destructive rollbacks, truncation or mass deletion unless the user explicitly requests that exact operation after being told which database will be affected.
- Never run `db:seed` or `migrate --seed` without the same explicit request.
- Before any command that can write to a database, verify the effective connection (driver, host, port, database) read-only, accounting for `.env`, process environment and cached config.
- Run automated tests only with `DB_CONNECTION=sqlite DB_DATABASE=:memory:` set for the test process.
- Never print secrets from `.env` (DB password, `ADMIN_API_TOKEN`, `OPENROUTER_API_KEY`).
