<?php

use App\Jobs\PingHeartbeat;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->dailyAt('03:30')->withoutOverlapping();

// Collectors report the previous UTC day just after midnight; anything not in by the cutoff is an alert.
Schedule::command('logwatch:check-missing')->dailyAt(config('logwatch.reports.cutoff', '06:00'))->withoutOverlapping();

// Through the queue, so the external check-in only hears from us while the scheduler and the worker both run.
Schedule::job(new PingHeartbeat)->everyFiveMinutes()->when(fn (): bool => filled(config('logwatch.heartbeat.url')));

Schedule::command('logwatch:digest --email')->weeklyOn(1, '07:00')->when(fn (): bool => (bool) config('logwatch.digest.email'));
