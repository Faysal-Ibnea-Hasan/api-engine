<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashController;
use App\Http\Controllers\HookController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::post('users', [UserController::class, 'store'])->middleware('auth:sanctum');

Route::prefix('v1')->name('v1.')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders', [OrderController::class, 'index']);

    Route::prefix('admin')->as('admin.')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports');
        Route::delete('reports/{report}', [ReportController::class, 'destroy'])->withoutMiddleware('throttle:api');
    });
});

Route::controller(UserController::class)->prefix('profile')->group(function () {
    Route::get('/', 'show')->name('profile.show');
    Route::put('/', 'update');
});

Route::group(['prefix' => 'legacy', 'middleware' => 'auth', 'as' => 'legacy.'], function () {
    Route::get('ping', ['as' => 'ping', 'uses' => 'App\Http\Controllers\PingController@ping']);
    Route::get('pong', 'App\Http\Controllers\PingController@pong')->middleware('verified');
});

Route::match(['get', 'post'], '/search', [SearchController::class, 'run']);
Route::any('/hook', [HookController::class, 'handle']);

Route::apiResource('photos', PhotoController::class);
Route::resource('user-profiles', ProfileController::class)->only(['index', 'show']);
Route::resource('photos.comments', CommentController::class)->except(['create', 'edit'])->names(['index' => 'photo-comments']);
Route::apiResource('teams', TeamController::class)->parameters(['teams' => 'squad']);
Route::resources(['albums' => PhotoController::class]);

Route::domain('{account}.example.com')->group(function () {
    Route::get('dashboard', [DashController::class, 'show']);
});

Route::get('/closure', fn () => 'x');
Route::view('/welcome', 'welcome');
Route::redirect('/old', '/new');
Route::get('/can', [UserController::class, 'index'])->can('viewAny');
