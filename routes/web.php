<?php

use App\Http\Controllers\Web\CsvUploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('csv')->group(function () {
    Route::get('/login', [CsvUploadController::class, 'showLoginForm'])->name('csv.login');
    Route::post('/login', [CsvUploadController::class, 'login']);

    Route::middleware('csv.auth')->group(function () {
        Route::post('/logout', [CsvUploadController::class, 'logout'])->name('csv.logout');
        Route::get('/', [CsvUploadController::class, 'index'])->name('csv.index');
        Route::post('/upload', [CsvUploadController::class, 'upload'])->name('csv.upload');
        Route::get('/{import}/status', [CsvUploadController::class, 'status'])->name('csv.status');
        Route::get('/{import}/download', [CsvUploadController::class, 'download'])->name('csv.download');
    });
});
