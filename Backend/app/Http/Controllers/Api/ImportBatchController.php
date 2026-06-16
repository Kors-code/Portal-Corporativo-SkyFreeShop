<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Comisiones\ImportBatch;
use App\Models\Comisiones\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ImportBatchController extends Controller
{
    // GET /api/v1/imports
    public function index()
    {
        $batches = ImportBatch::orderBy('created_at', 'desc')
            ->select(['id','filename','checksum','status','rows','created_at','note'])
            ->get();

        return response()->json($batches);
    }

    // GET /api/v1/imports/{id}
    public function show($id)
    {
        $batch = ImportBatch::findOrFail($id);
        $salesQuery = Sale::where('import_batch_id', $batch->id);

        $batch->sales_count = (clone $salesQuery)->count();
        $batch->first_sale_date = (clone $salesQuery)->min('sale_date');
        $batch->last_sale_date = (clone $salesQuery)->max('sale_date');
        $batch->sales_sample = (clone $salesQuery)
            ->select('id','sale_date','folio','pdv','product_id','quantity','amount','value_pesos','value_usd','currency','cashier','import_batch_id')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json($batch);
    }

    // DELETE /api/v1/imports/{id}
    public function destroy($id)
    {
        DB::connection('budget')->beginTransaction();

        try {
            $batch = ImportBatch::findOrFail($id);

            if ($batch->status === 'processing' && (int) $batch->rows > 0) {
                DB::connection('budget')->rollBack();
                return response()->json([
                    'message' => 'No se puede eliminar un import mientras esta procesando filas.',
                    'batch_id' => $batch->id,
                    'rows' => (int) $batch->rows,
                ], 409);
            }

            // 1) Borrar ventas asociadas explícitamente
            Sale::where('import_batch_id', $batch->id)->delete();

            // 2) Borrar registro del batch
            $filename = $batch->filename;
            $batch->delete();

            // 3) Intentar borrar archivo físico si existe (varias ubicaciones probables)
            try {
                // Si guardas en storage/app/imports
                $path1 = 'imports/' . $filename;
                if ($filename && Storage::exists($path1)) {
                    Storage::delete($path1);
                } else {
                    // si guardaste en public/uploads o similar:
                    $path2 = public_path('uploads/' . $filename);
                    if ($filename && file_exists($path2)) unlink($path2);
                    // si guardaste en storage/app/public
                    $path3 = storage_path('app/public/' . $filename);
                    if ($filename && file_exists($path3)) unlink($path3);
                }
            } catch (\Throwable $e) {
                Log::warning("No se pudo borrar archivo físico: " . $e->getMessage());
            }

            DB::connection('budget')->commit();
            return response()->json(['message' => 'Batch eliminado y ventas asociadas borradas.']);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            Log::error("Error al eliminar import batch: " . $e->getMessage());
            return response()->json(['error' => 'No se pudo eliminar el batch', 'detail' => $e->getMessage()], 500);
        }
    }

    // POST /api/v1/imports/bulk-delete
public function bulkDestroy(Request $request)
{
    $request->validate([
        'ids' => 'required|array|min:1',
        'ids.*' => 'integer|distinct|exists:budget.import_batches,id',
    ]);

    $ids = $request->input('ids');

    DB::connection('budget')->beginTransaction();

    try {
        $batches = ImportBatch::whereIn('id', $ids)->get();
        $processingIds = $batches
            ->filter(fn ($batch) => $batch->status === 'processing' && (int) $batch->rows > 0)
            ->pluck('id')
            ->values()
            ->all();

        foreach ($batches as $batch) {
            if ($batch->status === 'processing' && (int) $batch->rows > 0) {
                continue;
            }

            // 1) borrar ventas
            Sale::where('import_batch_id', $batch->id)->delete();

            // 2) borrar archivo físico (si existe)
            try {
                $path = 'imports/' . $batch->filename;
                if ($batch->filename && Storage::exists($path)) {
                    Storage::delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning("No se pudo borrar archivo del batch {$batch->id}: " . $e->getMessage());
            }

            // 3) borrar batch
            $batch->delete();
        }

        DB::connection('budget')->commit();

        return response()->json([
            'message' => 'Batches eliminados correctamente',
            'deleted' => count($ids) - count($processingIds),
            'skipped_processing' => $processingIds,
        ]);

    } catch (\Throwable $e) {
        DB::connection('budget')->rollBack();
        Log::error('Bulk delete imports failed', [
            'error' => $e->getMessage(),
            'ids' => $ids
        ]);

        return response()->json([
            'message' => 'Error eliminando batches',
            'error' => $e->getMessage()
        ], 500);
    }
}

}
