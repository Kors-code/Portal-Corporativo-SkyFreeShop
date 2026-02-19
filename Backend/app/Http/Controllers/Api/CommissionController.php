<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CommissionService;

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
            'budget_id' => 'required|exists:budget.budgets,id'
        ]);
    
        return response()->json(
            $this->svc->generateForBudget((int) $request->budget_id)
        );
    }

}
