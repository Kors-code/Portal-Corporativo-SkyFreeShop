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

        if ($search && trim($search) !== '') {
            $term = '%' . trim($search) . '%';

            $catalogKeys = DB::connection('budget')
                ->table('products as p_search')
                ->crossJoin('stores as st_search')
                ->select('p_search.id as product_id', 'st_search.id as store_id')
                ->when(!empty($storeIds), fn ($q) => $q->whereIn('st_search.id', $storeIds))
                ->where(function ($q) use ($term) {
                    $q->where('p_search.product_code', 'like', $term)
                        ->orWhere('p_search.sku_mia', 'like', $term)
                        ->orWhere('p_search.upc', 'like', $term)
                        ->orWhere('p_search.upc2', 'like', $term)
                        ->orWhere('p_search.upc3', 'like', $term)
                        ->orWhere('p_search.description', 'like', $term)
                        ->orWhere('p_search.classification_desc', 'like', $term)
                        ->orWhere('p_search.brand', 'like', $term)
                        ->orWhere('p_search.provider_name', 'like', $term);
                });

            $basePairs = $basePairs->union($catalogKeys);
        }

        $salesStoreCodeSql = $this->salesStoreCodeSql('st.code');

        $query = DB::connection('budget')
            ->query()
            ->fromSub($basePairs, 'base')
            ->join('products as p', 'p.id', '=', 'base.product_id')
            ->leftJoin('stores as st', 'st.id', '=', 'base.store_id')
            ->leftJoin('stores as sales_st', function ($join) use ($salesStoreCodeSql) {
                $join->on(DB::raw('UPPER(REPLACE(sales_st.code, " ", ""))'), '=', DB::raw($salesStoreCodeSql));
            })
            ->leftJoin('product_metrics as pm', function ($join) {
                $join->on('pm.product_id', '=', 'base.product_id')
                    ->on('pm.store_id', '=', DB::raw('COALESCE(sales_st.id, base.store_id)'));
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
                'sales_st.id as sales_store_id',
                'sales_st.code as sales_store_code',
                'sales_st.name as sales_store_name',
                'p.product_code',
                'p.description',
                'p.classification_desc',
                DB::raw('COALESCE(inv.stock_actual, 0) as stock_actual'),
                DB::raw('COALESCE(inv.factor_caja, 1) as factor_caja'),
                DB::raw('COALESCE(pm.total_ventas, 0) as total_ventas'),
                DB::raw('COALESCE(pm.maximo_mes, 0) as maximo_mes'),
                DB::raw('COALESCE(pm.maximo_dia, 0) as maximo_dia'),
                DB::raw('COALESCE(pm.promedio_diario, 0) as promedio_diario'),
                DB::raw('COALESCE(inv.proveedor, p.provider_name) as proveedor'),
                DB::raw('COALESCE(inv.supplier, p.provider_name) as supplier'),
                DB::raw('COALESCE(inv.brand, p.brand) as brand'),
                DB::raw('COALESCE(inv.retail, p.regular_price, 0) as retail'),
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
                        ->orWhere('p.sku_mia', 'like', $term)
                        ->orWhere('p.upc', 'like', $term)
                        ->orWhere('p.upc2', 'like', $term)
                        ->orWhere('p.upc3', 'like', $term)
                        ->orWhere('p.brand', 'like', $term)
                        ->orWhere('p.provider_name', 'like', $term)
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

        return $this->mergeLinkedLocations($query)->map(function ($row) {
            $monthColumns = json_decode($row->monthly_sales_json ?? '{}', true);
            if (!is_array($monthColumns)) {
                $monthColumns = [];
            }

            $monthColumns = collect($monthColumns)
                ->mapWithKeys(fn ($value, $key) => [(string) $key => (float) $value])
                ->sortKeysUsing(function (string $left, string $right) {
                    return $this->monthKeyTimestamp($left) <=> $this->monthKeyTimestamp($right);
                })
                ->all();

            $totalGeneral = array_sum($monthColumns);
            $maximoMes = !empty($monthColumns) ? max($monthColumns) : (float) ($row->maximo_mes ?? 0);
            $maximoMesKey = null;
            foreach ($monthColumns as $monthKey => $monthValue) {
                if ((float) $monthValue === (float) $maximoMes) {
                    $maximoMesKey = $monthKey;
                    break;
                }
            }

            $stockActual = (float) ($row->stock_actual ?? 0);
            $maximoDia = (float) ($row->maximo_dia ?? 0);
            $rotDiaMes = $maximoMes > 0 ? $maximoMes / 30 : 0;
            $diasDisponibles = $rotDiaMes > 0 ? $stockActual / $rotDiaMes : 0;
            $alerta = $this->resolveStockAlert($diasDisponibles, $stockActual, $rotDiaMes);

            return [
                'product_id' => (int) $row->product_id,
                'store_id' => (int) $row->store_id,
                'store_code' => $row->store_code,
                'store_name' => $row->store_name,
                'sales_store_id' => $row->sales_store_id ? (int) $row->sales_store_id : null,
                'sales_store_code' => $row->sales_store_code,
                'sales_store_name' => $row->sales_store_name,
                'product_code' => $row->product_code,
                'description' => $row->description,
                'classification_desc' => $row->classification_desc,
                'stock_actual' => $stockActual,
                'factor_caja' => (float) ($row->factor_caja ?? 1),
                'total_ventas' => (float) ($row->total_ventas ?? 0),
                'total_general' => (float) $totalGeneral,
                'maximo_mes' => (float) $maximoMes,
                'maximo_mes_key' => $maximoMesKey,
                'maximo_dia' => $maximoDia,
                'rotacion_diaria_mes' => $rotDiaMes,
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

    private function mergeLinkedLocations($rows)
    {
        return collect($rows)
            ->groupBy(function ($row) {
                return $row->product_id . ':' . $this->inventoryProductGroupCode((string) ($row->store_code ?? ''));
            })
            ->map(function ($group) {
                if ($group->count() === 1) {
                    return $group->first();
                }

                $primary = $group->first(function ($row) {
                    return $this->normalizeStoreCode((string) ($row->store_code ?? '')) ===
                        $this->normalizeStoreCode((string) ($row->sales_store_code ?? ''));
                }) ?? $group->first();

                $stock = $group->sum(fn ($row) => (float) ($row->stock_actual ?? 0));
                $salesMetricRows = $this->uniqueSalesMetricRows($group);
                $monthColumns = $this->mergeMonthlySales($salesMetricRows->pluck('monthly_sales_json')->all());
                $storeLabels = $group
                    ->map(fn ($row) => $this->inventoryGroupCode((string) ($row->store_code ?? '')))
                    ->filter()
                    ->unique()
                    ->values();

                $primary->stock_actual = $stock;
                $primary->total_ventas = $salesMetricRows->sum(fn ($row) => (float) ($row->total_ventas ?? 0));
                $primary->maximo_dia = $salesMetricRows->sum(fn ($row) => (float) ($row->maximo_dia ?? 0));
                $primary->promedio_diario = $salesMetricRows->sum(fn ($row) => (float) ($row->promedio_diario ?? 0));
                $primary->monthly_sales_json = json_encode($monthColumns);

                if ($storeLabels->count() > 1) {
                    $label = $storeLabels->implode(' + ');
                    $primary->store_code = $label;
                    $primary->store_name = $label;
                    $primary->sales_store_id = null;
                    $primary->sales_store_code = null;
                    $primary->sales_store_name = null;
                } else {
                    $primary->store_id = $primary->sales_store_id ?? $primary->store_id;
                    $primary->store_code = $primary->sales_store_code ?? $primary->store_code;
                    $primary->store_name = $primary->sales_store_name ?? $primary->store_name;
                }

                $inventoryDates = $group
                    ->pluck('last_inventory_date')
                    ->filter()
                    ->sort()
                    ->values();

                if ($inventoryDates->isNotEmpty()) {
                    $primary->last_inventory_date = $inventoryDates->last();
                }

                return $primary;
            })
            ->values();
    }

    private function uniqueSalesMetricRows($rows)
    {
        return collect($rows)->unique(function ($row) {
            return $this->normalizeStoreCode(
                (string) ($row->sales_store_code ?? $row->store_code ?? '')
            );
        })->values();
    }

    private function mergeMonthlySales(array $monthlySalesJson): array
    {
        $months = [];

        foreach ($monthlySalesJson as $json) {
            $decoded = json_decode($json ?? '{}', true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $month => $value) {
                $key = (string) $month;
                $months[$key] = ($months[$key] ?? 0) + (float) $value;
            }
        }

        uksort($months, fn (string $left, string $right) => $this->monthKeyTimestamp($left) <=> $this->monthKeyTimestamp($right));

        return $months;
    }

    private function inventoryGroupCode(string $storeCode): string
    {
        return $this->salesStoreCodeFor($storeCode) ?? $this->normalizeStoreCode($storeCode);
    }

    private function inventoryProductGroupCode(string $storeCode): string
    {
        $code = $this->inventoryGroupCode($storeCode);

        return in_array($code, ['COLS1', 'COLS2'], true) ? 'COLS' : $code;
    }

    private function salesStoreCodeFor(string $storeCode): ?string
    {
        $code = $this->normalizeStoreCode($storeCode);

        if (preg_match('/^COLB(\d+)$/', $code, $matches)) {
            return 'COLS' . $matches[1];
        }

        return match ($code) {
            'DEPARTURES' => 'COLS1',
            'ARRIVALS' => 'COLS2',
            default => $code ?: null,
        };
    }

    private function normalizeStoreCode(string $storeCode): string
    {
        return strtoupper(str_replace(' ', '', trim($storeCode)));
    }

    private function salesStoreCodeSql(string $column): string
    {
        $normalized = 'UPPER(REPLACE(' . $column . ', " ", ""))';

        return 'CASE ' .
            'WHEN ' . $normalized . ' REGEXP "^COLB[0-9]+$" THEN CONCAT("COLS", SUBSTRING(' . $normalized . ', 5)) ' .
            'WHEN ' . $normalized . ' = "DEPARTURES" THEN "COLS1" ' .
            'WHEN ' . $normalized . ' = "ARRIVALS" THEN "COLS2" ' .
            'ELSE ' . $normalized . ' END';
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

    private function monthKeyTimestamp(string $monthKey): int
    {
        [$month, $year] = array_pad(explode('.', $monthKey, 2), 2, '0');

        $month = max(1, min(12, (int) $month));
        $year = (int) $year;
        $year += $year < 100 ? 2000 : 0;

        return (int) sprintf('%04d%02d', $year, $month);
    }
}
