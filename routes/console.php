<?php

use Illuminate\Support\Facades\Schedule;

// Expired and revoked API tokens are useless; removing them keeps the token table small.
Schedule::command('passport:purge')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->onOneServer();
