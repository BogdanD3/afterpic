# TODO: Improvements

Last updated: 2026-10-18. Ordered by priority. Items marked **(bug)** are problems in the code, not new features.

Effort guide: **S** = under half a day, **M** = a day or two, **L** = several days.

---

## Done

| What | Result |
| ---- | ------ |
| **Public access** | The gallery runs on the server and is reachable from a public address, with HTTPS (needed for the camera) |
| **Download** | Lightbox has **Preuzmi** (normal download, every device) and **Podijeli** (share sheet with "Save Image", phones only). The server sends a high quality JPEG copy (max 3000 px, quality 90, no GPS). Endpoint: `GET /api/photos/{uuid}/download`, 120 per minute per IP |
| **Compression and disk use** | Every photo is stored as `thumb.webp` (400 px), `web.webp` (1600 px) and `download.jpg`. The raw upload is **deleted** after processing (`PHOTOS_KEEP_ORIGINALS=false` by default). Measured on a real phone photo: 882 KB + 171 KB + 32 KB, about 1.1 MB per photo |
| **Large photos (up to 200 MP)** | The phone scales photos above 4000 px on the long side down before upload (quality 92 JPEG, no EXIF), so a 200 MP photo is sent as about 12 MP and a few MB. Normal photos are sent untouched. HEIC is converted to JPEG when the browser can decode it (Safari). The server scales a photo down once before making the copies: a 48 MP photo is processed in 1.2 s with 267 MB peak memory instead of 3.0 s and 458 MB. Tested with a real 200 MP photo |
| **Upload limits per phone** | Limits count per phone (signed `photo_device` cookie), not per IP, so a shared Wi-Fi or mobile carrier address is no longer one shared limit. 100 per hour per phone; more than 20 in 5 minutes blocks the phone for 15 minutes, 1 hour if repeated. Safety net per IP: 1000 uploads and 200 new device ids per hour |
| **Admin delete** | `DELETE /api/photos/{uuid}` with the `X-Admin-Token` header. The admin opens `/?admin=TOKEN` once, then "Obriši" appears in the lightbox. Removes the row and both folders, for any status |
| **Deletes reach every phone** | Every delete (admin or nightly retention) is logged in `photo_deletions`. The polling answer lists them in `deleted`, so open pages remove the photo within one poll, and an open lightbox on it closes. The log keeps 7 days |
| **Delete during processing** | The delete and the processing job lock the photo row. A photo deleted while it is being processed no longer gets its files written back, and a job for an already deleted photo is dropped |
| **GPS privacy** | None of the three stored copies contain GPS or camera data |
| **Live gallery bug** | Polling also asks for everything that finished since the last poll, so a photo that finishes late is no longer missed |
| **Disk write failures** | Storage disks raise errors. A failed save answers 503 and creates no photo. A failed processing marks the photo `failed` |
| **Upload error messages** | Clear Bosnian messages: too big, too many pixels, HEIC, not an image, server problem, rate limit (with the minutes to wait). The failed tile shows the faded photo |
| **Fast failure report** | `GET /api/photos/{uuid}/status`: the uploader sees "processing failed" within seconds, not after 3 minutes |
| **One-command tests** | `bash scripts/test.sh <url>`: backend tests (run inside the app's Docker image), frontend lint and build, and a 23-check live test. All passed against the real server on 2026-10-18 |
| **Retention** | `app:prune-old-photos` runs every night at 03:30 and deletes photos older than `PHOTOS_RETENTION_DAYS` (default 30). `--dry-run` only lists them. `PHOTOS_RETENTION_ENABLED=false` turns it off |
| **Disk space warning** | `app:check-disk-space` runs every hour and logs a warning when the storage disk is at least `PHOTOS_DISK_WARNING_PERCENT` full (default 90) |
| **Docker backups** | `bash scripts/backup.sh [folder]`: database dump and all photos in a dated folder, keeps the newest 14. Restore commands are in the script header. Not yet run on the server, see item 1 |
| **API consistency** | No internal id reaches the browser: the gallery pages by `created_at` + `uuid`, and polling uses an encrypted `cursor`. `links.next` is a path without host |

---

## P0: before a real event

### 1. Server safety: retention, backup, restore (S)

The app runs on the server and the live test passes. What has not been done there yet:
- **Retention**: run `docker compose exec app php artisan app:prune-old-photos --dry-run`. If it lists photos that must stay, set `PHOTOS_RETENTION_ENABLED=false` in `.env`.
- **Backup**: run `bash scripts/backup.sh` once by hand, then add the cron line from its header.
- **Restore**: test it once, ideally on a copy (another folder with its own `docker compose -p` project name). A backup that was never restored is not tested.
- Check that `docker compose logs app` shows the hourly disk check without errors.

### 2. Test on real phones (S), in progress

Still to check:
- Does `capture="environment"` open the camera directly on iPhone and Android?
- Do iPhone photos upload (Safari converts HEIC to JPEG on the phone)?
- **Download and Podijeli** on both platforms: where does the file end up, does the quality look right?
- Two phones uploading at the same moment: do both photos appear on both phones?
- Admin delete: does the photo disappear on a second phone within a few seconds?
- Upload speed on mobile data, and opening the page from a phone that is not on Tailscale.

### 3. Privacy notice (S)

One short line for guests: photos are visible to everyone with the link, and who to contact to have one removed (the admin can delete it).

### 4. Check the quality of the download copy (S)

Compare a real phone photo with its download copy zoomed in. Decide the final `PHOTOS_DOWNLOAD_MAX_EDGE` and `PHOTOS_DOWNLOAD_QUALITY`. A typical 12 MP photo (4032x3024) becomes 3000x2250, about 55% of the pixels. `PHOTOS_DOWNLOAD_MAX_EDGE=4000` keeps the full 12 MP (the phone already sends up to 4000 px).

---

## P1: important for running this as a business

### 5. Events (separate galleries) (L)

Today there is one global gallery. For several clients you need an `events` table, a QR code and link per event (`/e/{slug}`), and per-event settings (upload window, rate limit, moderation, retention, admin access). This changes the schema and every endpoint, so decide it **before** adding more features on top of the single-gallery model. Design it together with rooms (item 6).

### 6. Rooms: private galleries inside a party (L), idea

Every phone already has its own id (the signed `photo_device` cookie from the upload limits). That makes it possible to put some guests in a **room**: for example, of 200 guests at a party, only 30 are in a room, and only they see the photos taken in it.

A possible shape:
- The room owner creates a room and gets an invite link or QR code with a secret code.
- A guest who opens the link joins: the server stores that their device id is a member of the room.
- Photos uploaded in a room are only listed, polled and downloadable for members. The main gallery stays for everyone.

Open questions to decide first:
- **The device id is not an account.** Clearing cookies, a private tab or another browser loses the membership. Is rejoining through the same link enough, or is a short name or PIN needed?
- Can a link be shared further (anyone with the link joins), or does the owner approve members?
- Does a photo go to one room only, or can it be in the main gallery too?
- How rooms relate to events (item 5): a room probably belongs to one event.
- Today the cookie is issued on the first upload. Rooms need it earlier, when the page is opened.
- Photo URLs (`/storage/photos/...`) are public files today. Room photos need a check before they are served, or unguessable paths at least.

### 7. Hide or approve photos (M)

The admin can delete, but not hide a photo or approve photos before they are shown. Useful at events where the host wants control.

### 8. Download everything as a ZIP, and select several (M)

- ZIP of all photos for the host. Stream it (for example with ZipStream), never build it in memory.
- Select several photos in the grid and download them.

### 9. Live wall / slideshow page for a TV (M)

A full-screen page with automatic transitions and the event QR code in a corner. Uses the existing polling endpoint.

### 10. Monitoring and alerts (S to M)

Alert on failed jobs or `failed` photos, an uptime check on `/up`, memory alerts, and Docker log rotation. The disk check already writes a warning to the log every hour; it still needs to reach you (e-mail or a push message).

### 11. Real-time updates instead of polling (M to L)

Polling every 5 seconds works. Laravel Reverb or Server-Sent Events would make photos appear instantly. Optional.

---

## P2: quality and polish

### 12. Load test (S)

A script that uploads about 100 photos at once from many fake phones, while watching server memory and how long the queue takes to clear. Do it before a party with more than about 100 guests.

### 13. Automated checks (M)

- Run the tests in CI (GitHub Actions) and build the Docker image there.
- A test with the real queue driver and two workers (the tests use `QUEUE_CONNECTION=sync`).
- Frontend tests; none exist.
- `scripts/test.sh` downloads about 116 packages on every run. Keep a composer cache in a Docker volume.
- `scripts/smoke-test.sh` leaves one test photo in the gallery on every run. Make it delete its photo with the admin delete when `PHOTOS_ADMIN_TOKEN` is given.

### 14. Housekeeping (S)

- `backend/composer.lock` is out of date after changing PHP to `^8.4` (`composer install` warns about it). Run `composer update --lock` in `backend/` and commit it.
- Docker warns that the volumes `PhotoGallery-DB` and `PhotoGallery-Storage` belong to the project `coinis-hub---projekat-1` (the old folder name). Add `name: coinis-hub---projekat-1` at the top of `docker-compose.yml` so the warning goes away and the data stays.
- Replace `backend/README.md` and `frontend/README.md` with short pointers to the root `README.md`.
- Mark `backend/DEPLOY.md` as the bare-metal alternative, or remove it with `backend/deploy/` if it will not be used.

### 15. Security hardening (S to M)

`Content-Security-Policy` and `Strict-Transport-Security` headers, bot protection on uploads now that the gallery is public, a database user with minimal rights, pinned image versions (`mariadb:lts` and `php:8.4-fpm-bookworm` move over time).

### 16. HEIC on Android (S)

Safari converts HEIC on the phone. Chrome on Android usually cannot decode HEIC, so such a photo is still rejected. Rare (Android cameras save JPEG by default). If it matters: Imagick with libheif on the server.

### 17. Frontend polish (S to M)

- Texts are hard-coded in Bosnian.
- Allow picking several photos at once.
- A retry button on failed uploads instead of only "Ukloni".
- A "new photos" indicator.
- **(bug)** If the admin deletes a photo while its uploader is still waiting for it, the uploader's tile shows "Obrada je trajala predugo" after 3 minutes instead of a clear "removed" message (the status endpoint answers 404).

---

## Suggested order

1. Server safety (1), then phone test (2) and privacy notice (3). After that the app is ready for a birthday-size party.
2. Download quality (4) and a load test (12) before a bigger party.
3. Decide events (5) and rooms (6) together, before the live wall (9) and ZIP export (8).
4. Monitoring (10) once real events run.
