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

    // Uploads allowed per IP address per hour.
    'upload_rate_limit' => (int) env('PHOTOS_UPLOAD_RATE_LIMIT', 30),

    // Original-photo downloads allowed per IP address per minute. Many guests
    // can share one Wi-Fi address, so this is generous.
    'download_rate_limit' => (int) env('PHOTOS_DOWNLOAD_RATE_LIMIT', 120),

    // Shared secret for admin actions such as deleting a photo. Guests have
    // no accounts, so the admin sends it in the X-Admin-Token header. When
    // it is empty, the admin routes answer 404 to everyone.
    'admin_token' => env('PHOTOS_ADMIN_TOKEN'),

    // Admin requests per IP address per minute, which slows down guessing the token.
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
    | Every night, photos older than `days` are deleted: the database row and
    | both folders (private and public). Set PHOTOS_RETENTION_ENABLED=false to
    | keep everything, for example during a long event. Check what would be
    | deleted with: php artisan app:prune-old-photos --dry-run
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
