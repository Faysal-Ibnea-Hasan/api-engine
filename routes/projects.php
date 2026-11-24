<?php

use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectWizardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard'])->group(function () {
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
    Route::get('projects/{project}/setup/{step}', [ProjectWizardController::class, 'edit'])->name('projects.setup.edit');
    Route::put('projects/{project}/setup/{step}', [ProjectWizardController::class, 'update'])->name('projects.setup.update');
});
