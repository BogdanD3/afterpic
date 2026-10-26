# TODO: Improvements

Last updated: 2026-10-26. Ordered by priority. Items marked **(bug)** are problems in the code, not new features.

Effort guide: **S** = under half a day, **M** = a day or two, **L** = several days.

---

## Done

| What | Result |
| ---- | ------ |
| **Public access** | The gallery runs on the server and is reachable from a public address, with HTTPS (needed for the camera) |
| **Download** | Lightbox has **Preuzmi** (normal download, every device) and **Podijeli** (share sheet with "Save Image", phones only). The server sends a high quality JPEG copy (max 3000 px, quality 90, no GPS). Endpoint: `GET /api/photos/{uuid}/download`, 600 per minute per IP |
| **Compression and disk use** | Every photo is stored as `thumb.webp` (400 px), `web.webp` (1600 px) and `download.jpg`. The raw upload is **deleted** after processing (`PHOTOS_KEEP_ORIGINALS=false` by default). Measured on a real phone photo: 882 KB + 171 KB + 32 KB, about 1.1 MB per photo |
| **Large photos (up to 200 MP)** | The phone scales photos above 4000 px on the long side down before upload (quality 92 JPEG, no EXIF), so a 200 MP photo is sent as about 12 MP and a few MB. Normal photos are sent untouched. HEIC is converted to JPEG when the browser can decode it (Safari). The server scales a photo down once before making the copies: a 48 MP photo is processed in 1.2 s with 267 MB peak memory instead of 3.0 s and 458 MB. Tested with a real 200 MP photo |
| **Upload limits per phone** | Limits count per phone (signed `photo_device` cookie), not per IP, so a shared Wi-Fi or mobile carrier address is no longer one shared limit. 100 per hour per phone; more than 20 in 5 minutes blocks the phone for 15 minutes, 1 hour if repeated. Safety net per IP: 1000 uploads and 200 new device ids per hour |
| **Admin delete** | `DELETE /api/photos/{uuid}` with the `X-Admin-Token` header. The admin opens `/?admin=TOKEN` once, then "Obriši" appears in the lightbox. Removes the row and both folders, for any status |
| **Deletes reach every phone** | Every delete (admin or nightly retention) is logged in `photo_deletions`. The polling answer lists them in `deleted`, so open pages remove the photo within one poll, and an open lightbox on it closes. The log keeps 7 days |
| **Delete during processing** | The delete and the processing job lock the photo row. A photo deleted while it is being processed no longer gets its files written back, and a job for an already deleted photo is dropped |
| **Uploads on a bad connection** | An upload with no progress for 30 seconds is cut off instead of blocking the phone's queue forever. Lost connections and busy-server answers (502-504) are retried after 2 and 5 seconds. Every photo has an `upload_id`, so a retry never creates a duplicate. The "took too long" timer starts when the server accepted the photo (5 minutes), not when it was taken |
| **Ready for load** | 12 PHP workers instead of the image default of 5. Index on `(status, updated_at)` so each poll no longer reads every photo. Downloads 600 per minute per IP (was 120, too low for one Wi-Fi). Only wrong admin tokens count against the admin limit, so the admin can delete many photos in a row. The device cookie is marked `Secure` when `APP_URL` is https |
| **Deleted while waiting** | An uploader whose photo the admin deletes sees "Fotografija je uklonjena." instead of "took too long" |
| **Load test script** | `python3 scripts/load-test.py <url> --photo <file>`: many phones upload at once while guests poll, then it reports upload times, queue time and errors. Not run against the server yet, see item 1 |
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
- **Load test** before Friday, see "Load test" below.

#### Load test

1. On the server, against the local port (server only, no internet in between):
   `python3 scripts/load-test.py http://127.0.0.1:9002 --photo phone.jpg --phones 100 --photos-per-phone 3 --viewers 200 --admin-token TOKEN`
2. From your computer or a phone hotspot, against the public URL, with fewer phones (your upload speed is the limit there).
3. Pass: every upload accepted, no poll errors, and all photos processed within a few minutes. Watch `docker stats` during the run for memory.
4. Run it at least an hour before guests arrive: the test uses the per-IP hourly allowance of the IP it runs from.

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

### 5. Rooms: separate galleries at the same time (L), design proposal

Two needs, one feature:
- **Separate groups at the same time**: a party gallery and a travel group's gallery, with nobody seeing the other's photos.
- **Smaller groups inside a big party**: of 300 guests, groups of about 50 have their own room.

**Proposal: everything is a room.** A room is one gallery with its own link and QR code (`/r/{code}`). The gallery that exists today becomes the default room, so the current link keeps working. A party is a room; a group inside the party makes another room. There are no nested rooms: that keeps the code, the UI and the "who sees what" rules simple.

**Access: the link is the key.** Decided 2026-10-26: every room link has a random part, also for public parties, and is only shared by people. The link shows the room's name plus the random part, like `/r/party-k7m2q9` or `/r/travel-x4p8wd`: easy to recognise, impossible to guess, and two groups can both call their room "Party". The room name itself is free text chosen by the creator and shown at the top of the page. Whoever has the link can view and upload, the same model as a Google Photos or WhatsApp invite link. This avoids the weak spot of tying membership to the device id: the device id is not an account (a new browser or cleared cookies lose it), and with link-based access, opening the link again is all it takes. The phone remembers its rooms (in the browser) to offer a room switcher.

**Owner: creates and manages.** Whoever creates a room gets a separate admin link (`/r/{code}?admin={secret}`), which works like today's admin token but for that room only: delete photos, rename the room, and **replace the invite link** (the old one stops working, for when it leaked). The global `PHOTOS_ADMIN_TOKEN` stays the super-admin for all rooms.

**Data model:**
- `rooms`: `code` (unique: name slug + random part), `name` (free text), `admin_secret_hash`, `created_by_device`, `created_at`, later `expires_at` and per-room settings (uploads open/closed, retention days).
- `photos.room_id` (required). A migration creates the default room and puts every existing photo in it.
- `photo_deletions.room_id`, so polling only reports deletions from its own room.

**API:** the gallery, polling and upload endpoints move under the room: `GET /api/rooms/{code}/photos`, `GET /api/rooms/{code}/photos/updates`, `POST /api/rooms/{code}/photos`. The polling cursor carries the room, so a cursor from one room cannot read another. Endpoints by photo uuid (status, download) can stay as they are, because the uuid is already secret. The old `/api/photos` paths answer for the default room, so phones with the old page keep working.

**Photo files:** today `/storage/photos/{uuid}/web.webp` is served by Nginx with no check, and the uuid is unguessable. With rooms, a photo is only listed inside its room, so a stranger never learns its uuid. This is the same "secret link" level as the room link itself and is enough to start. Stronger option for later: Nginx asks Laravel before serving a file (`auth_request`), or links that expire.

**Frontend:**
- The room name is always visible at the top. The biggest risk is a guest uploading a travel photo into the party room by mistake.
- A room switcher lists the rooms this phone has opened.
- "Nova soba" (New room): enter a name, get the invite link and a QR code to show the group, and the admin link to keep.

**Limits:** upload limits stay per phone (across all rooms). Creating rooms is limited per phone, for example 5 per day, so nobody can create thousands of rooms. Polling load does not grow: each guest still polls one room.

**Decisions needed before building:**
- **Who can create rooms:** any guest, or only you (the admin), handing out QR codes? Any guest is more flexible; only the admin is safer for a paid event.
- **One room per photo,** or "also show in the main party room"? One room is simpler, recommended to start.
- **Should rooms expire,** for example deleted 30 days after the event, like retention today?
- **Uploads closed after the event** (view only) or always open?

Effort: about 3-4 days with tests. **Not for this Friday.** If two groups need separate galleries on Friday, run a second copy of the app: a copy of `docker-compose.yml` with other container names, volume names and `APP_PORT`, plus a second Nginx entry. No code changes needed.

### 6. Hide or approve photos (M)

The admin can delete, but not hide a photo or approve photos before they are shown. Useful at events where the host wants control.

### 7. Download everything as a ZIP, and select several (M)

- ZIP of all photos for the host. Stream it (for example with ZipStream), never build it in memory.
- Select several photos in the grid and download them.

### 8. Live wall / slideshow page for a TV (M)

A full-screen page with automatic transitions and the event QR code in a corner. Uses the existing polling endpoint.

### 9. Monitoring and alerts (S to M)

Alert on failed jobs or `failed` photos, an uptime check on `/up`, memory alerts, and Docker log rotation. The disk check already writes a warning to the log every hour; it still needs to reach you (e-mail or a push message).

### 10. Real-time updates instead of polling (M to L)

Polling every 5 seconds works. Laravel Reverb or Server-Sent Events would make photos appear instantly. Optional.

---

## P2: quality and polish

### 11. Automated checks (M)

- Run the tests in CI (GitHub Actions) and build the Docker image there.
- A test with the real queue driver and two workers (the tests use `QUEUE_CONNECTION=sync`).
- Frontend tests; none exist.
- `scripts/test.sh` downloads about 116 packages on every run. Keep a composer cache in a Docker volume.
- `scripts/smoke-test.sh` leaves one test photo in the gallery on every run. Make it delete its photo with the admin delete when `PHOTOS_ADMIN_TOKEN` is given.

### 12. Housekeeping (S)

- `backend/composer.lock` is out of date after changing PHP to `^8.4` (`composer install` warns about it). Run `composer update --lock` in `backend/` and commit it.
- Docker warns that the volumes `PhotoGallery-DB` and `PhotoGallery-Storage` belong to the project `coinis-hub---projekat-1` (the old folder name). Add `name: coinis-hub---projekat-1` at the top of `docker-compose.yml` so the warning goes away and the data stays.
- Replace `backend/README.md` and `frontend/README.md` with short pointers to the root `README.md`.
- Mark `backend/DEPLOY.md` as the bare-metal alternative, or remove it with `backend/deploy/` if it will not be used.

### 13. Security hardening (S to M)

`Content-Security-Policy` and `Strict-Transport-Security` headers, bot protection on uploads now that the gallery is public, a database user with minimal rights, pinned image versions (`mariadb:lts` and `php:8.4-fpm-bookworm` move over time).

### 14. HEIC on Android (S)

Safari converts HEIC on the phone. Chrome on Android usually cannot decode HEIC, so such a photo is still rejected. Rare (Android cameras save JPEG by default). If it matters: Imagick with libheif on the server.

### 15. Frontend polish (S to M)

- Texts are hard-coded in Bosnian.
- Allow picking several photos at once.
- A retry button on failed uploads instead of only "Ukloni".
- A "new photos" indicator.

---

## Suggested order

1. Server safety and load test (1), then phone test (2) and privacy notice (3). After that the app is ready for Friday.
2. Download quality (4) before a bigger party.
3. Decide the open questions for rooms (5), build them, then the live wall (8) and ZIP export (7).
4. Monitoring (9) once real events run.
