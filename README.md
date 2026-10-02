# Geovizija API

Laravel 12 backend for posts and categories. Production URL: `https://geovizija.com/endpoints/api/...`

## Deployment layout

Same structure as freightbook: the frontend repo is the web root and this repo is cloned inside it as `endpoints/`.

```
public_html/                 <- geovizija-front (web root)
├── .htaccess                <- serves dist/, passes /endpoints/* through
├── dist/                    <- npm run build
└── endpoints/               <- this repo
    ├── .htaccess            <- forwards everything into public/
    └── public/
        ├── .htaccess        <- Laravel front controller rewrite
        └── index.php        <- treats /endpoints as the base path
```

Server setup:

```
cd public_html && git clone <backend repo> endpoints && cd endpoints
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://geovizija.com/endpoints,
#            DB_* (MySQL), ADMIN_API_TOKEN=<long random string>
php artisan migrate
php artisan config:cache
```

### Redeploy

Copied from freightbook. Open in a browser (streams plain-text output):

- `https://geovizija.com/redeploy.php` — frontend: `git pull --ff-only`, `npm ci`, `npm run build`
- `https://geovizija.com/endpoints/redeploy.php` — backend: `git pull --ff-only`, `composer install --no-dev`, clears caches, `config:cache`

The backend redeploy does not run migrations; run `php artisan migrate` manually when a release adds one. Both scripts are unauthenticated, same as freightbook.

`php artisan db:seed` imports the frontend's mock articles (`database/seeders/data/*.json`). It is idempotent (matches by slug), but only run it on purpose.

## Endpoints

Public (published posts only):

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/categories` | ordered by `sort_order`, includes `postsCount` |
| GET | `/api/categories/{slug}` | |
| GET | `/api/posts` | `?category=<slug>&featured=1&search=<text>&per_page=12&page=1` |
| GET | `/api/posts/{id or slug}` | |
| GET | `/api/posts/{id or slug}/comments` | top-level comments newest first, each with `replies` (max 200) |
| POST | `/api/posts/{id or slug}/comments` | `author, body, parentId?` (reply to a top-level comment); 5/min per IP, max one link, no duplicate within 10 min, hidden `website` honeypot must be empty |
| POST / DELETE | `/api/comments/{id}/like` | add / remove a like; 30/min per IP |

Admin (`Authorization: Bearer <ADMIN_API_TOKEN>`):

| Method | Path | Body |
| --- | --- | --- |
| POST / PATCH / DELETE | `/api/categories[/{slug}]` | `slug, name, color, image_url, sort_order` |
| DELETE | `/api/comments/{id}` | removes a comment and its replies |
| POST / PATCH / DELETE | `/api/posts[/{id or slug}]` | `category (slug), title, slug?, excerpt, content, image_url, author, read_time?, featured, published_at` |

`POST /api/posts/{id or slug}/generate-image` (admin) generates a new cover through OpenRouter and returns the updated post.

A post is public once `published_at` is set and not in the future. Deleting a category that still has posts returns 409.

Responses use the frontend's field names (`categoryId`, `imageUrl`, `readTime`, `date` like `12. Oktobar 2026`).

## Local development

```
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed
php artisan serve   # http://127.0.0.1:8000/api/posts
```

CORS origins come from `FRONTEND_URLS` (comma separated).
