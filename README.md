# Photo Gallery

A public photo gallery for parties and events. Guests open the page on their phone, tap **Slikaj** (Take photo), the phone camera opens, and the photo is uploaded automatically and appears in a shared gallery. There are no accounts and no login.

## How it works

1. The guest taps the camera button. The page uses a file input with `capture="environment"`, so the phone camera opens directly.
2. When the photo is taken, the frontend uploads it right away (with a progress indicator and up to 3 retries on network errors).
3. The backend validates the file, stores the original, and queues a job.
4. A queue worker converts the photo to a 1600px WebP and a 400px thumbnail. EXIF data, including GPS location, is removed.
5. The gallery polls the API every 5 seconds and shows new photos at the top. The first gallery page returns an encrypted `updates_cursor`, and every poll answer returns the next `cursor`, so no internal ids reach the browser.

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

- Formats: JPEG, PNG, WebP. HEIC is not supported, so iPhones must use the "Most Compatible" camera format.
- Maximum size: 50 MB. Maximum resolution: 60 megapixels.
- Rate limit: 30 uploads per hour per IP address.

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
| `PHOTOS_UPLOAD_RATE_LIMIT` | Uploads per IP per hour (default 30).                     |
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
