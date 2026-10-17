#!/usr/bin/env bash
# One command to test the whole app.
#
#   bash scripts/test.sh                              # backend tests + frontend lint/build
#   bash scripts/test.sh https://your-url [--insecure]  # ... plus live end-to-end check of a running server
#
# Steps are skipped (and reported as SKIPPED) when their tools are missing.
# Exit code is non-zero if any step that ran has failed.

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
URL=""
SMOKE_ARGS=()

for arg in "$@"; do
  case "$arg" in
    http://*|https://*) URL="$arg" ;;
    *) SMOKE_ARGS+=("$arg") ;;
  esac
done

RESULTS=()
FAILED=0

record() { RESULTS+=("$1  $2"); [ "$1" = "FAIL " ] && FAILED=1; }

run_step() {
  local name="$1"; shift
  echo
  echo "=== $name ==="
  if "$@"; then record "PASS " "$name"; else record "FAIL " "$name"; fi
}

skip_step() {
  echo
  echo "=== $1 ==="
  echo "Skipped: $2"
  record "SKIP " "$1 ($2)"
}

# ---- Backend ----------------------------------------------------------------
backend_tests() (
  cd "$ROOT/backend" || exit 1
  if [ ! -d vendor ]; then composer install --no-interaction --prefer-dist || exit 1; fi
  [ -f .env ] || { [ -f .env.example ] && cp .env.example .env; }
  grep -q '^APP_KEY=.\+' .env 2>/dev/null || php artisan key:generate --no-interaction >/dev/null
  php artisan test
)

# Same tests, but inside a throwaway container made from the app image (it has
# PHP 8.4 with all extensions). The backend folder is mounted read-only and
# copied, so nothing is written into your project.
backend_tests_in_docker() {
  docker run --rm -v "$ROOT/backend:/src:ro" --entrypoint sh photo-gallery:latest -c '
    set -e
    cp -r /src /tmp/app
    cd /tmp/app
    rm -f bootstrap/cache/*.php
    composer install --no-interaction --prefer-dist
    [ -f .env ] || cp .env.example .env
    php artisan key:generate --no-interaction >/dev/null
    php artisan test
  '
}

if command -v php >/dev/null && php -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);' \
   && { [ -d "$ROOT/backend/vendor" ] || command -v composer >/dev/null; }; then
  run_step "Backend tests (php artisan test)" backend_tests
elif command -v docker >/dev/null && docker image inspect photo-gallery:latest >/dev/null 2>&1; then
  run_step "Backend tests (inside the app's Docker image)" backend_tests_in_docker
else
  skip_step "Backend tests" "no PHP 8.4 here and no Docker image 'photo-gallery:latest' (run: docker compose build)"
fi

# ---- Frontend ---------------------------------------------------------------
frontend_checks() (
  cd "$ROOT/frontend" || exit 1
  [ -d node_modules ] || npm ci || exit 1
  npm run lint && npm run build
)

if command -v npm >/dev/null; then
  run_step "Frontend lint + build" frontend_checks
else
  skip_step "Frontend lint + build" "npm not found"
fi

# ---- Live server ------------------------------------------------------------
if [ -n "$URL" ]; then
  run_step "Live end-to-end check ($URL)" bash "$ROOT/scripts/smoke-test.sh" "$URL" "${SMOKE_ARGS[@]+"${SMOKE_ARGS[@]}"}"
else
  skip_step "Live end-to-end check" "no URL given"
fi

# ---- Summary ----------------------------------------------------------------
echo
echo "================ SUMMARY ================"
printf '%s\n' "${RESULTS[@]}"
echo "========================================="
if [ "$FAILED" -eq 0 ]; then echo "All steps that ran passed."; else echo "Some steps FAILED."; fi
exit "$FAILED"
