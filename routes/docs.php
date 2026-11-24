<?php

use App\Http\Controllers\Docs\DocumentationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots', 'feature:framework_detection', 'feature:route_discovery', 'feature:endpoint_analysis', 'feature:static_docs'])
    ->scopeBindings()
    ->group(function () {
        Route::post('projects/{project}/analyses/{analysis}/documentation', [DocumentationController::class, 'store'])->middleware('throttle:static-docs')->name('projects.documentation.store');
        Route::get('projects/{project}/endpoints/{endpoint}/documentation', [DocumentationController::class, 'index'])->name('projects.endpoints.documentation');
        Route::get('projects/{project}/documentation/{documentation}', [DocumentationController::class, 'show'])->name('projects.documentation.show');
    });
