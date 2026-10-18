# Photo Gallery

A public photo gallery for parties and events. Guests open the page on their phone, tap **Slikaj** (Take photo), the phone camera opens, and the photo is uploaded automatically and appears in a shared gallery. There are no accounts and no login.

## How it works

1. The guest taps the camera button. The page uses a file input with `capture="environment"`, so the phone camera opens directly.
2. When the photo is taken, the phone prepares it: photos larger than 4000 px on the long side (for example 200 MP) are scaled down to 4000 px and saved as a quality 92 JPEG, which looks the same and is a few MB instead of 30-60 MB. Normal photos are sent untouched. The frontend then uploads it right away (with a progress indicator and up to 3 retries on network errors).
3. The backend validates the file, stores the original, and queues a job.
4. A queue worker converts the photo to a 1600px WebP and a 400px thumbnail. EXIF data, including GPS location, is removed.
5. The gallery polls the API every 5 seconds and shows new photos at the top. The first gallery page returns an encrypted `updates_cursor`, and every poll answer returns the next `cursor`, so no internal ids reach the browser. Each poll answer also lists photos deleted since the last poll (`deleted`), so a photo removed by the admin or the nightly retention disappears on every open phone.

## Tech stack

| Part     | Technology                                   |
| -------- | -------------------------------------------- |
| Frontend | React 19, TypeScript, Vite                   |
| Backend  | Laravel 13, PHP 8.4, Intervention Image (GD) |
| Database | MariaDB (SQLite for local development)       |
| Queue    | Laravel database queue                       |

## Project structure

```
backend/    Laravel API, image processing, queue jobs, deploy examples
frontend/   React app (static build)
AGENTS.md   Project goals and guidelines
```

## API

| Method | Path                         | Description                                  |
| ------ | ---------------------------- | -------------------------------------------- |
| POST   | `/api/photos`                | Upload a photo (`photo` field). Returns 202. |
| GET    | `/api/photos`                | Processed photos, newest first, paginated.   |
| GET    | `/api/photos/updates`        | New photos since `cursor`, for polling.      |
| GET    | `/api/photos/{uuid}`         | One processed photo.                         |
| GET    | `/up`                        | Health check.                                |

## Upload rules

- Formats: JPEG, PNG, WebP. The server does not read HEIC; the phone converts it to JPEG when its browser can decode HEIC (Safari can), otherwise it is rejected.
- Any photo the phone can decode can be uploaded: it is scaled down to at most 4000 px on the long side before sending (`frontend/src/api/preparePhoto.ts`).
- Server limits, for uploads the phone could not prepare: 50 MB and 60 megapixels.
- Rate limits count per phone, not per IP address, so guests on one Wi-Fi do not share a limit. Each phone gets a random signed device id in an HttpOnly cookie on its first upload. A phone may upload 100 photos per hour. More than 20 in 5 minutes blocks that phone for 15 minutes (1 hour if it happens again within 12 hours). All phones on one IP together are limited to 1000 per hour, and to 200 new device ids per hour, which stops scripts that drop the cookie.

All limits are configurable in `backend/config/photos.php` and through environment variables.

## Local development

Requirements: PHP 8.4 with the `gd` (WebP), `exif`, `mbstring`, `intl`, `zip` and `pdo_sqlite` extensions, Composer, and Node.js 22.

```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan storage:link
php artisan serve                 # http://127.0.0.1:8000
php artisan queue:work            # in a second terminal

# Frontend
cd frontend
npm ci
npm run dev                       # http://localhost:5173, proxies /api to port 8000
```

Run the backend tests with `php artisan test`.

## Configuration

Main environment variables (see `backend/.env.example` and `backend/deploy/.env.production.example`):

| Variable                   | Purpose                                                   |
| -------------------------- | --------------------------------------------------------- |
| `APP_URL`                  | Public URL of the backend. Used to build image URLs.      |
| `FRONTEND_URL`             | Frontend origin allowed by CORS.                          |
| `DB_*`                     | Database connection.                                      |
| `TRUSTED_PROXIES`          | Reverse proxy addresses, so the real client IP is used.   |
| `PHOTOS_MAX_UPLOAD_KB`     | Upload size limit in kilobytes (default 51200).           |
| `PHOTOS_MAX_PIXELS`        | Maximum width x height (default 60,000,000).              |
| `PHOTOS_UPLOAD_RATE_LIMIT` | Uploads per phone per hour (default 100).                 |
| `PHOTOS_UPLOAD_BURST_LIMIT` | Uploads per phone in 5 minutes before a block (default 20). |
| `PHOTOS_UPLOAD_IP_RATE_LIMIT` | Uploads per IP per hour, all phones together (default 1000). |
| `PHOTOS_RETENTION_ENABLED` | Delete old photos every night (default true).             |
| `PHOTOS_RETENTION_DAYS`    | Age in days after which photos are deleted (default 30).  |
| `PHOTOS_DISK_WARNING_PERCENT` | Disk usage that logs a warning (default 90).           |
| `PHOTOS_ADMIN_TOKEN`       | Shared secret for deleting photos. Empty turns it off.    |
| `VITE_API_URL`             | Backend origin, set when building the frontend.           |

## Deployment

Bare-metal deployment (Nginx, PHP-FPM, MariaDB, Supervisor) is described in [`backend/DEPLOY.md`](backend/DEPLOY.md).

A production deployment needs:

- A queue worker running all the time, otherwise photos stay pending.
- The scheduler running every minute (`php artisan schedule:run`).
- Persistent storage for `storage/app/private` (originals) and `storage/app/public` (processed images).
- HTTPS, because phone browsers only allow camera access on secure pages.

## Retention and backups

Photos older than `PHOTOS_RETENTION_DAYS` are deleted every night at 03:30 (database row and files). See what would be deleted with:

```bash
docker compose exec app php artisan app:prune-old-photos --dry-run
```

Back up the Docker setup with `bash scripts/backup.sh` (database dump plus all photos, keeps the newest 14). The cron line and the restore commands are in the script header. `backend/deploy/backup.sh` is the bare-metal version.

## Privacy

Photos are public to anyone with the link. Metadata is removed during processing, but guests should still be told that their photos will be shown publicly.
