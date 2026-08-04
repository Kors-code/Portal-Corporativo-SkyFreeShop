<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banking\BankImportBatch;
use App\Models\Banking\BankMovement;
use App\Services\Banking\BankImportExportService;
use App\Services\Davibank\DavibankConverterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BankImportBatchController extends Controller
{
    public function import(Request $request, BankImportExportService $service): BinaryFileResponse
    {
        $validated = $request->validate([
            'bank' => ['required', 'string', 'in:davibank,davivienda,bancolombia,bancodebogota'],
            'file' => ['required', 'file', 'max:30720'],
            'receipt_start' => ['required', 'integer', 'min:1', 'max:999999999'],
        ]);

        try {
            $result = $service->importAndExport(
                $validated['bank'],
                $request->file('file'),
                (int) $validated['receipt_start'],
                $request->user()?->id
            );

            return response()
                ->download($result['path'], $result['filename'], [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Bank-Batch-Id' => (string) $result['batch_id'],
                    'X-Bank-Rows' => (string) $result['rows'],
                    'X-Bank-Rows-Imported' => (string) $result['rows_imported'],
                    'X-Bank-Rows-Skipped' => (string) $result['rows_skipped'],
                    'X-Bank-Sheets' => (string) $result['sheets'],
                ])
                ->deleteFileAfterSend(true);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $query = BankImportBatch::query()
            ->select([
                'id',
                'bank_id',
                'file_format_id',
                'bank_account_id',
                'bank',
                'source_type',
                'filename',
                'checksum',
                'status',
                'rows',
                'rows_imported',
                'rows_skipped',
                'from_date',
                'to_date',
                'total_sale_amount',
                'total_commission_amount',
                'total_withholding_amount',
                'total_income_amount',
                'total_debit_amount',
                'total_credit_amount',
                'created_at',
                'note',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('bank')) {
            $query->where('bank', $request->string('bank')->toString());
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->date('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->date('to_date'));
        }

        return response()->json($query->get());
    }

    public function show(int $id)
    {
        $batch = BankImportBatch::findOrFail($id);
        $movementsQuery = BankMovement::where('batch_id', $batch->id);

        return response()->json([
            'id' => $batch->id,
            'bank_id' => $batch->bank_id,
            'file_format_id' => $batch->file_format_id,
            'bank_account_id' => $batch->bank_account_id,
            'bank' => $batch->bank,
            'source_type' => $batch->source_type,
            'filename' => $batch->filename,
            'checksum' => $batch->checksum,
            'status' => $batch->status,
            'rows' => $batch->rows,
            'rows_imported' => $batch->rows_imported,
            'rows_skipped' => $batch->rows_skipped,
            'from_date' => optional($batch->from_date)->toDateString(),
            'to_date' => optional($batch->to_date)->toDateString(),
            'total_sale_amount' => $batch->total_sale_amount,
            'total_commission_amount' => $batch->total_commission_amount,
            'total_withholding_amount' => $batch->total_withholding_amount,
            'total_income_amount' => $batch->total_income_amount,
            'total_debit_amount' => $batch->total_debit_amount,
            'total_credit_amount' => $batch->total_credit_amount,
            'metadata' => $batch->metadata,
            'note' => $batch->note,
            'created_at' => optional($batch->created_at)->toISOString(),
            'movements_count' => (clone $movementsQuery)->count(),
            'first_movement_date' => (clone $movementsQuery)->min('movement_date'),
            'last_movement_date' => (clone $movementsQuery)->max('movement_date'),
            'movements_sample' => (clone $movementsQuery)
                ->select([
                    'id',
                    'bank_id',
                    'bank_account_id',
                    'row_number',
                    'bank',
                    'movement_date',
                    'deposit_date',
                    'transaction_code',
                    'reference',
                    'authorization_number',
                    'terminal',
                    'description',
                    'category',
                    'sale_amount',
                    'commission_amount',
                    'withholding_amount',
                    'income_amount',
                    'debit_amount',
                    'credit_amount',
                    'is_sale',
                    'is_income',
                    'is_expense',
                    'is_excluded',
                    'exclude_reason',
                ])
                ->orderBy('id')
                ->limit(200)
                ->get(),
        ]);
    }

    public function exportDavibank(Request $request, int $id, DavibankConverterService $converter): BinaryFileResponse
    {
        $validated = $request->validate([
            'receipt_start' => ['nullable', 'integer', 'min:1', 'max:999999999'],
        ]);

        try {
            $result = $converter->exportBatch(
                $id,
                isset($validated['receipt_start']) ? (int) $validated['receipt_start'] : null
            );

            return response()
                ->download($result['path'], $result['filename'], [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Davibank-Sheets' => (string) $result['sheets'],
                    'X-Davibank-Rows' => (string) $result['rows'],
                    'X-Davibank-Batch-Id' => (string) $result['batch_id'],
                ])
                ->deleteFileAfterSend(true);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function export(Request $request, int $id, BankImportExportService $service): BinaryFileResponse
    {
        $validated = $request->validate([
            'receipt_start' => ['nullable', 'integer', 'min:1', 'max:999999999'],
        ]);

        try {
            $result = $service->exportBatch(
                $id,
                isset($validated['receipt_start']) ? (int) $validated['receipt_start'] : null
            );

            return response()
                ->download($result['path'], $result['filename'], [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'X-Bank-Batch-Id' => (string) $result['batch_id'],
                    'X-Bank-Rows' => (string) $result['rows'],
                    'X-Bank-Sheets' => (string) $result['sheets'],
                ])
                ->deleteFileAfterSend(true);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        DB::connection('budget')->beginTransaction();

        try {
            $batch = BankImportBatch::findOrFail($id);
            $filename = $batch->filename;
            $storedPath = $batch->stored_path;

            BankMovement::where('batch_id', $batch->id)->delete();
            DB::connection('budget')->table('bank_daily_summaries')->where('batch_id', $batch->id)->delete();
            DB::connection('budget')->table('bank_cash_receipts')->where('batch_id', $batch->id)->delete();
            $batch->delete();

            $this->deletePhysicalFile($storedPath, $filename);

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Importacion bancaria eliminada.',
                'deleted' => 1,
                'batch_id' => $id,
            ]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();

            Log::error('Error eliminando importacion bancaria', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo eliminar la importacion bancaria',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:budget.bank_import_batches,id',
        ]);

        $ids = array_map('intval', $request->input('ids'));

        DB::connection('budget')->beginTransaction();

        try {
            $batches = BankImportBatch::whereIn('id', $ids)->get();

            foreach ($batches as $batch) {
                BankMovement::where('batch_id', $batch->id)->delete();
                DB::connection('budget')->table('bank_daily_summaries')->where('batch_id', $batch->id)->delete();
                DB::connection('budget')->table('bank_cash_receipts')->where('batch_id', $batch->id)->delete();
                $batch->delete();
                $this->deletePhysicalFile($batch->stored_path, $batch->filename);
            }

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Importaciones bancarias eliminadas.',
                'deleted' => $batches->count(),
                'requested' => count($ids),
            ]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();

            Log::error('Error eliminando importaciones bancarias en bloque', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error eliminando importaciones bancarias',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    private function deletePhysicalFile(?string $storedPath, ?string $filename): void
    {
        try {
            if ($storedPath && Storage::exists($storedPath)) {
                Storage::delete($storedPath);
                return;
            }

            if (!$filename) {
                return;
            }

            $safeName = basename($filename);
            if (!preg_match('/^[A-Za-z0-9_. -]+\.(xlsx|xls|xlsm|csv|txt)$/i', $safeName)) {
                Log::warning('Nombre de archivo bancario invalido, no se borra fisicamente', [
                    'filename' => $filename,
                ]);
                return;
            }

            $path = 'imports/banks/' . $safeName;
            if (Storage::exists($path)) {
                Storage::delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo borrar archivo fisico bancario', [
                'stored_path' => $storedPath,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
