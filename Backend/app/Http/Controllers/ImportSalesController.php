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
        Log::warning('IMPORT SKIP', [
            'row' => $row,
            'reason' => $reason,
            'folio' => $assoc['folio'] ?? null,
            'pdv' => $assoc['pdv'] ?? null,
            'seller' => $assoc['vendedor'] ?? $assoc['seller'] ?? $assoc['vendor'] ?? null,
            'date' => $assoc['fecha'] ?? $assoc['date'] ?? null,
            'raw' => $assoc,
        ]);
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
        $processed = 0;
        $skipped   = 0;
        $created   = ['products' => 0, 'users' => 0, 'sales' => 0];
        $errors    = [];

        /* ===== Caches ===== */
        $productsCache   = [];
        $usersCache      = [];
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

        // user_id => [ 'YYYY-MM-DD' => true|false ]
        // true = en algún momento del día fue cajero
// user_id => [ 'YYYY-MM-DD' => ['seller'=>bool,'cashier'=>bool] ]



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

                        /* ===== Seller (determinista, fallback al ID por defecto) ===== */
                        $sellerName = $this->firstNotEmpty($assoc, [
                            'vendedor', 'seller', 'vendor', 'vendedor_nombre', 'vendor_name'
                        ]);

                        if ($sellerName) {
                            $email = Str::slug($sellerName) . '@local';

                            $codigoVendedor = $this->firstNotEmpty($assoc, [
                                'codigo_vendedor',
                                'codigovendedor',
                                'seller_code',
                                'codigo'
                            ]);

                            $email = strtolower(Str::slug($sellerName) . '@local');

                            

                            if (!isset($usersCache[$email])) {
                                $usersCache[$email] = User::updateOrCreate(
                                    ['email' => $email],                 // 🔑 clave única
                                    [
                                        'name' => $sellerName,
                                        'codigo_vendedor' => $codigoVendedor
                                    ]
                                );

                                if ($usersCache[$email]->wasRecentlyCreated) {
                                    $created['users']++;
                                }
                            }


                            $sellerId = $usersCache[$email]->id;
                           

                        } else {
                            // Fallback: usar ID fijo
                            $sellerId = $DEFAULT_SELLER_ID;

                            // Log informativo (no crítico)
                            Log::info('IMPORT: seller fallback to SIN VENDEDOR', [
                                'row' => $row,
                                'seller_id' => $DEFAULT_SELLER_ID,
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

                                // Marcar que este usuario fue VENDEDOR este día
                            if (!isset($dailyRoles[$sellerId])) {
                                $dailyRoles[$sellerId] = [];
                            }
                            if (!isset($dailyRoles[$sellerId][$saleDate])) {
                                $dailyRoles[$sellerId][$saleDate] = ['seller' => false, 'cashier' => false];
                            }
                            $dailyRoles[$sellerId][$saleDate]['seller'] = true;


                        $cashierName = $this->firstNotEmpty($assoc, ['cajero', 'cashier']);
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


                        $normSeller  = $this->normalizePersonName($sellerName);
                        $normCashier = $this->normalizePersonName($cashierName);


                        if ($normSeller && $normCashier) {
                            Log::info('ROLE CHECK', [
                                'seller_raw' => $sellerName,
                                'cashier_raw' => $cashierName,
                                'seller_norm' => $normSeller,
                                'cashier_norm' => $normCashier,
                                'match' => $normSeller === $normCashier,
                            ]);
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

                        // Keep last TRM per date from the Excel: the last row seen for that date overwrites previous
                        if ($trmExcel !== null) {
                            $trmFromExcelByDate[$saleDate] = $trmExcel;
                        }

                        // Determine TRM to use for calculation:
                        // prefer the TRM from the Excel buffer if present; otherwise fallback to DB (last <= date)
                        $trmToUse = $trmFromExcelByDate[$saleDate] ?? $getTrmForDate($saleDate);

                        // Compute amount: same behavior as original but using trmToUse
                        $amountCop = $valorPesos ?? (($valorUsd && $trmToUse) ? round($valorUsd * $trmToUse, 2) : 0);

                        /* ===== Folio / PDV (guardamos pero no bloqueamos por duplicado) ===== */
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

                                    // 🔽 CAMPOS QUE TE SALÍAN NULL
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

                        /* ===== Buffer sale ===== */
                        $salesBuffer[] = [
                            'import_batch_id' => $batch->id,
                            'seller_id' => $sellerId,
                            'product_id' => $product->id,
                            'sale_date'  => $saleDate,
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

                        if (count($salesBuffer) >= 500) {
                            Sale::insert($salesBuffer);
                            $created['sales'] += count($salesBuffer);
                            $salesBuffer = [];
                        }

                        $processed++;

                    } catch (\Throwable $rowEx) {
                        $skipped++;
                        $errors[] = ['row' => $row, 'error' => $rowEx->getMessage()];
                        Log::error("Row {$row} error: " . $rowEx->getMessage(), [
                            'trace' => $rowEx->getTraceAsString(),
                            'data' => $assoc ?? null
                        ]);
                    }
                }

                if ($salesBuffer) {
                    Sale::insert($salesBuffer);
                    $created['sales'] += count($salesBuffer);
                    $salesBuffer = [];
                }

                DB::connection('budget')->commit();

            } catch (\Throwable $e) {
                DB::connection('budget')->rollBack();
                Log::error("Chunk {$start}-{$end} failed: " . $e->getMessage());
                $errors[] = ['chunk' => "{$start}-{$end}", 'error' => $e->getMessage()];
                // no hacemos continue; seguimos con el siguiente chunk
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

        $rolesMap = DB::connection('budget')->table('roles')->pluck('id', 'name');

        $rolesMap = DB::connection('budget')->table('roles')->pluck('id', 'name');

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




        $batch->update([
            'status' => 'done',
            'rows' => $processed
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
