<?php

use App\Http\Controllers\Analysis\AnalysisController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots', 'feature:framework_detection', 'feature:route_discovery', 'feature:endpoint_analysis'])
    ->scopeBindings()
    ->group(function () {
        Route::post('projects/{project}/endpoints/{endpoint}/analyses', [AnalysisController::class, 'store'])->middleware('throttle:endpoint-analysis')->name('projects.analyses.store');
        Route::get('projects/{project}/analyses/{analysis}', [AnalysisController::class, 'show'])->name('projects.analyses.show');
        Route::get('projects/{project}/analyses/{analysis}/evidence/{evidence:evidence_key}', [AnalysisController::class, 'evidence'])->name('projects.analyses.evidence');
    });
