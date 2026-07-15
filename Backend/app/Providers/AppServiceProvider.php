<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;

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
        RateLimiter::for('api', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return Limit::perMinute((int) env('API_RATE_LIMIT_PER_MINUTE', 60))->by($key);
        });

        RateLimiter::for('automation', function (Request $request) {
            return Limit::perMinute((int) env('AUTOMATION_RATE_LIMIT_PER_MINUTE', 30))->by($request->ip());
        });
    }
}
