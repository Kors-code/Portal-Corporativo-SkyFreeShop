<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define el horario de los comandos de la aplicación.
     */
    protected function schedule(Schedule $schedule)
    {
        // Ejecutar el comando cada día a medianoche
        $schedule->command('disciplinas:inactivar')->dailyAt('00:00');
    }

    /**
     * Registra los comandos personalizados.
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
