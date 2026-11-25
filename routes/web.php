<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

Route::get('ready', ReadinessController::class)->middleware(['auth', 'can:platform-admin'])->name('ready');

require __DIR__.'/settings.php';
require __DIR__.'/ai.php';

require __DIR__.'/workspaces.php';

require __DIR__.'/projects.php';

require __DIR__.'/sources.php';

require __DIR__.'/framework.php';

require __DIR__.'/discovery.php';
require __DIR__.'/analysis.php';
require __DIR__.'/docs.php';
require __DIR__.'/github.php';
