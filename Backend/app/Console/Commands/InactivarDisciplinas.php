<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Personal\LlamadoAtencion;

class InactivarDisciplinas extends Command
{
    /**
     * El nombre y la firma del comando
     *
     * @var string
     */
    protected $signature = 'disciplinas:inactivar';

    /**
     * Descripción del comando
     *
     * @var string
     */
    protected $description = 'Inactiva todas las disciplinas activas el 31 de diciembre';

    /**
     * Ejecuta el comando
     */
    public function handle()
    {
        $fechaActual = now()->format('m-d');

        // Solo ejecuta si es 31 de diciembre
        if ($fechaActual === '12-31') {
            $total = LlamadoAtencion::where('estado', 'activo')
                ->update(['estado' => 'inactivo']);

            $this->info("✅ Se inactivaron {$total} disciplinas activas.");
        } else {
            $this->info("⏳ Hoy no es 31 de diciembre. No se realizaron cambios.");
        }
    }
}
