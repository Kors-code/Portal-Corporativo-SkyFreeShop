<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Comisiones\Budget;
use App\Models\Comisiones\Sale;
use Carbon\Carbon;


class BudgetController extends Controller
{
    /**
     * Listar todos los presupuestos
     */
    protected int $MIN_PCT_TO_QUALIFY = 80;

    public function index()
    {
        return response()->json(
            Budget::orderBy('start_date', 'desc')->get()
        );
    }

    /**
     * Crear presupuesto
     */
   public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required|string',
        'target_amount' => 'required|numeric|min:0',
        'start_date' => 'required|date',
        'end_date' => 'required|date|after_or_equal:start_date',
        'total_turns' => 'nullable|integer|min:0',
    ]);

    $budget = Budget::create($data);

    return response()->json($budget, 201);
}


    /**
     * Presupuesto activo + cumplimiento
     */

public function active()
{
    $today = Carbon::today();

    $budget = Budget::where('start_date', '<=', $today)
        ->where('end_date', '>=', $today)
        ->first();

    if (!$budget) {
        // Lazy-create a monthly budget for the current month with default values
        $start = $today->copy()->firstOfMonth()->toDateString();
        $end = $today->copy()->lastOfMonth()->toDateString();

        $budget = Budget::create([
            'name' => 'Automatic budget ' . $today->format('Y-m'),
            'target_amount' => 0,                       // manual: set via UI later
            'start_date' => $start,
            'end_date' => $end,
            'total_turns' => null,                      // usa default si es null
        ]);
    }

    $salesTotal = Sale::whereBetween('sale_date', [
        $budget->start_date,
        $budget->end_date
    ])->sum('amount');

    $pct = $budget->target_amount > 0
        ? round(($salesTotal / $budget->target_amount) * 100, 2)
        : 0;

    return response()->json([
        'active' => true,
        'budget' => $budget,
        'sales_total' => $salesTotal,
        'compliance_pct' => $pct,
        'qualifies' => $pct >= $this->MIN_PCT_TO_QUALIFY
    ]);
}


public function update(Request $request, $id)
{
    $data = $request->validate([
        'name' => 'sometimes|string',
        'target_amount' => 'sometimes|numeric|min:0',
        'start_date' => 'sometimes|date',
        'end_date' => 'sometimes|date|after_or_equal:start_date',
        'total_turns' => 'nullable|integer|min:0',
    ]);

    $budget = Budget::find($id);
    if (!$budget) return response()->json(['message' => 'Budget not found'], 404);

    $budget->fill($data);
    $budget->save();

    return response()->json($budget);
}
public function destroy($id)
{
    $budget = Budget::find($id);
    if (!$budget) return response()->json(['message' => 'Budget not found'], 404);
    $budget->delete();
    return response()->json(null, 204);
}

public function updateCashierPrize(Request $request, $id)
{
    $data = $request->validate([
        'cashier_prize' => 'required|numeric|min:0'
    ]);

    $budget = Budget::find($id);
    if (!$budget) {
        return response()->json(['error' => 'Budget not found'], 404);
    }

    // Guardamos con 2 decimales por seguridad
    $budget->cashier_prize = round($data['cashier_prize'], 2);
    $budget->save();

    return response()->json([
        'status' => 'ok',
        'cashier_prize' => $budget->cashier_prize
    ]);
}


}
