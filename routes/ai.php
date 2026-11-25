<?php

use App\Http\Controllers\Settings\AiSettingsController;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Route;

// Settings → AI: platform admins only (others get 404 in the controller and request).
Route::middleware(['auth', 'verified', 'feature:ai_settings'])->group(function () {
    $providers = array_keys(AiProvider::PROVIDERS);

    Route::get('settings/ai', [AiSettingsController::class, 'edit'])->name('ai-settings.edit');
    Route::put('settings/ai/{provider}', [AiSettingsController::class, 'update'])->whereIn('provider', $providers)->middleware('throttle:ai-settings')->name('ai-settings.update');
    Route::post('settings/ai/{provider}/test', [AiSettingsController::class, 'test'])->whereIn('provider', $providers)->middleware('throttle:ai-settings')->name('ai-settings.test');
    Route::delete('settings/ai/{provider}/key', [AiSettingsController::class, 'destroyKey'])->whereIn('provider', $providers)->middleware('throttle:ai-settings')->name('ai-settings.key.destroy');
});
