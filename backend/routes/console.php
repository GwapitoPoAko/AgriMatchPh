<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled jobs (run `php artisan schedule:work` locally, or a cron entry
| `* * * * * php artisan schedule:run` in production).
*/

// Rebuild today's hub price index after the DA evening feed lands (17:30 PHT).
Schedule::command('agrimatch:price-index')->dailyAt('18:15')->withoutOverlapping();

// Re-price open truck pools every morning in case a weekly DOE adjustment was logged.
Schedule::command('agrimatch:freight-reprice')->dailyAt('06:30')->withoutOverlapping();

// Expire stale offers.
Schedule::command('agrimatch:expire-offers')->hourly();

// Weekly integrity check of the audit hash chain.
Schedule::command('agrimatch:audit-verify')->weeklyOn(1, '02:00');
