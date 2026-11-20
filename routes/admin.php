<?php

use App\Http\Controllers\Admin\ApiClientController;
use App\Http\Controllers\Admin\DeactivateUserController;
use App\Http\Controllers\Admin\ReactivateUserController;
use App\Http\Controllers\Admin\RegenerateApiClientSecretController;
use App\Http\Controllers\Admin\ResendInvitationController;
use App\Http\Controllers\Admin\RevokeApiClientController;
use App\Http\Controllers\Admin\UserController;
use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [UserController::class, 'index'])
        ->can('viewAny', User::class)
        ->name('users.index');

    Route::post('users', [UserController::class, 'store'])
        ->can('create', User::class)
        ->name('users.store');

    Route::patch('users/{user}', [UserController::class, 'update'])
        ->can('update', 'user')
        ->name('users.update');

    Route::post('users/{user}/deactivate', DeactivateUserController::class)
        ->can('deactivate', 'user')
        ->name('users.deactivate');

    Route::post('users/{user}/reactivate', ReactivateUserController::class)
        ->can('reactivate', 'user')
        ->name('users.reactivate');

    Route::post('users/{user}/invitation', ResendInvitationController::class)
        ->can('resendInvitation', 'user')
        ->middleware('throttle:6,1')
        ->name('users.invitation.resend');

    // Clients give other systems access to supplier data, so the admin must have entered their password recently.
    Route::get('api-clients', [ApiClientController::class, 'index'])
        ->can('manage', ApiClient::class)
        ->middleware(RequirePassword::class)
        ->name('api-clients.index');

    Route::post('api-clients', [ApiClientController::class, 'store'])
        ->can('manage', ApiClient::class)
        ->middleware(RequirePassword::class)
        ->name('api-clients.store');

    Route::put('api-clients/{apiClient}', [ApiClientController::class, 'update'])
        ->whereUuid('apiClient')
        ->can('update', 'apiClient')
        ->middleware(RequirePassword::class)
        ->name('api-clients.update');

    Route::post('api-clients/{apiClient}/secret', RegenerateApiClientSecretController::class)
        ->whereUuid('apiClient')
        ->can('update', 'apiClient')
        ->middleware(RequirePassword::class)
        ->name('api-clients.secret.regenerate');

    Route::post('api-clients/{apiClient}/revoke', RevokeApiClientController::class)
        ->whereUuid('apiClient')
        ->can('update', 'apiClient')
        ->middleware(RequirePassword::class)
        ->name('api-clients.revoke');
});
