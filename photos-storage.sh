#!/usr/bin/env bash
# Shows how much disk space the stored photos use.
#   bash scripts/photos-storage.sh
set -eu
# Find the project folder (the one with docker-compose.yml), wherever this file was put.
dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [ ! -f "$dir/docker-compose.yml" ] && [ "$dir" != "/" ]; do dir="$(dirname "$dir")"; done
if [ ! -f "$dir/docker-compose.yml" ]; then
  echo "Could not find docker-compose.yml above this script. Put the script inside your project folder." >&2
  exit 1
fi
cd "$dir"

echo "Disk space used by photos (inside the app container):"
docker compose exec -T app sh -c '
  cd /var/www/html/storage/app
  echo "  private (download copies):  $(du -sh private | cut -f1)"
  echo "  public  (gallery copies):   $(du -sh public | cut -f1)"
  echo "  total:                      $(du -sch private public | tail -1 | cut -f1)"
'
