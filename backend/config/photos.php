<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | max_upload_kb is in kilobytes (51200 KB = 50 MB). Keep the PHP and Nginx
    | limits above this value, otherwise the request never reaches Laravel.
    |
    */

    'max_upload_kb' => (int) env('PHOTOS_MAX_UPLOAD_KB', 51200),

    // Checked against the file content (finfo), never against the extension.
    // HEIC/HEIF is not decodable by GD, so it is intentionally not listed.
    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],

    // Width * height limit, checked before decoding (decompression bomb guard).
    'max_pixels' => (int) env('PHOTOS_MAX_PIXELS', 60_000_000),

    /*
    |--------------------------------------------------------------------------
    | Upload limits per phone
    |--------------------------------------------------------------------------
    |
    | Guests have no accounts, so each phone gets a random, signed device id
    | in a cookie on its first upload (see LimitPhotoUploads). Limits count
    | per device, so guests sharing one Wi-Fi (or one mobile carrier address)
    | do not use up each other's uploads.
    |
    | per_device_per_hour: normal hourly allowance for one phone.
    | burst / burst_minutes: more than `burst` uploads within `burst_minutes`
    | is not a person tapping the camera, so the phone is blocked for
    | block_minutes, and for repeat_block_minutes if it happens again within
    | strikes_reset_hours.
    |
    | per_ip_per_hour is only a safety net for scripts that drop the cookie;
    | it is high enough for a whole party on one Wi-Fi. A script without a
    | cookie gets a new device id every time, so handing out new ids is
    | limited per IP too.
    |
    */

    'upload_limits' => [
        'per_device_per_hour' => (int) env('PHOTOS_UPLOAD_RATE_LIMIT', 100),
        'burst' => (int) env('PHOTOS_UPLOAD_BURST_LIMIT', 20),
        'burst_minutes' => 5,
        'block_minutes' => 15,
        'repeat_block_minutes' => 60,
        'strikes_reset_hours' => 12,
        'per_ip_per_hour' => (int) env('PHOTOS_UPLOAD_IP_RATE_LIMIT', 1000),
        'new_devices_per_ip_per_hour' => 200,
        'device_cookie_days' => 30,
    ],

    // Original-photo downloads allowed per IP address per minute. A whole
    // party can share one Wi-Fi or mobile carrier address, so this is generous.
    'download_rate_limit' => (int) env('PHOTOS_DOWNLOAD_RATE_LIMIT', 600),

    // Shared secret for admin actions such as deleting a photo. Guests have
    // no accounts, so the admin sends it in the X-Admin-Token header. When
    // it is empty, the admin routes answer 404 to everyone.
    'admin_token' => env('PHOTOS_ADMIN_TOKEN'),

    // Wrong admin tokens allowed per IP address per minute, which slows down guessing.
    'admin_rate_limit' => (int) env('PHOTOS_ADMIN_RATE_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | Derived images
    |--------------------------------------------------------------------------
    */

    'web' => [
        'max_edge' => 1600,
        'quality' => 85,
    ],

    'thumb' => [
        'size' => 400,
        'quality' => 85,
    ],

    /*
    |--------------------------------------------------------------------------
    | Download copy
    |--------------------------------------------------------------------------
    |
    | The file guests get when they press "Preuzmi": a high quality JPEG, at
    | most max_edge pixels on the long side, without metadata (no GPS). It is
    | much smaller than the raw phone photo and looks the same on a screen and
    | in normal prints. JPEG because every app and printer opens it.
    |
    | keep_originals: false deletes the raw upload once the download copy is
    | saved, which saves most of the disk space. true keeps the raw file too
    | (full size, with GPS), and the download then serves the raw file.
    |
    */

    'download' => [
        'max_edge' => (int) env('PHOTOS_DOWNLOAD_MAX_EDGE', 3000),
        'quality' => (int) env('PHOTOS_DOWNLOAD_QUALITY', 90),
        'keep_originals' => filter_var(env('PHOTOS_KEEP_ORIGINALS', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events and rooms
    |--------------------------------------------------------------------------
    |
    | default_days: a new event expires after this many days unless the admin
    | chooses another number (0 = never). At expiry uploads close; the host
    | can still view and download for grace_days, then the nightly
    | app:prune-expired-events deletes the event with all rooms and photos.
    |
    | guest_rooms_per_device_per_day: how many rooms one phone may create, in
    | events that allow guest rooms.
    |
    */

    'events' => [
        'default_days' => (int) env('PHOTOS_EVENT_DAYS', 30),
        'grace_days' => (int) env('PHOTOS_EVENT_GRACE_DAYS', 3),
        'guest_rooms_per_device_per_day' => (int) env('PHOTOS_GUEST_ROOMS_PER_PHONE', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gallery and polling
    |--------------------------------------------------------------------------
    */

    'per_page' => 24,

    'updates_limit' => 100,

    // Each poll also returns photos that finished this many seconds before
    // the previous poll (covers clock rounding; the client skips duplicates).
    'updates_since_overlap_seconds' => 30,

    // Photos stuck in "pending" longer than this are marked as failed.
    'stale_pending_minutes' => 60,

    /*
    |--------------------------------------------------------------------------
    | Retention and disk space
    |--------------------------------------------------------------------------
    |
    | Only for events that never expire (no expiry date, for example a demo):
    | every night, their photos older than `days` are deleted, the database
    | row and both folders. Events with an expiry date are deleted as a whole
    | instead (see photos.events). Set PHOTOS_RETENTION_ENABLED=false to keep
    | everything. Check with: php artisan app:prune-old-photos --dry-run
    |
    | disk_warning_percent: the hourly disk check logs a warning when the
    | storage disk is at least this full.
    |
    */

    'retention' => [
        'enabled' => filter_var(env('PHOTOS_RETENTION_ENABLED', true), FILTER_VALIDATE_BOOL),
        'days' => (int) env('PHOTOS_RETENTION_DAYS', 30),
    ],

    'disk_warning_percent' => (int) env('PHOTOS_DISK_WARNING_PERCENT', 90),

];
