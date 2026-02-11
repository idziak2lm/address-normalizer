<?php

namespace App\Providers;

use App\Auth\ApiTokenGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::extend('api_token', function ($app, $name, array $config) {
            return new ApiTokenGuard($app['request']);
        });
    }
}
