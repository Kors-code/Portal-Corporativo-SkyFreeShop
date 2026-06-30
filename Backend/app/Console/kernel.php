<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define el horario de los comandos de la aplicacion.
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('inventory:metrics')->dailyAt('01:00');
        $schedule->command('reports:queue-end-of-day-whatsapp')->dailyAt('01:05');
    }

    /**
     * Registra los comandos personalizados.
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
