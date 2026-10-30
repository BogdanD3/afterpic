#!/usr/bin/env bash
#
# One staging copy of the app on the server, next to the live one, for
# trying any branch before merging it into main.
#
# Run it from the live checkout (~/afterpic, on main). It works on a second
# clone, ~/afterpic-staging (STAGING_DIR to change it), which has its own
# .env, port, containers, database and photos:
#
#   bash scripts/staging.sh setup https://host:14444     # once: make the staging clone and its .env
#   bash scripts/staging.sh deploy feature/events-rooms   # put a branch on staging
#   bash scripts/staging.sh copy-live-data                 # replace staging data with a copy of the live data
#   bash scripts/staging.sh reset                          # empty staging database and photos
#   bash scripts/staging.sh status                         # branch, commit, health
#   bash scripts/staging.sh logs                           # follow the app log (Ctrl+C to leave)
#   bash scripts/staging.sh down                           # stop staging (data is kept)
#
# The live app is only ever read, never changed: copy-live-data dumps its
# database and copies its photos.

set -euo pipefail

LIVE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
STAGING_DIR="${STAGING_DIR:-$HOME/afterpic-staging}"
STAGING_PORT="${STAGING_PORT:-9003}"

PROJECT=afterpic-staging
COMPOSE=(docker compose -p "$PROJECT" --project-directory "$STAGING_DIR"
    -f "$STAGING_DIR/docker-compose.yml" -f "$LIVE_DIR/docker-compose.staging.yml")
LIVE_DB=photo-gallery-db
LIVE_STORAGE=PhotoGallery-Storage
STAGING_DB=photo-gallery-staging-db
STAGING_STORAGE=PhotoGallery-Staging-Storage

say() { printf '\n== %s\n' "$*"; }
fail() { printf 'staging: %s\n' "$*" >&2; exit 1; }

env_value() {
    sed -n "s/^$1=//p" "$STAGING_DIR/.env" | tail -n 1 | tr -d '"'"'"
}

set_env_value() {
    local file="$STAGING_DIR/.env"

    if grep -q "^$1=" "$file"; then
        sed -i "s|^$1=.*|$1=$2|" "$file"
    else
        printf '%s=%s\n' "$1" "$2" >> "$file"
    fi
}

check_ready() {
    [ -d "$STAGING_DIR/.git" ] || fail "no staging clone at $STAGING_DIR. Run: bash scripts/staging.sh setup <public staging URL>"
    [ -f "$STAGING_DIR/.env" ] || fail "no .env in $STAGING_DIR. Run setup again."

    local port
    port="$(env_value APP_PORT)"
    [ -n "$port" ] && [ "$port" != "$(sed -n 's/^APP_PORT=//p' "$LIVE_DIR/.env" 2>/dev/null || echo 9002)" ] && [ "$port" != "9002" ] \
        || fail "APP_PORT in $STAGING_DIR/.env must differ from the live app's port."
}

wait_until_up() {
    local port
    port="$(env_value APP_PORT)"

    for _ in $(seq 1 60); do
        if curl -fsS "http://127.0.0.1:$port/up" >/dev/null 2>&1; then
            printf 'Staging is up on http://127.0.0.1:%s (public: %s)\n' "$port" "$(env_value APP_URL)"
            return 0
        fi
        sleep 2
    done

    "${COMPOSE[@]}" logs --tail 40 app >&2
    fail "the app did not come up within 2 minutes. The log is above."
}

setup() {
    local url="${1:-}"
    [ -n "$url" ] || fail "usage: staging.sh setup <public staging URL, e.g. https://host:14444>"
    [ -f "$LIVE_DIR/.env" ] || fail "no live .env in $LIVE_DIR to start from."

    if [ ! -d "$STAGING_DIR/.git" ]; then
        say "Cloning into $STAGING_DIR"
        git clone "$(git -C "$LIVE_DIR" remote get-url origin)" "$STAGING_DIR"
    fi

    if [ ! -f "$STAGING_DIR/.env" ]; then
        say "Creating $STAGING_DIR/.env from the live one"
        cp "$LIVE_DIR/.env" "$STAGING_DIR/.env"
        chmod 600 "$STAGING_DIR/.env"
    fi

    set_env_value APP_PORT "$STAGING_PORT"
    set_env_value APP_URL "$url"

    printf '\nStaging will listen on 127.0.0.1:%s and be opened as %s.\n' "$STAGING_PORT" "$url"
    printf 'Point your HTTPS proxy for that URL at 127.0.0.1:%s (see the README, Staging).\n' "$STAGING_PORT"
    printf 'Next: bash scripts/staging.sh deploy <branch>\n'
}

deploy() {
    local branch="${1:-}"
    [ -n "$branch" ] || fail "usage: staging.sh deploy <branch>"
    check_ready

    say "Getting $branch"
    git -C "$STAGING_DIR" fetch --prune origin
    git -C "$STAGING_DIR" checkout -B "$branch" "origin/$branch"
    git -C "$STAGING_DIR" log -1 --format='%h %s (%an, %ar)'

    say "Building and starting (migrations run on start)"
    "${COMPOSE[@]}" up -d --build
    wait_until_up
}

copy_live_data() {
    check_ready
    docker inspect "$LIVE_DB" >/dev/null 2>&1 || fail "the live database container ($LIVE_DB) is not running."
    docker volume inspect "$LIVE_STORAGE" >/dev/null 2>&1 || fail "the live photo volume ($LIVE_STORAGE) does not exist."
    docker image inspect photo-gallery:staging >/dev/null 2>&1 || fail "deploy a branch first, so the staging image exists."

    local database
    database="$(env_value DB_DATABASE)"
    database="${database:-photo_gallery}"

    say "Stopping the staging app"
    "${COMPOSE[@]}" stop app
    "${COMPOSE[@]}" up -d --wait db

    say "Copying the live database into staging"
    docker exec "$STAGING_DB" sh -c "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" -e 'DROP DATABASE IF EXISTS \`$database\`; CREATE DATABASE \`$database\`; GRANT ALL ON \`$database\`.* TO \"\$MARIADB_USER\"@\"%\";'"
    docker exec "$LIVE_DB" sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' \
        | docker exec -i "$STAGING_DB" sh -c "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" \`$database\`"

    say "Copying the live photos into staging"
    docker run --rm --entrypoint sh \
        -v "$LIVE_STORAGE:/from:ro" -v "$STAGING_STORAGE:/to" \
        photo-gallery:staging -c 'rm -rf /to/* /to/.[!.]* 2>/dev/null; cp -a /from/. /to/'

    say "Starting the staging app: the branch's migrations now run on the copied data"
    "${COMPOSE[@]}" up -d app
    wait_until_up
}

reset() {
    check_ready
    say "Deleting the staging database and photos (live data is not touched)"
    "${COMPOSE[@]}" down -v
    "${COMPOSE[@]}" up -d
    wait_until_up
}

status() {
    check_ready
    printf 'Branch: %s\nCommit: %s\n' "$(git -C "$STAGING_DIR" branch --show-current)" "$(git -C "$STAGING_DIR" log -1 --format='%h %s (%ar)')"
    "${COMPOSE[@]}" ps
    if curl -fsS "http://127.0.0.1:$(env_value APP_PORT)/up" >/dev/null 2>&1; then echo "Health: up"; else echo "Health: DOWN"; fi
}

case "${1:-}" in
    setup) setup "${2:-}" ;;
    deploy) deploy "${2:-}" ;;
    copy-live-data) copy_live_data ;;
    reset) reset ;;
    status) status ;;
    logs) check_ready && "${COMPOSE[@]}" logs -f app ;;
    down) check_ready && "${COMPOSE[@]}" down ;;
    *) sed -n '2,21p' "$0"; exit 2 ;;
esac
