<?php

use App\Http\Controllers\Notifications\MarkAllNotificationsReadController;
use App\Http\Controllers\Notifications\NotificationController;
use Illuminate\Support\Facades\Route;

// Inside a company: /{company}/notifications. A notification is only ever found among the signed-in
// person's own visible ones (App\Models\Notification::resolveRouteBinding).
Route::middleware('auth')
    ->prefix('notifications')
    ->name('notifications.')
    ->whereUuid('notification')
    ->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');

        Route::post('read-all', MarkAllNotificationsReadController::class)->name('read-all');

        Route::get('{notification}', [NotificationController::class, 'show'])
            ->can('view', 'notification')
            ->name('show');

        Route::patch('{notification}', [NotificationController::class, 'update'])
            ->can('update', 'notification')
            ->name('update');
    });
