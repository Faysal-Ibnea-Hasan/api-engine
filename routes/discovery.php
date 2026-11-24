<?php

use App\Http\Controllers\Discovery\EndpointController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots', 'feature:framework_detection', 'feature:route_discovery'])
    ->scopeBindings()
    ->group(function () {
        Route::post('projects/{project}/snapshots/{snapshot}/scans', [EndpointController::class, 'start'])->middleware('throttle:route-scan')->name('projects.scans.store');
        Route::get('projects/{project}/scans/{scan}', [EndpointController::class, 'scan'])->name('projects.scans.show');
        Route::get('projects/{project}/endpoints', [EndpointController::class, 'index'])->name('projects.endpoints.index');
        Route::get('projects/{project}/endpoints/{endpoint}', [EndpointController::class, 'show'])->name('projects.endpoints.show');
    });
