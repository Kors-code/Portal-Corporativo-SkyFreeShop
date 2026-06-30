<?php

namespace App\Http\Controllers;

use App\Models\Comisiones\Product;
use App\Models\Comisiones\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use App\Models\Comisiones\ImportBatch;
use App\Models\Comisiones\Sale;
use App\Services\CommissionService;
use App\Services\WhatsappReportJobService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Throwable;

class ImportSalesController extends Controller
{

private function deletePreviousBatch($file)
{
        $originalName = $file->getClientOriginalName();

        /*
        SALES DFP COLOMBIA Marzo 2026.xlsx
        */

        preg_match(
            '/(Enero|Febrero|Marzo|Abril|Mayo|Junio|Julio|Agosto|Septiembre|Octubre|Noviembre|Diciembre)\s(\d{4})/i',
            $originalName,
            $matches
        );

        $monthName = $matches[1] ?? null;
        $year = $matches[2] ?? null;

        if (!$monthName || !$year) {
            return;
        }

        $company = stripos($originalName, 'DFP') !== false
            ? 'DFP'
            : 'LDC';

        $type = stripos($originalName, 'SALES') !== false
            ? 'SALES'
            : 'INVENTORY';

        $batches = ImportBatch::query()
            ->where('filename', 'LIKE', "%{$company}%")
            ->where('filename', 'LIKE', "%{$type}%")
            ->where('filename', 'LIKE', "%{$monthName}%")
            ->where('filename', 'LIKE', "%{$year}%")
            ->get();

        if ($batches->isEmpty()) {
            return;
        }

        DB::connection('budget')->transaction(function () use ($batches) {
            foreach ($batches as $batch) {
                $this->deleteSalesForBatch((int) $batch->id);
                $batch->delete();
            }
        });
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

    private function recalculateCommissionsForImportBatch(int $batchId): array
    {
        $salesQuery = DB::connection('budget')->table('sales')
            ->where('import_batch_id', $batchId);

        $dateRange = (clone $salesQuery)
            ->selectRaw('MIN(sale_date) as start_date, MAX(sale_date) as end_date')
            ->first();

        $budgetIds = [];

        if (Schema::connection('budget')->hasColumn('sales', 'budget_id')) {
            $budgetIds = (clone $salesQuery)
                ->whereNotNull('budget_id')
                ->distinct()
                ->pluck('budget_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();
        }

        if (empty($budgetIds) && !empty($dateRange?->start_date) && !empty($dateRange?->end_date)) {
            $budgetIds = DB::connection('budget')->table('budgets')
                ->where('start_date', '<=', $dateRange->end_date)
                ->where('end_date', '>=', $dateRange->start_date)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $budgetIds = array_values(array_unique($budgetIds));
        $service = app(CommissionService::class);
        $results = [];

        foreach ($budgetIds as $budgetId) {
            $results[$budgetId] = $service->generateForBudget((int) $budgetId);
        }

        Log::info('IMPORT SALES COMMISSIONS RECALCULATED', [
            'batch_id' => $batchId,
            'date_range' => [
                'start_date' => $dateRange->start_date ?? null,
                'end_date' => $dateRange->end_date ?? null,
            ],
            'budget_ids' => $budgetIds,
            'results' => $results,
        ]);

        return [
            'budget_ids' => $budgetIds,
            'results' => $results,
        ];
    }

    private function tryRecalculateCommissionsForImportBatch(int $batchId): array
    {
        try {
            return $this->recalculateCommissionsForImportBatch($batchId);
        } catch (Throwable $e) {
            Log::error('IMPORT SALES COMMISSIONS RECALC FAILED', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            DB::connection('budget')->table('import_batches')
                ->where('id', $batchId)
                ->update([
                    'note' => 'Importacion completada; fallo recalculo de comisiones: ' . Str::limit($e->getMessage(), 500),
                    'updated_at' => now(),
                ]);

            return [
                'budget_ids' => [],
                'results' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    private function recalculateInventoryMetricsForImportBatch(int $batchId): array
    {
        $storeIds = DB::connection('budget')->table('sales')
            ->where('import_batch_id', $batchId)
            ->whereNotNull('store_id')
            ->distinct()
            ->pluck('store_id')
            ->map(fn ($storeId) => (int) $storeId)
            ->filter()
            ->values()
            ->all();

        foreach ($storeIds as $storeId) {
            Artisan::call('inventory:metrics', ['--store_id' => $storeId]);
        }

        Log::info('IMPORT SALES INVENTORY METRICS RECALCULATED', [
            'batch_id' => $batchId,
            'store_ids' => $storeIds,
        ]);

        return [
            'store_ids' => $storeIds,
        ];
    }

    private function tryRecalculateInventoryMetricsForImportBatch(int $batchId): array
    {
        try {
            return $this->recalculateInventoryMetricsForImportBatch($batchId);
        } catch (Throwable $e) {
            Log::error('IMPORT SALES INVENTORY METRICS RECALC FAILED', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'store_ids' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    private function reportDateForImportBatch(int $batchId): ?string
    {
        $date = DB::connection('budget')->table('sales')
            ->where('import_batch_id', $batchId)
            ->max('sale_date');

        return $date ? (new \DateTimeImmutable((string) $date))->format('Y-m-d') : null;
    }

    private function tryQueueStoreSalesWhatsappForImportBatch(int $batchId, ?int $expectedRows = null, array $lastSummary = []): array
    {
        try {
            $quality = $this->importQualityForWhatsapp($batchId, $expectedRows, $lastSummary);

            if (!$quality['ok']) {
                Log::warning('IMPORT SALES STORE WHATSAPP NOT QUEUED', [
                    'batch_id' => $batchId,
                    'quality' => $quality,
                ]);

                return [
                    'ok' => true,
                    'queued' => false,
                    'reason' => $quality['reason'],
                    'quality' => $quality,
                ];
            }

            $date = $this->reportDateForImportBatch($batchId);
            $job = app(WhatsappReportJobService::class)->enqueue('store_sales', $date, [
                'import_batch_id' => $batchId,
            ]);

            Log::info('IMPORT SALES STORE WHATSAPP QUEUED', [
                'batch_id' => $batchId,
                'job_id' => $job->id,
                'date' => $date,
            ]);

            return [
                'ok' => true,
                'queued' => true,
                'job_id' => $job->id,
                'date' => $date,
            ];
        } catch (Throwable $e) {
            Log::error('IMPORT SALES STORE WHATSAPP QUEUE FAILED', [
                'batch_id' => $batchId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function importQualityForWhatsapp(int $batchId, ?int $expectedRows = null, array $lastSummary = []): array
    {
        $batch = DB::connection('budget')->table('import_batches')
            ->select('status')
            ->where('id', $batchId)
            ->first();

        $insertedRows = (int) DB::connection('budget')->table('sales')
            ->where('import_batch_id', $batchId)
            ->count();

        $skippedRows = (int) ($lastSummary['skipped'] ?? 0);
        $errorsCount = count($lastSummary['errors'] ?? []);

        if (($batch->status ?? null) !== 'done') {
            return [
                'ok' => false,
                'reason' => 'La importacion no quedo completada.',
                'status' => $batch->status ?? null,
                'expected_rows' => $expectedRows,
                'inserted_rows' => $insertedRows,
                'skipped_rows' => $skippedRows,
                'errors_count' => $errorsCount,
            ];
        }

        if ($errorsCount > 0) {
            return [
                'ok' => false,
                'reason' => 'La importacion termino, pero tuvo errores de filas.',
                'status' => $batch->status ?? null,
                'expected_rows' => $expectedRows,
                'inserted_rows' => $insertedRows,
                'skipped_rows' => $skippedRows,
                'errors_count' => $errorsCount,
            ];
        }

        if ($insertedRows <= 0) {
            return [
                'ok' => false,
                'reason' => 'La importacion no genero ventas para reportar.',
                'status' => $batch->status ?? null,
                'expected_rows' => $expectedRows,
                'inserted_rows' => $insertedRows,
                'skipped_rows' => $skippedRows,
                'errors_count' => $errorsCount,
            ];
        }

        return [
            'ok' => true,
            'reason' => 'Importacion completa.',
            'status' => $batch->status ?? null,
            'expected_rows' => $expectedRows,
            'inserted_rows' => $insertedRows,
            'skipped_rows' => $skippedRows,
            'errors_count' => $errorsCount,
        ];
    }

    protected function logSkip(int $row, string $reason, array $context = []): void
    {
        Log::warning('IMPORT SKIP', [
            'row' => $row,
            'reason' => $reason,
            'folio' => $context['folio'] ?? null,
            'store_excel' => $context['store_excel'] ?? null,
            'store_selected' => $context['store_selected'] ?? null,
            'sku' => $context['sku'] ?? null,
            'seller' => $context['seller'] ?? null,
            'cashier' => $context['cashier'] ?? null,
            'date' => $context['date'] ?? null,
            'amount_pes' => $context['amount_pes'] ?? null,
            'amount_usd' => $context['amount_usd'] ?? null,
            'trm' => $context['trm'] ?? null,
        ]);
    }

    protected function rememberDailyTrm(array &$dailyTrms, ?string $saleDate, ?float $exchangeRate): void
    {
        if (!$saleDate || $exchangeRate === null || $exchangeRate <= 0 || isset($dailyTrms[$saleDate])) {
            return;
        }

        $dailyTrms[$saleDate] = $exchangeRate;
    }

    protected function persistDailyTrms(array $dailyTrms): void
    {
        if (empty($dailyTrms) || !Schema::connection('budget')->hasTable('trms')) {
            return;
        }

        $hasCreatedAt = Schema::connection('budget')->hasColumn('trms', 'created_at');
        $hasUpdatedAt = Schema::connection('budget')->hasColumn('trms', 'updated_at');

        foreach ($dailyTrms as $date => $value) {
            $query = DB::connection('budget')->table('trms')->where('date', $date);
            $payload = ['value' => $value];

            if ($query->exists()) {
                if ($hasUpdatedAt) {
                    $payload['updated_at'] = now();
                }

                $query->update($payload);
                continue;
            }

            $payload['date'] = $date;

            if ($hasCreatedAt) {
                $payload['created_at'] = now();
            }

            if ($hasUpdatedAt) {
                $payload['updated_at'] = now();
            }

            DB::connection('budget')->table('trms')->insert($payload);
        }
    }

    protected function resolveStoreIdFromPDV(?string $pdv, int $fallbackStoreId): int
{
    if (!$pdv) {
        return $fallbackStoreId;
    }

    $pdv = strtoupper(trim($pdv));

    //  MAPEO
    $map = [
        'COLS1' => 1, // MDE_Departures
        'COLS2' => 2, // MDE_ARR
        'COLS3' => 3, // CTG
        'COLS4' => 4, // LDC MDE  
        'COLS6' => 5, // LDC CALI
        'COLZ1' => 6, // ZONA FRANCA
        
        ];

    return $map[$pdv] ?? $fallbackStoreId;
}


    protected function normalizeHora(?string $horaRaw): ?string
    {
        if (!$horaRaw) {
            return null;
        }

        $clean = preg_replace('/\b(?:a\.m\.|p\.m\.|am|pm)\b/i', '', $horaRaw);
        $clean = trim((string) $clean);

        if ($clean === '') {
            return null;
        }

        try {
            return Carbon::parse($clean)->format('H:i:s');
        } catch (Throwable $e) {
            return null;
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

    protected function firstNotEmpty(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (!isset($row[$k])) {
                continue;
            }

            $v = trim((string) $row[$k]);

            if ($v !== '' && strtolower($v) !== 'null') {
                return $v;
            }
        }

        return null;
    }

    protected function parseNumber($v): ?float
    {
        if ($v === null) {
            return null;
        }

        $s = trim((string) $v);
        if ($s === '' || strtolower($s) === 'null') {
            return null;
        }

        $s = str_replace(["\xc2\xa0", ' '], '', $s);

        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace(',', '', $s);
        } elseif (strpos($s, ',') !== false) {
            $s = str_replace(',', '.', $s);
        }

        $s = preg_replace('/[^\d\.\-]/', '', $s);

        return is_numeric($s) ? (float) $s : null;
    }
protected function parseDate($value, string $context = 'sale'): ?string
{
    if ($value === null) {
        return null;
    }

    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d');
    }

    $text = trim((string) $value);

    if ($text === '' || strtolower($text) === 'null') {
        return null;
    }

    try {
        if (is_numeric($text) && (float) $text > 10000) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $text)
                ->format('Y-m-d');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return $text;
        }

        if (preg_match('/^\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4}$/', $text)) {
            $separator = str_contains($text, '-') ? '-' : '/';
            [$part1, $part2, $year] = array_map('intval', preg_split('/[\/\-]/', $text));

            if ($part1 > 12) {
                $format = $separator === '/' ? 'd/m/Y' : 'd-m-Y';
            } elseif ($part2 > 12) {
                $format = $separator === '/' ? 'm/d/Y' : 'm-d-Y';
            } else {
                $format = $context === 'birth'
                    ? ($separator === '/' ? 'd/m/Y' : 'd-m-Y')
                    : ($separator === '/' ? 'm/d/Y' : 'm-d-Y');
            }

            $normalized = sprintf(
                '%02d%s%02d%s%04d',
                $part1,
                $separator,
                $part2,
                $separator,
                $year
            );

            $dt = \DateTime::createFromFormat('!' . $format, $normalized);

            if ($dt) {
                $errors = \DateTime::getLastErrors();

                if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                    return $dt->format('Y-m-d');
                }

            }

            Log::warning('DATE DEBUG REJECTED', [
                'context' => $context,
                'input' => $text,
                'format' => $format,
            ]);

            return null;
        }

        return Carbon::parse($text)->format('Y-m-d');
    } catch (\Throwable $e) {
        Log::warning('DATE PARSE ERROR', [
            'context' => $context,
            'input' => $text,
            'error' => $e->getMessage(),
        ]);

        return null;
    }
}
    protected function parseDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '' || strtolower($text) === 'null') {
            return null;
        }

        try {
            return Carbon::parse($text)->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function normalizeStoreCode(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = mb_strtoupper(trim($value));
        $value = preg_replace('/\s+/', '', $value);

        return $value !== '' ? $value : null;
    }

    protected function rowHasValues(array $row): bool
    {
        foreach ($row as $v) {
            $text = trim((string) $v);
            if ($text !== '' && strtolower($text) !== 'null') {
                return true;
            }
        }

        return false;
    }

    protected function normalizeProductLookupCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        if (preg_match('/^\d+\.0+$/', $value)) {
            $value = preg_replace('/\.0+$/', '', $value);
        }

        return $value;
    }

    protected function limitText($value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '' || strtolower($text) === 'null') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }

    protected function resolveSellerId(array $assoc, array &$usersCache, int $defaultSellerId, array &$created): int
    {
        $sellerName = $this->firstNotEmpty($assoc, ['seller', 'vendedor', 'vendor']);
        $sellerCode = $this->firstNotEmpty($assoc, ['seller_code', 'codigo_vendedor', 'codigovendedor']);

        if ($sellerCode) {
            $cacheKey = 'code_' . $sellerCode;

            if (isset($usersCache[$cacheKey])) {
                return (int) $usersCache[$cacheKey]->id;
            }

            $foundByCode = User::on('budget')->where('codigo_vendedor', $sellerCode)->first();
            if ($foundByCode) {
                $usersCache[$cacheKey] = $foundByCode;
                return (int) $foundByCode->id;
            }
        }

        if ($sellerName) {
            $email = strtolower(Str::slug($sellerName) . '@local');

            if (isset($usersCache[$email])) {
                return (int) $usersCache[$email]->id;
            }

            $foundByName = User::on('budget')->where('name', $sellerName)->first();
            if ($foundByName) {
                $usersCache[$email] = $foundByName;
                return (int) $foundByName->id;
            }

            $usersCache[$email] = User::on('budget')->updateOrCreate(
                ['email' => $this->limitText($email, 255)],
                [
                    'name' => $this->limitText($sellerName, 255),
                    'codigo_vendedor' => $sellerCode,
                ]
            );

            if ($usersCache[$email]->wasRecentlyCreated) {
                $created['users']++;
            }

            return (int) $usersCache[$email]->id;
        }

        return $defaultSellerId;
    }

    protected function resolvePassengerId(array $assoc): ?int
    {
        $passportNumber = $this->firstNotEmpty($assoc, ['passport_number', 'pasaporte', 'passport']);
        $passengerName = $this->firstNotEmpty($assoc, ['passenger_name', 'pasajero', 'passenger']);
        $customerCode = $this->firstNotEmpty($assoc, ['customer_code', 'codcliente', 'codigo_cliente']);
        $nationality = $this->firstNotEmpty($assoc, ['nationality', 'nacionalidad']);
        $dateBirth = $this->parseDate($this->firstNotEmpty($assoc, ['date_birth', 'fecha_nacimiento']));
        $gender = $this->firstNotEmpty($assoc, ['gender', 'genero', 'sexo']);

        if (!$passportNumber && !$passengerName && !$customerCode) {
            return null;
        }

        if ($passportNumber) {
            $existing = DB::connection('budget')->table('passengers')
                ->where('passport_number', $passportNumber)
                ->first();

            if ($existing) {
                DB::connection('budget')->table('passengers')
                    ->where('id', $existing->id)
                    ->update([
                        'passenger_name' => $this->limitText($passengerName ?: $existing->passenger_name, 255),
                        'customer_code' => $this->limitText($customerCode ?: $existing->customer_code, 100),
                        'nationality' => $this->limitText($nationality ?: $existing->nationality, 100),
                        'date_birth' => $dateBirth ?: $existing->date_birth,
                        'gender' => $this->limitText($gender ?: $existing->gender, 20),
                        'updated_at' => now(),
                    ]);

                return (int) $existing->id;
            }
        }

        $payload = [
            'passport_number' => $this->limitText($passportNumber ?: ('NO-PASS-' . Str::uuid()), 255),
            'passenger_name' => $this->limitText($passengerName ?: 'SIN NOMBRE', 255),
            'customer_code' => $this->limitText($customerCode, 100),
            'nationality' => $this->limitText($nationality, 100),
            'date_birth' => $dateBirth,
            'gender' => $this->limitText($gender, 20),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        return (int) DB::connection('budget')->table('passengers')->insertGetId($payload);
    }

    protected function resolveItineraryId(array $assoc): ?int
    {
        $lineCode = $this->firstNotEmpty($assoc, ['line', 'linea']);
        $flightCruise = $this->firstNotEmpty($assoc, ['flight_cruise', 'vuelo', 'flight']);
        $origin = $this->firstNotEmpty($assoc, ['origin', 'origen']);
        $destination = $this->firstNotEmpty($assoc, ['destination', 'destino']);

        if (!$lineCode && !$flightCruise && !$origin && !$destination) {
            return null;
        }

        $existing = DB::connection('budget')->table('travel_itineraries')
            ->where('line_code', $lineCode)
            ->where('flight_cruise', $flightCruise)
            ->where('origin', $origin)
            ->where('destination', $destination)
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::connection('budget')->table('travel_itineraries')->insertGetId([
            'line_code' => $this->limitText($lineCode, 50),
            'flight_cruise' => $this->limitText($flightCruise, 50),
            'origin' => $this->limitText($origin, 50),
            'destination' => $this->limitText($destination, 50),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function resolveProduct(array $assoc, array &$productsCache, array &$created): ?Product
    {
        $sku = $this->normalizeProductLookupCode(
            $this->firstNotEmpty($assoc, ['sku', 'codigo', 'product_code', 'sku_code', 'upc', 'barcode', 'ean'])
        );

        if (!$sku) {
            return null;
        }

        if (!array_key_exists($sku, $productsCache)) {
            $productsCache[$sku] = Product::on('budget')
                ->where(function ($query) use ($sku) {
                    $query->where('product_code', $sku)
                        ->orWhere('sku_mia', $sku)
                        ->orWhere('upc', $sku)
                        ->orWhere('upc2', $sku)
                        ->orWhere('upc3', $sku);
                })
                ->first();
        }

        if (!$productsCache[$sku]) {
            $description = $this->firstNotEmpty($assoc, [
                'product_description',
                'descripcion',
                'description',
                'product',
                'producto',
            ]);

            $classification = $this->firstNotEmpty($assoc, ['category_code', 'classification', 'categoria']);
            $classificationDesc = $this->firstNotEmpty($assoc, ['category_description', 'classification_desc', 'category']);
            $brand = $this->firstNotEmpty($assoc, ['brand_description', 'brand', 'marca']);
            $regularPrice = $this->parseNumber($this->firstNotEmpty($assoc, [
                'retail_price',
                'regular_price',
                'precio_regular',
                'precio',
            ]));
            $costUsd = $this->parseNumber($this->firstNotEmpty($assoc, [
                'cost_unit_usd',
                'cogs_usd',
                'cost_usd',
                'costo_usd',
            ]));

            $productsCache[$sku] = Product::on('budget')->create([
                'product_code' => $this->limitText($sku, 255),
                'description' => $description ?: 'Producto importado sin catalogo',
                'classification' => $this->limitText($classification, 255),
                'classification_desc' => $this->limitText($classificationDesc, 255),
                'brand' => $this->limitText($brand, 255),
                'regular_price' => $regularPrice,
                'cost_usd' => $costUsd,
                'avg_cost_usd' => $costUsd,
                'currency' => $this->limitText($this->firstNotEmpty($assoc, ['currency', 'moneda']) ?? 'USD', 255),
            ]);

            $created['products']++;
        }

        return $productsCache[$sku];
    }

    public function import(Request $request)
    {
        if ($request->boolean('chunked', true)) {
            return $this->startChunked($request);
        }

        if (!$request->hasFile('file')) {
            return response()->json([
                'message' => 'Archivo requerido'
            ], 422);
        }
        if ($request->boolean('replace_existing')) {
            $this->deletePreviousBatch(
                $request->file('file')
            );
        }

        $file = $request->file('file');
        $selectedStoreId = (int) $request->store_id;

        $selectedStore = DB::connection('budget')->table('stores')
            ->select('id', 'code', 'name')
            ->where('id', $selectedStoreId)
            ->first();


        $checksum = hash('sha256', file_get_contents($file->getRealPath()));

        $existingBatch = DB::connection('budget')->table('import_batches')
            ->where('checksum', $checksum)
            ->first();

        if ($existingBatch) {
            return response()->json([
                'message' => 'Archivo ya importado',
                'batch_id' => $existingBatch->id,
            ], 409);
        }

        $batchId = DB::connection('budget')->table('import_batches')->insertGetId([
            'filename' => $file->getClientOriginalName(),
            'checksum' => $checksum,
            'import_date' => now()->toDateString(),
            'rows' => 0,
            'status' => 'processing',
            'note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('IMPORT SALES START', [
            'batch_id' => $batchId,
            'store_id' => $selectedStoreId,
            'store_code' => $selectedStore->code ?? null,
            'store_name' => $selectedStore->name ?? null,
            'filename' => $file->getClientOriginalName(),
        ]);

        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        } catch (Throwable $e) {
            DB::connection('budget')->table('import_batches')
                ->where('id', $batchId)
                ->update([
                    'status' => 'failed',
                    'note' => $this->formatImportFailureNote($e),
                    'updated_at' => now(),
                ]);

            Log::error('IMPORT SALES SHEET LOAD FAILED', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], 500);
        }

        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();

        $headerRange = $sheet->rangeToArray(
            'A1:' . $highestColumn . '1',
            null,
            true,
            true,
            true
        );

        $headerRaw = $headerRange ? reset($headerRange) : false;

        if (!$headerRaw || count(array_filter($headerRaw)) === 0) {
            DB::connection('budget')->table('import_batches')
                ->where('id', $batchId)
                ->update([
                    'status' => 'failed',
                    'note' => 'El archivo no contiene encabezados válidos en la fila 1',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'error' => 'El archivo no tiene encabezados válidos en la fila 1',
            ], 422);
        }

        $headers = [];
        foreach ($headerRaw as $col => $value) {
            $headers[$col] = $this->normalizeHeader((string) $value);
        }

        Log::info('IMPORT SALES HEADERS DETECTED', [
            'batch_id' => $batchId,
            'headers' => $headers,
        ]);

        $processed = 0;
        $skipped = 0;
        $created = ['products' => 0, 'users' => 0, 'sales' => 0];
        $errors = [];

        $productsCache = [];
        $usersCache = [];
        $passengersCache = [];
        $itinerariesCache = [];

        $chunkSize = 500;
        $salesBuffer = [];
        $dailyTrms = [];
        $totalRowsExcel = max(0, $highestRow - 1);

        $defaultSeller = User::on('budget')->find(40) ?: User::on('budget')->orderBy('id')->first();
        if (!$defaultSeller) {
            DB::connection('budget')->table('import_batches')
                ->where('id', $batchId)
                ->update([
                    'status' => 'failed',
                    'note' => 'No existe un usuario vendedor por defecto.',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'message' => 'No existe un usuario vendedor por defecto.',
            ], 422);
        }

        $defaultSellerId = (int) $defaultSeller->id;

        for ($start = 2; $start <= $highestRow; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $highestRow);

            DB::connection('budget')->beginTransaction();

            try {
                for ($row = $start; $row <= $end; $row++) {
                    try {
                        $range = $sheet->rangeToArray(
                            "A{$row}:{$highestColumn}{$row}",
                            null,
                            true,
                            true,
                            true
                        );

                        $rowData = $range ? reset($range) : false;

                        if (!$rowData || !$this->rowHasValues($rowData)) {
                            $skipped++;
                            $this->logSkip($row, 'empty_row');
                            continue;
                        }

                        $assoc = [];
                        foreach ($rowData as $c => $v) {
                            if (isset($headers[$c])) {
                                $assoc[$headers[$c]] = trim((string) $v);
                            }
                        }

                        if ($row === 2) {
                            Log::info('IMPORT SALES FIRST ROW MAPPED', [
                                'batch_id' => $batchId,
                                'row' => $row,
                                'mapped' => $assoc,
                            ]);
                        }

                        $saleDateRaw = $this->firstNotEmpty($assoc, ['date', 'fecha']);
                        $saleDate = $this->parseDate($saleDateRaw) ?? now()->toDateString();

                        $horaRaw = $this->firstNotEmpty($assoc, ['time', 'hora']);
                        $hora = $this->normalizeHora($horaRaw) ?? '00:00:00';

                        $storeCodeExcel = $this->normalizeStoreCode(
                            $this->firstNotEmpty($assoc, ['store', 'pdv', 'store_code'])
                        );
                        $storeIdFinal = $this->resolveStoreIdFromPDV($storeCodeExcel, $selectedStoreId);
                        

                        $sellerId = $this->resolveSellerId($assoc, $usersCache, $defaultSellerId, $created);

                        $cashierName = $this->firstNotEmpty($assoc, ['cashier', 'cajero']) ?: '';
                        $cashierId = null;

                        if ($cashierName !== '') {
                            $cashierEmail = strtolower(Str::slug($cashierName) . '@local');

                            if (!isset($usersCache[$cashierEmail])) {
                                $usersCache[$cashierEmail] = User::on('budget')->firstOrCreate(
                                    ['email' => $cashierEmail],
                                    ['name' => $cashierName]
                                );

                                if ($usersCache[$cashierEmail]->wasRecentlyCreated) {
                                    $created['users']++;
                                }
                            }

                            $cashierId = $usersCache[$cashierEmail]->id;
                        }

                        $product = $this->resolveProduct($assoc, $productsCache, $created);
                        $sku = $this->firstNotEmpty($assoc, ['sku', 'codigo', 'product_code', 'sku_code']);

                        if (!$product) {
                            $skipped++;
                            $this->logSkip($row, 'product_not_found', [
                                'sku' => $sku,
                                'date' => $saleDate,
                                'store_selected' => $selectedStore->code ?? null,
                                'store_excel' => $storeIdFinal,
                            ]);
                            continue;
                        }

                        $qty = $this->parseNumber($this->firstNotEmpty($assoc, ['quantity', 'cantidad', 'qty'])) ?? 1;

                        $cogsPes = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'cogs_pes',
                            'costo_de_venta',
                            'cost',
                            'costo',
                            'costo_venta',
                        ]));

                        $cogsUsd = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'cogs_usd',
                            'costo_de_venta_usd',
                            'cost_usd',
                        ]));

                        $exchangeRateCogs = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'exchange_rate_cogs',
                            't_cambio_costo',
                            'tipo_de_cambio_costo',
                        ]));

                        $amountPes = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'amount_pes',
                            'amount',
                            'valor_en_pesos',
                            'value_pesos',
                            'total',
                            'precio_total',
                        ]));

                        $amountUsd = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'amount_usd',
                            'valor_dolares',
                            'value_usd',
                        ]));

                        $exchangeRateSale = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'exchange_rate_sale',
                            'tipo_de_cambio',
                            'tipo_cambio',
                            'exchange_rate',
                        ]));

                        $regularPrice = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'precio_regular',
                            'regular_price',
                        ]));

                        $discount = $this->parseNumber($this->firstNotEmpty($assoc, [
                            'discount',
                            'descuento',
                        ])) ?? 0;

                        $total = $this->parseNumber($this->firstNotEmpty($assoc, ['total']));

                        $customerCode = $this->firstNotEmpty($assoc, ['customer_code', 'codcliente', 'cod_cliente']);
                        $currency = $this->firstNotEmpty($assoc, ['currency', 'moneda']) ?? 'USD';
                        $status = $this->firstNotEmpty($assoc, ['status', 'estatus']) ?? 'ACTIVO';
                        $cancellationDate = $this->parseDateTime($this->firstNotEmpty($assoc, ['cancellation_date', 'fecha_cancelacion']));
                        $appliedPromotion = $this->firstNotEmpty($assoc, ['applied_promotion', 'promociones_aplicadas']);

                        $lineCode = $this->firstNotEmpty($assoc, ['line', 'linea']);
                        $flightCruise = $this->firstNotEmpty($assoc, ['flight_cruise', 'vuelo', 'flight']);
                        $seat = $this->firstNotEmpty($assoc, ['seat', 'asiento']);
                        $origin = $this->firstNotEmpty($assoc, ['origin', 'origen']);
                        $destination = $this->firstNotEmpty($assoc, ['destination', 'destino']);
                        $nationality = $this->firstNotEmpty($assoc, ['nationality', 'nacionalidad']);
                        $passportNumber = $this->firstNotEmpty($assoc, ['passport_number', 'pasaporte', 'passport']);
                        $passengerName = $this->firstNotEmpty($assoc, ['passenger_name', 'pasajero', 'passenger']);
                        $dateBirth = $this->parseDate($this->firstNotEmpty($assoc, ['date_birth', 'fecha_nacimiento']));
                        $gender = $this->firstNotEmpty($assoc, ['gender', 'genero']);

                        if ($amountPes === null && $amountUsd !== null && $exchangeRateSale !== null) {
                            $amountPes = round($amountUsd * $exchangeRateSale, 2);
                        }

                        if ($amountPes === null && $total !== null) {
                            $amountPes = $total;
                        }

                        if ($amountUsd === null && $amountPes !== null && $exchangeRateSale !== null && $exchangeRateSale > 0) {
                            $amountUsd = round($amountPes / $exchangeRateSale, 2);
                        }

                        if ($exchangeRateCogs === null && $exchangeRateSale !== null) {
                            $exchangeRateCogs = $exchangeRateSale;
                        }

                        if ($amountPes === null && $amountUsd === null) {
                            $skipped++;
                            $this->logSkip($row, 'missing_amounts', [
                                'sku' => $sku,
                                'date' => $saleDate,
                            ]);
                            continue;
                        }

                        $this->rememberDailyTrm($dailyTrms, $saleDate, $exchangeRateSale ?? $exchangeRateCogs);

                        $passengerId = null;
                        if ($passportNumber || $passengerName || $customerCode) {
                            $passengerKey = $passportNumber ?: md5(($passengerName ?? '') . '|' . ($customerCode ?? ''));

                            if (!array_key_exists($passengerKey, $passengersCache)) {
                                $passengersCache[$passengerKey] = $this->resolvePassengerId([
                                    'passport_number' => $passportNumber,
                                    'passenger_name' => $passengerName,
                                    'customer_code' => $customerCode,
                                    'nationality' => $nationality,
                                    'date_birth' => $dateBirth,
                                    'gender' => $gender,
                                ]);
                            }

                            $passengerId = $passengersCache[$passengerKey];
                        }

                        $itineraryId = null;
                        if ($lineCode || $flightCruise || $origin || $destination) {
                            $itineraryKey = md5(($lineCode ?? '') . '|' . ($flightCruise ?? '') . '|' . ($origin ?? '') . '|' . ($destination ?? ''));

                            if (!array_key_exists($itineraryKey, $itinerariesCache)) {
                                $itinerariesCache[$itineraryKey] = $this->resolveItineraryId([
                                    'line' => $lineCode,
                                    'flight_cruise' => $flightCruise,
                                    'origin' => $origin,
                                    'destination' => $destination,
                                ]);
                            }

                            $itineraryId = $itinerariesCache[$itineraryKey];
                        }

                        $saleDatetime = $saleDate . ' ' . $hora;

                        $payload = [
                            'import_batch_id' => $batchId,
                            'store_id' => $storeIdFinal,

                            'seller_id' => $sellerId,
                            'product_id' => $product->id,
                            'passenger_id' => $passengerId,
                            'travel_itinerary_id' => $itineraryId,

                            'sale_date' => $saleDate,
                            'sale_datetime' => $saleDatetime,
                            'hora' => $hora,

                            'folio' => $this->limitText($this->firstNotEmpty($assoc, ['folio']), 255),
                            'pdv' => $this->limitText($storeCodeExcel ?: ($selectedStore->code ?? null), 255),

                            'quantity' => $qty,
                            'amount' => $amountPes,
                            'discount' => $discount,
                            'total' => $total ?? $amountPes,
                            'value_pesos' => $amountPes,
                            'value_usd' => $amountUsd,
                            'cost' => $cogsPes,
                            'currency' => $this->limitText($currency, 255),
                            'exchange_rate' => $exchangeRateSale ?? $exchangeRateCogs,
                            'amount_cop' => $amountPes,
                            'status' => $this->limitText($status, 255),
                            'applied_promotion' => $this->limitText($appliedPromotion, 255),
                            'cancellation_date' => $cancellationDate,

                            'line_code' => $this->limitText($lineCode, 50),
                            'seat' => $this->limitText($seat, 20),
                            'passport_number' => $this->limitText($passportNumber, 50),
                            'passenger_name' => $this->limitText($passengerName, 255),
                            'date_birth' => $dateBirth,
                            'gender' => $this->limitText($gender, 20),
                            'customer_code' => $this->limitText($customerCode, 100),

                            'raw_payload' => json_encode($assoc, JSON_UNESCAPED_UNICODE),
                            'cashier' => $this->limitText($cashierName, 255) ?? '',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        if (Schema::connection('budget')->hasColumn('sales', 'cashier_id')) {
                            $payload['cashier_id'] = $cashierId;
                        }

                        $salesBuffer[] = $payload;
                        $processed++;

                        if (count($salesBuffer) >= 500) {
                            DB::connection('budget')->table('sales')->insert($salesBuffer);
                            $created['sales'] += count($salesBuffer);
                            $salesBuffer = [];
                        }
                    } catch (Throwable $rowEx) {
                        $skipped++;

                        $errors[] = [
                            'row' => $row,
                            'error' => $rowEx->getMessage(),
                        ];

                        Log::error('IMPORT ROW ERROR', [
                            'batch_id' => $batchId,
                            'row' => $row,
                            'error' => $rowEx->getMessage(),
                            'trace' => $rowEx->getTraceAsString(),
                        ]);
                    }
                }

                if (!empty($salesBuffer)) {
                    DB::connection('budget')->table('sales')->insert($salesBuffer);
                    $created['sales'] += count($salesBuffer);
                    $salesBuffer = [];
                }

                $this->persistDailyTrms($dailyTrms);

                DB::connection('budget')->commit();
            } catch (Throwable $e) {
                DB::connection('budget')->rollBack();

                $errors[] = [
                    'chunk' => "{$start}-{$end}",
                    'error' => $e->getMessage(),
                ];

                Log::error("IMPORT CHUNK FAILED {$start}-{$end}", [
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        DB::connection('budget')->table('import_batches')
            ->where('id', $batchId)
            ->update([
                'status' => 'done',
                'rows' => $created['sales'],
                'updated_at' => now(),
            ]);

        Log::info('IMPORT FINISHED', [
            'batch_id' => $batchId,
            'store_id' => $selectedStoreId,
            'total_rows_excel' => $totalRowsExcel,
            'processed_rows_read' => $processed,
            'inserted_sales' => $created['sales'],
            'skipped_rows' => $skipped,
            'errors_count' => count($errors),
        ]);

        $commissionRecalc = $this->tryRecalculateCommissionsForImportBatch((int) $batchId);
        $storeWhatsapp = $this->tryQueueStoreSalesWhatsappForImportBatch((int) $batchId, $totalRowsExcel, [
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return response()->json([
            'message' => 'Importación completada',
            'processed' => $processed,
            'skipped' => $skipped,
            'created' => $created,
            'errors' => $errors,
            'batch_id' => $batchId,
            'commission_recalc' => $commissionRecalc,
            'store_whatsapp' => $storeWhatsapp,
        ]);
    }

    public function startChunked(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['message' => 'Archivo requerido'], 422);
        }

        if ($validationResponse = $this->validateSalesImportInput($request, true)) {
            return $validationResponse;
        }

        if ($request->boolean('replace_existing')) {
            $this->deletePreviousBatch($request->file('file'));
        }

        $file = $request->file('file');
        $selectedStoreId = (int) $request->store_id;
        $pendingChecksum = 'pending:' . Str::uuid()->toString();

        $batchId = DB::connection('budget')->table('import_batches')->insertGetId([
            'filename' => $file->getClientOriginalName(),
            'checksum' => $pendingChecksum,
            'import_date' => now()->toDateString(),
            'rows' => 0,
            'status' => 'processing',
            'note' => 'Preparando archivo de ventas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Log::info('IMPORT SALES CHUNKED START PREPARE', [
                'batch_id' => $batchId,
                'store_id' => $selectedStoreId,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]);

            $token = uniqid('sales_', true);
            $extension = $file->getClientOriginalExtension() ?: 'xlsx';
            $path = $file->storeAs('sales-imports', "{$token}.{$extension}");
            $fullPath = Storage::path($path);

            $checksum = hash_file('sha256', $fullPath);
            $existingBatch = DB::connection('budget')->table('import_batches')
                ->where('checksum', $checksum)
                ->where('id', '<>', $batchId)
                ->first();

            if ($existingBatch) {
                Storage::delete($path);

                DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
                    'status' => 'failed',
                    'checksum' => $pendingChecksum,
                    'note' => 'Archivo ya importado en batch ' . $existingBatch->id,
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'message' => 'Archivo ya importado',
                    'batch_id' => $existingBatch->id,
                ], 409);
            }

            try {
                DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
                    'checksum' => $checksum,
                    'updated_at' => now(),
                ]);
            } catch (Throwable $duplicateChecksumEx) {
                Storage::delete($path);

                $existingBatch = DB::connection('budget')->table('import_batches')
                    ->where('checksum', $checksum)
                    ->where('id', '<>', $batchId)
                    ->first();

                DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
                    'status' => 'failed',
                    'note' => 'Archivo ya importado' . ($existingBatch ? ' en batch ' . $existingBatch->id : ''),
                    'updated_at' => now(),
                ]);

                return response()->json([
                    'message' => 'Archivo ya importado',
                    'batch_id' => $existingBatch->id ?? null,
                ], 409);
            }

            $highestRow = $this->detectHighestSalesImportRow($fullPath);
        } catch (Throwable $e) {
            if (isset($path)) {
                Storage::delete($path);
            }

            DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
                'status' => 'failed',
                'note' => $this->formatImportFailureNote($e),
                'updated_at' => now(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }

        DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
            'note' => 'Importacion por bloques',
            'updated_at' => now(),
        ]);

        return response()->json([
            'path' => $path,
            'batch_id' => $batchId,
            'store_id' => $selectedStoreId,
            'total_rows' => max(0, $highestRow - 1),
            'next_row' => 2,
            'chunk_size' => 100,
        ]);
    }

    public function chunk(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
            'batch_id' => ['required', 'integer'],
            'store_id' => ['required', 'integer'],
            'next_row' => ['required', 'integer', 'min:2'],
            'chunk_size' => ['nullable', 'integer', 'min:50', 'max:500'],
            'total_rows' => ['nullable', 'integer', 'min:0'],
        ]);

        abort_unless(str_starts_with($data['path'], 'sales-imports/'), 422, 'Ruta de importacion invalida.');
        abort_unless(Storage::exists($data['path']), 404, 'Archivo de importacion no encontrado.');

        $batchId = (int) $data['batch_id'];
        $selectedStoreId = (int) $data['store_id'];
        $startRow = (int) $data['next_row'];
        $chunkSize = (int) ($data['chunk_size'] ?? 300);
        $endRow = $startRow + $chunkSize - 1;
        $absoluteHighestRow = isset($data['total_rows'])
            ? ((int) $data['total_rows']) + 1
            : null;
        $fullPath = Storage::path($data['path']);

        $batch = DB::connection('budget')->table('import_batches')->where('id', $batchId)->first();

        if (!$batch) {
            return response()->json([
                'message' => 'El lote de importacion ya no existe.',
                'batch_id' => $batchId,
                'path' => $data['path'],
                'next_row' => $startRow,
            ], 404);
        }

        if (($batch->status ?? null) === 'failed') {
            return response()->json([
                'message' => 'El lote de importacion esta marcado como fallido.',
                'batch_id' => $batchId,
                'note' => $batch->note ?? null,
            ], 409);
        }

        DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
            'note' => "Procesando filas {$startRow}-{$endRow}",
            'updated_at' => now(),
        ]);

        if ($absoluteHighestRow === null) {
            $probeReader = IOFactory::createReaderForFile($fullPath);
            $probeReader->setReadDataOnly(true);
            $probeSpreadsheet = $probeReader->load($fullPath);
            $absoluteHighestRow = (int) $probeSpreadsheet->getActiveSheet()->getHighestRow();
            $probeSpreadsheet->disconnectWorksheets();
        }

        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter {
            public function __construct(private int $startRow, private int $endRow) {}
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
            }
        });

        try {
            $chunkStartedAt = microtime(true);
            $spreadsheet = $reader->load($fullPath);
            $loadedAt = microtime(true);
            $sheet = $spreadsheet->getActiveSheet();
            $highestColumn = $sheet->getHighestColumn();
            $lastRow = min($endRow, $absoluteHighestRow);
            $headerRange = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, true, true);
            $headerRaw = $headerRange ? reset($headerRange) : false;
            $headers = [];
            foreach (($headerRaw ?: []) as $col => $value) {
                $headers[$col] = $this->normalizeHeader((string) $value);
            }

            $result = $this->processSalesRange($sheet, $headers, $startRow, $lastRow, $highestColumn, $batchId, $selectedStoreId);
            $processedAt = microtime(true);
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable $e) {
            DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
                'status' => 'failed',
                'note' => $this->formatImportFailureNote($e),
                'updated_at' => now(),
            ]);
            return response()->json(['message' => $e->getMessage()], 500);
        }

        $nextRow = $lastRow + 1;
        $done = $nextRow > $absoluteHighestRow;
        $inserted = (int) ($result['created']['sales'] ?? 0);
        DB::connection('budget')->table('import_batches')->where('id', $batchId)->increment('rows', $inserted, ['updated_at' => now()]);
        $timing = [
            'load_ms' => isset($loadedAt) ? (int) round(($loadedAt - $chunkStartedAt) * 1000) : null,
            'process_ms' => isset($processedAt, $loadedAt) ? (int) round(($processedAt - $loadedAt) * 1000) : null,
            'total_ms' => isset($processedAt, $chunkStartedAt) ? (int) round(($processedAt - $chunkStartedAt) * 1000) : null,
        ];

        Log::info('IMPORT SALES CHUNK PROCESSED', [
            'batch_id' => $batchId,
            'start_row' => $startRow,
            'end_row' => $lastRow,
            'inserted' => $inserted,
            'skipped' => $result['skipped'] ?? 0,
            'timing' => $timing,
        ]);

        if ($done) {
            DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
                'status' => 'done',
                'note' => 'Importacion completada por bloques',
                'updated_at' => now(),
            ]);
            Storage::delete($data['path']);
            $commissionRecalc = $this->tryRecalculateCommissionsForImportBatch($batchId);
            $totalRows = max(0, $absoluteHighestRow - 1);
            $storeWhatsapp = $this->tryQueueStoreSalesWhatsappForImportBatch($batchId, $totalRows, $result);
        } else {
            $commissionRecalc = null;
            $storeWhatsapp = null;
            DB::connection('budget')->table('import_batches')->where('id', $batchId)->update([
                'note' => "Ultimo chunk OK {$startRow}-{$lastRow}; siguiente fila {$nextRow}; {$timing['total_ms']} ms",
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'done' => $done,
            'next_row' => $nextRow,
            'batch_id' => $batchId,
            'processed_rows' => max(0, $lastRow - $startRow + 1),
            'total_rows' => max(0, $absoluteHighestRow - 1),
            'summary' => $result,
            'timing' => $timing,
            'commission_recalc' => $commissionRecalc,
            'store_whatsapp' => $storeWhatsapp,
        ]);
    }

    private function processSalesRange($sheet, array $headers, int $start, int $end, string $highestColumn, int $batchId, int $selectedStoreId): array
    {
        $selectedStore = DB::connection('budget')->table('stores')->select('id', 'code', 'name')->where('id', $selectedStoreId)->first();
        $defaultSeller = User::on('budget')->find(40) ?: User::on('budget')->orderBy('id')->first();
        if (!$defaultSeller) {
            throw new \RuntimeException('No existe un usuario vendedor por defecto.');
        }

        $processed = 0;
        $skipped = 0;
        $created = ['products' => 0, 'users' => 0, 'sales' => 0];
        $errors = [];
        $productsCache = [];
        $usersCache = [];
        $salesBuffer = [];
        $dailyTrms = [];
        $hasCashierId = Schema::connection('budget')->hasColumn('sales', 'cashier_id');

        DB::connection('budget')->beginTransaction();
        try {
            for ($row = $start; $row <= $end; $row++) {
                try {
                    $range = $sheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, true, true);
                    $rowData = $range ? reset($range) : false;
                    if (!$rowData || !$this->rowHasValues($rowData)) {
                        $skipped++;
                        continue;
                    }

                    $assoc = [];
                    foreach ($rowData as $c => $v) {
                        if (isset($headers[$c])) {
                            $assoc[$headers[$c]] = trim((string) $v);
                        }
                    }

                    $saleDate = $this->parseDate($this->firstNotEmpty($assoc, ['date', 'fecha'])) ?? now()->toDateString();
                    $hora = $this->normalizeHora($this->firstNotEmpty($assoc, ['time', 'hora'])) ?? '00:00:00';
                    $storeCodeExcel = $this->normalizeStoreCode($this->firstNotEmpty($assoc, ['store', 'pdv', 'store_code']));
                    $storeIdFinal = $this->resolveStoreIdFromPDV($storeCodeExcel, $selectedStoreId);
                    $sellerId = $this->resolveSellerId($assoc, $usersCache, (int) $defaultSeller->id, $created);
                    $cashierName = $this->firstNotEmpty($assoc, ['cashier', 'cajero']) ?: '';
                    $cashierId = null;

                    if ($cashierName !== '') {
                        $cashierEmail = strtolower(Str::slug($cashierName) . '@local');
                        if (!isset($usersCache[$cashierEmail])) {
                            $usersCache[$cashierEmail] = User::on('budget')->firstOrCreate(['email' => $cashierEmail], ['name' => $cashierName]);
                            if ($usersCache[$cashierEmail]->wasRecentlyCreated) {
                                $created['users']++;
                            }
                        }
                        $cashierId = $usersCache[$cashierEmail]->id;
                    }

                    $product = $this->resolveProduct($assoc, $productsCache, $created);
                    if (!$product) {
                        $skipped++;
                        continue;
                    }

                    $amountPes = $this->parseNumber($this->firstNotEmpty($assoc, ['amount_pes', 'amount', 'valor_en_pesos', 'value_pesos', 'total', 'precio_total']));
                    $amountUsd = $this->parseNumber($this->firstNotEmpty($assoc, ['amount_usd', 'valor_dolares', 'value_usd']));
                    $exchangeRateSale = $this->parseNumber($this->firstNotEmpty($assoc, ['exchange_rate_sale', 'tipo_de_cambio', 'tipo_cambio', 'exchange_rate']));
                    $exchangeRateCogs = $this->parseNumber($this->firstNotEmpty($assoc, ['exchange_rate_cogs', 't_cambio_costo', 'tipo_de_cambio_costo'])) ?? $exchangeRateSale;
                    $total = $this->parseNumber($this->firstNotEmpty($assoc, ['total']));

                    if ($amountPes === null && $amountUsd !== null && $exchangeRateSale !== null) {
                        $amountPes = round($amountUsd * $exchangeRateSale, 2);
                    }
                    if ($amountPes === null && $total !== null) {
                        $amountPes = $total;
                    }
                    if ($amountUsd === null && $amountPes !== null && $exchangeRateSale !== null && $exchangeRateSale > 0) {
                        $amountUsd = round($amountPes / $exchangeRateSale, 2);
                    }

                    if ($amountPes === null && $amountUsd === null) {
                        $skipped++;
                        continue;
                    }

                    $this->rememberDailyTrm($dailyTrms, $saleDate, $exchangeRateSale ?? $exchangeRateCogs);

                    $payload = [
                        'import_batch_id' => $batchId,
                        'store_id' => $storeIdFinal,
                        'seller_id' => $sellerId,
                        'product_id' => $product->id,
                        'sale_date' => $saleDate,
                        'sale_datetime' => $saleDate . ' ' . $hora,
                        'hora' => $hora,
                        'folio' => $this->limitText($this->firstNotEmpty($assoc, ['folio']), 255),
                        'pdv' => $this->limitText($storeCodeExcel ?: ($selectedStore->code ?? null), 255),
                        'quantity' => $this->parseNumber($this->firstNotEmpty($assoc, ['quantity', 'cantidad', 'qty'])) ?? 1,
                        'amount' => $amountPes,
                        'discount' => $this->parseNumber($this->firstNotEmpty($assoc, ['discount', 'descuento'])) ?? 0,
                        'total' => $total ?? $amountPes,
                        'value_pesos' => $amountPes,
                        'value_usd' => $amountUsd,
                        'cost' => $this->parseNumber($this->firstNotEmpty($assoc, ['cogs_pes', 'costo_de_venta', 'cost', 'costo', 'costo_venta'])),
                        'cogs_usd' => $this->parseNumber($this->firstNotEmpty($assoc, ['cogs_usd', 'costo_de_venta_usd', 'cost_usd'])),
                        'currency' => $this->limitText($this->firstNotEmpty($assoc, ['currency', 'moneda']) ?? 'USD', 255),
                        'exchange_rate' => $exchangeRateSale ?? $exchangeRateCogs,
                        'exchange_rate_cogs' => $exchangeRateCogs,
                        'regular_price' => $this->parseNumber($this->firstNotEmpty($assoc, ['precio_regular', 'regular_price'])),
                        'amount_cop' => $amountPes,
                        'status' => $this->limitText($this->firstNotEmpty($assoc, ['status', 'estatus']) ?? 'ACTIVO', 255),
                        'applied_promotion' => $this->limitText($this->firstNotEmpty($assoc, ['applied_promotion', 'promociones_aplicadas']), 255),
                        'cancellation_date' => $this->parseDateTime($this->firstNotEmpty($assoc, ['cancellation_date', 'fecha_cancelacion'])),
                        'line_code' => $this->limitText($this->firstNotEmpty($assoc, ['line', 'linea']), 50),
                        'flight_cruise' => $this->limitText($this->firstNotEmpty($assoc, ['flight_cruise', 'vuelo', 'flight']), 50),
                        'seat' => $this->limitText($this->firstNotEmpty($assoc, ['seat', 'asiento']), 20),
                        'origin' => $this->limitText($this->firstNotEmpty($assoc, ['origin', 'origen']), 50),
                        'destination' => $this->limitText($this->firstNotEmpty($assoc, ['destination', 'destino']), 50),
                        'nationality' => $this->limitText($this->firstNotEmpty($assoc, ['nationality', 'nacionalidad']), 100),
                        'passport_number' => $this->limitText($this->firstNotEmpty($assoc, ['passport_number', 'pasaporte', 'passport']), 50),
                        'passenger_name' => $this->limitText($this->firstNotEmpty($assoc, ['passenger_name', 'pasajero', 'passenger']), 255),
                        'date_birth' => $this->parseDate($this->firstNotEmpty($assoc, ['date_birth', 'fecha_nacimiento'])),
                        'gender' => $this->limitText($this->firstNotEmpty($assoc, ['gender', 'genero']), 20),
                        'customer_code' => $this->limitText($this->firstNotEmpty($assoc, ['customer_code', 'codcliente', 'cod_cliente']), 100),
                        'raw_payload' => json_encode($assoc, JSON_UNESCAPED_UNICODE),
                        'cashier' => $this->limitText($cashierName, 255) ?? '',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if ($hasCashierId) {
                        $payload['cashier_id'] = $cashierId;
                    }

                    $salesBuffer[] = $payload;
                    $processed++;
                } catch (Throwable $rowEx) {
                    $skipped++;
                    $errors[] = ['row' => $row, 'error' => $rowEx->getMessage()];
                }
            }

            if (!empty($salesBuffer)) {
                DB::connection('budget')->table('sales')->insert($salesBuffer);
                $created['sales'] += count($salesBuffer);
            }

            $this->persistDailyTrms($dailyTrms);

            DB::connection('budget')->commit();
        } catch (Throwable $e) {
            DB::connection('budget')->rollBack();
            throw $e;
        }

        return compact('processed', 'skipped', 'created', 'errors');
    }

    private function formatImportFailureNote(Throwable $e): string
    {
        $message = $e->getMessage();
        $previous = $e->getPrevious();

        if ($previous instanceof Throwable && $previous->getMessage() !== '') {
            $message = $previous->getMessage() . "\n\nDetalle Laravel: " . $message;
        }

        return Str::limit($message, 60000, '... [truncated]');
    }

    private function validateSalesImportInput(Request $request, bool $requireStore)
    {
        $rules = [
            'file' => ['required', 'file'],
            'replace_existing' => ['nullable', 'boolean'],
        ];

        $rules['store_id'] = $requireStore ? ['required', 'integer'] : ['nullable'];

        $request->validate($rules);

        $extension = strtolower((string) $request->file('file')->getClientOriginalExtension());
        $allowed = ['xlsx', 'xls', 'xlsm', 'csv'];

        if (!in_array($extension, $allowed, true)) {
            return response()->json([
                'message' => 'El archivo debe ser de tipo: xlsx, xls, xlsm o csv.',
                'errors' => [
                    'file' => ['El archivo debe ser de tipo: xlsx, xls, xlsm o csv.'],
                ],
            ], 422);
        }

        return null;
    }

    private function detectHighestSalesImportRow(string $fullPath): int
    {
        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'listWorksheetInfo')) {
            $worksheets = $reader->listWorksheetInfo($fullPath);
            $firstSheet = $worksheets[0] ?? null;

            if (isset($firstSheet['totalRows'])) {
                return (int) $firstSheet['totalRows'];
            }
        }

        $spreadsheet = $reader->load($fullPath);
        $highestRow = (int) $spreadsheet->getActiveSheet()->getHighestRow();
        $spreadsheet->disconnectWorksheets();

        return $highestRow;
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:budget.import_batches,id',
        ]);

        $ids = $request->input('ids');

        DB::connection('budget')->beginTransaction();

        try {
            $deleteIds = array_map('intval', $ids);

            foreach ($deleteIds as $batchId) {
                $this->deleteSalesForBatch($batchId);
            }

            DB::connection('budget')->table('import_batches')
                ->whereIn('id', $deleteIds)
                ->delete();

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Batches eliminados',
                'deleted' => count($deleteIds),
            ]);
        } catch (Throwable $e) {
            DB::connection('budget')->rollBack();

            return response()->json([
                'message' => 'Error eliminando batches',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function importAutomation(Request $request)
    {
        $token = $request->header('X-Automation-Token');

        if ($token !== env('IMPORT_AUTOMATION_TOKEN')) {
            return response()->json([
                'message' => 'No autorizado',
            ], 403);
        }

        if ($validationResponse = $this->validateSalesImportInput($request, false)) {
            return $validationResponse;
        }

        $storeId = $request->input('store_id');

        $request->merge([
            'store_id' => is_numeric($storeId) ? (int) $storeId : 0,
            'replace_existing' => $request->boolean('replace_existing'),
        ]);

        $startResponse = $this->startChunked($request);
        $startData = $startResponse->getData(true);

        if ($startResponse->getStatusCode() >= 400) {
            return $startResponse;
        }

        return response()->json([
            'message' => 'Importacion automatica iniciada por bloques',
            'done' => ((int) ($startData['total_rows'] ?? 0)) === 0,
            'path' => $startData['path'] ?? null,
            'batch_id' => $startData['batch_id'] ?? null,
            'store_id' => $startData['store_id'] ?? null,
            'total_rows' => $startData['total_rows'] ?? 0,
            'next_row' => $startData['next_row'] ?? 2,
            'chunk_size' => $startData['chunk_size'] ?? 100,
        ], 202);
    }

    public function importAutomationChunk(Request $request)
    {
        $token = $request->header('X-Automation-Token');

        if ($token !== env('IMPORT_AUTOMATION_TOKEN')) {
            return response()->json([
                'message' => 'No autorizado',
            ], 403);
        }

        return $this->chunk($request);
    }
}
