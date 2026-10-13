<?php

namespace App\Console\Commands;

use App\Http\Controllers\ImportSalesController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class ImportSalesFile extends Command
{
    protected $signature = 'imports:sales-file
        {path : Ruta local al archivo xlsx/xls/csv}
        {--store_id= : ID de tienda obligatoria para el importador}
        {--replace-existing : Reemplaza importaciones previas equivalentes}
        {--chunk-size=300 : Filas por bloque, entre 50 y 500}';

    protected $description = 'Importa ventas desde un archivo local usando el importador por bloques.';

    public function handle(ImportSalesController $controller): int
    {
        $path = $this->argument('path');
        $storeId = $this->option('store_id');
        $chunkSize = max(50, min(500, (int) $this->option('chunk-size')));

        if (! $storeId) {
            $this->error('Debes indicar --store_id.');
            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");
            return self::FAILURE;
        }

        $uploaded = new UploadedFile($path, basename($path), null, null, true);
        $startRequest = new Request([
            'store_id' => (int) $storeId,
            'replace_existing' => (bool) $this->option('replace-existing'),
        ]);
        $startRequest->files->set('file', $uploaded);

        $startResponse = $controller->startChunked($startRequest);
        $startPayload = json_decode($startResponse->getContent(), true) ?: [];

        if ($startResponse->getStatusCode() >= 400) {
            $this->error($startPayload['message'] ?? $startPayload['error'] ?? 'No se pudo iniciar la importacion.');
            return self::FAILURE;
        }

        $batchId = (int) $startPayload['batch_id'];
        $nextRow = (int) $startPayload['next_row'];
        $totalRows = (int) ($startPayload['total_rows'] ?? 0);
        $pathInStorage = (string) $startPayload['path'];
        $this->info("Importacion iniciada. Batch {$batchId}. Filas esperadas: {$totalRows}.");

        $bar = $totalRows > 0 ? $this->output->createProgressBar($totalRows) : null;
        $processed = 0;

        while (true) {
            $chunkRequest = new Request([
                'path' => $pathInStorage,
                'batch_id' => $batchId,
                'store_id' => (int) $storeId,
                'next_row' => $nextRow,
                'chunk_size' => $chunkSize,
                'total_rows' => $totalRows,
            ]);

            $chunkResponse = $controller->chunk($chunkRequest);
            $payload = json_decode($chunkResponse->getContent(), true) ?: [];

            if ($chunkResponse->getStatusCode() >= 400) {
                $bar?->finish();
                $this->newLine();
                $this->error($payload['message'] ?? $payload['error'] ?? 'Fallo procesando bloque.');
                $this->line('Batch: ' . $batchId);
                return self::FAILURE;
            }

            $processedRows = (int) ($payload['processed_rows'] ?? 0);
            $processed += $processedRows;
            $bar?->advance($processedRows);
            $nextRow = (int) ($payload['next_row'] ?? ($nextRow + $chunkSize));

            $inserted = (int) ($payload['summary']['created']['sales'] ?? 0);
            $skipped = (int) ($payload['summary']['skipped'] ?? 0);
            $this->line(" Bloque OK: +{$inserted} ventas, {$skipped} omitidas. Siguiente fila {$nextRow}.");

            if ((bool) ($payload['done'] ?? false)) {
                $bar?->finish();
                $this->newLine(2);
                $this->info("Importacion completada. Batch {$batchId}. Filas procesadas: {$processed}.");

                if (! empty($payload['publication'])) {
                    $this->line('Publicacion: ' . json_encode($payload['publication'], JSON_UNESCAPED_UNICODE));
                }

                if (! empty($payload['roles_rectification'])) {
                    $this->line('Roles: ' . json_encode($payload['roles_rectification'], JSON_UNESCAPED_UNICODE));
                }

                if (! empty($payload['commission_recalc'])) {
                    $this->line('Comisiones: ' . json_encode($payload['commission_recalc'], JSON_UNESCAPED_UNICODE));
                }

                return self::SUCCESS;
            }
        }
    }
}
