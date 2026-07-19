<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ARCH-003 — Activate Scheduled orders whose delivery date has arrived.
// Runs at 00:05 daily so orders are in the operational queue for the morning shift.
// withoutOverlapping() prevents concurrent runs on large order volumes.
Schedule::command('orders:activate-scheduled')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground();

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

// TASK-META-HARDENING-001 — Provider health checks at three frequencies.
// Hourly: connection validation (credentials still accepted by provider API).
// Every 6 hours: permission depth check.
// Daily: full check including app availability.
Schedule::command('marketing:provider:health-check')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('marketing:provider:health-check --level=permissions')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('marketing:provider:health-check --level=full')
    ->daily()
    ->withoutOverlapping()
    ->runInBackground();

// TASK-WAVE-ENGINE-001 — Wave Engine lifecycle scheduler.
// Runs every minute; processes all active WaveEngineConfiguration records and
// triggers: collection-window open → order sync → preparation start → wave rotation.
// withoutOverlapping() ensures a slow warehouse does not spawn duplicate runs.
Schedule::command('wave:run-scheduler')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
