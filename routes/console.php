<?php

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
