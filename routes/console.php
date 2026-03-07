<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Cache & Performance Schedules
|--------------------------------------------------------------------------
|
| These schedules handle cache synchronization and ranking updates.
| Make sure cron is configured: * * * * * php artisan schedule:run
|
*/

// Sync view counts from Redis buffer to DB (every 5 minutes)
Schedule::command('views:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Refresh rankings cache (every 30 minutes)
Schedule::command('rankings:refresh')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Reset daily view counts (every day at midnight)
Schedule::command('views:reset daily')
    ->dailyAt('00:00')
    ->withoutOverlapping();

// Reset weekly view counts (every Monday at midnight)
Schedule::command('views:reset weekly')
    ->weeklyOn(1, '00:00')
    ->withoutOverlapping();

// Reset monthly view counts (1st of every month at midnight)
Schedule::command('views:reset monthly')
    ->monthlyOn(1, '00:00')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Scrape Job Schedules
|--------------------------------------------------------------------------
|
| Two-part system: dispatch + process.
| Works with both web cron (auto) and server cron (php artisan schedule:work).
|
*/

// Part 1: Find due scheduled jobs → dispatch to queue (fast, <1s)
Schedule::command('scrape:run-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Part 2: Process queued jobs (for server cron mode)
// Web cron mode processes queue inline — this covers server cron mode.
// --stop-when-empty: exit when queue is empty (no idle resource waste)
// --max-time=3300: auto-restart before next hour (safety net)
Schedule::command('queue:work --stop-when-empty --max-time=3300')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Cleanup old scrape items daily at 3 AM
Schedule::command('scrape:cleanup')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Recover jobs stuck in scraping/importing due to crashes (every 15 min)
Schedule::command('scrape:recover-stale')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Cleanup old activity logs on 1st of every month at 4 AM
Schedule::command('logs:cleanup --days=90')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| AI Scheduled Tasks
|--------------------------------------------------------------------------
|
| Auto-generate content and SEO for stories using AI.
| Frequency is configurable via System Settings.
| The command handles its own frequency throttling via cache.
|
*/

// AI auto-generate content + SEO (frequency managed inside command)
Schedule::command('ai:run-scheduled')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

