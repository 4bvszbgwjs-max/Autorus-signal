<?php

use App\Http\AdminController;
use App\Http\MetricsController;
use App\Http\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['meta' => (object) [], 'data' => []]));

Route::get('/ready', ReadinessController::class);
Route::get('/metrics', MetricsController::class);

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'login'])->name('login');
    Route::post('/login', [AdminController::class, 'authenticate'])->middleware('throttle:5,1')->name('authenticate');
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
    $editable = ['channel', 'scenario', 'recipient', 'message', 'sending'];
    Route::get('/{resource}', [AdminController::class, 'index'])
        ->whereIn('resource', [...$editable, 'eventlog'])->name('directory');
    Route::get('/{resource}/new', [AdminController::class, 'form'])
        ->whereIn('resource', $editable)->name('create');
    Route::get('/{resource}/record/{key}', [AdminController::class, 'form'])
        ->whereIn('resource', [...$editable, 'eventlog'])->name('edit');
    Route::get('/{resource}/export', [AdminController::class, 'export'])
        ->whereIn('resource', [...$editable, 'eventlog'])->name('export');
    Route::post('/{resource}/import', [AdminController::class, 'import'])
        ->whereIn('resource', $editable)->name('import');
    Route::post('/{resource}/remove/{key}', [AdminController::class, 'delete'])
        ->whereIn('resource', $editable)->name('delete');
    Route::post('/{resource}/{key?}', [AdminController::class, 'save'])
        ->whereIn('resource', $editable)->name('save');
});

Route::get('/openapi', \App\Http\OpenApiController::class);
