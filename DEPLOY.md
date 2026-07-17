# Deploying to cPanel Shared Hosting (no SSH)

## 1. Build the release locally

    cd infominaAI-ssm-mock
    composer install --no-dev --optimize-autoloader
    php artisan key:generate --show   # copy this value for APP_KEY below

## 2. Package and upload

1. Zip the entire project directory (including `vendor/`, excluding `.git/`).
2. In cPanel File Manager, create a folder **outside** `public_html`, e.g. `~/laravel-ssm-mock/`.
3. Upload and extract the zip there, so `~/laravel-ssm-mock/app`, `~/laravel-ssm-mock/vendor`, etc. exist.

## 3. Expose the public/ folder

1. Copy `~/laravel-ssm-mock/public/index.php` and `~/laravel-ssm-mock/public/.htaccess` into `public_html/` (or `public_html/ssm-mock/` if sharing the domain with another app).
2. Edit the copied `index.php`: change

       require __DIR__.'/../vendor/autoload.php';

   to

       require '/home/<cpanel-username>/laravel-ssm-mock/vendor/autoload.php';

   and change

       $app = require_once __DIR__.'/../bootstrap/app.php';

   to

       $app = require_once '/home/<cpanel-username>/laravel-ssm-mock/bootstrap/app.php';

## 4. Configure .env

Create `~/laravel-ssm-mock/.env` via File Manager with:

    APP_NAME="InfominaAI SSM Mock"
    APP_ENV=production
    APP_KEY=base64:...          # from step 1
    APP_DEBUG=false
    APP_URL=https://<your-domain>/ssm-mock

    MOCK_SSM_API_KEY=<choose-a-value>
    MOCK_SSM_API_SECRET=<choose-a-value>

`APP_URL` must be the public URL this app is reachable at — it's used to build the `documentUrl` returned by `/get-order-document`, which `infominaAI-BE` fetches directly.

## 5. Permissions

Via File Manager, set `storage/` and `bootstrap/cache/` to `775` (recursively).

## 6. Point infominaAI-BE at the mock

Set, in infominaAI-BE's env:

    SSM_API_URL=https://<your-domain>/ssm-mock/
    SSM_API_KEY=<same as MOCK_SSM_API_KEY>
    SSM_API_SECRET=<same as MOCK_SSM_API_SECRET>

## 7. Adding a new case afterward

Upload a new folder under `storage/app/ssm-fixtures/cases/{caseKey}/` via FTP/File Manager (see `README.md` for the folder contents). No rebuild or redeploy of app code is needed.
