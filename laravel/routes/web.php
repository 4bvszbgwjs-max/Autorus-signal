<?php

use App\Http\AdminAccess;
use App\Http\AdminController;
use App\Http\AdminWorkspaceController;
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
    Route::get('/queue', [AdminWorkspaceController::class, 'queue'])->middleware(AdminAccess::class.':list')->name('queue');
    Route::post('/scenario/preview/{key?}', [AdminController::class, 'preview'])->whereNumber('key')
        ->middleware(AdminAccess::class.':write')->name('scenario.preview');
    Route::middleware(AdminAccess::class.':admin')->group(function () {
        Route::get('/accounts', [AdminWorkspaceController::class, 'accounts'])->name('accounts');
        Route::get('/accounts/new', [AdminWorkspaceController::class, 'accountForm'])->name('account.create');
        Route::get('/accounts/{id}', [AdminWorkspaceController::class, 'accountForm'])->whereNumber('id')->name('account.edit');
        Route::post('/accounts/{id?}', [AdminWorkspaceController::class, 'saveAccount'])->whereNumber('id')->name('account.save');
        Route::get('/audit', [AdminWorkspaceController::class, 'audit'])->name('audit');
    });
    $editable = ['channel', 'scenario', 'recipient', 'message', 'sending'];
    Route::get('/{resource}', [AdminController::class, 'index'])
        ->whereIn('resource', [...$editable, 'eventlog'])->middleware(AdminAccess::class.':list')->name('directory');
    Route::get('/{resource}/new', [AdminController::class, 'form'])
        ->whereIn('resource', $editable)->middleware(AdminAccess::class.':write')->name('create');
    Route::get('/{resource}/record/{key}', [AdminController::class, 'form'])
        ->whereIn('resource', [...$editable, 'eventlog'])->middleware(AdminAccess::class.':read')->name('edit');
    Route::get('/{resource}/export', [AdminController::class, 'export'])
        ->whereIn('resource', [...$editable, 'eventlog'])->middleware(AdminAccess::class.':read')->name('export');
    Route::post('/{resource}/import', [AdminController::class, 'import'])
        ->whereIn('resource', $editable)->middleware(AdminAccess::class.':write')->name('import');
    Route::post('/{resource}/remove/{key}', [AdminController::class, 'delete'])
        ->whereIn('resource', $editable)->middleware(AdminAccess::class.':write')->name('delete');
    Route::post('/{resource}/{key?}', [AdminController::class, 'save'])
        ->whereIn('resource', $editable)->middleware(AdminAccess::class.':write')->name('save');
});

Route::get('/openapi', \App\Http\OpenApiController::class);
