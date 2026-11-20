<?php

use App\Enums\ApiScope;
use App\Http\Middleware\Api\AuthenticateApiClient;
use App\Http\Middleware\Api\LogApiRequest;
use App\Http\Middleware\Api\RequireApiScope;
use App\Modules\Srm\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

Route::name('api.')->group(function () {
    Route::prefix('v1')
        ->name('v1.')
        ->middleware([LogApiRequest::class, AuthenticateApiClient::class, 'throttle:api', 'cache.headers:no_store;private'])
        ->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])
                ->middleware(RequireApiScope::for(ApiScope::SuppliersRead))
                ->name('suppliers.index');

            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
                ->whereNumber('supplier')
                ->middleware(RequireApiScope::for(ApiScope::SuppliersRead))
                ->name('suppliers.show');
        });
});
