<?php

use App\Http\Controllers\Analysis\AnalysisController;
use App\Http\Controllers\Analysis\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots', 'feature:framework_detection', 'feature:route_discovery', 'feature:endpoint_analysis'])
    ->scopeBindings()
    ->group(function () {
        Route::post('projects/{project}/endpoints/{endpoint}/analyses', [AnalysisController::class, 'store'])->middleware('throttle:endpoint-analysis')->name('projects.analyses.store');
        Route::get('projects/{project}/analyses/{analysis}', [AnalysisController::class, 'show'])->name('projects.analyses.show');
        Route::get('projects/{project}/analyses/{analysis}/evidence/{evidence:evidence_key}', [AnalysisController::class, 'evidence'])->name('projects.analyses.evidence');
    });

// P10: the endpoint's workflow graph, from the analysis the endpoint page shows.
Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots', 'feature:framework_detection', 'feature:route_discovery', 'feature:endpoint_analysis', 'feature:advanced_analysis'])
    ->scopeBindings()
    ->group(function () {
        Route::post('projects/{project}/analyses/{analysis}/dependencies', [WorkflowController::class, 'store'])->middleware('throttle:endpoint-analysis')->name('projects.analyses.dependencies.store');
        Route::get('projects/{project}/analyses/{analysis}/dependencies', [WorkflowController::class, 'show'])->name('projects.analyses.dependencies');
    });
