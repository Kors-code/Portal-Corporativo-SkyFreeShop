<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
         $middleware->alias([
        'ensure.role' => \App\Http\Middleware\EnsureRole::class,
        'permission' => \App\Http\Middleware\CheckPermission::class,
    ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('inventory:metrics')->dailyAt('01:00');
        $schedule->command('reports:queue-end-of-day-whatsapp')->dailyAt('23:59');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
