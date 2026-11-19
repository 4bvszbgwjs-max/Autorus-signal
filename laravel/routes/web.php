<?php

use App\Http\AdminController;
use App\Http\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['meta' => (object) [], 'data' => []]));

Route::get('/ready', ReadinessController::class);

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'login'])->name('login');
    Route::post('/login', [AdminController::class, 'authenticate'])->middleware('throttle:5,1')->name('authenticate');
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
    Route::get('/{resource}', [AdminController::class, 'index'])
        ->whereIn('resource', ['channel', 'scenario', 'recipient', 'message', 'sending'])->name('directory');
    Route::post('/{resource}/{key?}', [AdminController::class, 'save'])
        ->whereIn('resource', ['channel', 'scenario', 'recipient'])->name('save');
});

Route::get('/openapi', \App\Http\OpenApiController::class);
