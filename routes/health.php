<?php

use App\Http\Controllers\ShowHealthController;
use App\Http\Middleware\LimitHealthChecks;
use Illuminate\Support\Facades\Route;

// Outside the web middleware: no session, no cookies, no CSRF token. See docs/monitoring.md.
Route::get('health', ShowHealthController::class)
    ->middleware(LimitHealthChecks::class)
    ->name('health');
