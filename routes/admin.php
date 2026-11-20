<?php

use App\Http\Controllers\Admin\ApiClientController;
use App\Http\Controllers\Admin\DeactivateMembershipController;
use App\Http\Controllers\Admin\MembershipController;
use App\Http\Controllers\Admin\ReactivateMembershipController;
use App\Http\Controllers\Admin\RegenerateApiClientSecretController;
use App\Http\Controllers\Admin\ResendInvitationController;
use App\Http\Controllers\Admin\RevokeApiClientController;
use App\Models\ApiClient;
use App\Models\Membership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [MembershipController::class, 'index'])
        ->can('viewAny', Membership::class)
        ->name('users.index');

    Route::post('users', [MembershipController::class, 'store'])
        ->can('create', Membership::class)
        ->middleware('throttle:20,1')
        ->name('users.store');

    // {membership} only ever finds a membership of the open company (Membership::resolveRouteBinding).
    Route::patch('users/{membership}', [MembershipController::class, 'update'])
        ->whereNumber('membership')
        ->can('update', 'membership')
        ->name('users.update');

    Route::post('users/{membership}/deactivate', DeactivateMembershipController::class)
        ->whereNumber('membership')
        ->can('deactivate', 'membership')
        ->name('users.deactivate');

    Route::post('users/{membership}/reactivate', ReactivateMembershipController::class)
        ->whereNumber('membership')
        ->can('reactivate', 'membership')
        ->name('users.reactivate');

    Route::post('users/{membership}/invitation', ResendInvitationController::class)
        ->whereNumber('membership')
        ->can('resendInvitation', 'membership')
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
