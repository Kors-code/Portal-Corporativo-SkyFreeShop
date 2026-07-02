<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Inventario\Inventory;
use App\Models\Inventario\ProductInventoryConfig;
use App\Models\Inventario\Store;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class InventoryImport implements ToCollection, WithHeadingRow
{
    protected Store $store;
    protected int $batchId;
    protected int $rowsImported = 0;

    public function __construct(int $storeId, int $batchId)
    {
        $this->store = Store::on('budget')->findOrFail($storeId);
        $this->batchId = $batchId;
    }

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            return;
        }

        DB::connection('budget')->transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $productCode = $this->text($this->pick($row, ['sku_code', 'codigo', 'sku', 'product_code']));
                if ($productCode === '') {
                    continue;
                }

                $product = Product::on('budget')
                    ->where(function ($query) use ($productCode) {
                        $query->where('product_code', $productCode)
                            ->orWhere('sku_mia', $productCode)
                            ->orWhere('upc', $productCode)
                            ->orWhere('upc2', $productCode)
                            ->orWhere('upc3', $productCode);
                    })
                    ->first();

                if (!$product) {
                    continue;
                }

                $initialStock = $this->number($this->pick($row, ['initial_stock', 'existencia_anterior']));
                $unitSales = $this->number($this->pick($row, ['unit_sales', 'ventas']));
                $unitPurchase = $this->number($this->pick($row, ['unit_purchase', 'compras']));
                $disposed = $this->number($this->pick($row, ['disposed_product', 'salida']));
                $finalStock = $this->number($this->pick($row, ['final_stock', 'existencia_final']));
                $exchangeRate = $this->number($this->pick($row, ['exchange_rate', 't_cambio'])) ?? 1;
                $unitCostUsd = $this->number($this->pick($row, ['unit_cost_usd', 'costo_unitario_usd']));
                $totalCostUsd = $this->number($this->pick($row, ['total_cost_usd', 'valor_final_usd']));
                $daysInStock = $this->int($this->pick($row, ['days_in_stock', 'dias_en_existencia']));
                $lastPurchaseDate = $this->parseDate($this->pick($row, ['last_purchase_date', 'fecha_ultima_compra', 'last_received_transfer_date']));
                $lastSaleDate = $this->parseDate($this->pick($row, ['last_sale_date', 'fecha_ultima_venta']));
                $withoutSalesDays = $this->int($this->pick($row, ['without_sales_days', 'dias_sin_ventas']));
                $toDate = $this->parseDate($this->pick($row, ['a_la_fecha', 'to_date', 'toDate'])) ?? now()->toDateString();

                if ($lastSaleDate) {
                    $saleDate = Carbon::parse($lastSaleDate)->startOfDay();
                    if ($saleDate->greaterThan(now()->startOfDay())) {
                        $lastSaleDate = null;
                    } else {
                        $withoutSalesDays = now()->startOfDay()->diffInDays($saleDate);
                    }
                }

                if ($unitCostUsd === null && $totalCostUsd !== null && $finalStock !== null && $finalStock > 0) {
                    $unitCostUsd = round($totalCostUsd / $finalStock, 2);
                }

                $configFactor = ProductInventoryConfig::on('budget')
                    ->where('product_id', $product->id)
                    ->value('factor_caja') ?? 1;

                $retail = $this->number($product->regular_price) ?? 0;
                $costUsd = $unitCostUsd ?? $this->number($product->cost_usd) ?? $this->number($product->avg_cost_usd) ?? 0;

                $pctCosto = ($retail > 0)
                    ? round(($costUsd / $retail) * 100, 2)
                    : 0;

                $pctMargen = ($retail > 0)
                    ? round((($retail - $costUsd) / $retail) * 100, 2)
                    : 0;

                $finalStock = $finalStock ?? 0;
                $calculatedTotalCostUsd = $totalCostUsd ?? round($finalStock * $costUsd, 2);

                Inventory::on('budget')->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'store_id' => $this->store->id,
                        'toDate' => $toDate,
                    ],
                    [
                        'batch_id' => $this->batchId,

                        'stock_actual' => $finalStock,
                        'stock_teorico' => $finalStock,
                        'factor_caja' => $configFactor,

                        'existencia_anterior' => $initialStock,
                        'compras' => $unitPurchase,
                        'ventas' => $unitSales,
                        'entrada' => null,
                        'salida' => $disposed,
                        'existencia_final' => $finalStock,

                        'costo_unitario' => $costUsd,
                        'total_inv_final' => $calculatedTotalCostUsd,
                        'costo_unitario_usd' => $unitCostUsd,
                        'valor_final_usd' => $calculatedTotalCostUsd,
                        't_cambio' => $exchangeRate,
                        'cogs' => null,

                        'proveedor' => $product->provider_name,
                        'supplier' => $product->provider_name,
                        'brand' => $product->brand,
                        'upc1' => $product->upc,
                        'upc2' => $product->upc2,
                        'upc3' => $product->upc3,
                        'retail' => $retail,
                        'pct_costo' => $pctCosto,
                        'pct_margen' => $pctMargen,

                        'days_in_stock' => $daysInStock,
                        'last_purchase_date' => $lastPurchaseDate,
                        'last_sale_date' => $lastSaleDate,
                        'without_sales_days' => $withoutSalesDays,
                        'toDate' => $toDate,
                    ]
                );

                $this->rowsImported++;
            }
        });
    }

    public function getRowsImported(): int
    {
        return $this->rowsImported;
    }

    private function pick(Collection|array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($row) && array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }

            if ($row instanceof Collection && $row->has($key) && $row->get($key) !== null && $row->get($key) !== '') {
                return $row->get($key);
            }
        }

        return null;
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function int(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);
        return is_numeric($text) ? (int) $text : null;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);
        $text = str_replace(['$', ' '], '', $text);

        if ($text === '' || $text === '-') {
            return null;
        }

        $text = str_replace(',', '', $text);

        return is_numeric($text) ? (float) $text : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Date::excelToDateTimeObject($value)->format('Y-m-d');
            }

            $text = trim((string) $value);

            foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $format) {
                try {
                    return Carbon::createFromFormat($format, $text)->format('Y-m-d');
                } catch (\Throwable $e) {
                }
            }

            return Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
