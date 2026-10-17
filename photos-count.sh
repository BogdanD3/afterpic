#!/usr/bin/env bash
# Shows how many photos are in the database, per status.
#   bash scripts/photos-count.sh
set -eu
# Find the project folder (the one with docker-compose.yml), wherever this file was put.
dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [ ! -f "$dir/docker-compose.yml" ] && [ "$dir" != "/" ]; do dir="$(dirname "$dir")"; done
if [ ! -f "$dir/docker-compose.yml" ]; then
  echo "Could not find docker-compose.yml above this script. Put the script inside your project folder." >&2
  exit 1
fi
cd "$dir"

docker compose exec -T db sh -c \
  'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT status, COUNT(*) AS photos FROM photos GROUP BY status; SELECT COUNT(*) AS total FROM photos;"'
