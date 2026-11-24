<?php

use App\Http\Controllers\Framework\DetectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots', 'feature:framework_detection'])
    ->scopeBindings()
    ->group(function () {
        Route::post('projects/{project}/snapshots/{snapshot}/detect', [DetectionController::class, 'detect'])->middleware('throttle:framework-detect')->name('projects.snapshots.detect');
        Route::get('projects/{project}/snapshots/{snapshot}/detection', [DetectionController::class, 'show'])->name('projects.snapshots.detection');
        Route::patch('projects/{project}/snapshots/{snapshot}/application-root', [DetectionController::class, 'selectRoot'])->name('projects.snapshots.application-root');
    });
