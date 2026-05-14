<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AdvisorBudget;

class AdvisorBudgetController extends Controller
{
    /**
     * Obtener presupuesto asesor
     */
    public function show(Request $request)
    {
        $budgetId = (int) $request->query('budget_id');
        $roleId   = (int) $request->query('role_id');

        $row = AdvisorBudget::where('budget_id', $budgetId)
            ->where('role_id', $roleId)
            ->first();

        return response()->json([
            'budget_usd' => $row?->budget_usd ?? 0,
        ]);
    }

    /**
     * Guardar / actualizar
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'budget_id' => ['required', 'integer'],
            'role_id'   => ['required', 'integer'],
            'budget_usd'=> ['required', 'numeric', 'min:0'],
        ]);

        $row = AdvisorBudget::updateOrCreate(
            [
                'budget_id' => $validated['budget_id'],
                'role_id'   => $validated['role_id'],
            ],
            [
                'budget_usd' => $validated['budget_usd'],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
    }
}