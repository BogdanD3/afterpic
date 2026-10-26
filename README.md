# Photo Gallery

A photo gallery for parties and events, sold per event. Guests scan the QR code on their table, tap **Slikaj** (Take photo), the phone camera opens, and the photo is uploaded automatically and appears in the event's gallery. There are no accounts and no login.

## Events, rooms and admins

- **Event**: one booked party. Only the super admin creates events, on `/admin`. An event expires after `PHOTOS_EVENT_DAYS` (default 30, or a number chosen per event, or never). At expiry uploads close; the gallery stays viewable and downloadable for `PHOTOS_EVENT_GRACE_DAYS` (default 3), then the whole event is deleted at night.
- **Room**: one gallery with its own link, `/r/{name}-{random}`. The random part makes the link the key: whoever has it can view and upload, nobody can guess it. Every event has a **main room**, the one its table QR codes open. When the event allows it (a switch per event), guests can create **guest rooms** for smaller groups; each phone can create `PHOTOS_GUEST_ROOMS_PER_PHONE` per day (default 5).
- **Admins**, all by secret key, no accounts:

| Who | Key | Can |
| --- | --- | --- |
| Super admin (you) | `PHOTOS_ADMIN_TOKEN`, on `/admin` or once with `?admin=TOKEN` | Everything: create, change and delete events, delete rooms, give a room a new link, delete any photo |
| Host of an event | Host link (`/r/{main room}?key=...`), shown on `/admin` | Delete photos in every room of that event |
| Creator of a guest room | Admin link, shown once after creating the room (and on `/admin`) | Delete photos in that room |

**Who uploaded what.** Every photo remembers the phone that sent it (the device id in the phone's signed cookie, see "Upload rules"). After the first photo a guest is asked once for a name (optional, can be skipped or changed later from "Sobe"); it shows under all their photos, also earlier ones. On `/admin` each event lists its phones with their photo counts: delete everything from one phone, or block it (a blocked phone cannot upload or create rooms). In a room opened as super admin, the full-screen photo shows which phone sent it, with one button to delete all its photos in the event and block it.

**Admin page (`/admin`).** Three tabs. *Događaji*: create an event, search and filter (active, expired, all), and per event the guest link and QR code, the host link, printable table cards (four per A4 page), switches for uploads and guest rooms, a new expiry, the rooms (open, admin link, new link, delete), the phones, and deleting the event. *Zahtjevi*: the inbox. *Pregled*: events, photos, phones, open requests and free disk space.

**Requests.** A guest opens a photo and taps "Ukloni?" to ask for it to be removed, with a reason. A host or guest room admin asks from "Upravljanje" for their room to be deleted (from the main room: the whole event). Requests land in *Zahtjevi*; approving does the deletion, rejecting keeps everything. Each phone can send 10 requests per hour.

**Preuzmi sve.** In "Upravljanje", the host, a guest room's admin and the super admin download every photo of the room as one ZIP (the JPEG download copies), through a signed link that works for 10 minutes. The ZIP is built on disk first, so it needs about as much free space as the photos.

Wrong keys count against `PHOTOS_ADMIN_RATE_LIMIT` per IP per minute. Deleting a room or an event hides it at once and removes the photos in the background. `/` is a static page that explains the product and calls no API.

## How it works

1. The guest taps the camera button. The page uses a file input with `capture="environment"`, so the phone camera opens directly.
2. When the photo is taken, the phone prepares it: photos larger than 4000 px on the long side (for example 200 MP) are scaled down to 4000 px and saved as a quality 92 JPEG, which looks the same and is a few MB instead of 30-60 MB. Normal photos are sent untouched. The frontend then uploads it right away, with a progress indicator. An upload that makes no progress for 30 seconds is cut off, and a lost connection or a busy server (502-504) is retried twice, after 2 and 5 seconds. Every photo carries an `upload_id` chosen by the phone, so a retry whose first attempt did arrive gets the same photo back instead of a duplicate.
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

| Method | Path | Description |
| ------ | ---- | ----------- |
| GET    | `/api/rooms/{code}` | The room and what its event allows (uploads open, guest rooms, expiry). |
| GET    | `/api/rooms/{code}/access` | The role the sent key gives (`super`, `host`, `room` or null). |
| POST   | `/api/rooms/{code}/rooms` | A guest creates a room in the event (`name`). Returns the room and its admin key. |
| POST   | `/api/rooms/{code}/photos` | Upload a photo (`photo`, `upload_id`). Returns 202, or 403 when uploads are closed. |
| GET    | `/api/rooms/{code}/photos` | The room's processed photos, newest first, paginated. |
| GET    | `/api/rooms/{code}/photos/updates` | New and deleted photos since `cursor`, for polling. |
| GET    | `/api/photos/{uuid}` | One processed photo. |
| GET    | `/api/photos/{uuid}/status` | Processing status, for the uploader. |
| GET    | `/api/photos/{uuid}/download` | The high quality copy. |
| DELETE | `/api/photos/{uuid}` | Delete a photo (super admin, host or room admin key). |
| POST   | `/api/photos/{uuid}/removal-request` | A guest asks for the photo to be removed (`reason`). |
| POST   | `/api/rooms/{code}/deletion-request` | Host or room admin asks for the room (or event) to be deleted (`reason`). |
| POST   | `/api/rooms/{code}/download-link` | Host, room admin or super admin: a 10-minute signed link to the ZIP. |
| GET    | `/api/rooms/{code}/download-all` | The ZIP of every photo in the room (signed link only). |
| GET/PUT | `/api/me`, `/api/me/name` | The phone's optional name under its photos. |
| GET    | `/api/admin/stats` | Super admin: overview numbers and disk space. |
| GET/POST | `/api/admin/requests`, `.../{id}/approve`, `.../{id}/reject` | Super admin: the request inbox. |
| GET/POST/DELETE | `/api/admin/devices...` | Super admin: phones per event, delete all their photos, block. |
| GET/POST/PATCH/DELETE | `/api/admin/events[/{id}]` | Super admin: list, create, change, delete events. |
| DELETE | `/api/admin/rooms/{code}` | Super admin: delete a guest room. |
| POST   | `/api/admin/rooms/{code}/rotate-code` | Super admin: a new link for a room; the old one stops working. |
| GET    | `/up` | Health check. |

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
| `PHOTOS_EVENT_DAYS`        | Days until a new event expires, unless chosen per event (default 30). |
| `PHOTOS_EVENT_GRACE_DAYS`  | Days after expiry the gallery stays viewable before it is deleted (default 3). |
| `PHOTOS_GUEST_ROOMS_PER_PHONE` | Rooms one phone may create per day, where allowed (default 5). |
| `PHOTOS_RETENTION_ENABLED` | Delete old photos every night, in events that never expire (default true). |
| `PHOTOS_RETENTION_DAYS`    | Age in days after which those photos are deleted (default 30). |
| `PHOTOS_DISK_WARNING_PERCENT` | Disk usage that logs a warning (default 90).           |
| `PHOTOS_ADMIN_TOKEN`       | Super admin secret (`/admin`). Empty turns the admin page off. |
| `VITE_API_URL`             | Backend origin, set when building the frontend.           |

## Deployment

Bare-metal deployment (Nginx, PHP-FPM, MariaDB, Supervisor) is described in [`backend/DEPLOY.md`](backend/DEPLOY.md).

A production deployment needs:

- A queue worker running all the time, otherwise photos stay pending.
- The scheduler running every minute (`php artisan schedule:run`).
- Persistent storage for `storage/app/private` (originals) and `storage/app/public` (processed images).
- HTTPS, because phone browsers only allow camera access on secure pages.

### Load test

`python3 scripts/load-test.py <url> --photo <phone photo>` makes many fake phones upload at the same moment (default 50 phones x 3 photos) while 100 fake guests keep the gallery open, then waits until every photo is processed and prints upload times, processing time and errors. `--admin-token` deletes the test photos afterwards. Run it on the server against the local port to measure the server alone, and from outside to measure the whole path. `--help` lists the options and limits.

## Retention and backups

Every night at 03:00, events past their expiry plus the grace days are deleted with all rooms and photos. At 03:30, in events that never expire, photos older than `PHOTOS_RETENTION_DAYS` are deleted. See what would be deleted with:

```bash
docker compose exec app php artisan app:prune-expired-events --dry-run
docker compose exec app php artisan app:prune-old-photos --dry-run
```

Back up the Docker setup with `bash scripts/backup.sh` (database dump plus all photos, keeps the newest 14). The cron line and the restore commands are in the script header. `backend/deploy/backup.sh` is the bare-metal version.

## Privacy

Photos are visible to everyone who has the room link; the gallery says so under the photos. Metadata (EXIF, GPS) is removed during processing.

About guests, the server keeps only: a random device id (in an HttpOnly cookie, 30 days), the name if the guest typed one, a short description such as "iPhone · Safari" (not the full user agent), and when the phone was last seen. No account, no e-mail, no phone number.
