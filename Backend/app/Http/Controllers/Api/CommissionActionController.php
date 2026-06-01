<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CommissionService;
use App\Models\Comisiones\Budget;

class CommissionActionController extends Controller
{
    public function generate(Request $request)
    {
        $request->validate([
            'budget_id' => 'required|integer|exists:budget.budgets,id',
            'budget_ids' => 'prohibited',
            'budget_ids.*' => 'prohibited',
        ]);

        $budget = Budget::findOrFail((int) $request->budget_id);
        if ((bool) $budget->is_closed) {
            return response()->json([
                'status' => 'budget_closed',
                'message' => 'El presupuesto esta cerrado. No se pueden generar comisiones.',
            ], 423);
        }

        $result = app(CommissionService::class)->generateForBudget((int) $request->budget_id);
        return response()->json($result);
    }
}
