<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->routes(function () {

            // 🔵 RUTAS API
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // 🟢 RUTAS WEB
            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
