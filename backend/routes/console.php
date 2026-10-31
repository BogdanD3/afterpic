<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('app:fail-stale-photos')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();

Schedule::command('app:prune-old-photos')->dailyAt('03:30')->withoutOverlapping();

Schedule::command('app:prune-expired-events')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('app:check-disk-space')->hourly();

// Open pages learn about deleted photos from this log (see PhotoController::updates).
// A page polls every few seconds, so a week of history is far more than it needs.
Schedule::call(fn () => DB::table('photo_deletions')->where('deleted_at', '<', now()->subDays(7))->delete())
    ->daily()
    ->name('prune-photo-deletions');
