<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'track.api.usage' => \App\Http\Middleware\TrackApiUsage::class,
            'csv.auth' => \App\Http\Middleware\CsvSessionAuth::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('logs:clean-expired')->dailyAt('02:00');
        $schedule->command('usage:reset-monthly')->monthlyOn(1, '00:01');
        $schedule->command('csv:clean-expired')->dailyAt('03:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
