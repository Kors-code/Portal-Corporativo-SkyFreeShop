<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\CommissionService;
use App\Services\SalesRoleRectificationService;
use App\Models\Comisiones\Budget;
use Carbon\Carbon;

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

    public function rectifySalesRoles(Request $request, SalesRoleRectificationService $service)
    {
        $request->validate([
            'budget_id' => 'nullable|integer|exists:budget.budgets,id',
            'budget_ids' => 'nullable|array',
            'budget_ids.*' => 'integer|exists:budget.budgets,id',
            'dry_run' => 'nullable|boolean',
            'apply' => 'nullable|boolean',
        ]);

        $budgetIds = collect($request->input('budget_ids', []))
            ->push($request->input('budget_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($budgetIds->isEmpty()) {
            return response()->json([
                'message' => 'Selecciona al menos un presupuesto para rectificar roles.',
            ], 422);
        }

        $budgets = Budget::query()
            ->whereIn('id', $budgetIds->all())
            ->orderBy('start_date')
            ->get();

        $results = [];
        $totals = [
            'budgets_count' => $budgets->count(),
            'users_count' => 0,
            'ranges_count' => 0,
            'trimmed_rows' => 0,
            'deleted_rows' => 0,
            'inserted_rows' => 0,
            'merged_rows' => 0,
            'backup_rows' => 0,
        ];
        $shouldApply = $request->boolean('apply');

        if ($shouldApply && !filter_var(env('SALES_ROLE_RECTIFICATION_ALLOW_APPLY', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'message' => 'La rectificacion con escritura esta desactivada. Activa SALES_ROLE_RECTIFICATION_ALLOW_APPLY=true solo en la BD local.',
            ], 423);
        }

        foreach ($budgets as $budget) {
            $result = $service->rectifyRange(
                Carbon::parse($budget->start_date)->toDateString(),
                Carbon::parse($budget->end_date)->toDateString(),
                null,
                !$shouldApply || $request->boolean('dry_run'),
                null,
                [
                    'source' => 'commissions.rectify_sales_roles',
                    'budget_id' => (int) $budget->id,
                    'budget_name' => $budget->name ?? null,
                ]
            );

            $result['budget_id'] = (int) $budget->id;
            $result['budget_name'] = $budget->name ?? null;
            $results[] = $result;

            foreach (['users_count', 'ranges_count', 'trimmed_rows', 'deleted_rows', 'inserted_rows', 'merged_rows', 'backup_rows'] as $key) {
                $totals[$key] += (int) ($result[$key] ?? 0);
            }
        }

        return response()->json($totals + [
            'dry_run' => !$shouldApply || $request->boolean('dry_run'),
            'apply' => $shouldApply,
            'results' => $results,
        ]);
    }

}
