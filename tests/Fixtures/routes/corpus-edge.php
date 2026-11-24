<?php

use App\Http\Controllers\CommentController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\ThumbController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Registrar attributes replace, they don't append.
Route::middleware('auth')->middleware('throttle:60,1')->group(fn () => Route::get('twice-mw', [UserController::class, 'index']));
Route::name('a.')->name('b.')->group(fn () => Route::get('twice-name', [UserController::class, 'index'])->name('x'));

// A registrar prefix on a verb goes before the group prefix.
Route::prefix('outer')->group(function () {
    Route::prefix('v1')->get('direct', [UserController::class, 'index']);
});

// Resources: shallow nesting, slashed names, string names, singletons, middleware.
Route::resource('photos.comments', CommentController::class)->shallow();
Route::resource('admin/photos', PhotoController::class)->only(['index', 'show']);
Route::apiResource('tags', TagController::class)->names('lbl');
Route::singleton('profile', ProfileController::class);
Route::apiSingleton('settings', SettingsController::class)->destroyable();
Route::singleton('photos.thumbnail', ThumbController::class)->creatable();
Route::resource('users', UserController::class)->middleware('auth')->only(['index']);

// Group namespace applies to string actions only, and never twice.
Route::group(['namespace' => 'App\Http\Controllers', 'prefix' => 'ns'], function () {
    Route::get('arr', [UserController::class, 'index']);
    Route::get('str', 'UserController@show');
    Route::get('full', 'App\Http\Controllers\UserController@edit');
});

// Slashes, domains with prefixes, optional parameters.
Route::prefix('/slashes/')->group(fn () => Route::get('/x/', [UserController::class, 'index']));
Route::domain('api.example.com')->prefix('d')->group(fn () => Route::get('y', [UserController::class, 'index']));
Route::get('opt/{id?}', [UserController::class, 'index']);

// switch / try-catch-finally bodies are walked.
switch (true) {
    case true:
        Route::get('in-switch', [UserController::class, 'index']);
}
try {
    Route::get('in-try', [UserController::class, 'index']);
} finally {
    Route::get('in-finally', [UserController::class, 'index']);
}
