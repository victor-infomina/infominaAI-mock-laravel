#!/usr/bin/env bash
#
# Builds a self-contained release zip of infominaAI-ssm-mock for cPanel shared
# hosting: composer deps vendored, production .env baked in, ready to extract
# and point a subdomain's document root at <extracted-dir>/public.
#
# Usage:
#   APP_URL=https://ssm-mock.example.com ./build-release.sh
#
# Optional env vars:
#   MOCK_SSM_API_KEY, MOCK_SSM_API_SECRET  - fixed values instead of random ones
#   SKIP_TESTS=1                           - skip the pre-build test run
#   ALLOW_DIRTY=1                          - build even with uncommitted changes
#                                             (uncommitted/untracked files are never
#                                             included either way - only `git HEAD` is)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

for bin in php composer zip git openssl; do
  command -v "$bin" >/dev/null 2>&1 || { echo "error: '$bin' is required but not found in PATH" >&2; exit 1; }
done

: "${APP_URL:?Set APP_URL, e.g. APP_URL=https://ssm-mock.example.com ./build-release.sh}"

if [[ -z "${ALLOW_DIRTY:-}" && -n "$(git status --porcelain)" ]]; then
  echo "error: uncommitted or untracked changes present. Commit them first (only" >&2
  echo "       'git HEAD' is packaged) or re-run with ALLOW_DIRTY=1 to build anyway." >&2
  git status --short >&2
  exit 1
fi

if [[ -z "${SKIP_TESTS:-}" ]]; then
  [[ -f vendor/autoload.php ]] || composer install --no-interaction
  echo "==> Running tests"
  php artisan test
fi

MOCK_SSM_API_KEY="${MOCK_SSM_API_KEY:-$(openssl rand -hex 16)}"
MOCK_SSM_API_SECRET="${MOCK_SSM_API_SECRET:-$(openssl rand -hex 32)}"
APP_KEY="base64:$(openssl rand -base64 32)"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "==> Exporting committed tree"
git archive HEAD | tar -x -C "$BUILD_DIR"

echo "==> Installing production dependencies"
(cd "$BUILD_DIR" && composer install --no-dev --optimize-autoloader --no-interaction)

echo "==> Writing production .env"
cat > "$BUILD_DIR/.env" <<EOF
APP_NAME="InfominaAI SSM Mock"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$APP_URL

MOCK_SSM_API_KEY=$MOCK_SSM_API_KEY
MOCK_SSM_API_SECRET=$MOCK_SSM_API_SECRET
EOF

echo "==> Setting storage/bootstrap permissions"
chmod -R 775 "$BUILD_DIR/storage" "$BUILD_DIR/bootstrap/cache"

OUT_ZIP="$ROOT_DIR/ssm-mock-release-$(date +%Y%m%d%H%M%S).zip"
echo "==> Zipping release"
(cd "$BUILD_DIR" && zip -rq "$OUT_ZIP" .)

cat <<EOF

Release built: $OUT_ZIP

MOCK_SSM_API_KEY=$MOCK_SSM_API_KEY
MOCK_SSM_API_SECRET=$MOCK_SSM_API_SECRET
(save these - set the same values as SSM_API_KEY / SSM_API_SECRET in infominaAI-BE's env)

Deploy:
  1. cPanel File Manager: create a folder outside public_html, e.g. ~/laravel-ssm-mock/
  2. Upload $(basename "$OUT_ZIP") into it and Extract
  3. cPanel > Domains: point your subdomain's document root at ~/laravel-ssm-mock/public
  4. Test: curl -X POST https://<domain>/get-search-entity ...
EOF
