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
        $token = $request->header('X-Automation-Token');

        if ($token !== env('IMPORT_AUTOMATION_TOKEN')) {
            return response()->json([
                'message' => 'No autorizado',
            ], 403);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
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
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
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

        $batchId = DB::connection('budget')->table('inventory_import_batches')->insertGetId([
            'filename' => $filename,
            'store_id' => $storeId,
            'to_date' => $now->toDateString(),
            'rows_imported' => 0,
            'status' => 'processing',
            'checksum' => $checksum,
            'notes' => $automation ? 'Importación automática' : 'Importación manual',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            Excel::import(new InventoryImport($storeId, $batchId), $file);

            DB::connection('budget')->table('inventory_import_batches')
                ->where('id', $batchId)
                ->update([
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
            DB::connection('budget')->table('inventory_import_batches')
                ->where('id', $batchId)
                ->update([
                    'status' => 'failed',
                    'notes' => $e->getMessage(),
                    'updated_at' => now(),
                ]);

            Log::error('Error importando inventario', [
                'store_id' => $storeId,
                'batch_id' => $batchId,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error importando inventario',
                'error' => $e->getMessage(),
                'batch_id' => $batchId,
            ], 500);
        }
    }
}