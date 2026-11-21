<?php

use App\Models\Setting;
use App\Modules\Ppr\Http\Controllers\Admin\SettingsController;
use App\Modules\Ppr\Http\Controllers\DashboardController;
use App\Modules\Ppr\Http\Controllers\MarkAllNotificationsReadController;
use App\Modules\Ppr\Http\Controllers\NotificationController;
use App\Modules\Ppr\Http\Controllers\Projects\MoveProjectMilestoneController;
use App\Modules\Ppr\Http\Controllers\Projects\PackageController;
use App\Modules\Ppr\Http\Controllers\Projects\PackageRiskFlagController;
use App\Modules\Ppr\Http\Controllers\Projects\PackageScheduleController;
use App\Modules\Ppr\Http\Controllers\Projects\ProjectCategoryController;
use App\Modules\Ppr\Http\Controllers\Projects\ProjectController;
use App\Modules\Ppr\Http\Controllers\Projects\ProjectMemberController;
use App\Modules\Ppr\Http\Controllers\Projects\ProjectMilestoneController;
use App\Modules\Ppr\Http\Controllers\Projects\ProjectNumberingController;
use App\Modules\Ppr\Models\Package;
use App\Modules\Ppr\Models\Project;
use Illuminate\Support\Facades\Route;

// Everything here is inside a company: /{company}/...

Route::middleware('auth')->group(function () {
    Route::get('projects/dashboard', DashboardController::class)->name('projects.dashboard');
});

// Only numbers reach the database, so a mistyped address is a "not found" instead of a database error.
Route::middleware('auth')
    ->prefix('projects')
    ->name('projects.')
    ->where(['project' => '[0-9]+', 'category' => '[0-9]+', 'member' => '[0-9]+', 'milestone' => '[0-9]+', 'package' => '[0-9]+'])
    ->group(function () {
        Route::get('/', [ProjectController::class, 'index'])
            ->can('viewAny', Project::class)
            ->name('index');

        Route::post('/', [ProjectController::class, 'store'])
            ->can('create', Project::class)
            ->name('store');

        Route::get('{project}', [ProjectController::class, 'show'])
            ->can('view', 'project')
            ->name('show');

        Route::get('{project}/edit', [ProjectController::class, 'edit'])
            ->can('update', 'project')
            ->name('edit');

        Route::put('{project}', [ProjectController::class, 'update'])
            ->can('update', 'project')
            ->name('update');

        Route::get('{project}/numbering', [ProjectNumberingController::class, 'edit'])
            ->can('update', 'project')
            ->name('numbering.edit');

        Route::put('{project}/numbering', [ProjectNumberingController::class, 'update'])
            ->can('update', 'project')
            ->name('numbering.update');

        // Scoped, so a category or member of another project can never be reached through this project's address.
        Route::scopeBindings()->group(function () {
            Route::post('{project}/categories', [ProjectCategoryController::class, 'store'])
                ->can('update', 'project')
                ->name('categories.store');

            Route::put('{project}/categories/{category}', [ProjectCategoryController::class, 'update'])
                ->can('update', 'project')
                ->name('categories.update');

            Route::delete('{project}/categories/{category}', [ProjectCategoryController::class, 'destroy'])
                ->can('update', 'project')
                ->name('categories.destroy');

            Route::get('{project}/members', [ProjectMemberController::class, 'index'])
                ->can('view', 'project')
                ->name('members.index');

            Route::post('{project}/members', [ProjectMemberController::class, 'store'])
                ->can('manageMembers', 'project')
                ->name('members.store');

            Route::patch('{project}/members/{member}', [ProjectMemberController::class, 'update'])
                ->can('manageMembers', 'project')
                ->name('members.update');

            Route::delete('{project}/members/{member}', [ProjectMemberController::class, 'destroy'])
                ->can('manageMembers', 'project')
                ->name('members.destroy');

            Route::get('{project}/milestones', [ProjectMilestoneController::class, 'index'])
                ->can('update', 'project')
                ->name('milestones.index');

            Route::post('{project}/milestones', [ProjectMilestoneController::class, 'store'])
                ->can('update', 'project')
                ->name('milestones.store');

            Route::put('{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'update'])
                ->can('update', 'project')
                ->name('milestones.update');

            Route::delete('{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'destroy'])
                ->can('update', 'project')
                ->name('milestones.destroy');

            Route::patch('{project}/milestones/{milestone}/move', MoveProjectMilestoneController::class)
                ->can('update', 'project')
                ->name('milestones.move');

            Route::get('{project}/packages', [PackageController::class, 'index'])
                ->can('view', 'project')
                ->name('packages.index');

            Route::post('{project}/packages', [PackageController::class, 'store'])
                ->can('create', [Package::class, 'project'])
                ->name('packages.store');

            Route::get('{project}/packages/{package}', [PackageController::class, 'show'])
                ->can('view', 'project')
                ->name('packages.show');

            Route::get('{project}/packages/{package}/edit', [PackageController::class, 'edit'])
                ->can('update', 'package')
                ->name('packages.edit');

            Route::put('{project}/packages/{package}', [PackageController::class, 'update'])
                ->can('update', 'package')
                ->name('packages.update');

            Route::get('{project}/packages/{package}/schedule/edit', [PackageScheduleController::class, 'edit'])
                ->can('update', 'package')
                ->name('packages.schedule.edit');

            Route::put('{project}/packages/{package}/schedule', [PackageScheduleController::class, 'update'])
                ->can('update', 'package')
                ->name('packages.schedule.update');

            Route::post('{project}/packages/{package}/risk-flag', [PackageRiskFlagController::class, 'store'])
                ->can('update', 'package')
                ->name('packages.risk-flag.store');

            Route::delete('{project}/packages/{package}/risk-flag', [PackageRiskFlagController::class, 'destroy'])
                ->can('update', 'package')
                ->name('packages.risk-flag.destroy');
        });
    });

// A notification is only ever found among the signed-in person's own (App\Modules\Ppr\Models\Notification::resolveRouteBinding).
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

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('risk-warnings', [SettingsController::class, 'edit'])
        ->can('manage', Setting::class)
        ->name('risk-warnings.edit');
    Route::put('risk-warnings', [SettingsController::class, 'update'])
        ->can('manage', Setting::class)
        ->name('risk-warnings.update');
});
