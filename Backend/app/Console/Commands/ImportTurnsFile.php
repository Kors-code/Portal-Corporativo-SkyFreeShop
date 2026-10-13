<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\TurnsImportController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class ImportTurnsFile extends Command
{
    protected $signature = 'imports:turns-file
        {path : Ruta local al archivo xlsx/xls/csv de turnos}';

    protected $description = 'Importa turnos desde un archivo local usando el importador existente.';

    public function handle(TurnsImportController $controller): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");
            return self::FAILURE;
        }

        $request = new Request();
        $request->files->set('file', new UploadedFile($path, basename($path), null, null, true));

        $response = $controller->import($request);
        $payload = json_decode($response->getContent(), true) ?: [];

        if ($response->getStatusCode() >= 400) {
            $this->error($payload['message'] ?? $payload['error'] ?? 'No se pudo importar turnos.');
            if (! empty($payload['line'])) {
                $this->line('Linea: ' . $payload['line']);
            }
            return self::FAILURE;
        }

        $this->info($payload['message'] ?? 'Importacion completada');
        $this->line('Batch: ' . ($payload['batch_id'] ?? 'N/A'));
        $this->line('Filas importadas: ' . ($payload['rows'] ?? 0));

        $errors = $payload['errors'] ?? [];
        if (! empty($errors)) {
            $this->warn('Errores/omitidas: ' . count($errors));
            foreach (array_slice($errors, 0, 20) as $error) {
                $this->line('- ' . $error);
            }
        }

        return self::SUCCESS;
    }
}
