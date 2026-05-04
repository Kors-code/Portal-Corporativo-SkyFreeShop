<?php

namespace App\Services\Inventario;

use Illuminate\Support\Facades\DB;

class InventoryReportService
{
    public function getStores(): array
    {
        return DB::connection('budget')
            ->table('stores')
            ->select(['id', 'name', 'code', 'type'])
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function getReport(?string $search = null, ?array $storeIds = null, ?string $asOfDate = null): array
    {
        $storeIds = collect($storeIds ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        $latestInventoryDates = DB::connection('budget')->table('inventory as i')
            ->selectRaw('
                i.product_id,
                i.store_id,
                MAX(i.toDate) as last_inventory_date
            ')
            ->when(!empty($storeIds), fn ($q) => $q->whereIn('i.store_id', $storeIds))
            ->when($asOfDate, fn ($q) => $q->whereDate('i.toDate', '<=', $asOfDate))
            ->groupBy('i.product_id', 'i.store_id');

        $latestInventory = DB::connection('budget')->table('inventory as i')
            ->joinSub($latestInventoryDates, 'ld', function ($join) {
                $join->on('i.product_id', '=', 'ld.product_id')
                    ->on('i.store_id', '=', 'ld.store_id')
                    ->on('i.toDate', '=', 'ld.last_inventory_date');
            })
            ->selectRaw('
                i.product_id,
                i.store_id,
                i.toDate as last_inventory_date,
                COALESCE(i.existencia_final, i.stock_actual, 0) as stock_actual,
                COALESCE(i.factor_caja, 1) as factor_caja,
                i.proveedor,
                i.supplier,
                i.brand,
                COALESCE(i.retail, 0) as retail,
                COALESCE(i.pct_costo, 0) as pct_costo,
                COALESCE(i.pct_margen, 0) as pct_margen,
                i.last_purchase_date,
                i.last_sale_date,
                i.without_sales_days,
                i.days_in_stock,
                i.batch_id
            ');

        $metricKeys = DB::connection('budget')
            ->table('product_metrics as pm')
            ->select('pm.product_id', 'pm.store_id')
            ->when(!empty($storeIds), fn ($q) => $q->whereIn('pm.store_id', $storeIds));

        $inventoryKeys = DB::connection('budget')
            ->table('inventory as i')
            ->select('i.product_id', 'i.store_id')
            ->when(!empty($storeIds), fn ($q) => $q->whereIn('i.store_id', $storeIds))
            ->groupBy('i.product_id', 'i.store_id');

        $basePairs = $metricKeys->union($inventoryKeys);

        $query = DB::connection('budget')
            ->query()
            ->fromSub($basePairs, 'base')
            ->join('products as p', 'p.id', '=', 'base.product_id')
            ->leftJoin('stores as st', 'st.id', '=', 'base.store_id')
            ->leftJoin('product_metrics as pm', function ($join) {
                $join->on('pm.product_id', '=', 'base.product_id')
                    ->on('pm.store_id', '=', 'base.store_id');
            })
            ->leftJoinSub($latestInventory, 'inv', function ($join) {
                $join->on('inv.product_id', '=', 'base.product_id')
                    ->on('inv.store_id', '=', 'base.store_id');
            })
            ->select([
                'p.id as product_id',
                'base.store_id',
                'st.code as store_code',
                'st.name as store_name',
                'p.product_code',
                'p.description',
                'p.classification_desc',
                DB::raw('COALESCE(inv.stock_actual, 0) as stock_actual'),
                DB::raw('COALESCE(inv.factor_caja, 1) as factor_caja'),
                DB::raw('COALESCE(pm.total_ventas, 0) as total_ventas'),
                DB::raw('COALESCE(pm.maximo_mes, 0) as maximo_mes'),
                DB::raw('COALESCE(pm.maximo_dia, 0) as maximo_dia'),
                DB::raw('COALESCE(pm.promedio_diario, 0) as promedio_diario'),
                DB::raw('COALESCE(inv.proveedor, NULL) as proveedor'),
                DB::raw('COALESCE(inv.supplier, NULL) as supplier'),
                DB::raw('COALESCE(inv.brand, NULL) as brand'),
                DB::raw('COALESCE(inv.retail, 0) as retail'),
                DB::raw('COALESCE(inv.pct_costo, 0) as pct_costo'),
                DB::raw('COALESCE(inv.pct_margen, 0) as pct_margen'),
                DB::raw('COALESCE(inv.last_inventory_date, NULL) as last_inventory_date'),
                DB::raw('COALESCE(inv.last_purchase_date, NULL) as last_purchase_date'),
                DB::raw('COALESCE(inv.last_sale_date, NULL) as last_sale_date'),
                DB::raw('COALESCE(inv.without_sales_days, 0) as without_sales_days'),
                DB::raw('COALESCE(inv.days_in_stock, 0) as days_in_stock'),
                DB::raw('COALESCE(inv.batch_id, NULL) as batch_id'),
                DB::raw('COALESCE(pm.monthly_sales_json, "{}") as monthly_sales_json'),
            ])
            ->when($search, function ($q) use ($search) {
                $term = '%' . trim($search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('p.product_code', 'like', $term)
                        ->orWhere('p.description', 'like', $term)
                        ->orWhere('p.classification_desc', 'like', $term)
                        ->orWhere('st.name', 'like', $term)
                        ->orWhere('st.code', 'like', $term)
                        ->orWhere('inv.brand', 'like', $term)
                        ->orWhere('inv.supplier', 'like', $term)
                        ->orWhere('inv.proveedor', 'like', $term);
                });
            })
            ->orderBy('st.name')
            ->orderByDesc('pm.maximo_mes')
            ->get();

        return $query->map(function ($row) {
            $monthColumns = json_decode($row->monthly_sales_json ?? '{}', true);
            if (!is_array($monthColumns)) {
                $monthColumns = [];
            }

            $totalGeneral = array_sum($monthColumns);
            $maximoMes = !empty($monthColumns) ? max($monthColumns) : (float) ($row->maximo_mes ?? 0);
            $stockActual = (float) ($row->stock_actual ?? 0);
            $maximoDia = (float) ($row->maximo_dia ?? 0);
            $rotDia = $maximoMes > 0 ? $maximoMes / 30 : 0;
            $diasDisponibles = $rotDia > 0 ? $stockActual / $rotDia : 0;
            $alerta = $this->resolveStockAlert($diasDisponibles, $stockActual, $maximoDia);
            $totalGeneral = array_sum($monthColumns);
            $maximoMes = !empty($monthColumns) ? max($monthColumns) : (float) ($row->maximo_mes ?? 0);
            $stockActual = (float) ($row->stock_actual ?? 0);



$alerta = $this->resolveStockAlert($diasDisponibles, $stockActual, $rotDia);
            return [
                'product_id' => (int) $row->product_id,
                'store_id' => (int) $row->store_id,
                'store_code' => $row->store_code,
                'store_name' => $row->store_name,
                'product_code' => $row->product_code,
                'description' => $row->description,
                'classification_desc' => $row->classification_desc,
                'stock_actual' => $stockActual,
                'factor_caja' => (float) ($row->factor_caja ?? 1),
                'total_ventas' => (float) ($row->total_ventas ?? 0),
                'total_general' => (float) $totalGeneral,
                'maximo_mes' => (float) $maximoMes,
                'maximo_dia' => $maximoDia,
                'ind_rot_stock' => $maximoDia > 0 ? round($stockActual / $maximoDia, 2) : 0,
                'ind_rot_promedio' => $maximoMes > 0 ? round(((float) ($row->promedio_diario ?? 0)) / $maximoMes, 2) : 0,
                'proveedor' => $row->proveedor,
                'supplier' => $row->supplier,
                'brand' => $row->brand,
                'retail' => (float) ($row->retail ?? 0),
                'pct_costo' => (float) ($row->pct_costo ?? 0),
                'pct_margen' => (float) ($row->pct_margen ?? 0),
                'last_inventory_date' => $row->last_inventory_date,
                'last_purchase_date' => $row->last_purchase_date,
                'last_sale_date' => $row->last_sale_date,
                'without_sales_days' => (int) ($row->without_sales_days ?? 0),
                'days_in_stock' => (int) ($row->days_in_stock ?? 0),
                'batch_id' => $row->batch_id ? (int) $row->batch_id : null,
                'dias_disponibles' => $diasDisponibles,
                'stock_alert_level' => $alerta['level'],
                'stock_alert_label' => $alerta['label'],
                'stock_alert_color' => $alerta['color'],
                'month_columns' => $monthColumns,
            ];
        })->values()->all();
    }

    private function resolveStockAlert(?float $diasDisponibles, float $stockActual, float $maximoDia): array
    {
        if ($stockActual <= 0) {
            return [
                'level' => 'sin_stock',
                'label' => 'Sin stock',
                'color' => 'slate',
            ];
        }

        if ($maximoDia <= 0 || $diasDisponibles === null) {
            return [
                'level' => 'sin_rotacion',
                'label' => 'Sin rotacion',
                'color' => 'sky',
            ];
        }

        if ($diasDisponibles < 7) {
            return [
                'level' => 'critico',
                'label' => 'Critico',
                'color' => 'rose',
            ];
        }

        if ($diasDisponibles < 15) {
            return [
                'level' => 'alto',
                'label' => 'Alto',
                'color' => 'amber',
            ];
        }

        if ($diasDisponibles < 30) {
            return [
                'level' => 'medio',
                'label' => 'Medio',
                'color' => 'yellow',
            ];
        }

        return [
            'level' => 'estable',
            'label' => 'Estable',
            'color' => 'emerald',
        ];
    }
}
