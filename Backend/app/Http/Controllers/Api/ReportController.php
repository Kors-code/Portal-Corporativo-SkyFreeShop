<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CashierAwardsExport;

class ReportController extends Controller
{
    /**
     * Helper: conexión a la BD de budgets (usa la conexión 'budget').
     */
    protected function budgetDB()
    {
        return DB::connection('budget');
    }

    /**
     * Devuelve el premio activado (en int) en función del cumplimiento y
     * del premio definido como "prize at 120%" ($prizeAt120).
     *
     * Reglas fijas:
     *  - cumplimiento < 80   => 0
     *  - 80 <= cumplimiento < 100 => 2/3 * prizeAt120
     *  - 100 <= cumplimiento < 120 => 5/6 * prizeAt120
     *  - cumplimiento >= 120 => prizeAt120
     */
    protected function prizeByCompliance(float $cumplimiento, int $prizeAt120): int
    {
        if ($prizeAt120 <= 0) {
            return 0;
        }

        if ($cumplimiento < 80) {
            return 0;
        }

        if ($cumplimiento < 100) {
            return (int) round($prizeAt120 * (2 / 3), 0); // 66.666...%
        }

        if ($cumplimiento < 120) {
            return (int) round($prizeAt120 * (5 / 6), 0); // 83.333...%
        }

        return (int) $prizeAt120;
    }

    /**
     * Premios por cajero (lista)
     * Opciones:
     * - budget_id (si se pasa, filtra por periodo del presupuesto y lee cashier_prize)
     * - year & month (si no hay budget_id, por defecto year=2025, month=10)
     *
     * El valor `cashier_prize` en budgets representa el premio cuando el cumplimiento = 120%.
     */
    public function cashierAwards(Request $request)
    {
        $year     = (int) $request->query('year', 2025);
        $month    = (int) $request->query('month', 10);
        $budgetId = $request->query('budget_id');

        // rol cajero (usando conexión 'budget')
        $cajeroRoleId = $this->budgetDB()->table('roles')
            ->whereRaw("LOWER(name) = 'cajero'")
            ->value('id');

        // periodo y meta/prize
        $TOTAL_PRIZE = 0; // prize at 120% (default 0)
        if ($budgetId) {
            $budget = $this->budgetDB()->table('budgets')->where('id', $budgetId)->first();
            if (!$budget) {
                return response()->json(['error' => 'Budget not found'], 404);
            }

            $start = $budget->start_date;
            $end   = $budget->end_date;

            // meta (ventas cruzadas por ejemplo 1.5% del target_amount)
            $metaUsd = round(($budget->target_amount ?? 0) * 0.015, 2);

            // premio que el usuario digitó en el budget — representa el premio para 120%
            $TOTAL_PRIZE = (int) ($budget->cashier_prize ?? 0);
        } else {
            // fallback por year/month (sin budget)
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-t', strtotime($start));
            $metaUsd = 0;

            // Si no hay budget, permitimos que la API reciba un prize via query param (opcional)
            $TOTAL_PRIZE = (int) $request->query('total_prize', 0);
        }

        /* Ventas reales por usuario (cajeros) — usando conexión 'budget' */
        $rows = $this->budgetDB()->table('sales as s')
            ->join('users as u', 'u.id', '=', 's.seller_id')
            ->join('user_roles as ur', function ($join) use ($cajeroRoleId) {
                $join->on('ur.user_id', '=', 'u.id')
                     ->on('ur.start_date', '=', 's.sale_date')
                     ->where('ur.role_id', '=', $cajeroRoleId);
            })
            ->whereBetween('s.sale_date', [$start, $end])
            ->selectRaw("
                u.id as user_id,
                u.name,
                SUM(
                    COALESCE(
                        s.value_usd,
                        CASE WHEN COALESCE(s.exchange_rate,0) > 0
                             THEN s.amount_cop / s.exchange_rate
                             ELSE 0 END
                    )
                ) as ventas_usd
            ")
            ->groupBy('u.id','u.name')
            ->get();

        /* total solo calificables (>=500 USD) */
        $totalVentas = $rows->sum(function ($r) {
            $total = $r->ventas_usd;
            return $total >= 500 ? $total : 0;
        });

        $totalSafe = $totalVentas > 0 ? $totalVentas : 1;

        // cumplimiento total del mes (en porcentaje entero)
        $cumplimiento = $metaUsd > 0
            ? round(($totalVentas / $metaUsd) * 100, 0)
            : 0;

        // calcular premio activado según cumplimiento y el prize definido para 120%
        $effectivePrize = $this->prizeByCompliance($cumplimiento, $TOTAL_PRIZE);

        // mapear filas y repartir proporcionalmente el premio activado
        $data = $rows->map(function ($r) use ($totalSafe, $effectivePrize) {

            $ventas = round($r->ventas_usd, 2);

            // si la venta individual no cumple el mínimo o no hay premio activado
            if ($ventas < 500 || $effectivePrize <= 0) {
                return [
                    'user_id'    => $r->user_id,
                    'nombre'     => $r->name,
                    'ventas_usd' => $ventas,
                    'pct'        => 0,
                    'premiacion' => 0,
                ];
            }

            $pct = $ventas / $totalSafe;

            return [
                'user_id'    => $r->user_id,
                'nombre'     => $r->name,
                'ventas_usd' => $ventas,
                'pct'        => round($pct * 100, 2),
                'premiacion' => (int) round($pct * $effectivePrize, 0),
            ];
        });

        return response()->json([
            'meta_usd'        => $metaUsd,
            'prize_at_120'    => $TOTAL_PRIZE,
            'prize_applied'   => $effectivePrize,
            'total_ventas'    => round($totalVentas, 2),
            'cumplimiento'    => $cumplimiento,
            'rows'            => $data,
            'period'          => ['start' => $start, 'end' => $end],
            'active'          => true
        ]);
    }

    /**
     * Detalle por categoría para un cajero (userId)
     * Parámetros:
     * - budget_id (opcional) => usa period del presupuesto
     * - year & month (opcional) => si no hay budget_id usa month
     */
    public function cashierCategories(Request $request, $userId)
    {
        $year     = (int) $request->query('year', 2025);
        $month    = (int) $request->query('month', 10);
        $budgetId = $request->query('budget_id', null);

        // verificar usuario (en connection 'budget')
        $user = $this->budgetDB()->table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        // periodo
        if ($budgetId) {
            $budget = $this->budgetDB()->table('budgets')->where('id', $budgetId)->first();
            if (!$budget) {
                return response()->json(['error' => 'Budget not found'], 404);
            }
            $start = $budget->start_date;
            $end = $budget->end_date;
        } else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-t', strtotime($start));
        }

        // rol cajero id (connection 'budget')
        $cajeroRoleId = $this->budgetDB()->table('roles')->whereRaw("LOWER(name) = 'cajero'")->value('id');

        // Query: sumar ventas por categoría solo en días donde user fue cajero (connection 'budget')
        $categoryRows = $this->budgetDB()->table('sales as s')
            // join user_roles (day match)
            ->join('user_roles as ur', function ($join) use ($cajeroRoleId, $userId) {
                $join->on('ur.user_id', '=', DB::raw((int)$userId)) // ensure join against constant user id
                     ->on('ur.start_date', '=', 's.sale_date')
                     ->where('ur.role_id', '=', $cajeroRoleId);
            })
            ->leftJoin('products as p', 'p.id', '=', 's.product_id')
            ->where('s.seller_id', $userId)
            ->whereBetween('s.sale_date', [$start, $end])
            ->selectRaw("
                COALESCE(NULLIF(TRIM(p.classification),''), 'Sin categoría') as classification,
                SUM(
                    COALESCE(
                        s.value_usd,
                        CASE WHEN COALESCE(s.exchange_rate,0) > 0 THEN s.amount_cop / s.exchange_rate ELSE 0 END
                    )
                ) as sales_usd,
                SUM(COALESCE(s.amount_cop,0)) as sales_cop,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio,''), CONCAT(s.id))) as tickets
            ")
            ->groupBy('classification')
            ->orderByDesc('sales_usd')
            ->get();

        // totals (connection 'budget')
        $totals = $this->budgetDB()->table('sales as s')
            ->join('user_roles as ur', function ($join) use ($cajeroRoleId, $userId) {
                $join->on('ur.user_id', '=', DB::raw((int)$userId))
                     ->on('ur.start_date', '=', 's.sale_date')
                     ->where('ur.role_id', '=', $cajeroRoleId);
            })
            ->where('s.seller_id', $userId)
            ->whereBetween('s.sale_date', [$start, $end])
            ->selectRaw("
                SUM(
                    COALESCE(
                        s.value_usd,
                        CASE WHEN COALESCE(s.exchange_rate,0) > 0 THEN s.amount_cop / s.exchange_rate ELSE 0 END
                    )
                ) as total_sales_usd,
                SUM(COALESCE(s.amount_cop,0)) as total_sales_cop,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio,''), CONCAT(s.id))) as tickets_count
            ")
            ->first();

        $totalSalesUsd = (float) ($totals->total_sales_usd ?? 0);
        $totalSalesCop = (int)   ($totals->total_sales_cop ?? 0);
        $ticketsCount   = (int)  ($totals->tickets_count ?? 0);
        $totalUsdNonZero = $totalSalesUsd > 0 ? $totalSalesUsd : 1;

        // map categories and compute pct
        $categories = collect($categoryRows)->map(function ($c) use ($totalUsdNonZero) {
            $salesUsd = (float) $c->sales_usd;
            $pct = round(($salesUsd / $totalUsdNonZero) * 100, 2);
            return [
                'classification' => $c->classification,
                'sales_usd'      => round($salesUsd, 2),
                'sales_cop'      => (int) $c->sales_cop,
                'tickets'        => (int) $c->tickets,
                'pct_of_total'   => $pct,
            ];
        });

        return response()->json([
            'cashier'   => ['id' => $user->id, 'name' => $user->name],
            'period'    => ['start' => $start, 'end' => $end],
            'summary'   => [
                'total_sales_usd' => round($totalSalesUsd, 2),
                'tickets_count'   => $ticketsCount
            ],
            'categories' => $categories,
        ]);
    }

    // Exportar Excel
    public function cashierAwardsExport(Request $request)
    {
        // reutilizar respuesta existente
        $response = $this->cashierAwards($request);
        $data = json_decode($response->getContent(), true);

        if (!$data || empty($data['rows'])) {
            return response()->json(['message' => 'No hay datos para exportar'], 422);
        }

        $rows = [];
        foreach ($data['rows'] as $r) {
            $rows[] = [
                $r['user_id'] ?? null,
                $r['nombre'] ?? '',
                $r['ventas_usd'] ?? 0,
                $r['pct'] ?? 0,
                $r['premiacion'] ?? 0,
            ];
        }

        $filename = 'cashier_awards_' . date('Ymd_His') . '.xlsx';

        return Excel::download(
            new CashierAwardsExport($rows),
            $filename
        );
    }
}
