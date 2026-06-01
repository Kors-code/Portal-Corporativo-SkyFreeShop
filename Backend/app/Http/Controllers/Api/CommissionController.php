<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CommissionService;
use App\Models\Comisiones\Budget;

class CommissionController extends Controller
{
    protected CommissionService $svc;

    public function __construct(CommissionService $svc)
    {
        $this->svc = $svc;
    }

    // POST /api/v1/commissions/generate?budget_id=ID
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
     
        return response()->json(
            $this->svc->generateForBudget((int) $request->budget_id)
        );
    }

}
