#!/usr/bin/env bash
# Backup of the Docker setup: database dump plus all stored photos.
#
#   bash scripts/backup.sh                    # backups go to /var/backups/photo-gallery
#   bash scripts/backup.sh /some/other/folder
#
# Each run writes a dated folder (e.g. 2026-10-14_0300/) with db.sql.gz and
# photos.tar.gz, and keeps the newest 14 (KEEP=30 bash scripts/backup.sh to change).
# Both containers must be running. Nightly from cron, as a user that can run docker:
#
#   0 3 * * * bash /path/to/repo/scripts/backup.sh >> /var/log/photo-gallery-backup.log 2>&1
#
# The database is dumped first, then the photos. A photo uploaded in between
# is in the archive but not in the dump; that is harmless (an unused folder).
#
# ---- Restore ----------------------------------------------------------------
# Run from the repo folder. B is the backup folder, e.g. B=/var/backups/photo-gallery/2026-10-14_0300
#
#   docker compose stop app
#
#   # 1. Database (replaces the tables that are in the dump)
#   gunzip -c "$B/db.sql.gz" | docker compose exec -T db sh -c \
#     'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" "$MARIADB_DATABASE"'
#
#   # 2. Photos (removes the current photo folders, then unpacks the archive)
#   docker compose run --rm -T --no-deps --entrypoint sh app -c \
#     'rm -rf storage/app/private/photos storage/app/public/photos && tar -xzf - -C storage' \
#     < "$B/photos.tar.gz"
#
#   docker compose start app
# -----------------------------------------------------------------------------

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${1:-/var/backups/photo-gallery}"
KEEP="${KEEP:-14}"
STAMP="$(date +%F_%H%M)"
TARGET="$BACKUP_DIR/$STAMP"
PARTIAL="$TARGET.partial"

cd "$ROOT"

log() { echo "[$(date '+%F %T')] $*"; }

# A failed run leaves only a .partial folder, which is never counted as a backup.
cleanup() { [ -d "$PARTIAL" ] && rm -rf "$PARTIAL"; }
trap 'log "Backup FAILED."; cleanup' ERR

mkdir -p "$PARTIAL"

log "Dumping the database..."
docker compose exec -T db sh -c \
  'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump --single-transaction --routines -u"$MARIADB_USER" "$MARIADB_DATABASE"' \
  | gzip > "$PARTIAL/db.sql.gz"

log "Archiving the photos..."
docker compose exec -T app tar -czf - -C /var/www/html/storage app/private app/public \
  > "$PARTIAL/photos.tar.gz"

gzip -t "$PARTIAL/db.sql.gz"
gzip -t "$PARTIAL/photos.tar.gz"

mv "$PARTIAL" "$TARGET"
log "Saved $TARGET ($(du -sh "$TARGET" | cut -f1))."

# Keep the newest $KEEP complete backups. Folder names sort by date.
mapfile -t backups < <(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d \
  -name '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]_[0-9][0-9][0-9][0-9]' | sort)

if [ "${#backups[@]}" -gt "$KEEP" ]; then
  for old in "${backups[@]:0:${#backups[@]}-KEEP}"; do
    rm -rf "$old"
    log "Removed old backup $old."
  done
fi

log "Done."
