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

    public function movementsAudit()
    {
        $summary = BankMovement::query()
            ->select('bank')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COUNT(DISTINCT movement_uid) as unique_uids')
            ->selectRaw("SUM(CASE WHEN movement_uid IS NULL OR movement_uid = '' THEN 1 ELSE 0 END) as missing_uid")
            ->groupBy('bank')
            ->orderBy('bank')
            ->get();

        $duplicateGroups = BankMovement::query()
            ->select('bank', 'movement_uid')
            ->selectRaw('COUNT(*) as duplicates_count')
            ->whereNotNull('movement_uid')
            ->groupBy('bank', 'movement_uid')
            ->having('duplicates_count', '>', 1)
            ->orderByDesc('duplicates_count')
            ->limit(50)
            ->get();

        $indexRows = DB::connection('budget')
            ->select("SHOW INDEX FROM bank_movements WHERE Key_name = 'bank_movements_bank_uid_unique'");

        return response()->json([
            'unique_index_exists' => count($indexRows) >= 2,
            'unique_index_columns' => array_values(array_map(fn ($row) => $row->Column_name ?? null, $indexRows)),
            'summary_by_bank' => $summary,
            'duplicate_uid_groups' => $duplicateGroups,
        ]);
    }

    public function movements(Request $request)
    {
        $validated = $request->validate([
            'bank' => ['nullable', 'string', 'in:davibank,colpatria,davivienda,bancolombia,bancodebogota'],
            'batch_id' => ['nullable', 'integer', 'exists:budget.bank_import_batches,id'],
            'movement_date_from' => ['nullable', 'date'],
            'movement_date_to' => ['nullable', 'date'],
            'movement_month' => ['nullable', 'date_format:Y-m'],
            'deposit_date_from' => ['nullable', 'date'],
            'deposit_date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $baseQuery = $this->movementFilterQuery($request);

        $byMonth = $this->movementFilterQuery($request, true)
            ->selectRaw("DATE_FORMAT(bank_movements.movement_date, '%Y-%m') as movement_month")
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COUNT(DISTINCT bank_movements.movement_date) as days_count')
            ->selectRaw('COUNT(DISTINCT bank_movements.bank) as banks_count')
            ->selectRaw('SUM(sale_amount) as sale_amount')
            ->selectRaw('SUM(commission_amount) as commission_amount')
            ->selectRaw('SUM(withholding_amount) as withholding_amount')
            ->selectRaw('SUM(income_amount) as income_amount')
            ->selectRaw("SUM(CASE WHEN movement_type = 'debit' OR sale_amount < 0 OR income_amount < 0 OR category = 'refund' THEN 1 ELSE 0 END) as refund_count")
            ->selectRaw("SUM(CASE WHEN category = 'acquisition' OR description LIKE '%ADQUIRE%' OR description LIKE '%APLICAD%' THEN 1 ELSE 0 END) as acquisition_count")
            ->selectRaw("SUM(CASE WHEN movement_uid IS NULL OR movement_uid = '' THEN 1 ELSE 0 END) as missing_uid")
            ->whereNotNull('bank_movements.movement_date')
            ->whereRaw('YEAR(bank_movements.movement_date) BETWEEN 2020 AND 2100')
            ->groupByRaw("DATE_FORMAT(bank_movements.movement_date, '%Y-%m')")
            ->orderByDesc('movement_month')
            ->limit(18)
            ->get();

        $totals = (clone $baseQuery)
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('SUM(sale_amount) as sale_amount')
            ->selectRaw('SUM(commission_amount) as commission_amount')
            ->selectRaw('SUM(withholding_amount) as withholding_amount')
            ->selectRaw('SUM(income_amount) as income_amount')
            ->selectRaw('SUM(debit_amount) as debit_amount')
            ->selectRaw('SUM(credit_amount) as credit_amount')
            ->first();

        $byDay = (clone $baseQuery)
            ->select('bank_movements.bank', 'bank_movements.movement_date')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('SUM(sale_amount) as sale_amount')
            ->selectRaw('SUM(commission_amount) as commission_amount')
            ->selectRaw('SUM(withholding_amount) as withholding_amount')
            ->selectRaw('SUM(income_amount) as income_amount')
            ->selectRaw("SUM(CASE WHEN movement_type = 'debit' OR sale_amount < 0 OR income_amount < 0 OR category = 'refund' THEN 1 ELSE 0 END) as refund_count")
            ->selectRaw("SUM(CASE WHEN category = 'acquisition' OR description LIKE '%ADQUIRE%' OR description LIKE '%APLICAD%' THEN 1 ELSE 0 END) as acquisition_count")
            ->selectRaw("SUM(CASE WHEN movement_uid IS NULL OR movement_uid = '' THEN 1 ELSE 0 END) as missing_uid")
            ->whereNotNull('bank_movements.movement_date')
            ->whereRaw('YEAR(bank_movements.movement_date) BETWEEN 2020 AND 2100')
            ->groupBy('bank_movements.bank', 'bank_movements.movement_date')
            ->orderByDesc('bank_movements.movement_date')
            ->limit(120)
            ->get();

        $byBank = (clone $baseQuery)
            ->select('bank_movements.bank')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('COUNT(DISTINCT movement_uid) as unique_uids')
            ->selectRaw("SUM(CASE WHEN movement_uid IS NULL OR movement_uid = '' THEN 1 ELSE 0 END) as missing_uid")
            ->selectRaw('SUM(sale_amount) as sale_amount')
            ->selectRaw('SUM(commission_amount) as commission_amount')
            ->selectRaw('SUM(withholding_amount) as withholding_amount')
            ->selectRaw('SUM(income_amount) as income_amount')
            ->groupBy('bank_movements.bank')
            ->orderBy('bank_movements.bank')
            ->get();

        $movements = (clone $baseQuery)
            ->select([
                'bank_movements.id',
                'bank_movements.batch_id',
                'bank_movements.bank',
                'bank_movements.movement_uid',
                'bank_movements.row_number',
                'bank_movements.movement_date',
                'bank_movements.process_date',
                'bank_movements.deposit_date',
                'bank_movements.account_number',
                'bank_movements.transaction_code',
                'bank_movements.reference',
                'bank_movements.receipt_number',
                'bank_movements.authorization_number',
                'bank_movements.terminal',
                'bank_movements.network',
                'bank_movements.card_type',
                'bank_movements.card_last_digits',
                'bank_movements.description',
                'bank_movements.movement_type',
                'bank_movements.category',
                'bank_movements.sale_amount',
                'bank_movements.commission_amount',
                'bank_movements.withholding_amount',
                'bank_movements.income_amount',
                'bank_movements.net_amount',
                'bank_import_batches.filename',
            ])
            ->orderByDesc('bank_movements.movement_date')
            ->orderByDesc('bank_movements.id')
            ->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => $movements->items(),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
            ],
            'totals' => $totals,
            'by_month' => $byMonth,
            'by_day' => $byDay,
            'by_bank' => $byBank,
        ]);
    }

    public function exportMovements(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'bank' => ['nullable', 'string', 'in:davibank,colpatria,davivienda,bancolombia,bancodebogota'],
            'batch_id' => ['nullable', 'integer', 'exists:budget.bank_import_batches,id'],
            'movement_date_from' => ['nullable', 'date'],
            'movement_date_to' => ['nullable', 'date'],
            'movement_month' => ['nullable', 'date_format:Y-m'],
            'deposit_date_from' => ['nullable', 'date'],
            'deposit_date_to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = $this->movementFilterQuery($request)
            ->select([
                'bank_movements.bank',
                'bank_movements.movement_uid',
                'bank_movements.movement_date',
                'bank_movements.process_date',
                'bank_movements.deposit_date',
                'bank_movements.account_number',
                'bank_movements.transaction_code',
                'bank_movements.reference',
                'bank_movements.receipt_number',
                'bank_movements.authorization_number',
                'bank_movements.terminal',
                'bank_movements.card_type',
                'bank_movements.card_last_digits',
                'bank_movements.description',
                'bank_movements.movement_type',
                'bank_movements.sale_amount',
                'bank_movements.commission_amount',
                'bank_movements.withholding_amount',
                'bank_movements.income_amount',
                'bank_movements.net_amount',
                'bank_import_batches.filename',
            ])
            ->orderBy('bank_movements.bank')
            ->orderBy('bank_movements.movement_date')
            ->orderBy('bank_movements.id');

        $filename = 'movimientos_bancarios_' . now('America/Bogota')->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');
            $dateText = fn ($value): string => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
            fputcsv($handle, [
                'Banco',
                'UID',
                'Fecha movimiento',
                'Fecha proceso',
                'Fecha deposito',
                'Cuenta',
                'Codigo',
                'Referencia',
                'Recibo',
                'Autorizacion',
                'Terminal',
                'Tipo tarjeta',
                'Ultimos digitos',
                'Descripcion',
                'Tipo movimiento',
                'Venta',
                'Comision',
                'Retencion',
                'Ingreso',
                'Neto',
                'Archivo',
            ]);

            $query->chunk(500, function ($rows) use ($handle): void {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->bank,
                        $row->movement_uid,
                        $dateText($row->movement_date),
                        $dateText($row->process_date),
                        $dateText($row->deposit_date),
                        $row->account_number,
                        $row->transaction_code,
                        $row->reference,
                        $row->receipt_number,
                        $row->authorization_number,
                        $row->terminal,
                        $row->card_type,
                        $row->card_last_digits,
                        $row->description,
                        $row->movement_type,
                        $row->sale_amount,
                        $row->commission_amount,
                        $row->withholding_amount,
                        $row->income_amount,
                        $row->net_amount,
                        $row->filename,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
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

    private function movementFilterQuery(Request $request, bool $ignoreMovementDates = false)
    {
        $query = BankMovement::query()
            ->leftJoin('bank_import_batches', 'bank_import_batches.id', '=', 'bank_movements.batch_id');

        if ($request->filled('bank')) {
            $query->where('bank_movements.bank', $request->string('bank')->toString());
        }

        if ($request->filled('batch_id')) {
            $query->where('bank_movements.batch_id', (int) $request->input('batch_id'));
        }

        if (! $ignoreMovementDates) {
            if ($request->filled('movement_date_from')) {
                $query->whereDate('bank_movements.movement_date', '>=', $request->date('movement_date_from'));
            }

            if ($request->filled('movement_date_to')) {
                $query->whereDate('bank_movements.movement_date', '<=', $request->date('movement_date_to'));
            }

            if (! $request->filled('movement_date_from') && ! $request->filled('movement_date_to') && $request->filled('movement_month')) {
                $month = \Carbon\Carbon::createFromFormat('Y-m', $request->string('movement_month')->toString());
                $query->whereDate('bank_movements.movement_date', '>=', $month->copy()->startOfMonth()->toDateString())
                    ->whereDate('bank_movements.movement_date', '<=', $month->copy()->endOfMonth()->toDateString());
            }
        }

        if ($request->filled('deposit_date_from')) {
            $query->whereDate('bank_movements.deposit_date', '>=', $request->date('deposit_date_from'));
        }

        if ($request->filled('deposit_date_to')) {
            $query->whereDate('bank_movements.deposit_date', '<=', $request->date('deposit_date_to'));
        }

        if ($request->filled('search')) {
            $search = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()) . '%';
            $query->where(function ($inner) use ($search): void {
                $inner->where('bank_movements.movement_uid', 'like', $search)
                    ->orWhere('bank_movements.reference', 'like', $search)
                    ->orWhere('bank_movements.receipt_number', 'like', $search)
                    ->orWhere('bank_movements.authorization_number', 'like', $search)
                    ->orWhere('bank_movements.terminal', 'like', $search)
                    ->orWhere('bank_movements.card_last_digits', 'like', $search)
                    ->orWhere('bank_movements.description', 'like', $search)
                    ->orWhere('bank_import_batches.filename', 'like', $search);
            });
        }

        return $query;
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
