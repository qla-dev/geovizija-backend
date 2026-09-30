# Geovizija backend

Laravel 12 API for posts and categories, deployed at `https://geovizija.com/endpoints/api/...`. See `README.md` for endpoints and layout.

# Deployment

The frontend repo is the web root; this repo is cloned inside it as `endpoints/`.

After pushing, redeploy by opening (plain-text streamed output):

- Backend: https://geovizija.com/endpoints/redeploy.php — `git pull --ff-only`, `composer install --no-dev`, clears caches, `config:cache`
- Frontend: https://geovizija.com/redeploy.php — `git pull --ff-only`, `npm ci`, `npm run build`

The backend redeploy does not run migrations. A release that adds a migration needs `php artisan migrate` run explicitly, after the user approves it.

The host's MySQL defaults to MyISAM with a 1000-byte key limit; `config/database.php` forces InnoDB and `AppServiceProvider` sets `Schema::defaultStringLength(191)`. Keep both.

# Database safety — mandatory

`.env` may point at the live production database (`tagnetba_geovizija` on the remote host). Never assume a command is safe because of an environment name.

- Never run `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe`, destructive rollbacks, truncation or mass deletion unless the user explicitly requests that exact operation after being told which database will be affected.
- Never run `db:seed` or `migrate --seed` without the same explicit request.
- Before any command that can write to a database, verify the effective connection (driver, host, port, database) read-only, accounting for `.env`, process environment and cached config.
- Run automated tests only with `DB_CONNECTION=sqlite DB_DATABASE=:memory:` set for the test process.
- Never print secrets from `.env` (DB password, `ADMIN_API_TOKEN`, `OPENROUTER_API_KEY`).
