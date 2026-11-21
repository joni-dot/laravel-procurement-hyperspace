<?php

use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\JoinCompanyController;
use App\Http\Controllers\OpenCompanyController;
use App\Http\Controllers\OpenCompanyHomeController;
use App\Models\Company;
use Illuminate\Support\Facades\Route;

Route::get('/', OpenCompanyController::class)->middleware('auth')->name('home');

// The link in an invitation email, for guests and signed-in people alike.
Route::get('invitation/{token}', [AcceptInvitationController::class, 'show'])
    ->middleware('throttle:20,1')
    ->name('invitation.show');

Route::post('invitation', [AcceptInvitationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('invitation.store');

// The signed-in person's own invitations, outside any company.
Route::middleware('auth')->group(function () {
    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');

    Route::post('invitations/{invitation}/accept', JoinCompanyController::class)
        ->whereNumber('invitation')
        ->middleware('throttle:20,1')
        ->name('invitations.accept');

    Route::post('invitations/{invitation}/decline', [InvitationController::class, 'destroy'])
        ->whereNumber('invitation')
        ->middleware('throttle:20,1')
        ->name('invitations.decline');
});

require __DIR__.'/settings.php';
require __DIR__.'/platform.php';
// Everything inside a company lives under its address: /{company}/...
Route::prefix('{company}')
    ->where(['company' => Company::routePattern()])
    ->middleware('company')
    ->group(function () {
        Route::get('/', OpenCompanyHomeController::class)->middleware('auth')->name('company.home');

        require __DIR__.'/admin.php';
        require __DIR__.'/notifications.php';
    });
