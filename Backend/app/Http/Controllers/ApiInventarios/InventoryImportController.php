<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Http\Controllers\Controller;
use App\Imports\InventoryImport;
use App\Models\Inventario\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class InventoryImportController extends Controller
{
    public function importAutomation(Request $request): JsonResponse
    {
        $expectedToken = (string) config('services.automation.token', '');
        $token = (string) $request->header('X-Automation-Token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            return response()->json([
                'message' => 'No autorizado',
            ], 403);
        }

        $validated = $request->validate([
            'file' => $this->spreadsheetFileRules(),
            'store_id' => ['required', 'integer', 'exists:budget.stores,id'],
        ]);

        return $this->runImport(
            $request->file('file'),
            (int) $validated['store_id'],
            true
        );
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => $this->spreadsheetFileRules(),
            'store_id' => ['required', 'integer', 'exists:budget.stores,id'],
        ]);

        return $this->runImport(
            $request->file('file'),
            (int) $validated['store_id'],
            false
        );
    }

    public function deleteBatch(int $batchId): JsonResponse
    {
        DB::connection('budget')->transaction(function () use ($batchId) {
            DB::connection('budget')->table('inventory')
                ->where('batch_id', $batchId)
                ->delete();

            DB::connection('budget')->table('inventory_import_batches')
                ->where('id', $batchId)
                ->delete();
        });

        return response()->json([
            'message' => 'Batch eliminado correctamente.',
            'batch_id' => $batchId,
        ]);
    }

    public function stores(): JsonResponse
    {
        return response()->json(
            Store::on('budget')->orderBy('name')->get()
        );
    }

    private function runImport($file, int $storeId, bool $automation = false): JsonResponse
    {
        $now = now();
        $checksum = @hash_file('sha256', $file->getRealPath()) ?: null;
        $filename = $file->getClientOriginalName();
        $lockName = 'inventory_import_store_' . $storeId;
        $lockAcquired = false;

        try {
            $lock = DB::connection('budget')->selectOne('SELECT GET_LOCK(?, 1) as acquired', [$lockName]);
            $lockAcquired = (int) ($lock->acquired ?? 0) === 1;

            if (!$lockAcquired) {
                return response()->json([
                    'message' => 'Ya hay una importacion de inventario en proceso para esta tienda.',
                    'store_id' => $storeId,
                ], 409);
            }

            $batchId = DB::connection('budget')->table('inventory_import_batches')->insertGetId([
                'filename' => $filename,
                'store_id' => $storeId,
                'to_date' => $now->toDateString(),
                'rows_imported' => 0,
                'status' => 'processing',
                'checksum' => $checksum,
                'notes' => $automation
                    ? 'Importacion automatica - reemplaza inventario actual'
                    : 'Importacion manual - reemplaza inventario actual',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $import = new InventoryImport($storeId, $batchId);
            Excel::import($import, $file);

            $rowsImported = method_exists($import, 'getRowsImported')
                ? $import->getRowsImported()
                : DB::connection('budget')->table('inventory')
                    ->where('batch_id', $batchId)
                    ->count();

            DB::connection('budget')->table('inventory_import_batches')
                ->where('store_id', $storeId)
                ->where('id', '!=', $batchId)
                ->update([
                    'status' => 'superseded',
                    'updated_at' => now(),
                ]);

            DB::connection('budget')->table('inventory_import_batches')
                ->where('id', $batchId)
                ->update([
                    'rows_imported' => $rowsImported,
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'Inventario importado correctamente.',
                'batch_id' => $batchId,
                'store_id' => $storeId,
                'filename' => $filename,
            ]);
        } catch (Throwable $e) {
            if (isset($batchId)) {
                try {
                    DB::connection('budget')->table('inventory_import_batches')
                        ->where('id', $batchId)
                        ->update([
                            'status' => 'failed',
                            'notes' => $e->getMessage(),
                            'updated_at' => now(),
                        ]);
                } catch (Throwable) {
                }
            }

            Log::error('Error importando inventario', [
                'store_id' => $storeId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error importando inventario',
                'error' => $e->getMessage(),
            ], 500);
        } finally {
            if ($lockAcquired) {
                try {
                    DB::connection('budget')->selectOne('SELECT RELEASE_LOCK(?) as released', [$lockName]);
                } catch (Throwable) {
                }
            }
        }
    }

    private function spreadsheetFileRules(): array
    {
        return [
            'required',
            'file',
            'max:20480',
            function (string $attribute, $value, \Closure $fail): void {
                $extension = strtolower($value->getClientOriginalExtension() ?: '');

                if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                    $fail('El archivo debe ser de tipo: xlsx, xls o csv.');
                }
            },
        ];
    }
}
