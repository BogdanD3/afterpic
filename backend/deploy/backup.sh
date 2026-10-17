#!/usr/bin/env bash
# Nightly backup: database dump plus stored photos. Keeps 14 days.
# Run from cron as root, e.g. 0 3 * * * /var/www/photo-gallery/current/backend/deploy/backup.sh
# This is for the bare-metal install. For the Docker setup use scripts/backup.sh.
set -euo pipefail

APP_DIR=/var/www/photo-gallery/current/backend
BACKUP_DIR=/var/backups/photo-gallery
STAMP=$(date +%F_%H%M)

mkdir -p "$BACKUP_DIR"

DB_NAME=$(grep '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2-)
DB_USER=$(grep '^DB_USERNAME=' "$APP_DIR/.env" | cut -d= -f2-)
DB_PASS=$(grep '^DB_PASSWORD=' "$APP_DIR/.env" | cut -d= -f2-)

MYSQL_PWD="$DB_PASS" mysqldump --single-transaction -u "$DB_USER" "$DB_NAME" | gzip > "$BACKUP_DIR/db_$STAMP.sql.gz"

tar -czf "$BACKUP_DIR/files_$STAMP.tar.gz" -C "$APP_DIR/storage/app" private public

find "$BACKUP_DIR" -type f -mtime +14 -delete
