<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('photo-uploads', function (Request $request): Limit {
            return Limit::perHour(config('photos.upload_rate_limit'))->by($request->ip());
        });

        RateLimiter::for('photo-downloads', function (Request $request): Limit {
            return Limit::perMinute(config('photos.download_rate_limit'))->by($request->ip());
        });

        RateLimiter::for('photo-admin', function (Request $request): Limit {
            return Limit::perMinute(config('photos.admin_rate_limit'))->by($request->ip());
        });
    }
}
