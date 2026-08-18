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
        $middleware->trustHosts();
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->authenticateSessions();
        $middleware->append(\App\Http\Middleware\SecureHeaders::class);

        $middleware->alias([
            'ensure.role' => \App\Http\Middleware\EnsureRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'automation.token' => \App\Http\Middleware\VerifyAutomationToken::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('inventory:metrics')->dailyAt('01:00');
        $schedule->command('inventory:alerts-cache-top --force')->dailyAt('07:00');
        $schedule->command('inventory:alerts-send')->dailyAt('07:15');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
