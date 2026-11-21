<?php

use App\Modules\Sourcing\Http\Controllers\PackageSupplierController;
use Illuminate\Support\Facades\Route;

// Everything here is inside a company: /{company}/...

// Only numbers reach the database, so a mistyped address is a "not found" instead of a database error.
// Scoped, so a package of another project, or a supplier entry of another package, can never be reached through this address.
Route::middleware('auth')
    ->prefix('projects/{project}/packages/{package}/suppliers')
    ->name('projects.packages.suppliers.')
    ->where(['project' => '[0-9]+', 'package' => '[0-9]+', 'packageSupplier' => '[0-9]+'])
    ->scopeBindings()
    ->group(function () {
        Route::post('/', [PackageSupplierController::class, 'store'])
            ->can('update', 'package')
            ->name('store');

        Route::put('{packageSupplier}', [PackageSupplierController::class, 'update'])
            ->can('update', 'package')
            ->name('update');

        Route::delete('{packageSupplier}', [PackageSupplierController::class, 'destroy'])
            ->can('update', 'package')
            ->name('destroy');
    });
