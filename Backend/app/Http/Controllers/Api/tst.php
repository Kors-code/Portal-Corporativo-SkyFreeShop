<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comisiones\Budget;
use App\Models\Comisiones\Commission;
use App\Models\Comisiones\Sale;
use App\Models\Comisiones\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CommissionReportController extends Controller
{
    // Total general de turnos configurable — cámbialo si quieres
    protected int $TOTAL_TURNS = 200;

    public function bySeller(Request $request)
    {
        $today = now()->toDateString();

        $budget = Budget::where('start_date','<=',$today)
            ->where('end_date','>=',$today)
            ->first();

        if (!$budget) {
            return response()->json([
                'active' => false,
                'message' => 'No hay presupuesto activo'
            ]);
        }

        $roleName = $request->query('role_name');

        $query = User::query()
            ->selectRaw("
                users.id AS user_id,
                users.name AS seller,
                COALESCE(users.assigned_turns, 0) AS assigned_turns,
                COUNT(DISTINCT sales.id) AS sales_count,
                COALESCE(SUM(sales.amount_cop), 0) AS total_sales_cop,
                COALESCE(SUM(sales.value_usd), 0) AS total_sales_usd,
                COALESCE(SUM(commissions.commission_amount), 0) AS total_commission_cop,
                AVG(sales.exchange_rate) AS avg_trm
            ")
            ->leftJoin('sales', function ($join) use ($budget) {
                $join->on('sales.seller_id', '=', 'users.id')
                     ->whereBetween('sales.sale_date', [
                         $budget->start_date,
                         $budget->end_date
                     ]);
            })
            ->leftJoin('commissions', function ($join) {
                $join->on('commissions.sale_id', '=', 'sales.id')
                     ->on('commissions.user_id', '=', 'users.id');
            })
            ->groupBy('users.id', 'users.name', 'users.assigned_turns')
            ->orderByDesc('total_sales_cop');

        if ($roleName) {
            $query
                ->join('user_roles', function ($join) {
                    $join->on('user_roles.user_id', '=', 'users.id');
                })
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('roles.name', $roleName)
                ->whereRaw("user_roles.start_date <= ?", [$budget->end_date])
                ->whereRaw(
                    "COALESCE(user_roles.end_date, ?) >= ?",
                    [$budget->start_date, $budget->start_date]
                );
        }

        $rows = $query->get();

        // Totales de turnos
        $totalAssigned = (int) User::sum(DB::raw('COALESCE(assigned_turns,0)'));
        $totalTurns = $this->TOTAL_TURNS;
        $remainingTurns = max(0, $totalTurns - $totalAssigned);

        // Ventas totales USD del periodo (igual que antes)
        $totalUsd = Sale::whereBetween('sale_date', [
            $budget->start_date,
            $budget->end_date
        ])->sum(DB::raw('COALESCE(value_usd,0)'));

        $pct = $budget->target_amount > 0
            ? round(($totalUsd / $budget->target_amount) * 100, 2)
            : 0;

        $isProvisional = $pct < $budget->min_pct_to_qualify;
        $requiredUsd = round($budget->target_amount * ($budget->min_pct_to_qualify / 100), 2);
        $missingUsd = max(0, round($requiredUsd - $totalUsd, 2));

        return response()->json([
            'active' => true,
            'currency' => 'COP',
            'budget' => [
                'id'=>$budget->id,
                'name'=>$budget->name,
                'start_date'=>$budget->start_date,
                'end_date'=>$budget->end_date,
                'target_amount'=>$budget->target_amount,
                'min_pct_to_qualify'=>$budget->min_pct_to_qualify
            ],
            'progress' => [
                'pct' => $pct,
                'min_pct' => $budget->min_pct_to_qualify,
                'missing_usd' => $missingUsd,
                'is_provisional' => $isProvisional,
                'total_usd' => round($totalUsd, 2),
                'required_usd' => $requiredUsd
            ],
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => $remainingTurns,
            ],
            'sellers' => $rows
        ]);
    }

    public function bySellerDetail(Request $request, $userId)
    {
        $today = now()->toDateString();
        $budget = Budget::where('start_date','<=',$today)
            ->where('end_date','>=',$today)
            ->first();

        if (!$budget) return response()->json(['active'=>false,'message'=>'No hay presupuesto activo']);

        $sales = Commission::select(
                'commissions.id as commission_id',
                'commissions.commission_amount',
                'commissions.is_provisional',
                'sales.id as sale_id',
                'sales.sale_date',
                'sales.folio',
                'sales.pdv',
                'products.description as product',
                'products.classification as category_code',
                'sales.amount_cop',
                'sales.value_usd',
                'sales.exchange_rate'
            )
            ->join('sales','commissions.sale_id','=','sales.id')
            ->leftJoin('products','sales.product_id','=','products.id')
            ->where('commissions.user_id', $userId)
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->orderBy('sales.sale_date')
            ->get();

        $categories = Commission::selectRaw("
                COALESCE(products.classification, 'Sin categoría') AS category,
                COUNT(DISTINCT commissions.sale_id) AS sales_count,
                SUM(sales.amount_cop) AS sales_sum_cop,
                SUM(commissions.commission_amount) AS commission_sum_cop
            ")
            ->join('sales','commissions.sale_id','=','sales.id')
            ->leftJoin('products','sales.product_id','=','products.id')
            ->where('commissions.user_id', $userId)
            ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date])
            ->groupBy('products.classification')
            ->orderByDesc('commission_sum_cop')
            ->get();

        $totals = [
            'total_commission_cop' => $sales->sum('commission_amount'),
            'total_sales_cop' => $sales->sum('amount_cop'),
            'total_sales_usd' => $sales->sum('value_usd'),
            'avg_trm' => $sales->avg('exchange_rate') ?? null
        ];

        $user = User::find($userId);
        $assignedToUser = $user ? (int) $user->assigned_turns : 0;

        $totalAssigned = (int) User::sum(DB::raw('COALESCE(assigned_turns,0)'));
        $totalTurns = $this->TOTAL_TURNS;
        $remaining = max(0, $totalTurns - $totalAssigned);

        return response()->json([
            'active' => true,
            'currency' => 'COP',
            'sales' => $sales,
            'categories' => $categories,
            'totals' => $totals,
            'assigned_turns_for_user' => $assignedToUser,
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => $remaining
            ]
        ]);
    }

    /**
     * Asignar turnos a un usuario (PUT)
     * body: { assigned_turns: int }
     */
    public function assignTurns(Request $request, $userId)
    {
        $data = $request->validate([
            'assigned_turns' => ['required', 'integer', 'min:0']
        ]);

        $user = User::find($userId);
        if (!$user) return response()->json(['message' => 'Usuario no encontrado'], 404);

        $newValue = (int) $data['assigned_turns'];

        $totalAssignedExcept = (int) User::where('id', '!=', $userId)
            ->sum(DB::raw('COALESCE(assigned_turns,0)'));

        $totalTurns = $this->TOTAL_TURNS;

        if ($totalAssignedExcept + $newValue > $totalTurns) {
            return response()->json([
                'message' => 'No hay suficientes turnos disponibles',
                'available' => max(0, $totalTurns - $totalAssignedExcept)
            ], 422);
        }

        $user->assigned_turns = $newValue;
        $user->save();

        $totalAssigned = (int) User::sum(DB::raw('COALESCE(assigned_turns,0)'));

        return response()->json([
            'message' => 'Turnos asignados',
            'assigned_for_user' => $newValue,
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => max(0, $totalTurns - $totalAssigned)
            ]
        ]);
    }
}
