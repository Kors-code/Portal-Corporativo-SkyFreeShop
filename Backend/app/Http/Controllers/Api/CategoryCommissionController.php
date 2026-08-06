<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Comisiones\Category;
use App\Models\Comisiones\CategoryCommission;
use App\Models\Comisiones\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\Comisiones\Budget;
class CategoryCommissionController extends Controller
{
    
    private function ensureBudgetOpen($budgetId)
{
    if (!$budgetId) {
        return; // si no hay presupuesto específico no bloqueamos
    }

    $budget = Budget::find($budgetId);

    if (!$budget) {
        abort(404, 'Presupuesto no encontrado');
    }

    if ((int)$budget->is_closed === 1) {
        abort(423, 'El presupuesto está cerrado. No se pueden modificar categorías.');
    }
}

    private function normalizeDecimal($value, int $decimals = 6): float
    {
        return is_numeric($value) ? round((float) $value, $decimals) : 0.0;
    }

    private function adjustedSellerTargetAmount($budgetId): float
    {
        if (!$budgetId) {
            return 0.0;
        }

        $budgetTotal = (float) (Budget::on('budget')->where('id', $budgetId)->value('target_amount') ?? 0);
        if ($budgetTotal <= 0) {
            return 0.0;
        }

        $discountAmount = (float) DB::connection('budget')
            ->table('category_commissions as cc')
            ->join('categories as c', 'c.id', '=', 'cc.category_id')
            ->where('cc.budget_id', $budgetId)
            ->where('cc.role_id', 1)
            ->where('c.classification_code', '25')
            ->sum('cc.participation_value');

        return max(0.0, $budgetTotal - $discountAmount);
    }

    private function resolveBaseBudget(int $roleId, $budgetId): float
    {
        if (!$budgetId) {
            return 0.0;
        }

        if ($roleId === 1) {
            $sellerBase = $this->adjustedSellerTargetAmount($budgetId);
            if ($sellerBase > 0) {
                return $sellerBase;
            }
        }

        $advisorBudget = in_array($roleId, [4, 5], true)
            ? (float) (DB::connection('budget')->table('advisor_budgets')
                ->where('budget_id', $budgetId)
                ->where('role_id', $roleId)
                ->value('budget_usd') ?? 0)
            : 0.0;

        $budgetTotal = (float) (Budget::on('budget')->where('id', $budgetId)->value('target_amount') ?? 0);

        return $advisorBudget > 0 ? $advisorBudget : $budgetTotal;
    }

    private function resolveParticipationFields(array $data, int $roleId, $budgetId, ?float $baseBudgetOverride = null): array
    {
        $baseBudget = $baseBudgetOverride ?? $this->resolveBaseBudget($roleId, $budgetId);
        $hasValue = array_key_exists('participation_value', $data)
            && $data['participation_value'] !== null
            && $data['participation_value'] !== '';
        $hasPct = array_key_exists('participation_pct', $data)
            && $data['participation_pct'] !== null
            && $data['participation_pct'] !== '';

        if ($hasValue) {
            $value = $this->normalizeDecimal($data['participation_value'], 2);
            $pct = $baseBudget > 0
                ? $this->normalizeDecimal(($value / $baseBudget) * 100, 9)
                : ($hasPct ? $this->normalizeDecimal($data['participation_pct'], 9) : 0.0);

            return [$value, $pct];
        }

        $pct = $hasPct ? $this->normalizeDecimal($data['participation_pct'], 9) : 0.0;
        $value = 0.0;

        return [$value, $pct];
    }

    private function sellerBaseBudgetFromPayload(array $items, int $budgetId): ?float
    {
        $budgetTotal = (float) (Budget::on('budget')->where('id', $budgetId)->value('target_amount') ?? 0);
        if ($budgetTotal <= 0) {
            return null;
        }

        $categoryIds = array_values(array_filter(array_map(
            fn ($item) => isset($item['category_id']) ? (int) $item['category_id'] : null,
            $items
        )));

        if (empty($categoryIds)) {
            return null;
        }

        $advisorCategoryIds = DB::connection('budget')
            ->table('categories')
            ->whereIn('id', $categoryIds)
            ->where('classification_code', '25')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($advisorCategoryIds)) {
            return null;
        }

        $advisorValue = 0.0;
        foreach ($items as $item) {
            if (in_array((int) ($item['category_id'] ?? 0), $advisorCategoryIds, true)) {
                $advisorValue += $this->normalizeDecimal($item['participation_value'] ?? 0, 2);
            }
        }

        return max(0.0, $budgetTotal - $advisorValue);
    }

    private function sellerBaseBudgetFromItem(array $data, int $budgetId): ?float
    {
        $budgetTotal = (float) (Budget::on('budget')->where('id', $budgetId)->value('target_amount') ?? 0);
        if ($budgetTotal <= 0) {
            return null;
        }

        $isAdvisorCategory = DB::connection('budget')
            ->table('categories')
            ->where('id', (int) ($data['category_id'] ?? 0))
            ->where('classification_code', '25')
            ->exists();

        if (!$isAdvisorCategory) {
            return null;
        }

        $advisorValue = $this->normalizeDecimal($data['participation_value'] ?? 0, 2);

        return max(0.0, $budgetTotal - $advisorValue);
    }

    private function isAdvisorSpecialistCategory($categoryId): bool
    {
        return DB::connection('budget')
            ->table('categories')
            ->where('id', (int) $categoryId)
            ->where('classification_code', '25')
            ->exists();
    }

    // List categories with commission (optionally filter by role_id)
    public function index(Request $request)
    {
        $roleId = $request->query('role_id');

        $budget_id = $request->query('budget_id');
        
        $CODIGOS_OMITIR = ['10','11','12','98'];

        $categories = Category::on('budget')
            ->select('id','classification_code as code','name','description')
            ->orderBy('name')
            ->get()
            ->reject(function ($c) use ($CODIGOS_OMITIR) {
                return in_array((string)$c->code, $CODIGOS_OMITIR);
            })
            ->values();

        // load commissions for those categories for role (if provided) in one query
        $commissions = collect();

        if ($roleId) {
            $query = CategoryCommission::on('budget')
                ->whereIn('category_id', $categories->pluck('id'))
                ->where('role_id', $roleId);
        
            if ($budget_id) {
                $query->where('budget_id', $budget_id);
            }
        
            $commissions = $query->get()->keyBy('category_id');
        }



        $payload = $categories->map(function($c) use ($commissions) {
            
            $r = $commissions[$c->id] ?? null;
           return [
                'category_id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'description' => $c->description,
                'commission_id' => $r ? $r->id : null,
                'commission_percentage' => $r ? (float)$r->commission_percentage : null,
                'commission_percentage100' => $r ? (float)$r->commission_percentage100 : null,
                'commission_percentage120' => $r ? (float)$r->commission_percentage120 : null,
                'participation_pct' => $r ? (float)$r->participation_pct : null,
                'participation_value'      => $r ? (float)$r->participation_value : null,
                'particiaption_pct_sellers' => $r ? (float)$r->particiaption_pct_sellers : null,
                'budget_id' => $r ? (float)$r->budget_id : null,
            ];

        });

        return response()->json(['categories' => $payload]);
    }

    // Upsert a commission for category + role
    public function upsert(Request $request)
    {
        $data = $request->validate([
        'category_id' => ['required','integer','exists:budget.categories,id'],
        'role_id' => ['required','integer','exists:budget.roles,id'],
        'commission_percentage' => ['nullable','numeric','min:0'],
        'commission_percentage100' => ['nullable','numeric','min:0'],
        'commission_percentage120' => ['nullable','numeric','min:0'],
        'participation_pct' => ['nullable','numeric','min:0'],
        'participation_value'      => ['nullable','numeric','min:0'],
        'budget_id' => ['nullable','integer','exists:budget.budgets,id'],

    ]);
        $this->ensureBudgetOpen($data['budget_id'] ?? null);
        $budgetId = isset($data['budget_id']) ? (int) $data['budget_id'] : null;
        $baseBudgetOverride = ((int) $data['role_id'] === 1 && $budgetId)
            ? $this->sellerBaseBudgetFromItem($data, $budgetId)
            : null;

        [$participationValue, $participationPct] = $this->resolveParticipationFields($data, (int) $data['role_id'], $data['budget_id'] ?? null, $baseBudgetOverride);


        $particiaption_pct_sellers = 0;
        if($data['role_id'] == 1 && !$this->isAdvisorSpecialistCategory($data['category_id'])){
            
            $particiaption_pct_sellers = $participationPct;
        }
        DB::connection('budget')->beginTransaction();
        try {
                $row = CategoryCommission::on('budget')->updateOrCreate(
            [
                'category_id' => $data['category_id'],
                'role_id' => $data['role_id'],
                'budget_id' => $data['budget_id'] ?? null
            ],
            [
                'commission_percentage' => $data['commission_percentage'] ?? 0,
                'commission_percentage100' => $data['commission_percentage100'] ?? 0,
                'commission_percentage120' => $data['commission_percentage120'] ?? 0,
                'participation_pct' => $participationPct,
                'particiaption_pct_sellers' => $particiaption_pct_sellers,
                'participation_value' => $participationValue,
            ]
        );



            DB::connection('budget')->commit();
            return response()->json(['commission' => $row]);
        } catch (\Throwable $e) {
            DB::connection('budget')->rollBack();
            return response()->json(['message'=>'Error saving commission','error'=>$e->getMessage()],500);
        }
    }

    // Delete commission config by id
    public function destroy($id)
    {
        $row = CategoryCommission::on('budget')->find($id);
        if (!$row) return response()->json(['message'=>'Not found'],404);
         $this->ensureBudgetOpen($row->budget_id);
        $row->delete();
        return response()->json(['message'=>'Deleted']);
    }

    // Optional: bulk update (array of {category_id, commission_percentage})
public function bulkUpdate(Request $request)
{
    $payload = $request->validate([
    'role_id' => ['required','integer','exists:budget.roles,id'],
    'items' => ['required','array'],
    'items.*.category_id' => ['required','integer','exists:budget.categories,id'],
    'items.*.commission_percentage' => ['nullable','numeric','min:0'],
    'items.*.commission_percentage100' => ['nullable','numeric','min:0'],
    'items.*.commission_percentage120' => ['nullable','numeric','min:0'],
    'items.*.participation_pct' => ['nullable','numeric','min:0'],
    'items.*.participation_value' => ['nullable','numeric','min:0'],
    'items.*.budget_id' => ['nullable','integer','exists:budget.budgets,id'],
    
]);

    foreach ($payload['items'] as $it) {
    $this->ensureBudgetOpen($it['budget_id'] ?? null);
}
        
    DB::connection('budget')->beginTransaction();

    try {
        $baseBudgetOverrides = [];
        foreach ($payload['items'] as $it) {
            $itemBudgetId = isset($it['budget_id']) ? (int) $it['budget_id'] : null;
            $baseBudgetOverride = null;
            if ((int) $payload['role_id'] === 1 && $itemBudgetId) {
                if (!array_key_exists($itemBudgetId, $baseBudgetOverrides)) {
                    $itemsForBudget = array_values(array_filter(
                        $payload['items'],
                        fn ($item) => (int) ($item['budget_id'] ?? 0) === $itemBudgetId
                    ));
                    $baseBudgetOverrides[$itemBudgetId] = $this->sellerBaseBudgetFromPayload($itemsForBudget, $itemBudgetId);
                }
                $baseBudgetOverride = $baseBudgetOverrides[$itemBudgetId];
            }

            [$participationValue, $participationPct] = $this->resolveParticipationFields($it, (int) $payload['role_id'], $it['budget_id'] ?? null, $baseBudgetOverride);
            $particiaption_pct_sellers = (int) $payload['role_id'] === 1 && !$this->isAdvisorSpecialistCategory($it['category_id'])
                ? $participationPct
                : 0;

            CategoryCommission::on('budget')->updateOrCreate(
                [
                    'category_id' => $it['category_id'],
                    'role_id' => $payload['role_id'],
                    'budget_id' => $it['budget_id'] ?? null
                ],
                [
                    'commission_percentage' => $it['commission_percentage'] ?? 0,
                    'commission_percentage100' => $it['commission_percentage100'] ?? 0,
                    'commission_percentage120' => $it['commission_percentage120'] ?? 0,
                    'participation_pct' => $participationPct,
                    'particiaption_pct_sellers' => $particiaption_pct_sellers,
                    'participation_value' => $participationValue,
                ]
            );

        }

        DB::connection('budget')->commit();
    } catch (\Throwable $e) {
        DB::connection('budget')->rollBack();
        return response()->json(['message' => 'Error saving commissions', 'error' => $e->getMessage()], 500);
    }

    return response()->json(['message' => 'Bulk saved']);
}

}
