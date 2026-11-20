<?php

use App\Http\Middleware\Api\GuardTokenEndpoint;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

Route::name('api.')->group(function () {
    // The only way in: a system trades its client id and secret for a short-lived access token.
    Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])
        ->middleware(['throttle:30,1', GuardTokenEndpoint::class])
        ->name('oauth.token');
});
