<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Comisiones\Budget;
use App\Models\Comisiones\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
    $this->copyCategoryCommissionsFromPreviousBudget($budget);


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
    $budget = Budget::find($id);
    if (!$budget) {
        return response()->json(['message' => 'Budget not found'], 404);
    }

    // 🔒 Validar si está cerrado
    if ($budget->is_closed) {
        return response()->json([
            'message' => 'No se puede modificar un presupuesto cerrado'
        ], 403);
    }

    $data = $request->validate([
        'name' => 'sometimes|string',
        'target_amount' => 'sometimes|numeric|min:0',
        'start_date' => 'sometimes|date',
        'end_date' => 'sometimes|date|after_or_equal:start_date',
        'total_turns' => 'nullable|integer|min:0',
    ]);

    $budget->fill($data);
    $budget->save();

    return response()->json($budget);
}
public function destroy($id)
{
    $budget = Budget::find($id);

    if (!$budget) {
        return response()->json(['message' => 'Budget not found'], 404);
    }

    // 🔒 Validar si está cerrado
    if ($budget->is_closed) {
        return response()->json([
            'message' => 'No se puede eliminar un presupuesto cerrado'
        ], 403);
    }

    $budget->delete();
    return response()->json(null, 204);
}

private function copyCategoryCommissionsFromPreviousBudget(Budget $newBudget)
{
    // Buscar presupuesto anterior por fecha
    $previousBudget = Budget::where('start_date', '<', $newBudget->start_date)
        ->orderByDesc('start_date')
        ->first();

    if (!$previousBudget) {
        return; // No existe presupuesto anterior
    }

    // Obtener configuraciones anteriores
    $previousConfigs = DB::connection('budget')
        ->table('category_commissions')
        ->where('budget_id', $previousBudget->id)
        ->get();

    if ($previousConfigs->isEmpty()) {
        return;
    }

    $insertData = [];

    foreach ($previousConfigs as $config) {
        $insertData[] = [
            'budget_id' => $newBudget->id,
            'category_id' => $config->category_id,
            'role_id' => $config->role_id,
            'commission_percentage' => $config->commission_percentage,
            'commission_percentage100' => $config->commission_percentage100,
            'commission_percentage120' => $config->commission_percentage120,
            'participation_pct' => $config->participation_pct,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::connection('budget')
        ->table('category_commissions')
        ->insert($insertData);
}

public function updateCashierPrize(Request $request, $id)
{
    $budget = Budget::find($id);
    if (!$budget) {
        return response()->json(['error' => 'Budget not found'], 404);
    }

    // 🔒 Validar si está cerrado
    if ($budget->is_closed) {
        return response()->json([
            'message' => 'No se puede modificar un presupuesto cerrado'
        ], 403);
    }

    $data = $request->validate([
        'cashier_prize' => 'required|numeric|min:0'
    ]);

    $budget->cashier_prize = round($data['cashier_prize'], 2);
    $budget->save();

    return response()->json([
        'status' => 'ok',
        'cashier_prize' => $budget->cashier_prize
    ]);
}
public function close($id)
{
    $budget = Budget::find($id);

    if (!$budget) {
        return response()->json(['message' => 'Budget not found'], 404);
    }

    if ($budget->is_closed) {
        return response()->json(['message' => 'El presupuesto ya está cerrado'], 422);
    }

    // 🔒 No permitir cerrar antes de la fecha final
    $today = Carbon::today();

    if ($today->lt(Carbon::parse($budget->end_date))) {
        return response()->json([
            'message' => 'No se puede cerrar el presupuesto antes de que finalice el período'
        ], 403);
    }

    $budget->is_closed = true;
    $budget->closed_at = now();
    $budget->save();

    return response()->json([
        'message' => 'Presupuesto cerrado correctamente',
        'budget' => $budget
    ]);
}

}
