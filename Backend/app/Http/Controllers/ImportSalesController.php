<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Comisiones\Product;
use App\Models\Comisiones\User;
use App\Models\Comisiones\Sale;
use App\Models\Comisiones\Category;
use App\Models\Comisiones\ImportBatch;
use App\Models\Comisiones\UserRole;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportSalesController extends Controller
{
    /* =========================
     * Helpers
     * ========================= */
    protected function logSkip(int $row, string $reason, array $assoc = [])
    {
        // Log minimal y estructurado para luego filtrar por reason
        Log::warning('IMPORT SKIP', [
            'row' => $row,
            'reason' => $reason,
            'folio' => $assoc['folio'] ?? null,
            'pdv' => $assoc['pdv'] ?? null,
            'seller' => $assoc['vendedor'] ?? $assoc['seller'] ?? $assoc['vendor'] ?? null,
            'date' => $assoc['fecha'] ?? $assoc['date'] ?? null,
        ]);
    }

    protected function normalizeHora(?string $horaRaw): ?string
    {
        if (!$horaRaw) return null;

        // quitar variantes "a. m." "p. m." "am" "pm" (case-insensitive)
        $clean = preg_replace('/\b(?:a\.m\.|p\.m\.|am|pm)\b/i', '', $horaRaw);
        $clean = trim($clean);

        try {
            $dt = Carbon::parse($clean);
            return $dt->format('H:i:s'); // 24h
        } catch (\Throwable $e) {
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

    protected function firstNotEmpty(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if (!isset($row[$k])) continue;
            $v = trim((string)$row[$k]);
            if ($v !== '' && strtolower($v) !== 'null') return $v;
        }
        return null;
    }

    protected function parseNumber($v)
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || strtolower($s) === 'null') return null;

        $s = str_replace([' ', "\u{00A0}"], '', $s);

        if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
            $s = str_replace(',', '', $s);
        } elseif (strpos($s, ',') !== false) {
            $s = str_replace(',', '.', $s);
        }

        $s = preg_replace('/[^\d\.\-]/', '', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    protected function normalizePersonName(?string $name): ?string
    {
        if (!$name) return null;

        $name = mb_strtolower($name);
        $name = trim($name);

        // quitar tildes
        $name = iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        // quitar todo lo que no sea letra
        $name = preg_replace('/[^a-z]+/', '', $name);

        return $name ?: null;
    }

    /* =========================
     * Import
     * ========================= */
    public function import(Request $request)
    {
        Log::info('IMPORT SALES START');

        $request->validate(['file' => 'required|file']);
        $file = $request->file('file');

        /* ===== Batch / checksum ===== */
        $checksum = hash('sha256', file_get_contents($file->getRealPath()));

        if ($existing = ImportBatch::where('checksum', $checksum)->first()) {
            return response()->json([
                'message' => 'Archivo ya importado',
                'batch_id' => $existing->id
            ], 409);
        }

        $batch = ImportBatch::create([
            'filename' => $file->getClientOriginalName(),
            'checksum' => $checksum,
            'status' => 'processing',
            'rows' => 0,
        ]);

        /* ===== Load sheet ===== */
        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'note' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }

        /* ===== Headers (VALIDAR ANTES DE ENTRAR AL BUCLE) ===== */
        $highestColumn = $sheet->getHighestColumn();
        $headerRange = $sheet->rangeToArray(
            'A1:' . $highestColumn . '1',
            null, true, true, true
        );

        $headerRaw = $headerRange ? reset($headerRange) : false;

        if (!$headerRaw || count(array_filter($headerRaw)) === 0) {
            $batch->update([
                'status' => 'failed',
                'note'   => 'El archivo no contiene encabezados válidos en la fila 1'
            ]);
            return response()->json(['error' => 'El archivo no tiene encabezados válidos en la fila 1'], 422);
        }

        $headers = [];
        foreach ($headerRaw as $col => $value) {
            $headers[$col] = $this->normalizeHeader((string)$value);
        }

        /* ===== Counters ===== */
        $processed = 0; // filas leídas
        $skipped   = 0;
        $created   = ['products' => 0, 'users' => 0, 'sales' => 0];
        $errors    = [];

        /* ===== Caches ===== */
        $productsCache   = [];
        $usersCache      = [];      // cache local (por email o por code_)
        $categoriesCache = [];
        $dailyRoles = [];

        $chunkSize   = 500;
        $highestRow  = $sheet->getHighestRow();
        $salesBuffer = [];

        // DEFAULT SELLER ID fijo (usar tu ID ya creado)
        $DEFAULT_SELLER_ID = 40;
        // si quieres cachear el usuario por email, intenta cargarlo
        $defaultSellerModel = User::find($DEFAULT_SELLER_ID);
        if ($defaultSellerModel) {
            $usersCache['no-seller@system.local'] = $defaultSellerModel;
        }

        /**
         * TRM handling:
         * - $trmFromExcelByDate acumula la última TRM vista por fecha mientras iteramos
         * - $trmCache memoiza queries a la tabla trms (last <= date)
         */
        $trmFromExcelByDate = []; // ['YYYY-MM-DD' => float]
        $trmCache = []; // cache for DB fallback (date => float|null)

        $possibleTrmHeaders = [ 'tipo_de_cambio', 'tipo_cambio', 'tasa_cambio', 't_cambio', 'trm'];

        $getTrmForDate = function (string $date) use (&$trmCache) {
            if (array_key_exists($date, $trmCache)) return $trmCache[$date];
            $v = DB::connection('budget')->table('trms')
                ->where('date', '<=', $date)
                ->orderBy('date', 'desc')
                ->value('value');
            $trmCache[$date] = $v !== null ? (float)$v : null;
            return $trmCache[$date];
        };

        // Preparar roles map (se usa al final)
        $rolesMap = DB::connection('budget')->table('roles')->pluck('id', 'name');

        // Contador total de filas del Excel (útil para comparar)
        $totalRowsExcel = max(0, $highestRow - 1);
        Log::info('IMPORT: total rows in excel', ['rows' => $totalRowsExcel]);

        for ($start = 2; $start <= $highestRow; $start += $chunkSize) {

            $end = min($start + $chunkSize - 1, $highestRow);
            DB::connection('budget')->beginTransaction();

            try {

                for ($row = $start; $row <= $end; $row++) {

                    try {
                        $range = $sheet->rangeToArray(
                            "A{$row}:{$highestColumn}{$row}",
                            null, true, true, true
                        );

                        $rowData = $range ? reset($range) : false;

                        // Fila vacía o inválida: saltar
                        if (!$rowData || count(array_filter($rowData)) === 0) {
                            $skipped++;
                            $this->logSkip($row, 'empty_row', $rowData ?: []);
                            continue;
                        }

                        /* ===== Map ===== */
                        $assoc = [];
                        foreach ($rowData as $c => $v) {
                            if (isset($headers[$c])) {
                                $assoc[$headers[$c]] = trim((string)$v);
                            }
                        }

                        /* ===== Seller: PRIORIDAD código_vendedor si viene ===== */
                        $sellerName = $this->firstNotEmpty($assoc, [
                            'vendedor', 'seller', 'vendor', 'vendedor_nombre', 'vendor_name'
                        ]);

                        $codigoVendedor = $this->firstNotEmpty($assoc, [
                            'codigo_vendedor',
                            'codigovendedor',
                            'seller_code',
                            'codigo'
                        ]);

                        $sellerId = null;
                        // 1) si viene codigo_vendedor intentar buscar usuario existente por código
                        if ($codigoVendedor) {
                            // cache por code_
                            $cacheKey = 'code_' . $codigoVendedor;
                            if (isset($usersCache[$cacheKey])) {
                                $sellerId = $usersCache[$cacheKey]->id;
                            } else {
                                $foundByCode = User::where('codigo_vendedor', $codigoVendedor)->first();
                                if ($foundByCode) {
                                    $usersCache[$cacheKey] = $foundByCode;
                                    $sellerId = $foundByCode->id;
                                }
                            }
                        }

                        // 2) si no se resolvió, intentar por nombre/email (fallback)
                        if (!$sellerId && $sellerName) {
                            $email = strtolower(Str::slug($sellerName) . '@local');
                            if (isset($usersCache[$email])) {
                                $sellerId = $usersCache[$email]->id;
                            } else {
                                // intentar encontrar por nombre exacto (evita crear duplicados innecesarios)
                                $foundByName = User::where('name', $sellerName)->first();
                                if ($foundByName) {
                                    $usersCache[$email] = $foundByName;
                                    $sellerId = $foundByName->id;
                                } else {
                                    // crear o actualizar por email
                                    $usersCache[$email] = User::updateOrCreate(
                                        ['email' => $email],
                                        [
                                            'name' => $sellerName,
                                            'codigo_vendedor' => $codigoVendedor
                                        ]
                                    );
                                    if ($usersCache[$email]->wasRecentlyCreated) {
                                        $created['users']++;
                                    }
                                    $sellerId = $usersCache[$email]->id;
                                }
                            }
                        }

                        // 3) fallback definitivo al default seller
                        if (!$sellerId) {
                            $sellerId = $DEFAULT_SELLER_ID;
                            // Log minimal para las filas sin vendedor identificable
                            Log::info('IMPORT: fallback seller used', [
                                'row' => $row,
                                'folio' => $assoc['folio'] ?? null
                            ]);
                        }

                        /* ===== Date ===== */
                        $dateRaw = $this->firstNotEmpty($assoc, ['fecha', 'date']);
                        try {
                            if ($dateRaw) {
                                // tratar formatos comunes robustamente
                                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
                                    $saleDate = Carbon::createFromFormat('Y-m-d', $dateRaw)->toDateString();
                                } elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $dateRaw)) {
                                    [$a, $b, $y] = explode('/', $dateRaw);
                                    if ((int)$a > 12) {
                                        $saleDate = Carbon::createFromFormat('d/m/Y', $dateRaw)->toDateString();
                                    } else {
                                        $saleDate = Carbon::createFromFormat('m/d/Y', $dateRaw)->toDateString();
                                    }
                                } else {
                                    $saleDate = Carbon::parse($dateRaw)->toDateString();
                                }
                            } else {
                                $saleDate = now()->toDateString();
                            }
                        } catch (\Throwable $e) {
                            $saleDate = now()->toDateString();
                        }

                        // hora 
                        $horaRaw = $this->firstNotEmpty($assoc, ['hora']);
                        $hora = $this->normalizeHora($horaRaw);

                        // Marcar roles diarios (se usa luego para user_roles)
                        if (!isset($dailyRoles[$sellerId])) {
                            $dailyRoles[$sellerId] = [];
                        }
                        if (!isset($dailyRoles[$sellerId][$saleDate])) {
                            $dailyRoles[$sellerId][$saleDate] = ['seller' => false, 'cashier' => false];
                        }
                        $dailyRoles[$sellerId][$saleDate]['seller'] = true;

                        $cashierName = $this->firstNotEmpty($assoc, ['cajero', 'cashier']);
                        $cashierId = null;
                        if ($cashierName) {
                            $cashierEmail = strtolower(Str::slug($cashierName) . '@local');
                            if (!isset($usersCache[$cashierEmail])) {
                                $usersCache[$cashierEmail] = User::firstOrCreate(
                                    ['email' => $cashierEmail],
                                    ['name' => $cashierName]
                                );
                                if ($usersCache[$cashierEmail]->wasRecentlyCreated) {
                                    $created['users']++;
                                }
                            }
                            $cashierId = $usersCache[$cashierEmail]->id;
                            if (!isset($dailyRoles[$cashierId])) {
                                $dailyRoles[$cashierId] = [];
                            }
                            if (!isset($dailyRoles[$cashierId][$saleDate])) {
                                $dailyRoles[$cashierId][$saleDate] = ['seller' => false, 'cashier' => false];
                            }
                            $dailyRoles[$cashierId][$saleDate]['cashier'] = true;
                        }

                        /* ===== Amounts ===== */
                        $qty = $this->parseNumber($this->firstNotEmpty($assoc, ['cantidad', 'qty', 'quantity'])) ?? 0;

                        $valorPesos = $this->parseNumber(
                            $this->firstNotEmpty($assoc, ['valor_en_pesos', 'value_pesos', 'valor_pesos', 'total'])
                        );

                        $valorUsd = $this->parseNumber(
                            $this->firstNotEmpty($assoc, ['valor_dolares', 'value_usd', 'valor_usd'])
                        );

                        // TRM from Excel (if present in any of possible headers)
                        $trmExcel = null;
                        foreach ($possibleTrmHeaders as $h) {
                            $candidate = $this->firstNotEmpty($assoc, [$h]);
                            if ($candidate !== null) {
                                $trmExcel = $this->parseNumber($candidate);
                                break;
                            }
                        }

                        if ($trmExcel !== null) {
                            $trmFromExcelByDate[$saleDate] = $trmExcel;
                        }

                        $trmToUse = $trmFromExcelByDate[$saleDate] ?? $getTrmForDate($saleDate);

                        // Si no hay monto ni USD, saltar y loggear
                        if ($valorPesos === null && $valorUsd === null) {
                            $skipped++;
                            $this->logSkip($row, 'no_amount', $assoc);
                            continue;
                        }

                        // Si hay USD pero no hay TRM, log y saltar (esto puede explicar diferencias en totales)
                        if ($valorUsd !== null && (empty($trmToUse) || $trmToUse == 0)) {
                            // registramos por qué no se pudo convertir USD a COP
                            $skipped++;
                            $this->logSkip($row, 'missing_trm_for_usd', $assoc);
                            continue;
                        }

                        // Compute amount in COP
                        $amountCop = $valorPesos ?? round($valorUsd * $trmToUse, 2);

                        // Si amountCop es 0 => saltar (evita insertar 0s)
                        if ($amountCop === 0) {
                            $skipped++;
                            $this->logSkip($row, 'amount_zero', $assoc);
                            continue;
                        }

                        /* ===== Folio / PDV ===== */
                        $folio = $this->firstNotEmpty($assoc, ['folio']);
                        $pdv   = $this->firstNotEmpty($assoc, ['pdv']);

                        /* ===== Product (cache) ===== */
                        $productKey = ($this->firstNotEmpty($assoc, ['codigo', 'product_code']) ?? 'x')
                            . '|' . ($this->firstNotEmpty($assoc, ['upc', 'upc1']) ?? 'x');

                        /* ===== Classification (SIN UNIFICAR) ===== */
                        $classificationRaw = $this->firstNotEmpty($assoc, ['clasificacion', 'classification']);
                        $classificationNorm = $classificationRaw !== null
                            ? trim((string)$classificationRaw)
                            : null;

                        if (!isset($productsCache[$productKey])) {

                            $providerCode = $this->firstNotEmpty($assoc, ['codigo_proveedor']);
                            $providerName = $this->firstNotEmpty($assoc, ['proveedor']);
                            $regularPrice = $this->parseNumber($this->firstNotEmpty($assoc, ['precio_regular']));
                            $avgCostUsd   = $this->parseNumber($this->firstNotEmpty($assoc, ['costo_promedio_usd']));
                            $costUsd      = $this->parseNumber($this->firstNotEmpty($assoc, ['costo']));

                            $productsCache[$productKey] = Product::updateOrCreate(
                                [
                                    'product_code' => $this->firstNotEmpty($assoc, ['codigo', 'product_code']),
                                    'upc'          => $this->firstNotEmpty($assoc, ['upc', 'upc1']),
                                ],
                                [
                                    'description'         => $this->firstNotEmpty($assoc, ['descripcion', 'description']),
                                    'classification'      => $classificationNorm,
                                    'classification_desc' => $this->firstNotEmpty($assoc, ['descripcion_clasificacion', 'classification_desc']),
                                    'brand'               => $this->firstNotEmpty($assoc, ['brand', 'marca']),
                                    'currency'            => $this->firstNotEmpty($assoc, ['moneda', 'currency']),
                                    'provider_code' => $providerCode,
                                    'provider_name' => $providerName,
                                    'regular_price' => $regularPrice,
                                    'avg_cost_usd'  => $avgCostUsd,
                                    'cost_usd'      => $costUsd,
                                ]
                            );

                            if ($productsCache[$productKey]->wasRecentlyCreated) {
                                $created['products']++;
                            }
                        }

                        $product = $productsCache[$productKey];

                        /* ===== Category (cache) ===== */
                        $classificationForCategory = $classificationNorm ?? $this->firstNotEmpty($assoc, ['clasificacion', 'classification']);
                        $categoryKey = null;
                        if ($classificationForCategory !== null) {
                            $categoryKey = (string) $classificationForCategory;
                            $categoryKey = mb_strtolower(trim($categoryKey));
                        }

                        if ($categoryKey && !isset($categoriesCache[$categoryKey])) {
                            $categoriesCache[$categoryKey] = Category::firstOrCreate(
                                ['classification_code' => $categoryKey],
                                [
                                    'name' => $this->firstNotEmpty($assoc, ['descripcion_clasificacion', 'classification_desc']) ?? $categoryKey,
                                    'description' => $this->firstNotEmpty($assoc, ['descripcion_clasificacion', 'classification_desc']),
                                ]
                            );
                        }

                        // Construir datetime completo
                        $saleDatetime = $saleDate . ' ' . ($hora ?? '00:00:00');

                        /* ===== Buffer sale ===== */
                        $salesBuffer[] = [
                            'import_batch_id' => $batch->id,
                            'seller_id' => $sellerId,
                            'product_id' => $product->id,
                            'sale_date'  => $saleDate,
                            'sale_datetime' => $saleDatetime,
                            'hora' => $hora,
                            'amount'     => $amountCop,
                            'amount_cop' => $amountCop,
                            'value_pesos'=> $valorPesos,
                            'value_usd'  => $valorUsd,
                            'exchange_rate'=> $trmToUse, // trm used (may be null)
                            'currency'   => $this->firstNotEmpty($assoc, ['moneda', 'currency']),
                            'quantity'   => $qty,
                            'folio'      => $folio,
                            'pdv'        => $pdv,
                            'cashier'    => $this->firstNotEmpty($assoc, ['cajero', 'cashier']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $processed++;

                        if (count($salesBuffer) >= 500) {
                            Sale::insert($salesBuffer);
                            $insertedCount = count($salesBuffer);
                            $created['sales'] += $insertedCount;
                            $salesBuffer = [];
                            // Log mínimo por chunk insertado
                            Log::info('IMPORT: chunk inserted', ['start' => $start, 'end' => $end, 'inserted' => $insertedCount]);
                        }

                    } catch (\Throwable $rowEx) {
                        $skipped++;
                        $errors[] = ['row' => $row, 'error' => $rowEx->getMessage()];
                        Log::error("IMPORT ROW ERROR", [
                            'row' => $row,
                            'error' => $rowEx->getMessage(),
                            'trace' => $rowEx->getTraceAsString(),
                        ]);
                    }
                }

                if ($salesBuffer) {
                    Sale::insert($salesBuffer);
                    $insertedCount = count($salesBuffer);
                    $created['sales'] += $insertedCount;
                    $salesBuffer = [];
                    Log::info('IMPORT: chunk inserted (end)', ['start' => $start, 'end' => $end, 'inserted' => $insertedCount]);
                }

                DB::connection('budget')->commit();

            } catch (\Throwable $e) {
                DB::connection('budget')->rollBack();
                Log::error("Chunk {$start}-{$end} failed", ['error' => $e->getMessage()]);
                $errors[] = ['chunk' => "{$start}-{$end}", 'error' => $e->getMessage()];
                // seguimos con el siguiente chunk
            }
        }

        /* =========================
         * Insert TRMs discovered in the Excel into DB (one per day)
         * Use ON DUPLICATE KEY to avoid duplicates. The TRM buffer holds the last TRM per date
         * ========================= */
        foreach ($trmFromExcelByDate as $date => $trmValue) {
            try {
                DB::connection('budget')->statement(
                    "INSERT INTO trms (`date`,`value`,`created_at`,`updated_at`) VALUES (?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE id = id",
                    [$date, $trmValue]
                );
            } catch (\Throwable $e) {
                Log::warning("No se pudo insertar TRM para {$date}: " . $e->getMessage());
                $errors[] = ['trm_insert' => $date, 'error' => $e->getMessage()];
            }
        }

        foreach ($dailyRoles as $userId => $dates) {
            foreach ($dates as $date => $flags) {

                if ($flags['cashier']) {
                    $roleName = 'cajero';   // prioridad
                } elseif ($flags['seller']) {
                    $roleName = 'vendedor';
                } else {
                    continue;
                }

                $roleId = $rolesMap[$roleName] ?? null;

                if (!$roleId) {
                    Log::warning("Rol '{$roleName}' no existe", [
                        'user_id' => $userId,
                        'date' => $date
                    ]);
                    continue;
                }

                UserRole::updateOrCreate(
                    [
                        'user_id'    => $userId,
                        'start_date' => $date,
                    ],
                    [
                        'role_id'   => $roleId,
                        'end_date'  => null,
                    ]
                );
            }
        }

        // actualizamos batch con las filas realmente insertadas
        $batch->update([
            'status' => 'done',
            'rows' => $created['sales']
        ]);

        // resumen final (log mínimo)
        Log::info('IMPORT FINISHED', [
            'total_rows_excel' => $totalRowsExcel,
            'processed_rows_read' => $processed,
            'inserted_sales' => $created['sales'],
            'skipped_rows' => $skipped,
            'errors_count' => count($errors)
        ]);

        return response()->json([
            'message' => 'Importación completada',
            'processed' => $processed,
            'skipped' => $skipped,
            'created' => $created,
            'errors' => $errors,
            'batch_id' => $batch->id
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
            $batches = ImportBatch::whereIn('id', $ids)->get();

            foreach ($batches as $batch) {
                if (method_exists($batch, 'sales')) {
                    $batch->sales()->delete();
                }
                if ($batch->path && \Storage::exists($batch->path)) {
                    \Storage::delete($batch->path);
                }
                $batch->delete();
            }

            DB::connection('budget')->commit();
            return response()->json(['message' => 'Batches eliminados', 'deleted' => count($ids)]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            return response()->json(['message' => 'Error eliminando batches', 'error' => $e->getMessage()], 500);
        }
    }
}