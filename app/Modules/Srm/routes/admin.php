<?php

use App\Models\Setting;
use App\Modules\Srm\Http\Controllers\Admin\CategoryController;
use App\Modules\Srm\Http\Controllers\Admin\CertificateTypeController;
use App\Modules\Srm\Http\Controllers\Admin\ContractTypeController;
use App\Modules\Srm\Http\Controllers\Admin\RatingCriterionController;
use App\Modules\Srm\Http\Controllers\Admin\RestoreRatingCriterionController;
use App\Modules\Srm\Http\Controllers\Admin\SettingsController;
use App\Modules\Srm\Http\Controllers\Admin\UpdateRatingWeightsController;
use App\Modules\Srm\Models\Category;
use App\Modules\Srm\Models\CertificateType;
use App\Modules\Srm\Models\ContractType;
use App\Modules\Srm\Models\RatingCriterion;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('categories', [CategoryController::class, 'index'])
        ->can('manage', Category::class)
        ->name('categories.index');

    Route::post('categories', [CategoryController::class, 'store'])
        ->can('manage', Category::class)
        ->name('categories.store');

    Route::put('categories/{category}', [CategoryController::class, 'update'])
        ->can('manage', Category::class)
        ->name('categories.update');

    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
        ->can('manage', Category::class)
        ->name('categories.destroy');

    Route::get('certificate-types', [CertificateTypeController::class, 'index'])
        ->can('manage', CertificateType::class)
        ->name('certificate-types.index');

    Route::post('certificate-types', [CertificateTypeController::class, 'store'])
        ->can('manage', CertificateType::class)
        ->name('certificate-types.store');

    Route::put('certificate-types/{certificateType}', [CertificateTypeController::class, 'update'])
        ->can('manage', CertificateType::class)
        ->name('certificate-types.update');

    Route::delete('certificate-types/{certificateType}', [CertificateTypeController::class, 'destroy'])
        ->can('manage', CertificateType::class)
        ->name('certificate-types.destroy');

    Route::get('settings', [SettingsController::class, 'edit'])
        ->can('manage', Setting::class)
        ->name('settings.edit');

    Route::put('settings', [SettingsController::class, 'update'])
        ->can('manage', Setting::class)
        ->name('settings.update');

    Route::get('contract-types', [ContractTypeController::class, 'index'])
        ->can('manage', ContractType::class)
        ->name('contract-types.index');

    Route::post('contract-types', [ContractTypeController::class, 'store'])
        ->can('manage', ContractType::class)
        ->name('contract-types.store');

    Route::put('contract-types/{contractType}', [ContractTypeController::class, 'update'])
        ->can('manage', ContractType::class)
        ->name('contract-types.update');

    Route::delete('contract-types/{contractType}', [ContractTypeController::class, 'destroy'])
        ->can('manage', ContractType::class)
        ->name('contract-types.destroy');

    Route::get('rating-criteria', [RatingCriterionController::class, 'index'])
        ->can('manage', RatingCriterion::class)
        ->name('rating-criteria.index');

    Route::post('rating-criteria', [RatingCriterionController::class, 'store'])
        ->can('manage', RatingCriterion::class)
        ->name('rating-criteria.store');

    Route::put('rating-criteria/weights', UpdateRatingWeightsController::class)
        ->can('manage', RatingCriterion::class)
        ->name('rating-criteria.weights.update');

    Route::put('rating-criteria/{ratingCriterion}', [RatingCriterionController::class, 'update'])
        ->can('manage', RatingCriterion::class)
        ->name('rating-criteria.update');

    Route::delete('rating-criteria/{ratingCriterion}', [RatingCriterionController::class, 'destroy'])
        ->can('manage', RatingCriterion::class)
        ->name('rating-criteria.destroy');

    Route::post('rating-criteria/{ratingCriterion}/restore', RestoreRatingCriterionController::class)
        ->can('manage', RatingCriterion::class)
        ->name('rating-criteria.restore');
});
