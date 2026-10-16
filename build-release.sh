#!/usr/bin/env bash
#
# Builds a self-contained release zip of infominaAI-ssm-mock for cPanel shared
# hosting: composer deps vendored, production .env baked in, ready to extract
# and point a subdomain's document root at <extracted-dir>/public.
#
# Usage:
#   ./build-release.sh
#
# On start the script scans the repo root for release env files (`.env.*`, except
# `.env.example`) and asks which one to load; its values fill in any of the variables
# below that are not already set in the shell. Pick "none" to use the shell
# environment only. `.env` itself is never offered - it is the local dev config.
#
#   RELEASE_ENV_FILE=.env.production        - load that file without asking
#   RELEASE_ENV_FILE=none                   - skip the scan and prompt entirely
#   DRY_RUN=1                               - print the resolved settings and exit
#                                             before anything is built
#
# Env vars (set in the shell or in the chosen env file):
#   APP_URL                                 - required, the public URL of the deployment
#   ADMIN_EMAIL, ADMIN_PASSWORD            - fixed values instead of random/default ones
#                                             for the seeded admin account
#   SENANGPAY_SECRET_KEYS, MOCK_REDIRECT_URL - baked into the production .env for the
#                                             payment mock (see README). KEYS is
#                                             `frontend-host=secret;...`, one per BE
#                                             environment allowed to use the mock; each
#                                             secret must equal that BE's
#                                             SENANGPAY_SECRET_KEY.
#   SKIP_TESTS=1                           - skip the pre-build test run
#   ALLOW_DIRTY=1                          - build even with uncommitted changes
#                                             (uncommitted/untracked files are never
#                                             included either way - only `git HEAD` is)

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

for bin in php composer npm zip git openssl; do
  command -v "$bin" >/dev/null 2>&1 || { echo "error: '$bin' is required but not found in PATH" >&2; exit 1; }
done

# Variables a release env file may set. Values already exported in the shell win over
# the file, so `APP_URL=... ./build-release.sh` still overrides a chosen file.
RELEASE_VARS=(APP_URL ADMIN_EMAIL ADMIN_PASSWORD SENANGPAY_SECRET_KEYS MOCK_REDIRECT_URL SKIP_TESTS ALLOW_DIRTY)

load_release_env_file() {
  local file=$1 var saved
  # Remember values already set in the shell (bash 3.2 on macOS: no associative arrays).
  for var in "${RELEASE_VARS[@]}"; do
    if [[ -n "${!var:-}" ]]; then
      printf -v "preset_$var" '%s' "${!var}"
    else
      unset "preset_$var"
    fi
  done
  set -a
  # shellcheck disable=SC1090
  source "$file"
  set +a
  for var in "${RELEASE_VARS[@]}"; do
    saved="preset_$var"
    [[ -n "${!saved:-}" ]] && printf -v "$var" '%s' "${!saved}"
  done
  echo "==> Loaded $file"
}

choose_release_env_file() {
  local candidates=() f choice
  for f in .env.*; do
    [[ -f "$f" && "$f" != ".env.example" ]] && candidates+=("$f")
  done
  if [[ ${#candidates[@]} -eq 0 ]]; then
    echo "==> No release env files found (looked for .env.* next to this script); using the shell environment"
    return
  fi
  if [[ ! -t 0 ]]; then
    echo "==> Not a terminal; using the shell environment. Set RELEASE_ENV_FILE=<file> to load one." >&2
    return
  fi
  echo "==> Release env files found:"
  local i
  for i in "${!candidates[@]}"; do
    printf '  %d) %s\n' "$((i + 1))" "${candidates[$i]}"
  done
  printf '  0) none - use the shell environment only\n'
  while true; do
    read -r -p "Load which? [1]: " choice
    choice=${choice:-1}
    if [[ "$choice" == "0" ]]; then
      echo "==> Using the shell environment only"
      return
    fi
    if [[ "$choice" =~ ^[0-9]+$ && "$choice" -ge 1 && "$choice" -le ${#candidates[@]} ]]; then
      load_release_env_file "${candidates[$((choice - 1))]}"
      return
    fi
    echo "  enter a number between 0 and ${#candidates[@]}" >&2
  done
}

case "${RELEASE_ENV_FILE:-}" in
  "")   choose_release_env_file ;;
  none) echo "==> RELEASE_ENV_FILE=none; using the shell environment only" ;;
  *)
    [[ -f "$RELEASE_ENV_FILE" ]] || { echo "error: RELEASE_ENV_FILE '$RELEASE_ENV_FILE' not found" >&2; exit 1; }
    load_release_env_file "$RELEASE_ENV_FILE"
    ;;
esac

: "${APP_URL:?Set APP_URL in the shell or in a release env file, e.g. APP_URL=https://ssm-mock.example.com}"

if [[ -n "${DRY_RUN:-}" ]]; then
  echo "==> Dry run; resolved settings:"
  printf '  %-22s %s\n' APP_URL "$APP_URL"
  printf '  %-22s %s\n' ADMIN_EMAIL "${ADMIN_EMAIL:-admin@ssm-mock.local (default)}"
  if [[ -n "${ADMIN_PASSWORD:-}" ]]; then
    printf '  %-22s %s\n' ADMIN_PASSWORD "<set>"
  else
    printf '  %-22s %s\n' ADMIN_PASSWORD "<random>"
  fi
  if [[ -n "${SENANGPAY_SECRET_KEYS:-}" ]]; then
    printf '  %-22s %s\n' SENANGPAY_SECRET_KEYS "<set, ${#SENANGPAY_SECRET_KEYS} chars>"
  else
    printf '  %-22s %s\n' SENANGPAY_SECRET_KEYS "<unset - payment mock will answer 503>"
  fi
  printf '  %-22s %s\n' MOCK_REDIRECT_URL "${MOCK_REDIRECT_URL:-<unset>}"
  printf '  %-22s %s\n' SKIP_TESTS "${SKIP_TESTS:-<unset>}"
  printf '  %-22s %s\n' ALLOW_DIRTY "${ALLOW_DIRTY:-<unset>}"
  exit 0
fi

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

ADMIN_EMAIL="${ADMIN_EMAIL:-admin@ssm-mock.local}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(openssl rand -hex 16)}"
APP_KEY="base64:$(openssl rand -base64 32)"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "==> Exporting committed tree"
git archive HEAD | tar -x -C "$BUILD_DIR"

echo "==> Installing production dependencies"
(cd "$BUILD_DIR" && composer install --no-dev --optimize-autoloader --no-interaction)

echo "==> Building frontend assets"
(cd "$BUILD_DIR" && npm ci && npm run build)

echo "==> Writing production .env"
cat > "$BUILD_DIR/.env" <<EOF
APP_NAME="InfominaAI SSM Mock"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$APP_URL
EOF
if [[ -n "${SENANGPAY_SECRET_KEYS:-}" ]]; then
  printf 'SENANGPAY_SECRET_KEYS="%s"\n' "$SENANGPAY_SECRET_KEYS" >> "$BUILD_DIR/.env"
else
  echo "warn: SENANGPAY_SECRET_KEYS not set - payment mock endpoints will answer 503 until it is added to .env on the server" >&2
fi
if [[ -n "${MOCK_REDIRECT_URL:-}" ]]; then
  printf 'MOCK_REDIRECT_URL=%s\n' "$MOCK_REDIRECT_URL" >> "$BUILD_DIR/.env"
fi

echo "==> Setting storage/bootstrap permissions"
chmod -R 775 "$BUILD_DIR/storage" "$BUILD_DIR/bootstrap/cache"

echo "==> Migrating database and seeding admin user"
touch "$BUILD_DIR/database/database.sqlite"
(cd "$BUILD_DIR" && php artisan migrate --force)
(cd "$BUILD_DIR" && php artisan ssm:make-admin "$ADMIN_EMAIL" "$ADMIN_PASSWORD")

RELEASE_DIR="$ROOT_DIR/release"
mkdir -p "$RELEASE_DIR"
OUT_ZIP="$RELEASE_DIR/ssm-mock-release-$(date +%Y%m%d%H%M%S).zip"
echo "==> Zipping release"
(cd "$BUILD_DIR" && zip -rq "$OUT_ZIP" .)

cat <<EOF

Release built: $OUT_ZIP

ADMIN_EMAIL=$ADMIN_EMAIL
ADMIN_PASSWORD=$ADMIN_PASSWORD
(save these - log into https://<domain>/login with them, then visit /tokens to
generate an API credential pair. Set THAT key/secret as SSM_API_KEY / SSM_API_SECRET
in infominaAI-BE's env - not the admin password above.)

Deploy:
  1. cPanel File Manager: create a folder outside public_html, e.g. ~/laravel-ssm-mock/
  2. Upload $(basename "$OUT_ZIP") into it and Extract
  3. cPanel > Domains: point your subdomain's document root at ~/laravel-ssm-mock/public
  4. Log into https://<domain>/login, generate a token at /tokens, and configure
     infominaAI-BE with it
  5. Test: curl -X POST https://<domain>/get-search-entity ...
EOF
