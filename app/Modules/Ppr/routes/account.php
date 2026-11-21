<?php

use App\Modules\Ppr\Http\Controllers\Settings\NotificationSettingsController;
use Illuminate\Support\Facades\Route;

// The person's own settings, the same in every company.
Route::middleware('auth')->group(function () {
    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('notification-settings.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('notification-settings.update');
});
