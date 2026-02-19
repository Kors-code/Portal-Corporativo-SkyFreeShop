<?php

namespace App\Http\Controllers\WishList;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Imports\CatalogImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CatalogController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        @set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $file = $request->file('file');

        if (!$file || !$file->isValid()) {
            return response()->json(['message' => 'Archivo inválido'], 422);
        }

        try {
            $realPath = $file->getRealPath();
            if (!$realPath || !file_exists($realPath)) {
                throw new \RuntimeException("Archivo temporal no encontrado: {$realPath}");
            }

            $reader = IOFactory::createReaderForFile($realPath);
            // para evitar problemas con formatos raros
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($realPath);
            $sheet = $spreadsheet->getActiveSheet();

            // leer encabezados (fila 1)
            $highestColumn = $sheet->getHighestColumn();
            $headerRange = $sheet->rangeToArray('A1:' . $highestColumn . '1', null, true, true, true);
            $headerRaw = $headerRange ? reset($headerRange) : false;

            if (!$headerRaw || count(array_filter($headerRaw)) === 0) {
                return response()->json(['message' => 'El archivo no contiene encabezados válidos en la fila 1'], 422);
            }

            // normalizar encabezados (A,B,C -> claves)
            $headers = [];
            foreach ($headerRaw as $col => $value) {
                $headers[$col] = $this->normalizeHeader((string)$value);
            }

            $highestRow = $sheet->getHighestRow();
            $chunkSize = 500; // ajustable
            $import = new CatalogImport();

            $totalInserted = 0;
            $errors = [];

            for ($start = 2; $start <= $highestRow; $start += $chunkSize) {
                $end = min($start + $chunkSize - 1, $highestRow);

                $buffer = [];

                for ($row = $start; $row <= $end; $row++) {
                    $range = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, true, true);
                    $rowData = $range ? reset($range) : false;

                    // fila vacía -> saltar
                    if (!$rowData || count(array_filter($rowData)) === 0) {
                        continue;
                    }

                    // mapear a assoc por nombre de header
                    $assoc = [];
                    foreach ($rowData as $col => $val) {
                        if (isset($headers[$col])) {
                            $assoc[$headers[$col]] = is_scalar($val) ? trim((string)$val) : $val;
                        }
                    }

                    try {
                        $mapped = $import->mapRow($assoc);
                        if (!empty($mapped)) {
                            // forzamos que la inserción use la conexión mysql_personal
                            $buffer[] = $mapped;
                            $import->incrementRowCount();
                        }
                    } catch (\Throwable $rowEx) {
                        $errors[] = ['row' => $row, 'error' => $rowEx->getMessage()];
                        Log::error("Catalog import row {$row} error: " . $rowEx->getMessage(), ['data' => $assoc, 'trace' => $rowEx->getTraceAsString()]);
                    }
                }

                if (!empty($buffer)) {
                    // Inserción en batch usando la conexión mysql_personal
                    try {
                        DB::connection('mysql_personal')->table('catalog_products')->insert($buffer);
                        $totalInserted += count($buffer);
                    } catch (\Throwable $e) {
                        // si falla un batch, intentar insertar filas individuales para aislar errores
                        Log::error("Batch insert failed: " . $e->getMessage(), ['start' => $start, 'end' => $end]);
                        foreach ($buffer as $i => $rowInsert) {
                            try {
                                DB::connection('mysql_personal')->table('catalog_products')->insert($rowInsert);
                                $totalInserted++;
                            } catch (\Throwable $singleEx) {
                                $errors[] = ['row_in_batch' => ($start + $i), 'error' => $singleEx->getMessage()];
                                Log::error("Single insert failed for row " . ($start + $i) . ": " . $singleEx->getMessage());
                            }
                        }
                    }
                }
            }

            return response()->json([
                'message' => 'Importación completada',
                'rows' => $import->getRowCount(),
                'inserted' => $totalInserted,
                'errors_count' => count($errors),
                'errors' => array_slice($errors, 0, 20), // mostrar primeras 20 para no romper respuesta
            ]);

        } catch (\Throwable $e) {
            Log::error('Error importando catálogo: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Error importando catálogo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function normalizeHeader(string $h): string
    {
        $h = preg_replace('/^\x{FEFF}/u', '', $h);
        $h = trim($h);
        $h = mb_strtolower($h);
        $h = preg_replace('/\s+/', ' ', $h);
        $h = preg_replace('/[^\p{L}\p{N}]+/u', '_', $h);
        $h = preg_replace('/_+/', '_', $h);
        return trim($h, '_');
    }
}
