<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CashierAwardsExport;

class ReportController extends Controller
{
    /**
     * Helper: conexión a la BD de budgets.
     */
    protected function budgetDB()
    {
        return DB::connection('budget');
    }

    /**
     * Devuelve el premio activado en función del cumplimiento.
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
            return (int) round($prizeAt120 * (2 / 3), 0);
        }

        if ($cumplimiento < 120) {
            return (int) round($prizeAt120 * (5 / 6), 0);
        }

        return (int) $prizeAt120;
    }

    /**
     * Premios por cajero.
     * Regla final:
     *  - seller_id debe ser el usuario
     *  - cashier debe coincidir con el nombre del usuario
     *  - solo COLS1 / COLS2
     *  - suma solo value_usd
     */
    public function cashierAwards(Request $request)
    {
        $year     = (int) $request->query('year', 2025);
        $month    = (int) $request->query('month', 10);
        $budgetId = $request->query('budget_id');

        $cajeroRoleId = $this->budgetDB()->table('roles')
            ->whereRaw("LOWER(name) IN ('cajero', 'cashier')")
            ->value('id');

        if (!$cajeroRoleId) {
            return response()->json(['error' => 'Role cajero no encontrado'], 404);
        }

                    if ($budgetId) {
            $budget = $this->budgetDB()
                ->table('budgets')
                ->where('id', $budgetId)
                ->first();

            if (!$budget) {
                return response()->json([
                    'error' => 'Budget not found'
                ], 404);
            }

            $start = $budget->start_date;
            $end   = $budget->end_date;

            $metaUsd = round(($budget->target_amount ?? 0) * 0.025, 2);

            $PRIZE_80  = (float) ($budget->cashier_prize_80 ?? 0);
            $PRIZE_100 = (float) ($budget->cashier_prize_100 ?? 0);
            $PRIZE_120 = (float) ($budget->cashier_prize_120 ?? 0);
        }  else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-t', strtotime($start));
            $metaUsd = 0;
            $TOTAL_PRIZE = (int) $request->query('total_prize', 0);
        }

        $hasBudgetId = Schema::connection('budget')->hasColumn('sales', 'budget_id');

        $rows = $this->budgetDB()->table('users as u')
            ->join('sales as s', function ($join) {
                $join->on('s.seller_id', '=', 'u.id');
            })
            ->whereExists(function ($q) use ($cajeroRoleId) {
                $q->selectRaw('1')
                    ->from('user_roles as ur')
                    ->whereColumn('ur.user_id', 'u.id')
                    ->where('ur.role_id', '=', $cajeroRoleId)
                    ->whereColumn('ur.start_date', '<=', 's.sale_date')
                    ->where(function ($q2) {
                        $q2->whereNull('ur.end_date')
                           ->orWhereColumn('ur.end_date', '>=', 's.sale_date');
                    });
            })
            ->whereRaw('UPPER(TRIM(s.cashier)) = UPPER(TRIM(u.name))')
            ->whereBetween('s.sale_date', [$start, $end])
            ->whereIn('s.pdv', ['COLS1', 'COLS2'])
            ->when($budgetId && $hasBudgetId, function ($q) use ($budgetId) {
                $q->where('s.budget_id', $budgetId);
            })
            ->selectRaw("
                u.id as user_id,
                u.name,
                SUM(COALESCE(s.value_usd, 0)) as ventas_usd
            ")
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('ventas_usd')
            ->get();

        $totalVentas = $rows->sum(function ($r) {
            $total = (float) $r->ventas_usd;
            return $total >= 500 ? $total : 0;
        });

        $totalSafe = $totalVentas > 0 ? $totalVentas : 1;

        $cumplimiento = $metaUsd > 0
            ? round(($totalVentas / $metaUsd) * 100, 0)
            : 0;

        if ($cumplimiento < 80) {
            $effectivePrize = 0;
        } elseif ($cumplimiento < 100) {
            $effectivePrize = $PRIZE_80;
        } elseif ($cumplimiento < 120) {
            $effectivePrize = $PRIZE_100;
        } else {
            $effectivePrize = $PRIZE_120;
        }

        $data = $rows->map(function ($r) use ($totalSafe, $effectivePrize) {
            $ventas = round((float) $r->ventas_usd, 2);

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
        })->values();

        return response()->json([
            'meta_usd'      => $metaUsd,
            'prize_80' => $PRIZE_80,
            'prize_100' => $PRIZE_100,
            'prize_120' => $PRIZE_120,
            'prize_applied' => $effectivePrize,
            'total_ventas'  => round($totalVentas, 2),
            'cumplimiento'  => $cumplimiento,
            'rows'          => $data,
            'period'        => ['start' => $start, 'end' => $end],
            'active'        => true,
        ]);
    }

    /**
     * Detalle por categoría para un cajero.
     * Misma regla exacta que arriba.
     */
    public function cashierCategories(Request $request, $userId)
    {
        $year     = (int) $request->query('year', 2025);
        $month    = (int) $request->query('month', 10);
        $budgetId = $request->query('budget_id', null);

        $user = $this->budgetDB()->table('users')->where('id', $userId)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($budgetId) {
            $budget = $this->budgetDB()->table('budgets')->where('id', $budgetId)->first();
            if (!$budget) {
                return response()->json(['error' => 'Budget not found'], 404);
            }
            $start = $budget->start_date;
            $end   = $budget->end_date;
        } else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end   = date('Y-m-t', strtotime($start));
        }

        $cajeroRoleId = $this->budgetDB()->table('roles')
            ->whereRaw("LOWER(name) IN ('cajero', 'cashier')")
            ->value('id');

        if (!$cajeroRoleId) {
            return response()->json(['error' => 'Role cajero no encontrado'], 404);
        }

        $hasBudgetId = Schema::connection('budget')->hasColumn('sales', 'budget_id');

        $categoryRows = $this->budgetDB()->table('sales as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->whereExists(function ($q) use ($cajeroRoleId, $userId) {
                $q->selectRaw('1')
                    ->from('user_roles as ur')
                    ->where('ur.user_id', '=', (int) $userId)
                    ->where('ur.role_id', '=', $cajeroRoleId)
                    ->whereColumn('ur.start_date', '<=', 's.sale_date')
                    ->where(function ($q2) {
                        $q2->whereNull('ur.end_date')
                           ->orWhereColumn('ur.end_date', '>=', 's.sale_date');
                    });
            })
            ->where('s.seller_id', $userId)
            ->whereRaw('UPPER(TRIM(s.cashier)) = ?', [mb_strtoupper(trim((string) $user->name))])
            ->whereBetween('s.sale_date', [$start, $end])
            ->whereIn('s.pdv', ['COLS1', 'COLS2'])
            ->when($budgetId && $hasBudgetId, function ($q) use ($budgetId) {
                $q->where('s.budget_id', $budgetId);
            })
            ->selectRaw("
                COALESCE(NULLIF(TRIM(p.classification),''), 'Sin categoría') as classification,
                SUM(COALESCE(s.value_usd, 0)) as sales_usd,
                SUM(COALESCE(s.amount_cop, 0)) as sales_cop,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio,''), CONCAT(s.id))) as tickets
            ")
            ->groupBy('classification')
            ->orderByDesc('sales_usd')
            ->get();

        $totals = $this->budgetDB()->table('sales as s')
            ->whereExists(function ($q) use ($cajeroRoleId, $userId) {
                $q->selectRaw('1')
                    ->from('user_roles as ur')
                    ->where('ur.user_id', '=', (int) $userId)
                    ->where('ur.role_id', '=', $cajeroRoleId)
                    ->whereColumn('ur.start_date', '<=', 's.sale_date')
                    ->where(function ($q2) {
                        $q2->whereNull('ur.end_date')
                           ->orWhereColumn('ur.end_date', '>=', 's.sale_date');
                    });
            })
            ->where('s.seller_id', $userId)
            ->whereRaw('UPPER(TRIM(s.cashier)) = ?', [mb_strtoupper(trim((string) $user->name))])
            ->whereBetween('s.sale_date', [$start, $end])
            ->whereIn('s.pdv', ['COLS1', 'COLS2'])
            ->when($budgetId && $hasBudgetId, function ($q) use ($budgetId) {
                $q->where('s.budget_id', $budgetId);
            })
            ->selectRaw("
                SUM(COALESCE(s.value_usd, 0)) as total_sales_usd,
                SUM(COALESCE(s.amount_cop, 0)) as total_sales_cop,
                COUNT(DISTINCT COALESCE(NULLIF(s.folio,''), CONCAT(s.id))) as tickets_count
            ")
            ->first();

        $totalSalesUsd = (float) ($totals->total_sales_usd ?? 0);
        $totalSalesCop = (int)   ($totals->total_sales_cop ?? 0);
        $ticketsCount  = (int)   ($totals->tickets_count ?? 0);
        $totalUsdNonZero = $totalSalesUsd > 0 ? $totalSalesUsd : 1;

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
        })->values();

        return response()->json([
            'cashier' => ['id' => $user->id, 'name' => $user->name],
            'period'  => ['start' => $start, 'end' => $end],
            'summary' => [
                'total_sales_usd' => round($totalSalesUsd, 2),
                'total_sales_cop' => $totalSalesCop,
                'tickets_count'   => $ticketsCount,
            ],
            'categories' => $categories,
        ]);
    }

    /**
     * Exportar Excel.
     */
    public function cashierAwardsExport(Request $request)
    {
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