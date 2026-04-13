<?php

namespace App\Services\Inventario;

use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryReportService
{
    public function getReport(?string $search = null, ?int $storeId = null): array
    {
        $query = Product::query()
            ->join('inventory as i', function ($join) use ($storeId) {
                $join->on('products.id', '=', 'i.product_id');

                if ($storeId) {
                    $join->where('i.store_id', $storeId);
                }
            })
            ->select([
                'products.id',
                'products.product_code',
                'products.description',
                'products.classification_desc',
                'products.brand',
                'products.regular_price',
                'products.cost_usd',
                'products.avg_cost_usd',
                DB::raw('SUM(i.existencia_final) as stock_actual'),
            ])
            ->groupBy(
                'products.id',
                'products.product_code',
                'products.description',
                'products.classification_desc',
                'products.brand',
                'products.regular_price',
                'products.cost_usd',
                'products.avg_cost_usd'
            );

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('products.product_code', 'like', "%{$search}%")
                    ->orWhere('products.description', 'like', "%{$search}%")
                    ->orWhere('products.brand', 'like', "%{$search}%");
            });
        }

        $from = Carbon::now()->subMonths(12)->startOfDay()->toDateString();
        $to = Carbon::now()->endOfDay()->toDateString();

        /**
         * MAXIMO MES
         * Se calcula como el mayor total mensual de ventas por producto.
         */
        $maximosMes = DB::connection('budget')
            ->table(DB::raw('(
                SELECT product_id, DATE_FORMAT(sale_date, "%Y-%m") as ym, SUM(quantity) as total
                FROM inventory
                WHERE sale_date BETWEEN "' . $from . '" AND "' . $to . '"
                ' . ($storeId ? 'AND store_id = ' . (int) $storeId : '') . '
                GROUP BY product_id, ym
            ) as t'))
            ->selectRaw('product_id, MAX(total) as maximo_mes')
            ->groupBy('product_id')
            ->pluck('maximo_mes', 'product_id');

        return $query->get()->map(function ($product) use ($maximosMes) {
            $stockActual = (float) $product->stock_actual;
            $cost = (float) ($product->avg_cost_usd ?? $product->cost_usd ?? 0);
            $retail = (float) ($product->regular_price ?? 0);

            $maximoMes = (float) ($maximosMes[$product->id] ?? 0);
            $maximoDia = $maximoMes > 0 ? round($maximoMes / 30, 2) : 0;
            $diasEnExistencia = $maximoDia > 0 ? round($stockActual / $maximoDia, 1) : null;

            return [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'description' => $product->description,
                'classification_desc' => $product->classification_desc,
                'existencia_final' => $stockActual,
                'factor_caja' => $product->factor_caja ?? 1,
                'cost_unitario' => round($cost, 2),
                'total_inv_final' => round($stockActual * $cost, 2),
                'cost_unitario_usd' => round($cost, 2),
                'valor_final_usd' => round($stockActual * $cost, 2),
                'cogs' => 0,
                'proveedor' => $product->provider_name ?? '-',
                'supplier' => $product->provider_name ?? '-',
                'brand' => $product->brand ?? '-',
                'retail' => $retail,
                'pct_costo' => $retail > 0 ? round(($cost / $retail) * 100, 2) : 0,
                'pct_margen' => $retail > 0 ? round((($retail - $cost) / $retail) * 100, 2) : 0,
                'maximo_mes' => $maximoMes,
                'maximo_dia' => $maximoDia,
                'ind_rot_stock' => 0,
                'ind_rot_promedio' => 0,
                'dias_en_existencia' => $diasEnExistencia,
                'fecha_ultima_venta' => null,
                'dias_sin_ventas' => null,
                'promedio_diario' => 0,
            ];
        })->values()->all();
    }
}