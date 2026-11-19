<?php

use App\Http\DirectoryController;
use App\Http\NotificationController;
use App\Http\NotificationRecordsController;
use Illuminate\Support\Facades\Route;

Route::post('message', [NotificationController::class, 'createMessage']);
Route::patch('message/bulk/remove', [NotificationController::class, 'removeMessages']);
Route::patch('sending/bulk/remove', [NotificationController::class, 'removeSendings']);
Route::patch('sending/status', [NotificationController::class, 'updateSendingStatus']);

Route::get('{resource}', [NotificationController::class, 'index'])
    ->whereIn('resource', ['channel', 'message', 'recipient', 'scenario', 'sending', 'eventlog']);
Route::get('{resource}/{key}', [NotificationController::class, 'show'])
    ->whereIn('resource', ['channel', 'message', 'recipient', 'scenario', 'sending', 'eventlog']);

Route::patch('recipient/bulk/upsert', [DirectoryController::class, 'upsert']);
Route::patch('recipient/list/phone', [DirectoryController::class, 'phones']);
Route::post('{resource}', [DirectoryController::class, 'create'])
    ->whereIn('resource', ['channel', 'recipient', 'scenario']);
Route::match(['put', 'patch'], '{resource}/{key}', [DirectoryController::class, 'update'])
    ->whereIn('resource', ['channel', 'recipient', 'scenario']);
Route::delete('{resource}/{key}', [DirectoryController::class, 'delete'])
    ->whereIn('resource', ['channel', 'recipient', 'scenario']);

Route::post('sending', [NotificationRecordsController::class, 'createSending']);
Route::match(['put', 'patch'], '{record}/{key}', [NotificationRecordsController::class, 'update'])
    ->whereIn('record', ['message', 'sending'])->whereNumber('key');
Route::delete('{record}/{key}', [NotificationRecordsController::class, 'delete'])
    ->whereIn('record', ['message', 'sending'])->whereNumber('key');
