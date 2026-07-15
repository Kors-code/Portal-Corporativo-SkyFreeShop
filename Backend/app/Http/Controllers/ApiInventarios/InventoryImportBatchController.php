<?php

namespace App\Http\Controllers\ApiInventarios;

use App\Http\Controllers\Controller;
use App\Models\Inventario\Inventory;
use App\Models\Inventario\InventoryImportBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class InventoryImportBatchController extends Controller
{
    // GET /api/v1/inventory-imports
    public function index()
    {
        $batches = InventoryImportBatch::orderBy('created_at', 'desc')
            ->select(['id','filename','store_id','to_date','rows_imported','status','checksum','notes','created_at'])
            ->get()
            ->map(function ($b) {
                // Normalizamos a los nombres que espera el frontend (filename, rows, note)
                return [
                    'id'          => $b->id,
                    'filename'    => $b->filename,
                    'store_id'    => $b->store_id,
                    'to_date'     => $b->to_date,
                    'rows'        => $b->rows_imported,
                    'rows_imported' => $b->rows_imported,
                    'status'      => $b->status,
                    'checksum'    => $b->checksum,
                    'note'        => $b->notes,
                    'created_at'  => $b->created_at,
                ];
            });

        return response()->json($batches);
    }

    // GET /api/v1/inventory-imports/{id}
    public function show($id)
    {
        $batch = InventoryImportBatch::findOrFail($id);

        $items = Inventory::where('batch_id', $id)
            ->select([
                'id','product_id','store_id','toDate',
                'existencia_anterior','compras','ventas','entrada','salida','existencia_final',
                'costo_unitario','total_inv_final','costo_unitario_usd','valor_final_usd',
                't_cambio','cogs','proveedor','brand','upc1','retail',
                'pct_costo','pct_margen','last_purchase_date','last_sale_date',
                'without_sales_days','days_in_stock',
            ])
            ->limit(500)
            ->get();

        return response()->json([
            'id'            => $batch->id,
            'filename'      => $batch->filename,
            'store_id'      => $batch->store_id,
            'to_date'       => $batch->to_date,
            'rows'          => $batch->rows_imported,
            'rows_imported' => $batch->rows_imported,
            'status'        => $batch->status,
            'checksum'      => $batch->checksum,
            'note'          => $batch->notes,
            'created_at'    => $batch->created_at,
            'rows_data'     => $items,
        ]);
    }

    // POST /api/v1/inventory-imports/import
    public function import(Request $request)
    {
        $request->validate([
            'file'     => 'required|file|mimes:csv,txt,xlsx,xls|max:20480',
            'store_id' => 'required|integer|exists:budget.stores,id',
            'to_date'  => 'nullable|date',
        ]);

        $file     = $request->file('file');
        $storeId  = (int) $request->input('store_id');
        $toDate   = $request->input('to_date') ?: now()->toDateString();
        $checksum = hash_file('sha256', $file->getRealPath());

        // Evitar duplicados por contenido idéntico
        $existing = InventoryImportBatch::where('checksum', $checksum)
            ->where('store_id', $storeId)
            ->first();
        if ($existing) {
            return response()->json([
                'message'  => 'Este archivo ya fue importado previamente para esta tienda.',
                'batch_id' => $existing->id,
            ], 409);
        }

        // Guardar archivo
        $stored = $file->storeAs(
            'imports/inventory',
            now()->format('YmdHis') . '_' . $file->getClientOriginalName()
        );

        DB::connection('budget')->beginTransaction();

        try {
            $batch = InventoryImportBatch::create([
                'filename'      => $file->getClientOriginalName(),
                'store_id'      => $storeId,
                'to_date'       => $toDate,
                'rows_imported' => 0,
                'status'        => 'processing',
                'checksum'      => $checksum,
                'notes'         => null,
            ]);

            $rows = $this->parseFile($file);
            if (empty($rows)) {
                throw new \Exception('El archivo no contiene filas válidas.');
            }

            $inserted = 0;
            $errores  = [];

            foreach ($rows as $i => $row) {
                $numFila = $i + 2; // +2 por encabezado y base-1

                try {
                    $productId = $this->resolveProductId($row);
                    if (!$productId) {
                        $errores[] = [
                            'fila'  => $numFila,
                            'error' => 'Producto no encontrado (UPC/SKU no localizado)',
                            'valor' => $row['upc1'] ?? $row['sku'] ?? null,
                        ];
                        continue;
                    }

                    Inventory::updateOrCreate(
                        [
                            'product_id' => $productId,
                            'store_id'   => $storeId,
                            'toDate'     => $toDate,
                        ],
                        [
                            'batch_id'             => $batch->id,
                            'factor_caja'          => $row['factor_caja']          ?? null,
                            'existencia_anterior'  => $row['existencia_anterior']  ?? null,
                            'compras'              => $row['compras']              ?? null,
                            'ventas'               => $row['ventas']               ?? null,
                            'entrada'              => $row['entrada']              ?? null,
                            'salida'               => $row['salida']               ?? null,
                            'existencia_final'     => $row['existencia_final']     ?? null,
                            'stock_actual'         => $row['existencia_final']     ?? 0,
                            'stock_teorico'        => $row['existencia_final']     ?? 0,
                            'costo_unitario'       => $row['costo_unitario']       ?? null,
                            'total_inv_final'      => $row['total_inv_final']      ?? null,
                            'costo_unitario_usd'   => $row['costo_unitario_usd']   ?? null,
                            'valor_final_usd'      => $row['valor_final_usd']      ?? null,
                            't_cambio'             => $row['t_cambio']             ?? null,
                            'cogs'                 => $row['cogs']                 ?? null,
                            'proveedor'            => $row['proveedor']            ?? null,
                            'supplier'             => $row['supplier']             ?? $row['proveedor'] ?? null,
                            'brand'                => $row['brand']                ?? null,
                            'upc1'                 => $row['upc1']                 ?? null,
                            'upc2'                 => $row['upc2']                 ?? null,
                            'upc3'                 => $row['upc3']                 ?? null,
                            'retail'               => $row['retail']               ?? null,
                            'pct_costo'            => $row['pct_costo']            ?? null,
                            'pct_margen'           => $row['pct_margen']           ?? null,
                            'last_purchase_date'   => $row['last_purchase_date']   ?? null,
                            'last_sale_date'       => $row['last_sale_date']       ?? null,
                            'without_sales_days'   => $row['without_sales_days']   ?? null,
                            'days_in_stock'        => $row['days_in_stock']        ?? null,
                        ]
                    );

                    $inserted++;
                } catch (\Throwable $e) {
                    $errores[] = [
                        'fila'  => $numFila,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $batch->update([
                'rows_imported' => $inserted,
                'status'        => empty($errores) ? 'completed' : 'completed_with_errors',
                'notes'         => empty($errores) ? null : json_encode($errores, JSON_UNESCAPED_UNICODE),
            ]);

            DB::connection('budget')->commit();

            return response()->json([
                'message'  => 'Importación completada',
                'batch_id' => $batch->id,
                'rows'     => $inserted,
                'errors'   => $errores,
                'path'     => $stored,
            ]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            Log::error('Error importando inventario: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al procesar el archivo',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // DELETE /api/v1/inventory-imports/{id}
    public function destroy($id)
    {
        DB::connection('budget')->beginTransaction();

        try {
            $batch = InventoryImportBatch::findOrFail($id);

            Inventory::where('batch_id', $batch->id)->delete();

            $filename = $batch->filename;
            $batch->delete();

            try {
                $this->deletePhysicalInventoryFile($filename);
            } catch (\Throwable $e) {
                Log::warning("No se pudo borrar archivo físico de inventario: " . $e->getMessage());
            }

            DB::connection('budget')->commit();
            return response()->json(['message' => 'Batch de inventario eliminado.']);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            return response()->json([
                'error'  => 'No se pudo eliminar el batch',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    // POST /api/v1/inventory-imports/bulk-delete
    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:budget.inventory_import_batches,id',
        ]);

        $ids = $request->input('ids');

        DB::connection('budget')->beginTransaction();

        try {
            $batches = InventoryImportBatch::whereIn('id', $ids)->get();

            foreach ($batches as $batch) {
                Inventory::where('batch_id', $batch->id)->delete();

                try {
                    $this->deletePhysicalInventoryFile($batch->filename);
                } catch (\Throwable $e) {
                    Log::warning("No se pudo borrar archivo del batch {$batch->id}: " . $e->getMessage());
                }

                $batch->delete();
            }

            DB::connection('budget')->commit();

            return response()->json([
                'message' => 'Batches eliminados correctamente',
                'deleted' => count($ids),
            ]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            return response()->json([
                'message' => 'Error eliminando batches',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // --------- Helpers ---------

    private function parseFile($file): array
    {
        // Si usas Maatwebsite\Excel ya instalado, esto cubre csv/xlsx/xls.
        $data = Excel::toArray(null, $file);
        $sheet = $data[0] ?? [];
        if (empty($sheet)) return [];

        $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), $sheet[0]);
        $rows = [];

        for ($i = 1; $i < count($sheet); $i++) {
            $line = $sheet[$i];
            if (!array_filter($line, fn ($v) => $v !== null && $v !== '')) continue;

            $row = [];
            foreach ($headers as $idx => $key) {
                if ($key === '') continue;
                $row[$key] = $line[$idx] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function normalizeHeader(string $h): string
    {
        $h = strtolower(trim($h));
        $map = [
            'sku' => 'sku',
            'upc' => 'upc1', 'upc1' => 'upc1', 'upc2' => 'upc2', 'upc3' => 'upc3',
            'factor caja' => 'factor_caja', 'factor_caja' => 'factor_caja',
            'existencia anterior' => 'existencia_anterior', 'existencia_anterior' => 'existencia_anterior',
            'compras' => 'compras',
            'ventas' => 'ventas',
            'entrada' => 'entrada',
            'salida' => 'salida',
            'existencia final' => 'existencia_final', 'existencia_final' => 'existencia_final',
            'costo unitario' => 'costo_unitario', 'costo_unitario' => 'costo_unitario',
            'total inv final' => 'total_inv_final', 'total_inv_final' => 'total_inv_final',
            'costo unitario usd' => 'costo_unitario_usd', 'costo_unitario_usd' => 'costo_unitario_usd',
            'valor final usd' => 'valor_final_usd', 'valor_final_usd' => 'valor_final_usd',
            't cambio' => 't_cambio', 't_cambio' => 't_cambio', 'tipo de cambio' => 't_cambio',
            'cogs' => 'cogs',
            'proveedor' => 'proveedor', 'supplier' => 'supplier',
            'marca' => 'brand', 'brand' => 'brand',
            'retail' => 'retail', 'precio' => 'retail',
            'pct costo' => 'pct_costo', '%costo' => 'pct_costo', 'pct_costo' => 'pct_costo',
            'pct margen' => 'pct_margen', '%margen' => 'pct_margen', 'pct_margen' => 'pct_margen',
            'ultima compra' => 'last_purchase_date', 'last_purchase_date' => 'last_purchase_date',
            'ultima venta' => 'last_sale_date', 'last_sale_date' => 'last_sale_date',
            'dias sin venta' => 'without_sales_days', 'without_sales_days' => 'without_sales_days',
            'dias en stock' => 'days_in_stock', 'days_in_stock' => 'days_in_stock',
        ];
        return $map[$h] ?? $h;
    }

    private function resolveProductId(array $row): ?int
    {
        $sku = $this->normalizeLookupValue($row['sku'] ?? null);
        $upcs = array_values(array_filter(array_map(
            fn ($value) => $this->normalizeLookupValue($value),
            [$row['upc1'] ?? null, $row['upc2'] ?? null, $row['upc3'] ?? null]
        )));

        $q = DB::connection('budget')->table('products');
        if ($sku !== '') {
            $found = (clone $q)
                ->where(function ($w) use ($sku) {
                    $w->where('product_code', $sku)
                        ->orWhere('sku_mia', $sku)
                        ->orWhere('upc', $sku)
                        ->orWhere('upc2', $sku)
                        ->orWhere('upc3', $sku);
                })
                ->value('id');

            if ($found) return (int) $found;
        }

        if (!empty($upcs)) {
            $found = (clone $q)
                ->where(function ($w) use ($upcs) {
                    foreach ($upcs as $u) {
                        $w->orWhere('upc', $u)
                            ->orWhere('upc2', $u)
                            ->orWhere('upc3', $u)
                            ->orWhere('product_code', $u)
                            ->orWhere('sku_mia', $u);
                    }
                })
                ->value('id');

            if ($found) return (int) $found;
        }

        return null;
    }

    private function normalizeLookupValue(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function deletePhysicalInventoryFile(?string $filename): void
    {
        if (!$filename) {
            return;
        }

        $filename = basename($filename);

        if (!preg_match('/^[A-Za-z0-9_.-]+\.(xlsx|xls|xlsm|csv)$/i', $filename)) {
            Log::warning('Nombre de archivo de inventario invalido, no se borra fisicamente', [
                'filename' => $filename,
            ]);

            return;
        }

        $path = 'imports/inventory/' . $filename;

        if (Storage::exists($path)) {
            Storage::delete($path);
        }
    }
}
