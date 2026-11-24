<?php

use App\Http\Controllers\Platform\CompanyController;
use App\Http\Controllers\Platform\CreateDemoCompanyController;
use App\Http\Controllers\Platform\InviteFirstAdminController;
use App\Http\Controllers\Platform\PromoteMemberController;
use App\Http\Controllers\Platform\ReopenCompanyController;
use App\Http\Controllers\Platform\SearchMembersToPromoteController;
use App\Http\Controllers\Platform\SuspendCompanyController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

// The service's own administration, outside every company. Others get "not found" (EnsurePlatformAdmin).
// These pages create and suspend whole companies, so, like the API clients page, they ask for the
// password again if it wasn't entered recently. The platform check comes first, so the password
// prompt never shows anyone else that the area exists.
Route::middleware(['auth', 'platform', RequirePassword::class])->prefix('platform')->name('platform.')->group(function () {
    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');

    Route::post('companies', [CompanyController::class, 'store'])
        ->middleware('throttle:20,1,platform-companies')
        ->name('companies.store');

    // A company filled with made-up data. Filling it takes about a second, so fewer per minute.
    Route::post('companies/demo', CreateDemoCompanyController::class)
        ->middleware('throttle:10,1,platform-demo')
        ->name('companies.demo.store');

    Route::put('companies/{company}', [CompanyController::class, 'update'])
        ->whereNumber('company')
        ->name('companies.update');

    // Only while the company has no admin; after that its own admins invite people. A company whose
    // admins have all left gets one of its own members as admin instead of an invitation.
    // The members to choose from, searched while the dialog is open: a company's people never travel
    // with the companies list.
    Route::get('companies/{company}/members-to-promote', SearchMembersToPromoteController::class)
        ->whereNumber('company')
        ->middleware('throttle:60,1,platform-member-search')
        ->name('companies.members-to-promote');

    Route::post('companies/{company}/promote-member', PromoteMemberController::class)
        ->whereNumber('company')
        ->middleware('throttle:20,1,platform-promote-member')
        ->name('companies.promote-member');

    Route::post('companies/{company}/first-admin', InviteFirstAdminController::class)
        ->whereNumber('company')
        ->middleware('throttle:20,1,platform-first-admin')
        ->name('companies.first-admin');

    Route::post('companies/{company}/suspend', SuspendCompanyController::class)
        ->whereNumber('company')
        ->name('companies.suspend');

    Route::post('companies/{company}/reopen', ReopenCompanyController::class)
        ->whereNumber('company')
        ->name('companies.reopen');
});
