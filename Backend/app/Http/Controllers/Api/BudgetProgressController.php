<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comisiones\Budget;
use App\Models\Comisiones\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BudgetProgressController extends Controller
{
    public function daily()
    {
        $today = now()->toDateString();


        $budget = Budget::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();


        if (!$budget) {
            return response()->json([
                'active' => false,
                'message' => 'No hay presupuesto activo'
            ]);
        }

        // Ventas agrupadas por día
        $salesByDay = Sale::selectRaw('sale_date, SUM(value_usd) as total')
            ->whereBetween('sale_date', [
                $budget->start_date,
                $budget->end_date
            ])
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->keyBy('sale_date');

        $days = [];
        $accumulated = 0;

        $period = Carbon::parse($budget->start_date)
            ->daysUntil(min($today, Carbon::parse($budget->end_date)));

        foreach ($period as $date) {
            $dateStr = $date->toDateString();
            $daily = $salesByDay[$dateStr]->total ?? 0;
            $accumulated += $daily;

            $days[] = [
                'date' => $dateStr,
                'daily_sales' => round($daily, 2),
                'accumulated_sales' => round($accumulated, 2),
                'compliance_pct' => $budget->target_amount > 0
                    ? round(($accumulated / $budget->target_amount) * 100, 2)
                    : 0,
            ];
        }

        // Meta diaria teórica
        $totalDays = Carbon::parse($budget->start_date)
            ->diffInDays($budget->end_date) + 1;

        $dailyTarget = round($budget->target_amount / $totalDays, 2);

        return response()->json([
            'active' => true,
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'target_amount' => $budget->target_amount,
                'start_date' => $budget->start_date,
                'end_date' => $budget->end_date,
            ],
            'daily_target' => $dailyTarget,
            'days' => $days
        ]);
    }
}
