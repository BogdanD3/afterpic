<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:fail-stale-photos')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();

Schedule::command('app:prune-old-photos')->dailyAt('03:30')->withoutOverlapping();

Schedule::command('app:check-disk-space')->hourly();
