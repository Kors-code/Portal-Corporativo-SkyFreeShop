<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Inventario\Inventory;
use App\Models\Inventario\ProductInventoryConfig;
use App\Models\Inventario\Supplier;
use App\Models\Inventario\Store;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Illuminate\Support\Facades\Log;

class InventoryImport implements ToCollection
{
    protected Store $store;

    public function __construct(int $storeId)
    {
        $this->store = Store::findOrFail($storeId);
    }

    public function collection(Collection $rows)
    {
        if ($rows->count() === 0) {
            return;
        }

        // Quitar encabezado
        unset($rows[0]);

        foreach ($rows as $row) {

            $productCode = trim((string)($row[0] ?? ''));

            if ($productCode === '') {
                continue;
            }

            $product = Product::where('product_code', $productCode)->first();

            if (!$product) {
                continue;
            }

            // 🔢 NUMÉRICOS
            $existenciaAnterior = $this->toNumber($row[3] ?? null);
            $compras = $this->toNumber($row[4] ?? null);
            $ventas = $this->toNumber($row[5] ?? null);
            $entrada = $this->toNumber($row[6] ?? null);
            $salida = $this->toNumber($row[7] ?? null);
            $existenciaFinal = $this->toNumber($row[8] ?? null);
            $factorCaja = (int) ($this->toNumber($row[9] ?? null) ?? 1);
            $costoUnitario = $this->toNumber($row[10] ?? null);
            $totalInvFinal = $this->toNumber($row[11] ?? null);
            $costoUnitarioUsd = $this->toNumber($row[12] ?? null);
            $valorFinalUsd = $this->toNumber($row[13] ?? null);
            $tCambio = $this->toNumber($row[14] ?? null);
            $cogs = $this->toNumber($row[15] ?? null);
            $retail = $this->toNumber($row[22] ?? null);
            $pctCosto = $this->toNumber($row[23] ?? null);
            $pctMargen = $this->toNumber($row[24] ?? null);

            // 🧾 TEXTOS
            $supplierName = trim((string)($row[16] ?? ''));
            $supplierAlt = trim((string)($row[17] ?? ''));
            $brand = trim((string)($row[18] ?? ''));
            $upc1 = trim((string)($row[19] ?? ''));
            $upc2 = trim((string)($row[20] ?? ''));
            $upc3 = trim((string)($row[21] ?? ''));

            // 📅 FECHA (COLUMNA AR = índice 43)
            $ToDate = $this->parseExcelDate($row[43] ?? null);

            // 🏢 SUPPLIER
            $supplier = null;

            if ($supplierName !== '') {
                $supplier = Supplier::firstOrCreate([
                    'name' => $supplierName
                ]);
            }
Log::info('VALOR FECHA RAW', [
    'value' => $row[43] ?? null,
    'type' => gettype($row[43] ?? null),
]);
            // 📦 INVENTARIO
            Inventory::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'store_id' => $this->store->id,
                ],
                [
                    'existencia_anterior' => $existenciaAnterior,
                    'compras' => $compras,
                    'ventas' => $ventas,
                    'entrada' => $entrada,
                    'salida' => $salida,
                    'existencia_final' => $existenciaFinal,
                    'factor_caja' => $factorCaja,
                    'costo_unitario' => $costoUnitario,
                    'total_inv_final' => $totalInvFinal,
                    'costo_unitario_usd' => $costoUnitarioUsd,
                    'valor_final_usd' => $valorFinalUsd,
                    't_cambio' => $tCambio,
                    'cogs' => $cogs,
                    'proveedor' => $supplierName,
                    'supplier' => $supplierAlt,
                    'brand' => $brand,
                    'upc1' => $upc1,
                    'upc2' => $upc2,
                    'upc3' => $upc3,
                    'retail' => $retail,
                    'pct_costo' => $pctCosto,
                    'pct_margen' => $pctMargen,
                    'toDate' => $ToDate,
                ]
            );

            // ⚙️ CONFIG
            ProductInventoryConfig::updateOrCreate(
                ['product_id' => $product->id],
                [
                    'factor_caja' => $factorCaja,
                    'lead_time' => 15,
                    'tipo_abastecimiento' => 'local'
                ]
            );

            // 🔗 RELACIÓN PRODUCTO - SUPPLIER
            if ($supplier) {
                $product->supplier_id = $supplier->id;
                $product->save();
            }
        }
    }

    /**
     * 📅 Convertir fecha de Excel (robusto)
     */
    private function parseExcelDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Caso 1: Excel numérico (tu caso AR)
            if (is_numeric($value)) {
                return Date::excelToDateTimeObject($value)
                    ->format('Y-m-d');
            }

            $value = trim((string) $value);

            if ($value === '') {
                return null;
            }

            // Caso 2: formato d/m/Y
            return Carbon::createFromFormat('d/m/Y', $value)
                ->format('Y-m-d');

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 🔢 Convertir a número seguro
     */
    private function toNumber(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '' || $text === '-') {
            return null;
        }

        $text = str_replace([' ', ','], ['', '.'], $text);

        return is_numeric($text) ? (float) $text : null;
    }
}