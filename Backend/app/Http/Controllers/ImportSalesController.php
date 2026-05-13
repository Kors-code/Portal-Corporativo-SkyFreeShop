<?php

namespace App\Http\Controllers;

use App\Models\Comisiones\Product;
use App\Models\Comisiones\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ImportSalesController extends Controller
{
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
                    Log::info('DATE DEBUG OK', [
                        'context' => $context,
                        'input' => $text,
                        'format' => $format,
                        'parsed' => $dt->format('Y-m-d'),
                    ]);

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
                ['email' => $email],
                [
                    'name' => $sellerName,
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
                        'passenger_name' => $passengerName ?: $existing->passenger_name,
                        'customer_code' => $customerCode ?: $existing->customer_code,
                        'nationality' => $nationality ?: $existing->nationality,
                        'date_birth' => $dateBirth ?: $existing->date_birth,
                        'gender' => $gender ?: $existing->gender,
                        'updated_at' => now(),
                    ]);

                return (int) $existing->id;
            }
        }

        $payload = [
            'passport_number' => $passportNumber ?: ('NO-PASS-' . Str::uuid()),
            'passenger_name' => $passengerName ?: 'SIN NOMBRE',
            'customer_code' => $customerCode,
            'nationality' => $nationality,
            'date_birth' => $dateBirth,
            'gender' => $gender,
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
            'line_code' => $lineCode,
            'flight_cruise' => $flightCruise,
            'origin' => $origin,
            'destination' => $destination,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function resolveProduct(array $assoc, array &$productsCache, array &$created): ?Product
    {
        $sku = $this->firstNotEmpty($assoc, ['sku', 'codigo', 'product_code', 'sku_code']);

        if (!$sku) {
            return null;
        }

        if (!array_key_exists($sku, $productsCache)) {
            $productsCache[$sku] = Product::on('budget')
                ->where('product_code', $sku)
                ->first();
        }

        if (!$productsCache[$sku]) {
            return null;
        }

        return $productsCache[$sku];
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

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
                    'note' => $e->getMessage(),
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

                            'folio' => $this->firstNotEmpty($assoc, ['folio']),
                            'pdv' => $storeCodeExcel ?: ($selectedStore->code ?? null),

                            'quantity' => $qty,
                            'amount' => $amountPes,
                            'discount' => $discount,
                            'total' => $total ?? $amountPes,
                            'value_pesos' => $amountPes,
                            'value_usd' => $amountUsd,
                            'cost' => $cogsPes,
                            'currency' => $currency,
                            'exchange_rate' => $exchangeRateSale ?? $exchangeRateCogs,
                            'amount_cop' => $amountPes,
                            'status' => $status,
                            'applied_promotion' => $appliedPromotion,
                            'cancellation_date' => $cancellationDate,

                            'line_code' => $lineCode,
                            'seat' => $seat,
                            'passport_number' => $passportNumber,
                            'passenger_name' => $passengerName,
                            'date_birth' => $dateBirth,
                            'gender' => $gender,
                            'customer_code' => $customerCode,

                            'raw_payload' => json_encode($assoc, JSON_UNESCAPED_UNICODE),
                            'cashier' => $cashierName,
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

        return response()->json([
            'message' => 'Importación completada',
            'processed' => $processed,
            'skipped' => $skipped,
            'created' => $created,
            'errors' => $errors,
            'batch_id' => $batchId,
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:import_batches,id',
        ]);

        $ids = $request->input('ids');

        DB::connection('budget')->beginTransaction();

        try {
            DB::connection('budget')->table('sales')
                ->whereIn('import_batch_id', $ids)
                ->delete();

            DB::connection('budget')->table('import_batches')
                ->whereIn('id', $ids)
                ->delete();

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Batches eliminados',
                'deleted' => count($ids),
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

    $request->validate([
        'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        'store_id' => ['nullable'],
    ]);

    return $this->import($request);
}
}
