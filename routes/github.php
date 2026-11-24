<?php

use App\Http\Controllers\GitHub\GitHubCallbackController;
use App\Http\Controllers\GitHub\GitHubConnectionController;
use App\Http\Controllers\GitHub\GitHubRefController;
use App\Http\Controllers\GitHub\GitHubRepositoryController;
use App\Http\Controllers\Settings\GitHubAppSettingsController;
use App\Http\Controllers\Sources\GitHubImportController;
use Illuminate\Support\Facades\Route;

// P09: GitHub repository import. Everything 404s while git_import (or an earlier flag) is off.
Route::middleware(['auth', 'verified', 'feature:workspace_management', 'feature:project_wizard', 'feature:source_snapshots', 'feature:git_import'])->group(function () {
    // Platform settings for the one GitHub App: platform admins only (others get 404 in the controller).
    Route::get('settings/github-app', [GitHubAppSettingsController::class, 'edit'])->name('github-app.edit');
    Route::put('settings/github-app', [GitHubAppSettingsController::class, 'update'])->middleware('throttle:github-app-settings')->name('github-app.update');
    Route::post('settings/github-app/test', [GitHubAppSettingsController::class, 'test'])->middleware('throttle:github-app-settings')->name('github-app.test');

    Route::get('workspaces/{workspace}/integrations/github', [GitHubConnectionController::class, 'show'])->name('workspaces.github.show');
    Route::post('workspaces/{workspace}/integrations/github/connect', [GitHubConnectionController::class, 'store'])->middleware('throttle:github-connect')->name('workspaces.github.connect');
    Route::delete('workspaces/{workspace}/integrations/github/{installation}', [GitHubConnectionController::class, 'destroy'])->name('workspaces.github.destroy');
    // State-protected (one-time, user-bound) and rate-limited.
    Route::get('integrations/github/callback', GitHubCallbackController::class)->middleware('throttle:github-connect')->name('github.callback');

    Route::middleware('throttle:github-browse')->group(function () {
        Route::get('projects/{project}/github/installations', [GitHubRepositoryController::class, 'installations'])->name('projects.github.installations');
        Route::get('projects/{project}/github/installations/{installation}/repositories', [GitHubRepositoryController::class, 'repositories'])->name('projects.github.repositories');
        Route::put('projects/{project}/github/source', [GitHubRepositoryController::class, 'update'])->name('projects.github.source');
        Route::get('projects/{project}/github/refs', GitHubRefController::class)->name('projects.github.refs');
    });
    Route::post('projects/{project}/github/imports', [GitHubImportController::class, 'store'])->middleware('throttle:github-import')->name('projects.github.imports.store');
});
