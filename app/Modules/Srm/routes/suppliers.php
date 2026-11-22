<?php

use App\Modules\Srm\Http\Controllers\Suppliers\ArchiveSupplierController;
use App\Modules\Srm\Http\Controllers\Suppliers\CertificateController;
use App\Modules\Srm\Http\Controllers\Suppliers\CommentController;
use App\Modules\Srm\Http\Controllers\Suppliers\ContactController;
use App\Modules\Srm\Http\Controllers\Suppliers\ContractController;
use App\Modules\Srm\Http\Controllers\Suppliers\DocumentController;
use App\Modules\Srm\Http\Controllers\Suppliers\DownloadSupplierImportTemplateController;
use App\Modules\Srm\Http\Controllers\Suppliers\PreviewSupplierImportController;
use App\Modules\Srm\Http\Controllers\Suppliers\RatingController;
use App\Modules\Srm\Http\Controllers\Suppliers\RestoreSupplierController;
use App\Modules\Srm\Http\Controllers\Suppliers\RiskOverrideController;
use App\Modules\Srm\Http\Controllers\Suppliers\SpendController;
use App\Modules\Srm\Http\Controllers\Suppliers\SupplierController;
use App\Modules\Srm\Http\Controllers\Suppliers\SupplierImportController;
use App\Modules\Srm\Http\Controllers\Suppliers\UploadContractDocumentController;
use App\Modules\Srm\Models\Comment;
use App\Modules\Srm\Models\Contract;
use App\Modules\Srm\Models\Supplier;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('suppliers', [SupplierController::class, 'index'])
        ->can('viewAny', Supplier::class)
        ->name('suppliers.index');

    Route::get('suppliers/create', [SupplierController::class, 'create'])
        ->can('create', Supplier::class)
        ->name('suppliers.create');

    Route::get('suppliers/import', [SupplierImportController::class, 'create'])
        ->can('create', Supplier::class)
        ->name('suppliers.import.create');

    Route::post('suppliers/import/preview', PreviewSupplierImportController::class)
        ->can('create', Supplier::class)
        ->middleware('throttle:supplier-import')
        ->name('suppliers.import.preview');

    Route::post('suppliers/import', [SupplierImportController::class, 'store'])
        ->can('create', Supplier::class)
        ->middleware('throttle:supplier-import')
        ->name('suppliers.import.store');

    // ?format=xlsx for Excel, CSV otherwise.
    Route::get('suppliers/import/template', DownloadSupplierImportTemplateController::class)
        ->can('create', Supplier::class)
        ->name('suppliers.import.template');

    Route::post('suppliers', [SupplierController::class, 'store'])
        ->can('create', Supplier::class)
        ->name('suppliers.store');

    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])
        ->can('view', 'supplier')
        ->name('suppliers.show');

    Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])
        ->can('update', 'supplier')
        ->name('suppliers.edit');

    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])
        ->can('update', 'supplier')
        ->name('suppliers.update');

    Route::post('suppliers/{supplier}/archive', ArchiveSupplierController::class)
        ->can('archive', 'supplier')
        ->name('suppliers.archive');

    Route::post('suppliers/{supplier}/restore', RestoreSupplierController::class)
        ->can('restore', 'supplier')
        ->name('suppliers.restore');

    // scopeBindings() makes sure the contact really belongs to the supplier in the URL.
    Route::scopeBindings()->group(function () {
        Route::post('suppliers/{supplier}/contacts', [ContactController::class, 'store'])
            ->can('update', 'supplier')
            ->name('suppliers.contacts.store');

        Route::put('suppliers/{supplier}/contacts/{contact}', [ContactController::class, 'update'])
            ->can('update', 'supplier')
            ->name('suppliers.contacts.update');

        Route::delete('suppliers/{supplier}/contacts/{contact}', [ContactController::class, 'destroy'])
            ->can('update', 'supplier')
            ->name('suppliers.contacts.destroy');

        Route::post('suppliers/{supplier}/certificates', [CertificateController::class, 'store'])
            ->can('update', 'supplier')
            ->name('suppliers.certificates.store');

        Route::put('suppliers/{supplier}/certificates/{certificate}', [CertificateController::class, 'update'])
            ->can('update', 'supplier')
            ->name('suppliers.certificates.update');

        Route::delete('suppliers/{supplier}/certificates/{certificate}', [CertificateController::class, 'destroy'])
            ->can('update', 'supplier')
            ->name('suppliers.certificates.destroy');

        Route::get('suppliers/{supplier}/certificates/{certificate}/file', [CertificateController::class, 'show'])
            ->can('view', 'supplier')
            ->name('suppliers.certificates.download');

        // "update" on the supplier already means buyer or admin, and blocks archived suppliers.
        Route::get('suppliers/{supplier}/contracts/create', [ContractController::class, 'create'])
            ->can('update', 'supplier')
            ->name('suppliers.contracts.create');

        Route::post('suppliers/{supplier}/contracts', [ContractController::class, 'store'])
            ->can('update', 'supplier')
            ->name('suppliers.contracts.store');

        Route::get('suppliers/{supplier}/contracts/{contract}', [ContractController::class, 'show'])
            ->can('viewDetails', Contract::class)
            ->name('suppliers.contracts.show');

        Route::get('suppliers/{supplier}/contracts/{contract}/edit', [ContractController::class, 'edit'])
            ->can('update', 'supplier')
            ->name('suppliers.contracts.edit');

        Route::put('suppliers/{supplier}/contracts/{contract}', [ContractController::class, 'update'])
            ->can('update', 'supplier')
            ->name('suppliers.contracts.update');

        Route::delete('suppliers/{supplier}/contracts/{contract}', [ContractController::class, 'destroy'])
            ->can('update', 'supplier')
            ->name('suppliers.contracts.destroy');

        Route::post('suppliers/{supplier}/contracts/{contract}/documents', UploadContractDocumentController::class)
            ->can('update', 'supplier')
            ->name('suppliers.contracts.documents.store');

        Route::post('suppliers/{supplier}/documents', [DocumentController::class, 'store'])
            ->can('update', 'supplier')
            ->name('suppliers.documents.store');

        Route::get('suppliers/{supplier}/documents/{document}/file', [DocumentController::class, 'show'])
            ->can('view', 'document')
            ->name('suppliers.documents.download');

        Route::delete('suppliers/{supplier}/documents/{document}', [DocumentController::class, 'destroy'])
            ->can('delete', 'document')
            ->name('suppliers.documents.destroy');

        Route::post('suppliers/{supplier}/ratings', [RatingController::class, 'store'])
            ->can('update', 'supplier')
            ->name('suppliers.ratings.store');

        Route::delete('suppliers/{supplier}/ratings/{rating}', [RatingController::class, 'destroy'])
            ->can('update', 'supplier')
            ->name('suppliers.ratings.destroy');

        Route::post('suppliers/{supplier}/spend', [SpendController::class, 'store'])
            ->can('update', 'supplier')
            ->name('suppliers.spend.store');

        Route::delete('suppliers/{supplier}/spend/{spend}', [SpendController::class, 'destroy'])
            ->can('update', 'supplier')
            ->name('suppliers.spend.destroy');

        Route::put('suppliers/{supplier}/risk', [RiskOverrideController::class, 'update'])
            ->can('update', 'supplier')
            ->name('suppliers.risk.update');

        Route::delete('suppliers/{supplier}/risk', [RiskOverrideController::class, 'destroy'])
            ->can('update', 'supplier')
            ->name('suppliers.risk.destroy');

        Route::post('suppliers/{supplier}/comments', [CommentController::class, 'store'])
            ->can('create', [Comment::class, 'supplier'])
            ->middleware('throttle:30,1')
            ->name('suppliers.comments.store');

        Route::put('suppliers/{supplier}/comments/{comment}', [CommentController::class, 'update'])
            ->can('update', 'comment')
            ->name('suppliers.comments.update');

        Route::delete('suppliers/{supplier}/comments/{comment}', [CommentController::class, 'destroy'])
            ->can('delete', 'comment')
            ->name('suppliers.comments.destroy');
    });
});
