<?php

use App\Modules\Ppr\Http\Controllers\Settings\UpdateNotificationDaysController;
use Illuminate\Support\Facades\Route;

// The person's own settings, the same in every company. Core's Settings → Notifications page shows the form.
Route::middleware('auth')->group(function () {
    Route::put('settings/notifications/days', UpdateNotificationDaysController::class)->name('notification-days.update');
});
