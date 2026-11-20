<?php

use App\Http\Controllers\Auth\AcceptInvitationController;
use App\Http\Controllers\OpenCompanyController;
use App\Models\Company;
use Illuminate\Support\Facades\Route;

Route::get('/', OpenCompanyController::class)->middleware('auth')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('invitation/{token}', [AcceptInvitationController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('invitation.show');

    Route::post('invitation', [AcceptInvitationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('invitation.store');
});

require __DIR__.'/settings.php';
// Everything inside a company lives under its address: /{company}/...
Route::prefix('{company}')
    ->where(['company' => Company::SLUG_PATTERN])
    ->middleware('company')
    ->group(__DIR__.'/admin.php');
