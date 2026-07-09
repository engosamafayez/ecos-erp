<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CR-PREP-001 — Auto-create daily preparation sessions for all active warehouses.
// Runs at 06:00 every day; ensureSessionExists() is idempotent so double-runs are safe.
Schedule::command('preparation:create-daily-sessions')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// CR-PREP-001 Part 3 — Auto-freeze sessions at their configured freeze_time.
// Runs every minute so freeze precision is within 60 s of the configured time.
Schedule::command('preparation:freeze-sessions')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
