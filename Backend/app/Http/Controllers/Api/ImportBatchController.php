<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comisiones\ImportBatch;
use App\Models\Comisiones\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ImportBatchController extends Controller
{
    public function index()
    {
        $batches = ImportBatch::orderBy('created_at', 'desc')
            ->select(['id', 'filename', 'checksum', 'status', 'rows', 'created_at', 'note'])
            ->get();

        return response()->json($batches);
    }

    public function show($id)
    {
        $batch = ImportBatch::findOrFail($id);
        $salesQuery = Sale::where('import_batch_id', $batch->id);

        $batch->sales_count = (clone $salesQuery)->count();
        $batch->first_sale_date = (clone $salesQuery)->min('sale_date');
        $batch->last_sale_date = (clone $salesQuery)->max('sale_date');
        $batch->sales_sample = (clone $salesQuery)
            ->select('id', 'sale_date', 'folio', 'pdv', 'product_id', 'quantity', 'amount', 'value_pesos', 'value_usd', 'currency', 'cashier', 'import_batch_id')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return response()->json($batch);
    }

    public function destroy($id)
    {
        DB::connection('budget')->beginTransaction();

        try {
            $batch = DB::connection('budget')->table('import_batches')->where('id', $id)->first();

            if (!$batch) {
                DB::connection('budget')->rollBack();
                return response()->json(['message' => 'Importacion no encontrada.'], 404);
            }

            $this->deleteSalesForBatch((int) $batch->id);

            $deleted = DB::connection('budget')->table('import_batches')
                ->where('id', $batch->id)
                ->delete();

            if ($deleted !== 1) {
                DB::connection('budget')->rollBack();
                return response()->json([
                    'message' => 'No se pudo eliminar la importacion en la base de datos.',
                    'batch_id' => (int) $batch->id,
                ], 500);
            }

            $this->deletePhysicalImportFile($batch->filename ?? null);

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Batch eliminado y ventas asociadas borradas.',
                'deleted' => 1,
                'batch_id' => (int) $batch->id,
            ]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();

            Log::error('Error al eliminar import batch', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo eliminar el batch',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:budget.import_batches,id',
        ]);

        $ids = array_map('intval', $request->input('ids'));

        DB::connection('budget')->beginTransaction();

        try {
            $batches = DB::connection('budget')->table('import_batches')->whereIn('id', $ids)->get();

            foreach ($batches as $batch) {
                $this->deleteSalesForBatch((int) $batch->id);
            }

            $deleted = DB::connection('budget')->table('import_batches')
                ->whereIn('id', $ids)
                ->delete();

            foreach ($batches as $batch) {
                $this->deletePhysicalImportFile($batch->filename ?? null);
            }

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Batches eliminados correctamente',
                'deleted' => $deleted,
                'requested' => count($ids),
            ]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();

            Log::error('Bulk delete imports failed', [
                'error' => $e->getMessage(),
                'ids' => $ids,
            ]);

            return response()->json([
                'message' => 'Error eliminando batches',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function deletePhysicalImportFile(?string $filename): void
    {
        if (!$filename) {
            return;
        }

        $filename = basename($filename);

        if (!preg_match('/^[A-Za-z0-9_.-]+\.(xlsx|xls|xlsm|csv)$/i', $filename)) {
            Log::warning('Nombre de archivo de importacion invalido, no se borra fisicamente', [
                'filename' => $filename,
            ]);

            return;
        }

        try {
            foreach (['imports/' . $filename, 'sales-imports/' . $filename] as $path) {
                if (Storage::exists($path)) {
                    Storage::delete($path);
                }
            }

            foreach ([public_path('uploads/' . $filename), storage_path('app/public/' . $filename)] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo borrar archivo fisico del import', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteSalesForBatch(int $batchId): void
    {
        $saleIds = DB::connection('budget')->table('sales')
            ->where('import_batch_id', $batchId)
            ->pluck('id')
            ->all();

        if (!empty($saleIds) && Schema::connection('budget')->hasTable('commissions')) {
            DB::connection('budget')->table('commissions')
                ->whereIn('sale_id', $saleIds)
                ->delete();
        }

        DB::connection('budget')->table('sales')
            ->where('import_batch_id', $batchId)
            ->delete();
    }
}
