<?php

use App\Http\Controllers\Sources\ProcessingRunController;
use App\Http\Controllers\Sources\SnapshotController;
use App\Http\Controllers\Sources\ZipUploadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots'])->group(function () {
    Route::get('projects/{project}/snapshots/{snapshot}', [SnapshotController::class, 'show'])->scopeBindings()->name('projects.snapshots.show');
    Route::get('processing-runs/{run}', [ProcessingRunController::class, 'show'])->name('processing-runs.show');
    Route::post('processing-runs/{run}/cancel', [ProcessingRunController::class, 'cancel'])->name('processing-runs.cancel');
    Route::post('projects/{project}/fixture-import', [ProcessingRunController::class, 'importFixture'])->name('projects.fixture-import');
    Route::middleware('feature:zip_import')->scopeBindings()->group(function () {
        Route::post('projects/{project}/uploads', [ZipUploadController::class, 'store'])->middleware('throttle:upload-start')->name('projects.uploads.store');
        Route::get('projects/{project}/uploads/{upload}', [ZipUploadController::class, 'show'])->name('projects.uploads.show');
        // A 10 GB archive is ~200 pieces; allow a steady stream from one uploader.
        Route::put('projects/{project}/uploads/{upload}/chunk', [ZipUploadController::class, 'chunk'])->middleware('throttle:upload-chunk')->name('projects.uploads.chunk');
        Route::post('projects/{project}/uploads/{upload}/complete', [ZipUploadController::class, 'complete'])->middleware('throttle:upload-complete')->name('projects.uploads.complete');
    });
});
