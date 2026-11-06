<?php

use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Schedule;

/*
| Times are in config('outreach.timezone') (Africa/Cairo by default).
| System cron: * * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1
*/

// Nightly discovery — runs in the background so the scheduler isn't blocked by the polite delays.
Schedule::command('outreach:discover')
    ->dailyAt(config('outreach.discover_time', '03:00'))
    ->timezone(config('outreach.timezone'))
    ->withoutOverlapping(180)
    ->runInBackground();

// The day's batch at the "batch_time" setting (editable in the UI; read each minute by schedule:run).
$batchTime = rescue(fn () => app(SettingsRepository::class)->batchTime(), '09:00', report: false);

Schedule::command('outreach:build-batch')
    ->dailyAt($batchTime)
    ->timezone(config('outreach.timezone'))
    ->withoutOverlapping(10);
