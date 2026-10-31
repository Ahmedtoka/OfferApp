# Deploying Pairly: BOGO Offers

This guide records what was done for the Pairly staging deployment and how to update it or move it to the real domain. It covers only the Pairly Cloudways application. It does not apply to `docs/deploy.md`, which describes the older single-store app, and nothing here changes that app or any other application on the server.

No secret appears in this repository. The API secret, Cloudways passwords and database passwords are entered by the owner on the server or in the dashboards.

## 1. What exists now

| Item | Value |
|---|---|
| Cloudways application | "Pairly BOGO", id 6760392, folder `kzyvtmqmgb` |
| Server | PROMAX (shared with other applications, which are untouched) |
| Stack | Hybrid (Apache and Nginx), PHP 8.4 |
| Webroot | `public_html/web/public` (the panel prefixes `public_html/` itself, so the field holds `web/public`) |
| Varnish | Off |
| Source | git clone of branch `feat/pairly-public-app` |
| Temporary URL | https://phpstack-1216096-6760392.cloudwaysapps.com |
| Database | SQLite today (see §3) |
| Shopify config | `shopify.app.bogo.toml`, client id `40919f051c4b377ace2d4cb4937a73d4`, handle `pairly-bogo-offers` |
| Dev store | levoilest.myshopify.com |

Server path: `/home/master/applications/kzyvtmqmgb/public_html`. The whole repository lives there, not only `web/`, because Laravel reads `../shared/offer-constants.json`.

## 2. Environment file

On the server, in `public_html/web`, `.env` is created from `.env.example` and holds the owner's values. Never commit it. Keys:

| Key | Value |
|---|---|
| `APP_NAME` | `"Pairly: BOGO Offers"` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | from `php artisan key:generate` |
| `APP_URL` | the temporary URL now; the real domain after cutover |
| `SITE_URL` | same host as `APP_URL` until a domain exists (`/` without Shopify parameters serves the landing page on this host) |
| `LOG_LEVEL` | `warning` |
| `DB_CONNECTION` | `sqlite` now, `mysql` later |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` |
| `SHOPIFY_API_KEY` | the client id above (public) |
| `SHOPIFY_API_SECRET` | secret from Dev Dashboard, app "Pairly: BOGO Offers", Settings. Run `shopify app env show --config bogo` locally if you prefer. Paste on the server only |
| `SHOPIFY_API_VERSION` | `2026-07` |
| `SHOPIFY_ALLOWED_SHOPS` | **empty**. Empty means any store may install (public app) |
| `SHOPIFY_SHARED_CONSTANTS_PATH` | `../shared/offer-constants.json`. A relative path is resolved against the app root (fix bf892f9) |
| `SHOPIFY_FUNCTION_TITLE` | `"Pairly Discount"` |
| `SHOPIFY_APP_HANDLE` | `pairly-bogo-offers` |
| `SHOPIFY_INSTALL_URL` | the App Store listing URL once it exists. Empty falls back to the docs page |
| `SHOPIFY_LEGAL_NAME` | the owner's business name, shown in the privacy policy and terms |
| `SHOPIFY_SUPPORT_EMAIL` | the owner's support address, shown on the site and in the listing |

Mail, Redis and AWS keys stay at their defaults; the app does not use them.

Note on billing: with `APP_ENV=production` the subscription is a test charge only on a Shopify development store. On a real store it is a real charge.

## 3. First deploy (what was done)

From the Cloudways SSH terminal, in the web folder:

```bash
cd /home/master/applications/kzyvtmqmgb/public_html/web
```

```bash
composer install --no-dev --optimize-autoloader
```

```bash
cp .env.example .env
```

Edit `.env` as in §2, then:

```bash
php artisan key:generate
```

Create the cache folders **before** `config:cache`. Without them `view:cache` fails:

```bash
mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs bootstrap/cache
```

```bash
chmod -R ug+rwX storage bootstrap/cache
```

For SQLite, create the database file named in `config/database.php` (default `database/database.sqlite`) before migrating:

```bash
touch database/database.sqlite
```

```bash
php artisan migrate --force
```

```bash
php artisan config:cache
```

```bash
php artisan route:cache
```

```bash
php artisan view:cache
```

Smoke test:

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://phpstack-1216096-6760392.cloudwaysapps.com/up
```

Expect 200. The landing page returns 200, and an unsigned `POST /webhooks` returns 401 (all three were seen on 2026-10-31).

### Moving from SQLite to MySQL
SQLite was used because a MySQL password would have had to be handled in plain text. To move: in Cloudways, MySQL Management for the application shows the database name, user and password (owner only). Then set in `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<from Cloudways>
DB_USERNAME=<from Cloudways>
DB_PASSWORD=<from Cloudways>
```

Run `php artisan migrate --force` and `php artisan config:cache`. Stored shop tokens do not move with the file: each store gets a fresh token on its next open (token exchange), and settings live in Shopify, not in this database. This was not exercised; test on the dev store first.

## 4. Updating the running application

Cloudways: Application Management, Deployment via Git, pull branch `feat/pairly-public-app` (or `main` after merge). Then on the server, in `public_html/web`:

```bash
composer install --no-dev --optimize-autoloader
```

```bash
php artisan migrate --force
```

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

If `view:cache` fails, a folder from §3 is missing: recreate it and repeat. After editing `.env`, run `php artisan config:cache` again, otherwise the old values stay.

Then deploy the Shopify side if the Function, the theme extension or `shopify.app.bogo.toml` changed (§5).

## 5. Deploying the Shopify app

From the repository root on the owner's machine, signed in as the owner (the CLI opens the browser; nobody else enters the password):

```bash
npm install
```

```bash
shopify app deploy --config bogo
```

Check before deploying: `application_url` equals `APP_URL`, `scopes` is exactly `write_discounts,read_products`, `optional_scopes` are `read_orders` and `read_inventory`, webhooks api_version is `2026-07`, and no temporary QA scopes remain.

### Version log
| App version | Date | What | Notes |
|---|---|---|---|
| 1.0.0-qa | 2026-10-31 | Function, theme extension `pairly-theme`, privacy webhooks, billing webhook | Deployed to the dev store only. The first deploy of the theme extension was rejected for a tests folder inside it; fixed in 89a8878 |

Add one row per release, using the version name given to `shopify app deploy`.

## 6. Cutover to the real domain
Do these in order after the owner has bought the domain.

1. Cloudways, Domain Management: add the domain (and `www` if wanted) to the application "Pairly BOGO", point its DNS A record at the server IP.
2. SSL Certificate: install Let's Encrypt for the domain.
3. Enable HTTP to HTTPS redirect.
4. Edit `.env`: `APP_URL` and `SITE_URL` to `https://<domain>`. Run `php artisan config:cache`.
5. Edit `shopify.app.bogo.toml`: `application_url = "https://<domain>"`. Commit, then `shopify app deploy --config bogo`, and add a row to the version log.
6. Smoke test again: landing 200, `/up` 200, unsigned webhook 401, then open the app from the dev store.
7. Fill the legal and listing values: `SHOPIFY_LEGAL_NAME`, `SHOPIFY_SUPPORT_EMAIL`, and later `SHOPIFY_INSTALL_URL`. Run `config:cache`.
8. Edit the draft text, which is **not** removed automatically: `web/lang/en/site.php` and `web/lang/ar/site.php`, key `legal.draft` (the "Draft for review" banner, remove only after the owner accepts the legal text), and the FAQ answer under `plans` ("The listing is not live yet", line 167 in both files, remove once the listing is live). Commit and update as in §4.

## 7. Order of the remaining work toward submission
Shopify refuses billing calls from an app without Public distribution ("Apps without a public distribution cannot use the Billing API", seen on the dev store). Choosing Public distribution does not publish anything, but it is permanent, so the owner chooses it. Order:

1. Domain and cutover (§6).
2. Legal text and business identity accepted by the owner.
3. The owner selects Public distribution in the Dev Dashboard.
4. Test charge on the dev store (trial, approval, cancel, webhook, charge history).
5. Screenshots, screencast and the listing form (`docs/app-store/`).
6. Submission.

Shopify's own managed pricing is an alternative billing route: plans are then configured on the listing and the Billing API is blocked after opting in. It was not chosen; revisit only if review requires it (`docs/spike/2026-10-31-billing-distribution.md`).

## 8. Problems seen
| Symptom | Cause | Fix |
|---|---|---|
| Test page and analytics fail on the server | Relative constants path not resolved | bf892f9, already deployed |
| `view:cache` fails | `storage/framework/views` missing | create folders (§3) |
| Webroot shows an Apache page or downloads PHP | Webroot not `public_html/web/public` | set in Application Settings |
| Theme extension rejected on deploy | A tests directory inside the extension | removed, 89a8878 |
