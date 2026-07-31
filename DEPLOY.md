# Deploying to cPanel Shared Hosting (no SSH)

## 1. Build the release

    cd infominaAI-ssm-mock
    APP_URL=https://<your-domain> ./build-release.sh

Requires `php`, `composer`, `npm`, `zip`, `git`, `openssl` on your machine. It runs the
test suite, vendors production dependencies, builds frontend assets, generates `APP_KEY`,
migrates a fresh `database/database.sqlite` and seeds the single admin account (via
`php artisan ssm:make-admin`) — all *inside* the build directory, before zipping, since
the cPanel target has no SSH access to run these afterward. It then writes
`release/ssm-mock-release-<timestamp>.zip` (gitignored). Only committed changes are
packaged — commit first.

The generated `ADMIN_EMAIL` / `ADMIN_PASSWORD` are printed at the end; save them for
step 4 — they log into the admin UI, not `infominaAI-BE`. Pass your own instead of a
random password with `ADMIN_EMAIL=... ADMIN_PASSWORD=... APP_URL=... ./build-release.sh`.

## 2. Upload and extract

1. In cPanel File Manager, create a folder **outside** `public_html`, e.g. `~/laravel-ssm-mock/`.
2. Upload the zip into it and extract — `~/laravel-ssm-mock/app`, `~/laravel-ssm-mock/vendor`,
   `~/laravel-ssm-mock/public`, etc. should exist afterward.

## 3. Point a subdomain at `public/`

In cPanel > Domains (or Subdomains), create/edit the subdomain for `APP_URL` and set its
**Document Root** to `~/laravel-ssm-mock/public`. No file copying or path editing needed.

## 4. Generate an API credential and point infominaAI-BE at the mock

1. Log into `https://<your-domain>/login` with the `ADMIN_EMAIL` / `ADMIN_PASSWORD`
   printed at the end of step 1.
2. Go to `/tokens` and generate a new API credential pair — this is what gateway
   requests actually authenticate against (the admin login itself is only for the
   `/tokens` UI).
3. Set, in infominaAI-BE's env:

       SSM_API_URL=https://<your-domain>/
       SSM_API_KEY=<key from the token you just generated>
       SSM_API_SECRET=<secret from the token you just generated>

   The secret is only shown once at generation time — copy it immediately.

## 5. Adding a new case afterward

Upload a new folder under `storage/app/ssm-fixtures/cases/{caseKey}/` via FTP/File Manager
(see `README.md` for the folder contents). No rebuild or redeploy of app code is needed.

## 6. If permissions get reset

If you hit permission errors after extracting, set `storage/` and `bootstrap/cache/` to
`775` (recursively) via File Manager — `build-release.sh` sets this before zipping, but
some extractors don't preserve it.

## Appendix: fixed `public_html`, no custom document root

If your hosting can't point a subdomain at an arbitrary folder, the app must live outside
`public_html` with only `public/index.php` + `public/.htaccess` exposed, their `require`
paths rewritten to absolute paths:

1. Copy `~/laravel-ssm-mock/public/index.php` and `~/laravel-ssm-mock/public/.htaccess`
   into `public_html/` (or `public_html/ssm-mock/` if sharing the domain with another app).
2. Edit the copied `index.php`: change

       require __DIR__.'/../vendor/autoload.php';

   to

       require '/home/<cpanel-username>/laravel-ssm-mock/vendor/autoload.php';

   and change

       $app = require_once __DIR__.'/../bootstrap/app.php';

   to

       $app = require_once '/home/<cpanel-username>/laravel-ssm-mock/bootstrap/app.php';

3. Set `APP_URL` in `.env` to the actual public URL (e.g. `https://<domain>/ssm-mock`),
   matching wherever you copied `index.php` to.
