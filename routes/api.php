<?php

use App\Http\Controllers\Api\AddressController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:api', 'track.api.usage'])->group(function () {
    Route::post('/normalize', [AddressController::class, 'normalize']);
    Route::post('/normalize/batch', [AddressController::class, 'batch']);
    Route::post('/validate', [AddressController::class, 'validate']);
    Route::get('/status', [AddressController::class, 'status']);
});
