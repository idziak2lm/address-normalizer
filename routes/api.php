<?php

use App\Http\Controllers\Api\AddressController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'track.api.usage'])->group(function () {
    Route::post('/normalize', [AddressController::class, 'normalize']);
    Route::post('/normalize/batch', [AddressController::class, 'batch']);
    Route::get('/status', [AddressController::class, 'status']);
});
