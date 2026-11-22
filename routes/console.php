<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Monitoring\Heartbeats;
use Illuminate\Support\Facades\Schedule;

// Expired and revoked API tokens are useless; removing them keeps the token table small.
Schedule::command('passport:purge')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer();

// After the morning's reminders (projects at 04:00, contracts and certificates at 06:00), so each summary includes them.
Schedule::command('notifications:send-summaries')
    ->dailyAt('06:30')
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();

// Proves to the health check (/health) that the scheduler runs and, through a tiny queued job, that
// a queue worker does. Outside every company; recording twice (two schedulers) does no harm.
Schedule::call(function (Heartbeats $heartbeats): void {
    $heartbeats->recordScheduler();
    RecordQueueHeartbeat::dispatch();
})->everyMinute()->name('health-heartbeats');
