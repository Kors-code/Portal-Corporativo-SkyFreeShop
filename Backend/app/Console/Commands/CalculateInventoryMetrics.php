<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class CalculateInventoryMetrics extends Command
{
    protected $signature = 'inventory:metrics {--store_id=}';
    protected $description = 'Calculate and cache inventory metrics by store';

    public function handle()
    {
        $storeId = $this->option('store_id');
        $storeId = $storeId !== null && $storeId !== '' ? (int) $storeId : null;

        $dailySalesQuery = DB::connection('budget')->table('sales as s')
            ->selectRaw('
                s.product_id,
                s.store_id,
                s.sale_date,
                SUM(COALESCE(s.quantity, 0)) as daily_sales
            ')
            ->whereNotNull('s.sale_date')
            ->when($storeId, fn ($q) => $q->where('s.store_id', $storeId))
            ->groupBy('s.product_id', 's.store_id', 's.sale_date');

        $monthlySalesRows = DB::connection('budget')->query()
            ->fromSub($dailySalesQuery, 'd')
            ->selectRaw('
                product_id,
                store_id,
                DATE_FORMAT(sale_date, "%c.%y") as month_key,
                SUM(daily_sales) as total_month
            ')
            ->groupBy('product_id', 'store_id', DB::raw('DATE_FORMAT(sale_date, "%c.%y")'))
            ->orderBy('product_id')
            ->get();

        $dailyStatsRows = DB::connection('budget')->query()
            ->fromSub($dailySalesQuery, 'd')
            ->selectRaw('
                product_id,
                store_id,
                SUM(daily_sales) as total_ventas,
                MAX(daily_sales) as maximo_dia,
                AVG(daily_sales) as promedio_diario
            ')
            ->groupBy('product_id', 'store_id')
            ->get();

        $monthlyMap = [];
        foreach ($monthlySalesRows as $row) {
            $key = $row->product_id . ':' . $row->store_id;

            if (!isset($monthlyMap[$key])) {
                $monthlyMap[$key] = [];
            }

            $monthlyMap[$key][$row->month_key] = (float) $row->total_month;
        }

        $payload = [];

        foreach ($dailyStatsRows as $row) {
            $key = $row->product_id . ':' . $row->store_id;
            $months = $monthlyMap[$key] ?? [];

            $maximoMes = !empty($months) ? max($months) : 0;
            $maximoDia = (float) ($row->maximo_dia ?? 0);
            $promedioDiario = (float) ($row->promedio_diario ?? 0);
            $totalVentas = (float) ($row->total_ventas ?? 0);

            $rotacion = $maximoDia > 0
                ? round($promedioDiario / $maximoDia, 4)
                : 0;

            $payload[] = [
                'product_id' => (int) $row->product_id,
                'store_id' => (int) $row->store_id,
                'total_ventas' => $totalVentas,
                'maximo_mes' => $maximoMes,
                'maximo_dia' => $maximoDia,
                'promedio_diario' => $promedioDiario,
                'rotacion' => $rotacion,
                'monthly_sales_json' => json_encode($months, JSON_UNESCAPED_UNICODE),
                'last_calculated' => now(),
            ];
        }

        if (!empty($payload)) {
            DB::connection('budget')->table('product_metrics')->upsert(
                $payload,
                ['product_id', 'store_id'],
                ['total_ventas', 'maximo_mes', 'maximo_dia', 'promedio_diario', 'rotacion', 'monthly_sales_json', 'last_calculated']
            );
        }

        $this->info('Inventory metrics calculated successfully.');

        return self::SUCCESS;
    }
}
