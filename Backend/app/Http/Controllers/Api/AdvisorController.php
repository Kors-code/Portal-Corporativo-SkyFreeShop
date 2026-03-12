<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\Comisiones\UserCategoryBudget;
use App\Models\Comisiones\AdvisorSpecialist;
use App\Http\Controllers\Api\CommissionReportController;

class AdvisorController extends Controller
{
    // Constantes / helpers
    private const FRAG_CODES = [10, 11, 12];
    private const FRAG_KEY = 'fragancias';
    private const DIAMANTES_KEY = 'diamantes';
    private const DEFAULT_MONT_NAMES = ['gifts', 'watches', 'jewerly', 'sunglasses', 'electronics'];
    private const DEFAULT_MONT_KEYS = ['19','14','15','16','21'];
    private const DEFAULT_PARBEL_KEYS = ['13', self::FRAG_KEY];
    private const ADVISOR_CATEGORY_ID = 15;

    /**
     * Devuelve la lista de usuarios (desde la conexión budget).
     * Query params:
     *  - budget_id (opcional) -> si se provee, limita totales a ese rango/budget
     *  - only_with_sales (opcional, boolean) -> si true solo devuelve usuarios con ventas > 0
     */
    public function budgetSellers(Request $request)
    {
        $budgetId = (int) $request->query('budget_id');

        if (!$budgetId) {
            return response()->json([], 200);
        }

        // Si la tabla sales tiene budget_id lo usamos
        $hasBudgetId = Schema::connection('budget')->hasColumn('sales', 'budget_id');

        $query = DB::connection('budget')
            ->table('sales')
            ->join('users', 'users.id', '=', 'sales.seller_id')
            ->select(
                'users.id',
                'users.name',
                'users.codigo_vendedor',
                DB::raw('SUM(COALESCE(sales.value_usd,0)) as total_usd')
            )
            ->groupBy('users.id','users.name','users.codigo_vendedor');

        if ($hasBudgetId) {
            $query->where('sales.budget_id', $budgetId);
        } else {
            [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
            $query->whereBetween('sales.sale_date', [
                $startDate->toDateTimeString(),
                $endDate->toDateTimeString()
            ]);
        }

        $rows = $query
            ->orderByDesc('total_usd')
            ->get();

        return response()->json($rows);
    }

    public function getBudgetSellers(Request $request)
    {
        $budgetId = $request->query('budget_id') ? (int)$request->query('budget_id') : null;
        $onlyWithSales = filter_var($request->query('only_with_sales', false), FILTER_VALIDATE_BOOLEAN);

        // rango fallback
        [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);

        $hasBudgetIdColumn = Schema::connection('budget')->hasColumn('sales', 'budget_id');

        // base: users from connection budget
        $q = DB::connection('budget')
            ->table('users')
            ->leftJoin('sales', 'users.id', '=', 'sales.seller_id')
            ->select(
                'users.id',
                'users.name',
                'users.codigo_vendedor',
                DB::raw('COUNT(sales.id) as total_sales'),
                DB::raw('COALESCE(SUM(sales.value_usd),0) as total_usd')
            );

        // limit sales by budget or by date if necessary
        if ($budgetId && $hasBudgetIdColumn) {
            $q->where(function($qq) use ($budgetId) {
                // keep left join semantics; only filter sales rows
                $qq->where('sales.budget_id', $budgetId)
                   ->orWhereNull('sales.budget_id');
            });
        } else {
            // if no budget_id column, limit by date range on sales.sale_date (but preserve users with no sales)
            $q->where(function($qq) use ($startDate, $endDate) {
                $qq->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
                   ->orWhereNull('sales.sale_date');
            });
        }

        $q->groupBy('users.id','users.name','users.codigo_vendedor')
          ->orderBy('users.name');

        $rows = $q->get()->map(function($r){
            return [
                'id' => (int)$r->id,
                'name' => $r->name,
                'codigo_vendedor' => $r->codigo_vendedor,
                'total_sales' => (int)$r->total_sales,
                'total_usd' => (float)$r->total_usd,
            ];
        })->toArray();

        if ($onlyWithSales) {
            $rows = array_values(array_filter($rows, fn($it) => ($it['total_sales'] ?? 0) > 0));
        }

        return response()->json($rows);
    }

    private function normalizeClassification($raw): string
    {
        $raw = (string)($raw ?? '');
        $raw = trim($raw);
        if ($raw === '') return 'sin_categoria';

        // numeric frag codes -> fragancias
        if (is_numeric($raw) && in_array((int)$raw, self::FRAG_CODES, true)) {
            return self::FRAG_KEY;
        }

        $low = mb_strtolower($raw);

        if (strpos($low, 'frag') !== false || strpos($low, 'perf') !== false) {
            return self::FRAG_KEY;
        }

        // normalize spaces
        return preg_replace('/\s+/', ' ', $low);
    }

    /**
     * If budget(s) not providing dates, fallback to a wide range.
     * Returns array [Carbon $start, Carbon $end]
     */
    private function resolveBudgetRange($budgetId = null)
    {
        $q = DB::connection('budget')->table('budgets')->select('start_date', 'end_date');
        if ($budgetId) $q->where('id', $budgetId);

        $budgets = $q->get();

        if ($budgets->isEmpty()) {
            $start = Carbon::now()->subYears(5)->startOfDay();
            $end = Carbon::now()->endOfDay();
            return [$start, $end];
        }

        $minStart = null;
        $maxEnd = null;
        foreach ($budgets as $b) {
            if (!empty($b->start_date)) {
                try {
                    $dt = Carbon::parse($b->start_date);
                    if ($minStart === null || $dt->lessThan($minStart)) $minStart = $dt;
                } catch (\Throwable $e) { /* ignore */ }
            }
            if (!empty($b->end_date)) {
                try {
                    $dt2 = Carbon::parse($b->end_date);
                    if ($maxEnd === null || $dt2->greaterThan($maxEnd)) $maxEnd = $dt2;
                } catch (\Throwable $e) { /* ignore */ }
            }
        }

        if ($minStart === null) $minStart = Carbon::now()->subYears(5)->startOfDay();
        if ($maxEnd === null) $maxEnd = Carbon::now()->endOfDay();

        $minStart = $minStart->startOfDay();
        $maxEnd = $maxEnd->endOfDay();

        // log for debugging
        Log::info('resolveBudgetRange -> start: ' . $minStart->toDateTimeString() . ' end: ' . $maxEnd->toDateTimeString());

        return [$minStart, $maxEnd];
    }

    /* ======================
       SPLIT (guardar/leer)
       ====================== */

    public function saveAdvisorSplit(Request $r)
    {
        $data = $r->validate([
            'budget_id' => 'required|integer',
            'advisor_a_id' => 'required|integer',
            'advisor_a_pct' => 'required|numeric|min:0|max:100',
            'advisor_b_id' => 'required|integer',
            'advisor_b_pct' => 'required|numeric|min:0|max:100',
        ]);

        $budgetId = (int)$data['budget_id'];
        $aId = (int)$data['advisor_a_id'];
        $bId = (int)$data['advisor_b_id'];
        $aPct = (float)$data['advisor_a_pct'];
        $bPct = (float)$data['advisor_b_pct'];

        $sum = $aPct + $bPct;
        if ($sum <= 0) {
            return response()->json(['message' => 'Porcentajes inválidos'], 422);
        }
        if (abs($sum - 100.0) > 0.01) {
            $aPct = round(($aPct / $sum) * 100, 2);
            $bPct = round(100 - $aPct, 2);
        }

        $actor = auth()->id();

        DB::connection('budget')->table('advisor_splits')->updateOrInsert(
            ['budget_id' => $budgetId],
            [
                'advisor_a_id' => $aId,
                'advisor_a_pct' => $aPct,
                'advisor_b_id' => $bId,
                'advisor_b_pct' => $bPct,
                'updated_by' => $actor,
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())')
            ]
        );

        return response()->json(['message' => 'Split guardado', 'budget_id' => $budgetId]);
    }

    public function getAdvisorSplit(Request $r)
    {
        $data = $r->validate(['budget_id' => 'required|integer']);
        $budgetId = (int)$data['budget_id'];

        $row = DB::connection('budget')->table('advisor_splits')->where('budget_id', $budgetId)->first();
        if (!$row) {
            return response()->json([], 200);
        }

        $budgetRow = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
        $targetAmount = $budgetRow ? ($budgetRow->target_amount ?? ($budgetRow->amount ?? 0)) : 0;

        $advisorPctRow = DB::connection('budget')->table('category_commissions')
            ->where('category_id', self::ADVISOR_CATEGORY_ID)
            ->where('budget_id', $budgetId)
            ->selectRaw('AVG(COALESCE(participation_pct,0)) as pct')
            ->first();
        $advisorPct = (float)($advisorPctRow->pct ?? 0);
        $advisorPoolUsd = round($targetAmount * ($advisorPct / 100), 2);

        $aPct = (float)($row->advisor_a_pct ?? 0);
        $bPct = (float)($row->advisor_b_pct ?? 0);

        $aAssigned = round($advisorPoolUsd * ($aPct / 100), 2);
        $bAssigned = round($advisorPoolUsd * ($bPct / 100), 2);

        return response()->json([
            'budget_id' => $budgetId,
            'advisor_pool_usd' => $advisorPoolUsd,
            'advisor_a_id' => $row->advisor_a_id,
            'advisor_a_pct' => $aPct,
            'advisor_a_assigned_usd' => $aAssigned,
            'advisor_b_id' => $row->advisor_b_id,
            'advisor_b_pct' => $bPct,
            'advisor_b_assigned_usd' => $bAssigned,
            'saved_at' => $row->updated_at ?? $row->created_at,
        ]);
    }

    public function splitAdvisorPool(Request $r)
    {
        $data = $r->validate([
            'budget_id' => 'required|integer',
            'advisor_a_pct' => 'required|numeric|min:0|max:100',
            'advisor_b_pct' => 'required|numeric|min:0|max:100',
        ]);

        $budgetId = (int)$data['budget_id'];
        $aPct = (float)$data['advisor_a_pct'];
        $bPct = (float)$data['advisor_b_pct'];

        $sum = $aPct + $bPct;
        if ($sum <= 0) return response()->json(['message'=>'Porcentajes inválidos'], 422);

        if (abs($sum - 100) > 0.01) {
            $aPct = round(($aPct / $sum) * 100, 2);
            $bPct = round(100 - $aPct, 2);
        }

        $budget = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
        if (!$budget) return response()->json(['message'=>'Budget no encontrado'], 404);

        $targetAmount = $budget->target_amount ?? ($budget->amount ?? 0);

        $advisorPctRow = DB::connection('budget')->table('category_commissions')
            ->where('category_id', self::ADVISOR_CATEGORY_ID)
            ->where('budget_id', $budgetId)
            ->selectRaw('AVG(COALESCE(participation_pct,0)) as pct')
            ->first();
        $advisorPct = (float)($advisorPctRow->pct ?? 0);
        $advisorPoolUsd = round($targetAmount * ($advisorPct / 100), 2);

        $advisorAUsd = round($advisorPoolUsd * ($aPct / 100), 2);
        $advisorBUsd = round($advisorPoolUsd * ($bPct / 100), 2);

        return response()->json([
            'advisor_pool_usd' => $advisorPoolUsd,
            'advisor_a' => ['pct' => $aPct, 'assigned_usd' => $advisorAUsd],
            'advisor_b' => ['pct' => $bPct, 'assigned_usd' => $advisorBUsd]
        ]);
    }

    /**
     * computeAdvisorSplitWithOverrides
     * Similar to your original but hardened & clearer; returns per-line assigned budgets and sales.
     */
    public function computeAdvisorSplitWithOverrides(Request $r)
    {
        $data = $r->validate([
            'budget_id' => 'required|integer',
            'user_id'   => 'required|integer',
            'role_id'   => 'nullable|integer',
            'mont_pct'  => 'nullable|numeric|min:0|max:100',
            'parbel_pct'=> 'nullable|numeric|min:0|max:100',
        ]);

        $budgetId = (int)$data['budget_id'];
        $userId = (int)$data['user_id'];
        $roleId = isset($data['role_id']) ? (int)$data['role_id'] : null;
        $montPct = isset($data['mont_pct']) ? (float)$data['mont_pct'] : null;
        $parbelPct = isset($data['parbel_pct']) ? (float)$data['parbel_pct'] : null;

        $budget = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
        if (!$budget) return response()->json(['message'=>'Budget no encontrado'], 404);
        $budgetTotal = $budget->target_amount ?? ($budget->amount ?? 0);

        // advisor participation pct (category id ADVISOR_CATEGORY_ID)
        $commissionRow = DB::connection('budget')
            ->table('category_commissions')
            ->where('category_id', self::ADVISOR_CATEGORY_ID)
            ->where('budget_id', $budgetId)
            ->when($roleId, fn($q) => $q->where('role_id', $roleId))
            ->selectRaw('AVG(COALESCE(participation_pct,0)) as participation_pct')
            ->first();
        $advisorPct = $commissionRow ? (float)($commissionRow->participation_pct ?? 0) : 0.0;
        $advisorPoolUsd = round($budgetTotal * ($advisorPct / 100), 2);

        // normalize overrides
        if ($montPct === null && $parbelPct === null) {
            $montPct = 50.0; $parbelPct = 50.0;
        } elseif ($montPct !== null && $parbelPct === null) {
            $parbelPct = max(0, 100.0 - $montPct);
        } elseif ($parbelPct !== null && $montPct === null) {
            $montPct = max(0, 100.0 - $parbelPct);
        }

        $sum = $montPct + $parbelPct;
        if ($sum <= 0) { $montPct = 50; $parbelPct = 50; }
        elseif (abs($sum - 100.0) > 0.01) {
            $montPct = round(($montPct / $sum) * 100, 2);
            $parbelPct = round(100 - $montPct, 2);
        }

        $montAssignedUsd = round($advisorPoolUsd * ($montPct / 100), 2);
        $parbelAssignedUsd = round($advisorPoolUsd * ($parbelPct / 100), 2);

        // --- Montblanc categories (by name / fallback) ---
        $montClassification = DB::connection('budget')->table('categories')
            ->where(function($q) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%gifts%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%watch%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%jewel%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%sunglass%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%electro%']);
            })
            ->pluck('classification_code')
            ->map(fn($v) => (string)$v)
            ->unique()
            ->values()
            ->all();

        // Ensure defaults are always present (so '15' etc. appear even if heuristics found some)
        $montClassification = array_values(array_unique(array_merge($montClassification, self::DEFAULT_MONT_KEYS)));

        // Add 'diamantes' as a special key (we will compute its sales by provider L'ARTIST)
        if (!in_array(self::DIAMANTES_KEY, $montClassification, true)) {
            $montClassification[] = self::DIAMANTES_KEY;
        }

        // participation pct per classification (for splitting montAssignedUsd across categories)
        $montParts = [];
        if (!empty($montClassification)) {
            $q = DB::connection('budget')->table('category_commissions')
                ->join('categories','categories.id','=','category_commissions.category_id')
                ->whereIn(DB::raw('CAST(categories.classification_code AS CHAR)'), array_filter($montClassification, fn($v)=>is_numeric($v)))
                ->where('category_commissions.budget_id', $budgetId)
                ->select('categories.classification_code','category_commissions.participation_pct');
            if ($roleId) $q->where('category_commissions.role_id', $roleId);
            $rows = $q->get();
            foreach ($rows as $r) {
                $k = (string)$r->classification_code;
                $montParts[$k] = max(0.0, (float)($r->participation_pct ?? 0));
            }
        }

        // distribute montAssignedUsd across montParts (proportional) or equal split
        $montCategoryBudgets = [];
        if (!empty($montParts)) {
            $sumParts = array_sum($montParts);
            if ($sumParts <= 0) {
                $count = count($montParts);
                foreach ($montParts as $k => $v) {
                    $montCategoryBudgets[$k] = round($montAssignedUsd / max(1,$count), 2);
                }
            } else {
                foreach ($montParts as $k => $v) {
                    $montCategoryBudgets[$k] = round($montAssignedUsd * ($v / $sumParts), 2);
                }
            }
        } else {
            if (!empty($montClassification)) {
                // distribute equally among numeric categories only; diamantes is handled below
                $numericMont = array_values(array_filter($montClassification, fn($v)=>is_numeric($v)));
                $count = count($numericMont);
                foreach ($numericMont as $k) {
                    $montCategoryBudgets[$k] = round($montAssignedUsd / max(1,$count), 2);
                }
            }
        }

        // --- SPECIAL: split out 'diamantes' budget from the jewel (15) budget based on sales proportion ---
        // Determine jewelry classification key (15 by default)
        $jewKey = '15';
        // compute total jewelry sales and diamantes sales for the user in the budget timeframe
        $totalJewelrySalesUsd = 0.0;
        $diamantesSalesUsd = 0.0;

        try {
            if (!empty($montCategoryBudgets[$jewKey])) {
                // if we have a budget row precomputed, we'll still compute sales to split it
                // compute totalJewelrySalesUsd from budget_user_category_totals if available
                if (Schema::connection('budget')->hasTable('budget_user_category_totals')) {
                    $q = DB::connection('budget')->table('budget_user_category_totals')
                        ->where('user_id', $userId)
                        ->where(DB::raw('CAST(category_group AS CHAR)'), $jewKey);
                    if ($budgetId) $q->where('budget_id', $budgetId);
                    $totalJewelrySalesUsd = (float)$q->sum('sales_usd');
                } else {
                    // fallback to sales join products
                    $q = DB::connection('budget')->table('sales')
                        ->join('products','sales.product_id','=','products.id')
                        ->where('sales.seller_id', $userId)
                        ->where(DB::raw('CAST(products.classification AS CHAR)'), $jewKey);
                    if ($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id')) $q->where('sales.budget_id', $budgetId);
                    else {
                        [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
                        $q->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
                    }
                    $totalJewelrySalesUsd = (float)$q->sum(DB::raw('COALESCE(sales.value_usd,0)'));
                }

                // diamantes sales: provider = L'ARTIST AND classification = 15
                $diamantesQ = DB::connection('budget')->table('sales')
                    ->join('products','sales.product_id','=','products.id')
                    ->where('sales.seller_id', $userId)
                    ->where('products.provider_name', "L'ARTIST")
                    ->where(DB::raw('CAST(products.classification AS CHAR)'), $jewKey);
                if ($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id')) $diamantesQ->where('sales.budget_id', $budgetId);
                else {
                    [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
                    $diamantesQ->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
                }
                $diamantesSalesUsd = (float)$diamantesQ->sum(DB::raw('COALESCE(sales.value_usd,0)'));

                // split budget of '15' if exists
                if ($totalJewelrySalesUsd > 0 && $diamantesSalesUsd > 0) {
                    $jewBudget = $montCategoryBudgets[$jewKey] ?? 0.0;
                    $diamBudget = round($jewBudget * ($diamantesSalesUsd / max(1,$totalJewelrySalesUsd)), 2);
                    $montCategoryBudgets[self::DIAMANTES_KEY] = $diamBudget;
                    $montCategoryBudgets[$jewKey] = round(max(0, $jewBudget - $diamBudget), 2);
                } else {
                    // ensure key exists for diamantes with 0 if no sales
                    if (!isset($montCategoryBudgets[self::DIAMANTES_KEY])) $montCategoryBudgets[self::DIAMANTES_KEY] = 0.0;
                }
            } else {
                // ensure key exists
                if (!isset($montCategoryBudgets[self::DIAMANTES_KEY])) $montCategoryBudgets[self::DIAMANTES_KEY] = 0.0;
            }
        } catch (\Throwable $e) {
            Log::warning('Error calculando presupuesto diamantes: '.$e->getMessage());
            if (!isset($montCategoryBudgets[self::DIAMANTES_KEY])) $montCategoryBudgets[self::DIAMANTES_KEY] = 0.0;
        }

        // mont sales by user (aggregated) - NOTE: this function historically expected category_group numeric codes.
        // We'll still use the budget_user_category_totals for numeric groups; diamantes is handled separately when building rows.
        $montSalesUsd = 0.0;
        try {
            $montClassificationChars = array_map('strval', array_values(array_filter($montClassification, fn($v)=>is_numeric($v))));
            if (!empty($montClassificationChars)) {
                $montSalesUsd = (float) DB::connection('budget')
                    ->table('budget_user_category_totals')
                    ->whereIn(DB::raw('CAST(category_group AS CHAR)'), $montClassificationChars)
                    ->when($budgetId, fn($q) => $q->where('budget_id', $budgetId))
                    ->where('user_id', $userId)
                    ->sum('sales_usd');
            }
            // plus diamantes sales if present
            $diamQ = DB::connection('budget')->table('sales')
                ->join('products','sales.product_id','=','products.id')
                ->where('sales.seller_id', $userId)
                ->where('products.provider_name', "L'ARTIST")
                ->where(DB::raw('CAST(products.classification AS CHAR)'), '15');
            if ($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id')) $diamQ->where('sales.budget_id', $budgetId);
            else {
                [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
                $diamQ->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
            }
            $diamSum = (float)$diamQ->sum(DB::raw('COALESCE(sales.value_usd,0)'));
            $montSalesUsd += $diamSum;
        } catch (\Throwable $e) {
            Log::warning('Error montSalesUsd compute: '.$e->getMessage());
            $montSalesUsd = 0.0;
        }

        // --- Parbel: skin + frag (provider PARBEL) ---
        $skinClassifications = DB::connection('budget')->table('categories')
            ->where(function($q){
                $q->whereRaw('LOWER(name) LIKE ?', ['%skin%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%skin care%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%skin-care%']);
            })
            ->pluck('classification_code')
            ->map(fn($v)=>(string)$v)->unique()->values()->all();

        $fragClassifications = DB::connection('budget')->table('categories')
            ->whereIn(DB::raw('CAST(classification_code AS SIGNED)'), self::FRAG_CODES)
            ->pluck('classification_code')
            ->map(fn($v)=>(string)$v)->unique()->values()->all();

        $parbelSkinKeys = $skinClassifications;
        $parbelFragKeys = array_values(array_unique($fragClassifications));

        // participation for skin & frag
        $parbelParts = ['skin'=>0.0,'frag'=>0.0];

        if (!empty($parbelSkinKeys)) {
            $qSkin = DB::connection('budget')->table('category_commissions')
                ->join('categories','categories.id','=','category_commissions.category_id')
                ->whereIn(DB::raw('CAST(categories.classification_code AS CHAR)'), $parbelSkinKeys)
                ->where('category_commissions.budget_id', $budgetId)
                ->selectRaw('AVG(COALESCE(category_commissions.participation_pct,0)) as pct');
            if ($roleId) $qSkin->where('category_commissions.role_id', $roleId);
            $rSkin = $qSkin->first();
            $parbelParts['skin'] = (float)($rSkin->pct ?? 0.0);
        }

        if (!empty($parbelFragKeys)) {
            $qFrag = DB::connection('budget')->table('category_commissions')
                ->join('categories','categories.id','=','category_commissions.category_id')
                ->whereIn(DB::raw('CAST(categories.classification_code AS CHAR)'), $parbelFragKeys)
                ->where('category_commissions.budget_id', $budgetId)
                ->selectRaw('AVG(COALESCE(category_commissions.participation_pct,0)) as pct');
            if ($roleId) $qFrag->where('category_commissions.role_id', $roleId);
            $rFrag = $qFrag->first();
            $parbelParts['frag'] = (float)($rFrag->pct ?? 0.0);
        }

        $partsTotal = max(0.0, $parbelParts['skin'] + $parbelParts['frag']);
        if ($partsTotal <= 0) {
            $parbelParts['skin'] = 50; $parbelParts['frag'] = 50; $partsTotal = 100;
        }

        $parbelSkinAssignedUsd = round($parbelAssignedUsd * ($parbelParts['skin'] / $partsTotal), 2);
        $parbelFragAssignedUsd = round($parbelAssignedUsd * ($parbelParts['frag'] / $partsTotal), 2);

        // parbel sales: skin (provider PARBEL)
        $parbelSkinSalesUsd = 0.0;
        if (!empty($parbelSkinKeys)) {
            $q = DB::connection('budget')->table('sales')
                ->join('products','sales.product_id','=','products.id')
                ->where('products.provider_name', 'PARBEL')
                ->whereIn(DB::raw('CAST(products.classification AS CHAR)'), $parbelSkinKeys)
                ->when(Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId, fn($q) => $q->where('sales.budget_id', $budgetId))
                ->when(!Schema::connection('budget')->hasColumn('sales','budget_id'), function($q) use ($budgetId) {
                    [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
                    $q->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
                })
                ->where('sales.seller_id', $userId)
                ->sum(DB::raw('COALESCE(sales.value_usd,0)'));
            $parbelSkinSalesUsd = (float)$q;
        }

        // parbel frag sales: provider PARBEL OR description/classification contains frag
        $parbelFragSalesUsd = 0.0;
        {
            $fragQuery = DB::connection('budget')
                ->table('sales')
                ->join('products','sales.product_id','=','products.id')
                ->where('products.provider_name', 'PARBEL')
                ->where(function($q) use ($parbelFragKeys) {
                    if (!empty($parbelFragKeys)) {
                        $q->whereIn(DB::raw('CAST(products.classification AS CHAR)'), $parbelFragKeys);
                    }
                    $q->orWhereRaw('LOWER(products.description) LIKE ?', ['%frag%']);
                    $q->orWhereRaw('LOWER(products.classification_desc) LIKE ?', ['%frag%']);
                })
                ->when(Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId, fn($q) => $q->where('sales.budget_id', $budgetId))
                ->when(!Schema::connection('budget')->hasColumn('sales','budget_id'), function($q) use ($budgetId) {
                    [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
                    $q->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
                })
                ->where('sales.seller_id', $userId)
                ->selectRaw('COALESCE(SUM(sales.value_usd),0) as s');

            $fragRow = $fragQuery->first();
            $parbelFragSalesUsd = (float)($fragRow->s ?? 0.0);
        }

        return response()->json([
            'budget_id' => $budgetId,
            'user_id' => $userId,
            'advisor_pct' => $advisorPct,
            'advisor_pool_usd' => $advisorPoolUsd,
            'split' => [
                'mont_pct' => $montPct,
                'parbel_pct' => $parbelPct,
                'mont_assigned_usd' => $montAssignedUsd,
                'parbel_assigned_usd' => $parbelAssignedUsd,
            ],
            'montblanc' => [
                'classification_codes' => $montClassification,
                'category_budgets' => $montCategoryBudgets,
                'sales_usd' => round($montSalesUsd,2)
            ],
            'parbel' => [
                'skin' => [
                    'classification_codes' => $parbelSkinKeys,
                    'assigned_usd' => $parbelSkinAssignedUsd,
                    'sales_usd' => round($parbelSkinSalesUsd,2)
                ],
                'fragancias' => [
                    'classification_codes' => $parbelFragKeys,
                    'assigned_usd' => $parbelFragAssignedUsd,
                    'sales_usd' => round($parbelFragSalesUsd,2)
                ],
                'raw_parts' => $parbelParts
            ]
        ]);
    }

    /* ======================
       CATEGORY BUDGETS CRUD
       (mantengo tu original)
       ====================== */

    public function indexCategoryBudgets(Request $r)
    {
        $budgetId = (int) $r->query('budget_id');
        $userId = (int) $r->query('user_id');

        if (!$budgetId) return response()->json([], 200);

        $savedRows = UserCategoryBudget::where('budget_id', $budgetId)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->whereIn('business_line', ['montblanc','parbel'])
            ->get()
            ->keyBy(function($row){ return (string)($row->category_classification ?? $row->category_id ?? ''); });

        $budgetRow = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
        $targetAmount = $budgetRow ? ($budgetRow->target_amount ?? ($budgetRow->amount ?? 0)) : 0;

        $advisorPctRow = DB::connection('budget')->table('category_commissions')
            ->where('category_id', self::ADVISOR_CATEGORY_ID)
            ->where('budget_id', $budgetId)
            ->selectRaw('AVG(COALESCE(participation_pct,0)) as pct')
            ->first();
        $advisorPct = (float)($advisorPctRow->pct ?? 0);
        $advisorPoolUsd = round($targetAmount * ($advisorPct / 100), 2);

        // Participation map (classification_code => avg participation)
        $categoriesParticipation = DB::connection('budget')
            ->table('category_commissions')
            ->join('categories','categories.id','=','category_commissions.category_id')
            ->where('category_commissions.budget_id', $budgetId)
            ->select('categories.classification_code', DB::raw('AVG(COALESCE(category_commissions.participation_pct,0)) as participation_pct'))
            ->groupBy('categories.classification_code')
            ->get()
            ->mapWithKeys(function($c){ return [ (string)$c->classification_code => (float)$c->participation_pct ]; })
            ->toArray();

        // resolve mont classifications by name heuristics (fallback to defaults)
        $montClassifications = DB::connection('budget')->table('categories')
            ->where(function($q) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%gifts%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%watch%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%jewel%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%sunglass%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%electro%']);
            })
            ->pluck('classification_code')
            ->map(fn($v)=>(string)$v)
            ->unique()
            ->values()
            ->all();

        // debug checks to help find gaps
        $cat19 = DB::connection('budget')->table('categories')->where('classification_code', 19)->first();
        Log::info('MontClassifications actuales:', $montClassifications);
        Log::info('¿Contiene 19?', ['tiene_19' => in_array('19', $montClassifications, true)]);
        Log::info('Categoria 19 en categories:', (array)($cat19 ?? []));

        // Ensure defaults are present
        if (empty($montClassifications)) $montClassifications = self::DEFAULT_MONT_KEYS;
        else $montClassifications = array_values(array_unique(array_merge($montClassifications, self::DEFAULT_MONT_KEYS)));

        // Add diamantes as special key
        if (!in_array(self::DIAMANTES_KEY, $montClassifications, true)) {
            $montClassifications[] = self::DIAMANTES_KEY;
        }

        // parbel classifications: skin + frag
        $fragCodes = self::FRAG_CODES;
        $fragClassifications = DB::connection('budget')->table('categories')
            ->whereIn(DB::raw('CAST(classification_code AS SIGNED)'), $fragCodes)
            ->pluck('classification_code')->map(fn($v)=>(string)$v)->unique()->values()->all();

        $skinClassifications = DB::connection('budget')->table('categories')
            ->where(function($q){
                $q->whereRaw('LOWER(name) LIKE ?', ['%skin%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%skin care%'])
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%skin-care%']);
            })->pluck('classification_code')->map(fn($v)=>(string)$v)->unique()->values()->all();

        $parbelClassifications = array_values(array_unique(array_merge($skinClassifications, $fragClassifications)));
        if (empty($parbelClassifications)) $parbelClassifications = self::DEFAULT_PARBEL_KEYS;

        // compute sums used for proportional split
        $montPartsSum = 0.0; foreach ($montClassifications as $k) $montPartsSum += ($categoriesParticipation[$k] ?? 0.0);
        $parbelPartsSum = 0.0; foreach ($parbelClassifications as $k) $parbelPartsSum += ($categoriesParticipation[$k] ?? 0.0);

        $partsTotal = max(0.0, $montPartsSum + $parbelPartsSum);
        if ($partsTotal <= 0) {
            $montAssignedUsd = round($advisorPoolUsd / 2, 2);
            $parbelAssignedUsd = round($advisorPoolUsd / 2, 2);
        } else {
            $montAssignedUsd = round($advisorPoolUsd * ($montPartsSum / $partsTotal), 2);
            $parbelAssignedUsd = round($advisorPoolUsd * ($parbelPartsSum / $partsTotal), 2);
        }

        // canonical keys
        $canonicalMont = array_map('strval', array_values(array_unique($montClassifications)));
        $canonicalParbel = array_map('strval', array_values(array_unique($parbelClassifications)));
        if (!in_array(self::FRAG_KEY, $canonicalParbel, true) && !empty($fragClassifications)) $canonicalParbel[] = self::FRAG_KEY;

        $namesMap = [
            '19' => 'Gifts',
            '14' => 'Watches',
            '15' => 'Jewerly',
            '16' => 'Sunglasses',
            '21' => 'Electronics',
            '13' => 'Skin care',
            self::FRAG_KEY => 'Fragancias',
            self::DIAMANTES_KEY => 'Diamantes'
        ];

        // filter saved
        $filteredSaved = [];
        foreach ($savedRows as $k => $v) {
            $key = (string)$k;
            if (in_array($key, $canonicalMont, true) || in_array($key, $canonicalParbel, true)) {
                $filteredSaved[$key] = $v->toArray();
            }
        }

        // build rows
        $out = [];
        foreach ($canonicalMont as $key) {
            $saved = $filteredSaved[$key] ?? null;
            $participation = $categoriesParticipation[$key] ?? 0.0;

            if ($montPartsSum > 0) {
                $mont_share = round($montAssignedUsd * ($participation / max(0.0001, $montPartsSum)), 2);
            } else {
                $count = max(1, count($canonicalMont));
                $mont_share = round($montAssignedUsd / $count, 2);
            }

            $out[] = [
                'category_classification' => (string)$key,
                'category_id' => $saved['category_id'] ?? null,
                'name' => $saved['name'] ?? ($namesMap[$key] ?? (string)$key),
                'budget_usd' => $saved['budget_usd'] ?? 0,
                'participation_pct' => $participation,
                'mont_share_usd' => $mont_share,
                'parbel_share_usd' => null,
                'saved_id' => $saved['id'] ?? null,
            ];
        }

        foreach ($canonicalParbel as $key) {
            // Avoid duplicating frag individual entries (we keep 'fragancias' aggregate)
            if ($key !== self::FRAG_KEY && in_array(self::FRAG_KEY, $canonicalParbel, true) && in_array($key, $fragClassifications, true)) {
                continue;
            }

            $saved = $filteredSaved[$key] ?? null;
            $participation = $categoriesParticipation[$key] ?? 0.0;

            if ($parbelPartsSum > 0) {
                $parbel_share = round($parbelAssignedUsd * ($participation / max(0.0001, $parbelPartsSum)), 2);
            } else {
                $count = max(1, count($canonicalParbel));
                $parbel_share = round($parbelAssignedUsd / $count, 2);
            }

            $out[] = [
                'category_classification' => (string)$key,
                'category_id' => $saved['category_id'] ?? null,
                'name' => $saved['name'] ?? ($namesMap[$key] ?? (string)$key),
                'budget_usd' => $saved['budget_usd'] ?? 0,
                'participation_pct' => $participation,
                'mont_share_usd' => null,
                'parbel_share_usd' => $parbel_share,
                'saved_id' => $saved['id'] ?? null,
            ];
        }

        return response()->json([
            'advisor_pool' => [
                'pct' => $advisorPct,
                'pool_usd' => $advisorPoolUsd,
                'mont_assigned_usd' => $montAssignedUsd,
                'parbel_assigned_usd' => $parbelAssignedUsd
            ],
            'rows' => $out
        ]);
    }

    public function upsertCategoryBudget(Request $r)
    {
        $data = $r->validate([
            'budget_id' => 'required|integer',
            'user_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'category_classification' => 'nullable|string',
            'budget_usd' => 'required|numeric|min:0',
            'business_line' => 'nullable|string|in:montblanc,parbel'
        ]);

        $budgetId = (int)$data['budget_id'];
        $classification = isset($data['category_classification']) ? (string)$data['category_classification'] : null;
        $businessLine = isset($data['business_line']) ? $data['business_line'] : null;

        if (!$businessLine && $classification) {
            $k = strtolower((string)$classification);
            if ($k === '13' || $k === self::FRAG_KEY || strpos($k, 'frag') !== false || strpos($k, 'skin') !== false) {
                $businessLine = 'parbel';
            } else {
                $businessLine = 'montblanc';
            }
        }
        $businessLine = $businessLine ?? 'montblanc';

        if (empty($data['category_id']) && !empty($classification) && $classification !== self::FRAG_KEY) {
            try {
                $cat = DB::connection('budget')
                    ->table('categories')
                    ->where(DB::raw('CAST(classification_code AS CHAR)'), $classification)
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($classification)])
                    ->first();
                if ($cat) $data['category_id'] = $cat->id;
            } catch (\Throwable $e) {
                // ignore lookup errors
            }
        }

        $row = UserCategoryBudget::updateOrCreate(
            [
                'budget_id' => $budgetId,
                'business_line' => $businessLine,
                'category_classification' => $classification,
            ],
            [
                'user_id' => $data['user_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'budget_usd' => $data['budget_usd'],
                'updated_at' => now()
            ]
        );

        return response()->json($row);
    }

    public function deleteCategoryBudget($id)
    {
        $r = UserCategoryBudget::find($id);
        if (!$r) return response()->json(['message'=>'Not found'], 404);
        $r->delete();
        return response()->json(['message'=>'deleted']);
    }

    /* ======================
       SPECIALISTS (assign / list)
       ====================== */

    public function assignSpecialist(Request $r)
    {
        $data = $r->validate([
            'budget_id' => 'required|integer',
            'user_id' => 'required|integer',
            'business_line' => 'required|string|in:montblanc,parbel',
            'category_id' => 'nullable|integer',
            'valid_from' => 'nullable|date',
            'note' => 'nullable|string'
        ]);

        $line = $data['business_line'];

        DB::connection('budget')->transaction(function() use ($data, $line) {
            DB::connection('budget')->table('advisor_specialists')
                ->where('budget_id', $data['budget_id'])
                ->where('business_line', $line)
                ->whereNull('valid_to')
                ->update(['valid_to' => now()]);

            AdvisorSpecialist::create([
                'budget_id' => $data['budget_id'],
                'user_id' => $data['user_id'],
                'business_line' => $line,
                'category_id' => $data['category_id'] ?? null,
                'valid_from' => $data['valid_from'] ?? now(),
                'valid_to' => null,
                'created_by' => auth()->id(),
                'note' => $data['note'] ?? null
            ]);
        });

        return response()->json(['message'=>'assigned']);
    }

    public function getSpecialistsForBudget(Request $request)
    {
        $budgetId = (int) $request->query('budget_id');
        $line = $request->query('business_line');

        $query = AdvisorSpecialist::where('budget_id', $budgetId);
        if ($line) $query->where('business_line', $line);

        $rows = $query->orderByDesc('valid_from')->get();

        return response()->json($rows);
    }

    /* ======================
       OVERRIDES (guardar / listar)
       ====================== */

    public function saveCommissionOverrides(Request $r)
    {
        $data = $r->validate([
            'budget_ids' => 'required|array|min:1',
            'budget_ids.*' => 'integer',
            'user_id' => 'required|integer',
            'overrides' => 'required|array|min:1',
            'overrides.*.classification_code' => 'required|string',
            'overrides.*.applied_commission_pct' => 'required|numeric|min:0',
        ]);

        $budgetIds = array_map('intval', $data['budget_ids']);
        $userId = (int)$data['user_id'];
        $overrides = $data['overrides'];
        $actorId = auth()->id();

        DB::connection('budget')->transaction(function() use ($budgetIds, $userId, $overrides, $actorId) {
            foreach ($budgetIds as $budgetId) {
                foreach ($overrides as $ov) {
                    $classification = (string)$ov['classification_code'];
                    $pct = (float)$ov['applied_commission_pct'];

                    $category = null;
                    try {
                        $category = DB::connection('budget')
                            ->table('categories')
                            ->where(DB::raw('CAST(classification_code AS CHAR)'), $classification)
                            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($classification)])
                            ->first();
                    } catch (\Throwable $e) {
                        $category = null;
                    }
                    $categoryId = $category ? $category->id : null;

                    DB::connection('budget')->table('advisor_category_overrides')->updateOrInsert(
                        [
                            'budget_id' => $budgetId,
                            'user_id' => $userId,
                            'classification_code' => (string)$classification,
                        ],
                        [
                            'category_id' => $categoryId,
                            'applied_commission_pct' => $pct,
                            'updated_by' => $actorId,
                            'updated_at' => now(),
                            'created_at' => DB::raw('COALESCE(created_at, NOW())')
                        ]
                    );
                }
            }
        });

        return response()->json([
            'message' => 'Overrides guardados correctamente',
            'budget_ids' => $budgetIds,
            'user_id' => $userId
        ]);
    }

    public function getCommissionOverrides(Request $r)
    {
        $data = $r->validate([
            'budget_ids' => 'required|array|min:1',
            'budget_ids.*' => 'integer',
            'user_id' => 'required|integer',
        ]);

        $budgetIds = array_map('intval', $data['budget_ids']);
        $userId = (int)$data['user_id'];

        $rows = DB::connection('budget')
            ->table('advisor_category_overrides')
            ->where('user_id', $userId)
            ->whereIn('budget_id', $budgetIds)
            ->select('budget_id','classification_code','applied_commission_pct','category_id','created_at','updated_at','updated_by')
            ->orderBy('budget_id')
            ->get()
            ->groupBy('budget_id')
            ->map(function($items) {
                return $items->mapWithKeys(function($it) {
                    return [ (string)$it->classification_code => [
                        'applied_commission_pct' => (float)$it->applied_commission_pct,
                        'category_id' => $it->category_id,
                        'updated_at' => $it->updated_at,
                        'updated_by' => $it->updated_by
                    ]];
                });
            });

        return response()->json([
            'user_id' => $userId,
            'overrides' => $rows,
        ]);
    }

    /**
     * activeSpecialistsSales
     * Endpoint used by front-end to fetch active specialist + sales breakdown (montblanc / parbel)
     *
     * Devuelve en breakdown llaves con:
     *  - classification_key
     *  - sales_usd
     *  - sales_cop
     *  - category_budget_usd_for_user (si existe)
     *  - pct_user_of_category_budget
     *  - applied_commission_pct (override si existe)
     */
public function activeSpecialistsSales(Request $request)
{
    $data = $request->validate([
        'budget_id' => 'nullable|integer',
        'business_line' => 'nullable|string|in:montblanc,parbel',
        'user_id' => 'nullable|integer',
    ]);

    $budgetId = isset($data['budget_id']) ? (int)$data['budget_id'] : null;
    $businessLine = $data['business_line'] ?? null;
    $forcedUserId = isset($data['user_id']) ? (int)$data['user_id'] : null;

    $roleId = null;
    if ($businessLine === 'parbel') {
        $roleId = 4; // ajusta si es necesario
    } elseif ($businessLine === 'montblanc') {
        $roleId = 5; // ajusta si es necesario
    }

    // resolver especialista (activo o por user_id forzado)
    $specialist = null;
    if (!$forcedUserId) {
        $specQ = DB::connection('budget')->table('advisor_specialists')->whereNull('valid_to');
        if ($budgetId) $specQ->where('budget_id', $budgetId);
        if ($businessLine) $specQ->where('business_line', $businessLine);
        $specialist = $specQ->first();
    } else {
        $specRow = DB::connection('budget')->table('advisor_specialists')
            ->where('user_id', $forcedUserId)
            ->when($budgetId, fn($q) => $q->where('budget_id', $budgetId))
            ->when($businessLine, fn($q) => $q->where('business_line', $businessLine))
            ->orderByDesc('valid_from')
            ->first();
        if ($specRow) $specialist = $specRow;
    }

    $userId = $forcedUserId ?: ($specialist->user_id ?? null);
    if (!$userId) {
        return response()->json([
            'count' => 0,
            'message' => 'No se encontró especialista activo (ni user_id provisto).',
            'specialist' => $specialist
        ], 200);
    }

    $user = DB::connection('budget')->table('users')->select('id','name','codigo_vendedor')->where('id', $userId)->first();

    // ----------------------------
    // 1) CALCULO DEL POOL ADVISOR
    // ----------------------------
    $budgetRow = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
    $budgetTotal = $budgetRow ? ($budgetRow->target_amount ?? ($budgetRow->amount ?? 0)) : 0;

    $advisorPctRow = DB::connection('budget')->table('category_commissions')
        ->where('category_id', self::ADVISOR_CATEGORY_ID)
        ->where('budget_id', $budgetId)
        ->selectRaw('AVG(COALESCE(participation_pct,0)) as pct')
        ->first();
    $advisorPct = (float)($advisorPctRow->pct ?? 0);
    $advisorPoolUsd = round($budgetTotal * ($advisorPct / 100), 2);

    // read advisor_splits for budget -> derive user assigned %
    $userAssignedPct = 0.0;
    try {
        $splitRow = DB::connection('budget')->table('advisor_splits')->where('budget_id', $budgetId)->first();
        if ($splitRow) {
            if ((int)$splitRow->advisor_a_id === (int)$userId) $userAssignedPct = (float)($splitRow->advisor_a_pct ?? 0);
            elseif ((int)$splitRow->advisor_b_id === (int)$userId) $userAssignedPct = (float)($splitRow->advisor_b_pct ?? 0);
            else $userAssignedPct = 0.0;
        } else {
            $userAssignedPct = 0.0;
        }
    } catch (\Throwable $e) {
        Log::warning('Error leyendo advisor_splits: ' . $e->getMessage());
        $userAssignedPct = 0.0;
    }

    $userPoolUsd = round($advisorPoolUsd * ($userAssignedPct / 100.0), 2);

    // ----------------------------
    // Helpers
    // ----------------------------
    $fetchUserCategoryBudget = function($budgetId, $classification) {
        if (Schema::connection('budget')->hasTable('user_category_budgets')) {
            try {
                $r = DB::connection('budget')->table('user_category_budgets')
                    ->where('budget_id', $budgetId)
                    ->where(function($q) use ($classification) {
                        $q->where(DB::raw('CAST(category_classification AS CHAR)'), $classification)
                          ->orWhere('category_id', $classification)
                          ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($classification)]);
                    })->first();
                if ($r) return (float)($r->budget_usd ?? 0);
            } catch (\Throwable $e) { /* continue */ }
        }
        try {
            $m = UserCategoryBudget::where('budget_id', $budgetId)
                ->where(function($q) use ($classification) {
                    $q->where('category_classification', $classification)
                      ->orWhere('category_id', $classification);
                })->first();
            if ($m) return (float)($m->budget_usd ?? 0);
        } catch (\Throwable $e) { /* ignore */ }
        return 0.0;
    };

    $fetchOverridePct = function($budgetId, $userId, $classification) {
        try {
            $val = DB::connection('budget')->table('advisor_category_overrides')
                ->where('budget_id', $budgetId)
                ->where('user_id', $userId)
                ->where('classification_code', (string)$classification)
                ->value('applied_commission_pct');
            return is_null($val) ? null : (float)$val;
        } catch (\Throwable $e) {
            return null;
        }
    };

    // ----------------------------
    // Participaciones por classification (category_commissions)
    // ----------------------------
    $categoriesParticipation = DB::connection('budget')
        ->table('category_commissions')
        ->join('categories','categories.id','=','category_commissions.category_id')
        ->where('category_commissions.budget_id', $budgetId)
        ->select('categories.classification_code', DB::raw('AVG(COALESCE(category_commissions.participation_pct,0)) as participation_pct'))
        ->groupBy('categories.classification_code')
        ->get()
        ->mapWithKeys(function($c){ return [ (string)$c->classification_code => (float)$c->participation_pct ]; })
        ->toArray();

    // heurísticas / canonical lists (reutilizo tu lógica)
    $montClassifications = DB::connection('budget')->table('categories')
        ->where(function($q) {
            $q->whereRaw('LOWER(name) LIKE ?', ['%gifts%'])
              ->orWhereRaw('LOWER(name) LIKE ?', ['%watch%'])
              ->orWhereRaw('LOWER(name) LIKE ?', ['%jewel%'])
              ->orWhereRaw('LOWER(name) LIKE ?', ['%sunglass%'])
              ->orWhereRaw('LOWER(name) LIKE ?', ['%electro%']);
        })
        ->pluck('classification_code')
        ->map(fn($v)=>(string)$v)
        ->unique()
        ->values()
        ->all();

    $fragCodes = self::FRAG_CODES;
    $fragClassifications = DB::connection('budget')->table('categories')
        ->whereIn(DB::raw('CAST(classification_code AS SIGNED)'), $fragCodes)
        ->pluck('classification_code')->map(fn($v)=>(string)$v)->unique()->values()->all();

    $skinClassifications = DB::connection('budget')->table('categories')
        ->where(function($q){
            $q->whereRaw('LOWER(name) LIKE ?', ['%skin%'])
              ->orWhereRaw('LOWER(name) LIKE ?', ['%skin care%'])
              ->orWhereRaw('LOWER(name) LIKE ?', ['%skin-care%']);
        })->pluck('classification_code')->map(fn($v)=>(string)$v)->unique()->values()->all();

    $canonicalMont = !empty($montClassifications) ? array_values(array_unique(array_merge($montClassifications, self::DEFAULT_MONT_KEYS))) : self::DEFAULT_MONT_KEYS;
    $canonicalParbel = !empty(array_merge($skinClassifications, $fragClassifications)) ? array_values(array_unique(array_merge($skinClassifications, $fragClassifications))) : self::DEFAULT_PARBEL_KEYS;
    if (!in_array(self::FRAG_KEY, $canonicalParbel, true) && !empty($fragClassifications)) $canonicalParbel[] = self::FRAG_KEY;

    // Add diamantes explicitly to canonicalMont
    if (!in_array(self::DIAMANTES_KEY, $canonicalMont, true)) $canonicalMont[] = self::DIAMANTES_KEY;

    $montPartsSum = 0.0; foreach ($canonicalMont as $k) $montPartsSum += ($categoriesParticipation[$k] ?? 0.0);
    $parbelPartsSum = 0.0; foreach ($canonicalParbel as $k) $parbelPartsSum += ($categoriesParticipation[$k] ?? 0.0);

    // friendly names fallback
    $namesMap = [
        '19' => 'Gifts',
        '14' => 'Watches',
        '15' => 'Jewerly',
        '16' => 'Sunglasses',
        '21' => 'Electronics',
        '13' => 'Skin care',
        self::FRAG_KEY => 'Fragancias',
        self::DIAMANTES_KEY => 'Diamantes'
    ];

    // estructura de respuesta base
    $result = [
        'specialist_user_id' => $userId,
        'specialist' => $user,
        'specialist_name' => $user->name ?? null,
        'business_line' => $businessLine,
        'budget_id' => $budgetId,
        'rows' => [],
        'totals' => ['sales_usd' => 0.0, 'sales_cop' => 0.0],
        'advisor_pool' => [
            'pct' => $advisorPct,
            'pool_usd' => $advisorPoolUsd,
        ],
        'user_budget_usd' => $userPoolUsd,
    ];

    // ----------------------------
    // Helper para obtener commission tiers (si existen) => usa category_id y role_id
    // ----------------------------
    $getCategoryCommissionTiers = function($budgetId, $classification, $roleId) {
        try {
            // buscar category por classification (puede ser numérico)
            $category = DB::connection('budget')->table('categories')
                ->where('classification_code', $classification)
                ->first();
            if (!$category) return null;
            $categoryId = $category->id;

            // buscar fila de category_commissions por category_id + budget + role
            $row = DB::connection('budget')
                ->table('category_commissions')
                ->where('budget_id', $budgetId)
                ->where('role_id', $roleId)
                ->where('category_id', $categoryId)
                ->first();
            if (!$row) return null;

            return [
                'pct_80'  => (float)($row->commission_percentage ?? 0),
                'pct_100' => (float)($row->commission_percentage100 ?? 0),
                'pct_120' => (float)($row->commission_percentage120 ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::error('Error getCategoryCommissionTiers: '.$e->getMessage());
            return null;
        }
    };

    // ----------------------------
    // Recolectamos ventas por categoría según la línea solicitada
    // ----------------------------
    if ($businessLine === 'parbel') {
        // (sin cambios respecto a tu lógica original para parbel)
        $fragRegex = implode('|', array_map('intval', $fragCodes));
        $parbelQuery = DB::connection('budget')
            ->table('sales')
            ->join('products','sales.product_id','=','products.id')
            ->selectRaw("CASE
                WHEN CAST(products.classification AS CHAR) REGEXP '^(?:{$fragRegex})$' THEN '".self::FRAG_KEY."'
                WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%frag%' THEN '".self::FRAG_KEY."'
                WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%perf%' THEN '".self::FRAG_KEY."'
                WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%skin%' THEN 'skin'
                ELSE TRIM(COALESCE(products.classification, 'sin_categoria'))
            END as classification_key,
            COALESCE(SUM(sales.value_usd),0) as sales_usd,
            COALESCE(SUM(sales.amount_cop),0) as sales_cop")
            ->where(function ($q) use ($fragRegex) {

                // FRAGANCIAS → SOLO PARBEL
                $q->where(function ($frag) use ($fragRegex) {
                    $frag->where('products.provider_name', 'PARBEL')
                        ->where(function ($c) use ($fragRegex) {
                            $c->whereRaw("CAST(products.classification AS CHAR) REGEXP '^(?:{$fragRegex})$'")
                              ->orWhereRaw("LOWER(CAST(products.classification AS CHAR)) LIKE '%frag%'")
                              ->orWhereRaw("LOWER(CAST(products.classification AS CHAR)) LIKE '%perf%'");
                        });
                })
            
                // SKIN → TODOS LOS PROVIDERS
                ->orWhere(function ($skin) {
                    $skin->whereRaw("LOWER(CAST(products.classification AS CHAR)) LIKE '%skin%'")
                         ->orWhereRaw("CAST(products.classification AS CHAR) = '13'");
                });
            
            })
            ->where('sales.seller_id', $userId)
            ->groupBy(DB::raw('classification_key'));

        $hasSalesBudgetId = Schema::connection('budget')->hasColumn('sales','budget_id');
        if ($budgetId && $hasSalesBudgetId) {
            $parbelQuery->where('sales.budget_id', $budgetId);
        } elseif (!$hasSalesBudgetId) {
            [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
            $parbelQuery->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
        }

        Log::info('SQL Parbel:', [
            'sql' => $parbelQuery->toSql(),
            'bindings' => $parbelQuery->getBindings()
        ]);
        $parbelRows = $parbelQuery->get();
        Log::info('Filas Parbel:', $parbelRows->toArray());

        foreach ($parbelRows as $r) {
            $rawKey = $r->classification_key;
            $key = $this->normalizeClassification($rawKey);
            // solo interesan skin/fragancias (o classification codes que sean relevantes)
            if (!in_array($key, ['skin', self::FRAG_KEY], true) && !is_numeric($key)) continue;

            $sUsd = round((float)$r->sales_usd, 2);
            $sCop = round((float)$r->sales_cop, 2);

            // Display name (prefer DB name cuando posible)
            if ($key === self::FRAG_KEY) {
                $displayName = $namesMap[self::FRAG_KEY] ?? 'Fragancias';
            } elseif ($key === 'skin') {
                $displayName = $namesMap['13'] ?? 'Skin care';
            } elseif (is_numeric($key)) {
                $cat = DB::connection('budget')->table('categories')->where('classification_code', $key)->first();
                $displayName = $cat ? $cat->name : ($namesMap[$key] ?? (string)$key);
            } else {
                $displayName = (string)$key;
            }

            // presupuesto de categoría preferido (guardado) o calculado
            $categoryBudgetUsd = $fetchUserCategoryBudget($budgetId, $key);
            if ($categoryBudgetUsd <= 0) {
                $partsSum = $parbelPartsSum;
                if ($partsSum > 0) {
                    $catPart = ($categoriesParticipation[$key] ?? 0.0);
                    $categoryBudgetUsd = round($userPoolUsd * ($catPart / $partsSum), 2);
                } else {
                    $count = max(1, count($canonicalParbel));
                    $categoryBudgetUsd = round($userPoolUsd / $count, 2);
                }
            }

            $overridePct = $fetchOverridePct($budgetId, $userId, $key);
            $pctOfBudget = $categoryBudgetUsd > 0 ? round(($sUsd / $categoryBudgetUsd) * 100, 4) : 0.0;

            // determinar pct final (override > tiers > base)
            $finalCommissionPct = 0.0;
            if (!is_null($overridePct)) {
                $finalCommissionPct = (float)$overridePct;
            } else {
                $tiers = $getCategoryCommissionTiers($budgetId, $key, $roleId);
                if (!empty($tiers)) {
                    if ($pctOfBudget >= 120 && !is_null($tiers['pct_120'])) $finalCommissionPct = (float)$tiers['pct_120'];
                    elseif ($pctOfBudget >= 100 && !is_null($tiers['pct_100'])) $finalCommissionPct = (float)$tiers['pct_100'];
                    elseif ($pctOfBudget >= 80 && !is_null($tiers['pct_80'])) $finalCommissionPct = (float)$tiers['pct_80'];
                    else $finalCommissionPct = 0.0;
                } else {
                    try {
                        $row = DB::connection('budget')->table('category_commissions')
                            ->join('categories','categories.id','=','category_commissions.category_id')
                            ->where('category_commissions.budget_id', $budgetId)
                            ->where(DB::raw('CAST(categories.classification_code AS CHAR)'), $key)
                            ->selectRaw('AVG(COALESCE(category_commissions.applied_commission_pct, category_commissions.commission_pct, 0)) as pct')
                            ->first();
                        $finalCommissionPct = (float)($row->pct ?? 0.0);
                    } catch (\Throwable $e) {
                        $finalCommissionPct = 0.0;
                    }
                }
            }

            $commissionUsd = round(($sUsd * ($finalCommissionPct / 100.0)), 2);

            // guardar usando displayName como clave (nombre en vez de code)
            $result['rows'][$displayName] = [
                'classification_code' => is_numeric($key) ? (string)$key : $key,
                'classification_name' => $displayName,
                'sales_usd' => $sUsd,
                'sales_cop' => $sCop,
                'category_budget_usd_for_user' => round($categoryBudgetUsd,2),
                'pct_user_of_category_budget' => $pctOfBudget,
                'applied_commission_pct' => is_null($overridePct) ? ($finalCommissionPct ? round($finalCommissionPct,4) : null) : round($overridePct,4),
                'commission_usd' => $commissionUsd,
            ];

            $result['totals']['sales_usd'] += $sUsd;
            $result['totals']['sales_cop'] += $sCop;
        }

    } else { // montblanc
        Log::info('ENTRANDO A BLOQUE MONTBLANC - inicio robusto');
        $montRows = [];

        $canonicalMont = array_map('strval', array_values(array_unique($canonicalMont)));

        $montQuery = DB::connection('budget')
            ->table('budget_user_category_totals')
            ->selectRaw('category_group AS classification, COALESCE(SUM(sales_usd),0) as sales_usd, COALESCE(SUM(sales_cop),0) as sales_cop')
            ->where('user_id', $userId)
            ->whereIn(DB::raw('CAST(category_group AS CHAR)'), array_values(array_filter($canonicalMont, fn($v)=>is_numeric($v))))
            ->groupBy('category_group');

        if ($budgetId) $montQuery->where('budget_id', $budgetId);
        $montRowsCollection = $montQuery->get();
        foreach ($montRowsCollection as $mr) {
            $montRows[(string)$mr->classification] = (object)[
                'classification' => (string)$mr->classification,
                'sales_usd' => (float)$mr->sales_usd,
                'sales_cop' => (float)$mr->sales_cop
            ];
        }
        Log::info('MontRows (from budget_user_category_totals):', array_keys($montRows));

        // --- SPECIAL: compute diamantes sales (provider L'ARTIST) and subtract them from classification '15' totals ---
        $jewKey = '15';
        $diamantesSalesUsd = 0.0;
        $diamantesSalesCop = 0.0;
        $totalJewelrySalesUsd = $montRows[$jewKey]->sales_usd ?? 0.0;
        $totalJewelrySalesCop = $montRows[$jewKey]->sales_cop ?? 0.0;

        try {
            // compute diamantes sales (provider L'ARTIST) for this user
            $diamQ = DB::connection('budget')->table('sales')
                ->join('products','sales.product_id','=','products.id')
                ->where('sales.seller_id', $userId)
                ->where('products.provider_name', "L'ARTIST")
                ->where(DB::raw('CAST(products.classification AS CHAR)'), $jewKey);
            if ($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id')) $diamQ->where('sales.budget_id', $budgetId);
            else {
                [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
                $diamQ->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
            }
            $diamantesSalesUsd = (float)$diamQ->sum(DB::raw('COALESCE(sales.value_usd,0)'));
            $diamantesSalesCop = (float)$diamQ->sum(DB::raw('COALESCE(sales.amount_cop,0)'));
        } catch (\Throwable $e) {
            Log::warning('Error calculando diamantes sales: '.$e->getMessage());
            $diamantesSalesUsd = 0.0;
            $diamantesSalesCop = 0.0;
        }

        // subtract diamantes from jewerly totals if present in montRows
        if (isset($montRows[$jewKey])) {
            $montRows[$jewKey]->sales_usd = round(max(0, $montRows[$jewKey]->sales_usd - $diamantesSalesUsd), 2);
            $montRows[$jewKey]->sales_cop = round(max(0, $montRows[$jewKey]->sales_cop - $diamantesSalesCop), 2);
        }

        // set explicit diamantes row
        $montRows[self::DIAMANTES_KEY] = (object)[
            'classification' => self::DIAMANTES_KEY,
            'sales_usd' => round($diamantesSalesUsd,2),
            'sales_cop' => round($diamantesSalesCop,2)
        ];

        Log::info('MontRows ajustadas (diamantes separado):', array_keys($montRows));

        foreach ($canonicalMont as $key) {
            $r = isset($montRows[$key]) ? $montRows[$key] : (object)[
                'classification' => $key,
                'sales_usd' => 0.0,
                'sales_cop' => 0.0
            ];

            $sUsd = round((float)($r->sales_usd ?? 0), 2);
            $sCop = round((float)($r->sales_cop ?? 0), 2);

            // display name prefer DB (for numeric classifications)
            if (is_numeric($key)) {
                $cat = DB::connection('budget')->table('categories')->where('classification_code', $key)->first();
                $displayName = $cat ? $cat->name : ($namesMap[$key] ?? (string)$key);
            } else {
                // special keys: diamantes etc
                $displayName = $namesMap[$key] ?? (string)$key;
            }

            // category budget (guardado o calculado)
            $categoryBudgetUsd = 0.0;
            // If diamantes, we try to split from the parent jewerly (15) budget (see explanation)
            if ($key === self::DIAMANTES_KEY) {
                // try direct saved budget first
                $categoryBudgetUsd = $fetchUserCategoryBudget($budgetId, $key);
                // if not saved, allocate from jewerly (15) budget proportionally to diamantes sales share
                if ($categoryBudgetUsd <= 0) {
                    // parent classification
                    $parentKey = '15';
                    // parent budget
                    $parentBudgetUsd = $fetchUserCategoryBudget($budgetId, $parentKey);
                    if ($parentBudgetUsd <= 0) {
                        // fallback: derive parent budget from participation or equal split
                        $partsSum = $montPartsSum;
                        if ($partsSum > 0) {
                            $catPart = ($categoriesParticipation[$parentKey] ?? 0.0);
                            $parentBudgetUsd = round($userPoolUsd * ($catPart / max(0.0001, $partsSum)), 2);
                        } else {
                            $count = max(1, count(array_filter($canonicalMont, fn($v)=>is_numeric($v))));
                            $parentBudgetUsd = round($userPoolUsd / $count, 2);
                        }
                    }

                    // compute total jewelry sales to split parent budget
                    $totalJewelrySalesUsd = 0.0;
                    if (isset($montRows[$parentKey])) $totalJewelrySalesUsd = (float)$montRows[$parentKey]->sales_usd + (float)($montRows[self::DIAMANTES_KEY]->sales_usd ?? 0);
                    // avoid division by 0
                    if ($totalJewelrySalesUsd > 0) {
                        $categoryBudgetUsd = round($parentBudgetUsd * (($montRows[self::DIAMANTES_KEY]->sales_usd ?? 0) / $totalJewelrySalesUsd), 2);
                    } else {
                        $categoryBudgetUsd = 0.0;
                    }
                }
            } elseif ($key === '15') {
                // jewerly (non-L'ARTIST) -> try saved or computed; but if diamantes got a split above, parent budget must be reduced accordingly
                $categoryBudgetUsd = $fetchUserCategoryBudget($budgetId, $key);
                if ($categoryBudgetUsd <= 0) {
                    $partsSum = $montPartsSum;
                    if ($partsSum > 0) {
                        $catPart = ($categoriesParticipation[$key] ?? 0.0);
                        $categoryBudgetUsd = round($userPoolUsd * ($catPart / max(0.0001, $partsSum)), 2);
                    } else {
                        $count = max(1, count(array_filter($canonicalMont, fn($v)=>is_numeric($v))));
                        $categoryBudgetUsd = round($userPoolUsd / $count, 2);
                    }
                }

                // subtract any diamantes budget that was computed from the same parent budget
                // compute diamantes budget (if not already saved)
                $diamSavedBudget = $fetchUserCategoryBudget($budgetId, self::DIAMANTES_KEY);
                if ($diamSavedBudget <= 0) {
                    // compute split by sales
                    $totalJewelrySalesUsd = (float)$montRows[$key]->sales_usd + (float)$montRows[self::DIAMANTES_KEY]->sales_usd;
                    if ($totalJewelrySalesUsd > 0) {
                        $diamBudget = round($categoryBudgetUsd * ($montRows[self::DIAMANTES_KEY]->sales_usd / $totalJewelrySalesUsd), 2);
                        $categoryBudgetUsd = round(max(0, $categoryBudgetUsd - $diamBudget), 2);
                    }
                } else {
                    // if diamantes has a saved budget, subtract it
                    $categoryBudgetUsd = round(max(0, $categoryBudgetUsd - $diamSavedBudget), 2);
                }
            } else {
                // general case for numeric keys or others
                $categoryBudgetUsd = $fetchUserCategoryBudget($budgetId, $key);
                if ($categoryBudgetUsd <= 0) {
                    $partsSum = $montPartsSum;
                    if ($partsSum > 0) {
                        $catPart = ($categoriesParticipation[$key] ?? 0.0);
                        $categoryBudgetUsd = round($userPoolUsd * ($catPart / max(0.0001, $partsSum)), 2);
                    } else {
                        $count = max(1, count(array_filter($canonicalMont, fn($v)=>is_numeric($v))));
                        $categoryBudgetUsd = round($userPoolUsd / $count, 2);
                    }
                }
            }

            $overridePct = $fetchOverridePct($budgetId, $userId, $key);
            $pctOfBudget = $categoryBudgetUsd > 0 ? round(($sUsd / $categoryBudgetUsd) * 100, 4) : 0.0;

            $finalCommissionPct = 0.0;
            if (!is_null($overridePct)) {
                $finalCommissionPct = (float)$overridePct;
            } else {
                // For diamantes we try to reuse tiers of parent '15' classification (if exist)
                $tiersKey = $key === self::DIAMANTES_KEY ? '15' : $key;
                $tiers = $getCategoryCommissionTiers($budgetId, $tiersKey, $roleId);
                if (!empty($tiers)) {
                    if ($pctOfBudget >= 120 && !is_null($tiers['pct_120'])) $finalCommissionPct = (float)$tiers['pct_120'];
                    elseif ($pctOfBudget >= 100 && !is_null($tiers['pct_100'])) $finalCommissionPct = (float)$tiers['pct_100'];
                    elseif ($pctOfBudget >= 80 && !is_null($tiers['pct_80'])) $finalCommissionPct = (float)$tiers['pct_80'];
                    else $finalCommissionPct = 0.0;
                } else {
                    try {
                        $row = DB::connection('budget')->table('category_commissions')
                            ->join('categories','categories.id','=','category_commissions.category_id')
                            ->where('category_commissions.budget_id', $budgetId)
                            ->where(DB::raw('CAST(categories.classification_code AS CHAR)'), $tiersKey)
                            ->selectRaw('AVG(COALESCE(category_commissions.applied_commission_pct, category_commissions.commission_pct, 0)) as pct')
                            ->first();
                        $finalCommissionPct = (float)($row->pct ?? 0.0);
                    } catch (\Throwable $e) {
                        $finalCommissionPct = 0.0;
                    }
                }
            }

            $commissionUsd = round(($sUsd * ($finalCommissionPct / 100.0)), 2);

            // store row keyed by displayName (nombre)
            $result['rows'][(string)$key] = [
                'classification_code' => (string)$key,
                'classification_name' => $displayName,
                'sales_usd' => $sUsd,
                'sales_cop' => $sCop,
                'category_budget_usd_for_user' => round($categoryBudgetUsd,2),
                'pct_user_of_category_budget' => $pctOfBudget,
                'applied_commission_pct' => is_null($overridePct) ? ($finalCommissionPct ? round($finalCommissionPct,4) : null) : round($overridePct,4),
                'commission_usd' => $commissionUsd,
            ];

            $result['totals']['sales_usd'] += $sUsd;
            $result['totals']['sales_cop'] += $sCop;
        }

        Log::info('Montblanc rows finales contadas:', array_keys($result['rows']));
    }

    $result['totals']['sales_usd'] = round($result['totals']['sales_usd'], 2);
    $result['totals']['sales_cop'] = round($result['totals']['sales_cop'], 2);
    
    // Controlador CommissionReportController 
     
    $commissionController = new CommissionReportController();

    // crear Request con el budget_id en los query params (bySellerDetail usa ->query('budget_id' / 'budget_ids'))
    $detailRequest = Request::create('/', 'GET', ['budget_id' => $budgetId]);

    // pasar el userId como segundo argumento (la firma espera: (Request $request, $userId))
    $detailResponse = $commissionController->bySellerDetail($detailRequest, $userId);

    // si bySellerDetail devuelve un JsonResponse
    $detailData = $detailResponse->getData(true);
        
        
    // --- IMPORTANT: forward sales detail so frontend has a fallback ---
    $result['sales'] = $detailData['sales'] ?? [];
    $result['detail_totals'] = $detailData['totals'] ?? null;

    // opcional: log para debugging
    Log::info('activeSpecialistsSales -> bySellerDetail returned', [
        'user_id' => $userId,
        'budget_id' => $budgetId,
        'sales_count' => is_array($detailData['sales'] ?? null) ? count($detailData['sales']) : 0
    ]);
    $result['tickets'] = $detailData['tickets'] ?? [];
    $result['tickets_summary'] = $detailData['tickets_summary'] ?? null;
    $result['days_worked'] = $detailData['days_worked'] ?? [];
    $result['assigned_turns_for_user'] = $detailData['assigned_turns_for_user'] ?? 0;
    return response()->json([
        'count' => count($result['rows']),
        'specialist_user_id' => $result['specialist_user_id'],
        'specialist' => $result['specialist'],       // objeto user (id, name, ...)
        'specialist_name' => $result['specialist_name'], // nombre directo para conveniencia
        'business_line' => $result['business_line'],
        'budget_id' => $result['budget_id'],
        'totals' => $result['totals'],
        'user_budget_usd' => $result['user_budget_usd'],
        'breakdown' => $result['rows'],
        
        // sales
        'sales' => $result['sales'] ?? [],
        'detail_totals' => $result['detail_totals'] ?? null,
        
        // kpis Desde controlador 
        
        'tickets' => $result['tickets'] ?? [],
        'tickets_summary' => $result['tickets_summary'] ?? null,
        'days_worked' => $result['days_worked'] ?? [],
        'assigned_turns_for_user' => $result['assigned_turns_for_user'] ?? 0
    ]);
}
    /* ============================
       ENDPOINTS: My commissions
       ============================ */

    /**
     * GET /commissions/my
     * Devuelve la estructura que consume el frontend MyCommissionsPage.
     */
    public function myCommissions(Request $r)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['message' => 'Unauthenticated'], 401);

        $payload = $this->buildMyCommissionsPayload($r, $userId);

        return response()->json($payload);
    }

    /**
     * GET /commissions/my/export
     * Exporta CSV (fallback simple). No requiere paquetes extra.
     */
    public function exportMyCommissions(Request $r)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['message' => 'Unauthenticated'], 401);

        $payload = $this->buildMyCommissionsPayload($r, $userId);

        // Generar CSV simple
        $csv = $this->buildCsvFromCommissionsPayload($payload);

        $filename = 'mis_comisiones_user_'.$userId.'_budget_'.($r->query('budget_id') ?? 'all').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\""
        ]);
    }

    /**
     * Construye la carga (payload) que necesita el frontend.
     * Reúne categories, sales, totals, tickets, days_worked, assigned_turns_for_user, user_budget_usd, etc.
     */
    private function buildMyCommissionsPayload(Request $r, int $userId): array
    {
        $budgetId = $r->query('budget_id') ? (int)$r->query('budget_id') : null;

        // Budget row
        $budget = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
        $budgetTotal = $budget ? ($budget->target_amount ?? ($budget->amount ?? 0)) : 0;

        // categories participation map (avg)
        $categoriesParticipation = [];
        try {
            $rows = DB::connection('budget')
                ->table('category_commissions')
                ->join('categories','categories.id','=','category_commissions.category_id')
                ->where('category_commissions.budget_id', $budgetId)
                ->select('categories.classification_code', DB::raw('AVG(COALESCE(category_commissions.participation_pct,0)) as participation_pct'))
                ->groupBy('categories.classification_code')
                ->get();
            foreach ($rows as $c) $categoriesParticipation[(string)$c->classification_code] = (float)$c->participation_pct;
        } catch (\Throwable $e) {
            Log::warning('buildMyCommissionsPayload: categoriesParticipation error: '.$e->getMessage());
        }

        // Resolve canonical mont & parbel classifications (usando heurística usada en el controlador)
        try {
            $montClassifications = DB::connection('budget')->table('categories')
                ->where(function($q) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%gifts%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%watch%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%jewel%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%sunglass%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%electro%']);
                })
                ->pluck('classification_code')
                ->map(fn($v)=>(string)$v)
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $montClassifications = [];
        }
        if (empty($montClassifications)) $montClassifications = self::DEFAULT_MONT_KEYS;
        else $montClassifications = array_values(array_unique(array_merge($montClassifications, self::DEFAULT_MONT_KEYS)));

        // Add diamantes key
        if (!in_array(self::DIAMANTES_KEY, $montClassifications, true)) $montClassifications[] = self::DIAMANTES_KEY;

        try {
            $fragClassifications = DB::connection('budget')->table('categories')
                ->whereIn(DB::raw('CAST(classification_code AS SIGNED)'), self::FRAG_CODES)
                ->pluck('classification_code')->map(fn($v)=>(string)$v)->unique()->values()->all();
        } catch (\Throwable $e) {
            $fragClassifications = [];
        }

        try {
            $skinClassifications = DB::connection('budget')->table('categories')
                ->where(function($q){
                    $q->whereRaw('LOWER(name) LIKE ?', ['%skin%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%skin care%'])
                      ->orWhereRaw('LOWER(name) LIKE ?', ['%skin-care%']);
                })->pluck('classification_code')->map(fn($v)=>(string)$v)->unique()->values()->all();
        } catch (\Throwable $e) {
            $skinClassifications = [];
        }

        $parbelClassifications = array_values(array_unique(array_merge($skinClassifications, $fragClassifications)));
        if (empty($parbelClassifications)) $parbelClassifications = self::DEFAULT_PARBEL_KEYS;
        if (!in_array(self::FRAG_KEY, $parbelClassifications, true) && !empty($fragClassifications)) $parbelClassifications[] = self::FRAG_KEY;

        // Compute user budget: prefer UserCategoryBudget saved entries
        $userBudgetUsd = 0.0;
        try {
            $userBudgetUsd = (float) UserCategoryBudget::where('budget_id', $budgetId)
                ->where('user_id', $userId)
                ->sum('budget_usd');
        } catch (\Throwable $e) {
            $userBudgetUsd = 0.0;
        }

        // fallback: try advisor pool split if userBudgetUsd == 0
        if ($userBudgetUsd <= 0) {
            try {
                $advisorPctRow = DB::connection('budget')->table('category_commissions')
                    ->where('category_id', self::ADVISOR_CATEGORY_ID)
                    ->where('budget_id', $budgetId)
                    ->selectRaw('AVG(COALESCE(participation_pct,0)) as pct')
                    ->first();
                $advisorPct = (float)($advisorPctRow->pct ?? 0);
                $advisorPoolUsd = round($budgetTotal * ($advisorPct / 100), 2);

                $split = DB::connection('budget')->table('advisor_splits')->where('budget_id', $budgetId)->first();
                if ($split) {
                    if ((int)$split->advisor_a_id === (int)$userId) $userBudgetUsd = round($advisorPoolUsd * (($split->advisor_a_pct ?? 0) / 100), 2);
                    elseif ((int)$split->advisor_b_id === (int)$userId) $userBudgetUsd = round($advisorPoolUsd * (($split->advisor_b_pct ?? 0) / 100), 2);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Helper closures
        $fetchUserCategoryBudget = function($classification) use ($budgetId, $userId) {
            try {
                $r = UserCategoryBudget::where('budget_id', $budgetId)
                    ->where('user_id', $userId)
                    ->where(function($q) use ($classification) {
                        $q->where('category_classification', $classification)
                          ->orWhere('category_id', $classification);
                    })->first();
                if ($r) return (float)($r->budget_usd ?? 0);
            } catch (\Throwable $e) { }
            return 0.0;
        };

        $fetchOverridePct = function($classification) use ($budgetId, $userId) {
            try {
                $val = DB::connection('budget')->table('advisor_category_overrides')
                    ->where('budget_id', $budgetId)
                    ->where('user_id', $userId)
                    ->where('classification_code', (string)$classification)
                    ->value('applied_commission_pct');
                return is_null($val) ? null : (float)$val;
            } catch (\Throwable $e) { return null; }
        };

        $getAvgCommissionPct = function($classification) use ($budgetId) {
            try {
                $row = DB::connection('budget')->table('category_commissions')
                    ->join('categories','categories.id','=','category_commissions.category_id')
                    ->where('category_commissions.budget_id', $budgetId)
                    ->where(DB::raw('CAST(categories.classification_code AS CHAR)'), $classification)
                    ->selectRaw('AVG(COALESCE(category_commissions.applied_commission_pct, category_commissions.commission_pct, 0)) as pct')
                    ->first();
                return (float)($row->pct ?? 0.0);
            } catch (\Throwable $e) { return 0.0; }
        };

        // Build categories array
        $resultCategories = [];

        $processClassification = function($key, $displayName) use (&$resultCategories, $fetchUserCategoryBudget, $fetchOverridePct, $getAvgCommissionPct, $budgetId, $userId, $categoriesParticipation) {
            // sales for user by classification: prefer budget_user_category_totals
            $salesUsd = 0.0;
            $salesCop = 0.0;
            try {
                if ($key === AdvisorController::DIAMANTES_KEY) {
                    // diamantes: provider L'ARTIST and classification 15
                    $q = DB::connection('budget')->table('sales')
                        ->join('products','sales.product_id','=','products.id')
                        ->where('sales.seller_id', $userId)
                        ->where('products.provider_name', "L'ARTIST")
                        ->where(DB::raw('CAST(products.classification AS CHAR)'), '15');
                    if ($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id')) $q->where('sales.budget_id', $budgetId);
                    else {
                        [$start,$end] = $this->resolveBudgetRange($budgetId);
                        $q->whereBetween('sales.sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
                    }
                    $salesUsd = (float)$q->sum(DB::raw('COALESCE(sales.value_usd,0)'));
                    $salesCop = (float)$q->sum(DB::raw('COALESCE(sales.amount_cop,0)'));
                } else {
                    if (Schema::connection('budget')->hasTable('budget_user_category_totals')) {
                        $q = DB::connection('budget')->table('budget_user_category_totals')
                            ->where('user_id', $userId)
                            ->where(DB::raw('CAST(category_group AS CHAR)'), (string)$key);
                        if ($budgetId) $q->where('budget_id', $budgetId);
                        $salesUsd = (float)$q->sum('sales_usd');
                        $salesCop = (float)$q->sum('sales_cop');
                    } else {
                        // fallback: sum sales join products
                        $q = DB::connection('budget')->table('sales')
                            ->join('products','sales.product_id','=','products.id')
                            ->where('sales.seller_id', $userId)
                            ->where(DB::raw('CAST(products.classification AS CHAR)'), (string)$key);
                        if ($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id')) $q->where('sales.budget_id', $budgetId);
                        else {
                            [$start,$end] = $this->resolveBudgetRange($budgetId);
                            $q->whereBetween('sales.sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
                        }
                        $salesUsd = (float)$q->sum(DB::raw('COALESCE(sales.value_usd,0)'));
                        $salesCop = (float)$q->sum(DB::raw('COALESCE(sales.amount_cop,0)'));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('processClassification sales sum error: '.$e->getMessage());
            }

            // category budget saved (user-specific) or 0
            $categoryBudgetUsd = $fetchUserCategoryBudget((string)$key);

            // special handling: if key is 15 (jewerly) and diamantes exists, subtract diamantes budget if needed
            if ($key === '15' && $categoryBudgetUsd <= 0) {
                // compute default parent budget (as other code)
                $partsSum = array_sum(array_values($categoriesParticipation));
                if ($partsSum > 0) {
                    $catPart = ($categoriesParticipation[$key] ?? 0.0);
                    $categoryBudgetUsd = round($userBudgetUsd * ($catPart / max(0.0001, $partsSum)), 2);
                } else {
                    $count = 1;
                    $categoryBudgetUsd = round($userBudgetUsd / max(1,$count), 2);
                }
                // if diamantes has sales, allocate portion to diamantes (the diamantes row will compute its budget similarly)
                // here we simply keep the parent value as computed; diamantes splitting is applied when diamantes row is added.
            }

            $pctOfBudget = $categoryBudgetUsd > 0 ? ($salesUsd / $categoryBudgetUsd) * 100 : 0.0;
            $overridePct = $fetchOverridePct((string)$key);
            $finalPct = !is_null($overridePct) ? $overridePct : $getAvgCommissionPct((string)$key);
            $commissionUsd = round($salesUsd * ($finalPct / 100.0), 2);

            $resultCategories[] = [
                'classification_code' => (string)$key,
                'classification_name' => (string)$displayName,
                'category' => (string)$displayName,
                'sales_sum_usd' => round($salesUsd,2),
                'sales_sum_cop' => round($salesCop,2),
                'category_budget_usd_for_user' => round($categoryBudgetUsd,2),
                'pct_user_of_category_budget' => round($pctOfBudget,4),
                'applied_commission_pct' => round($finalPct,4),
                'commission_sum_usd' => round($commissionUsd,2),
            ];
        };

        // iterate mont + parbel canonical lists
        foreach ($montClassifications as $k) {
            if ($k === self::DIAMANTES_KEY) {
                $name = 'Diamantes';
            } else {
                $name = DB::connection('budget')->table('categories')->where('classification_code', $k)->value('name') ?? (string)$k;
            }
            $processClassification($k, $name);
        }
        foreach ($parbelClassifications as $k) {
            $name = $k === self::FRAG_KEY ? 'Fragancias' : (DB::connection('budget')->table('categories')->where('classification_code', $k)->value('name') ?? (string)$k);
            $processClassification($k, $name);
        }

        // Sales detail rows (per sale)
        $saleRows = [];
        try {
            $salesQ = DB::connection('budget')
                ->table('sales')
                ->leftJoin('products','sales.product_id','=','products.id')
                ->where('sales.seller_id', $userId)
                ->selectRaw('sales.id, sales.sale_date, sales.folio, COALESCE(products.name, sales.product_desc, \'\') as product, COALESCE(sales.amount_cop,0) as amount_cop, COALESCE(sales.value_usd,0) as value_usd, products.provider_name as provider, products.brand as brand, products.classification as classification, COALESCE(sales.commission_amount, NULL) as commission_amount, COALESCE(sales.exchange_rate, NULL) as exchange_rate');

            if ($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id')) $salesQ->where('sales.budget_id', $budgetId);
            else {
                [$start,$end] = $this->resolveBudgetRange($budgetId);
                $salesQ->whereBetween('sales.sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
            }

            $saleRows = $salesQ->orderByDesc('sales.sale_date')->get()->map(function($s) {
                return [
                    'id' => $s->id,
                    'sale_date' => (string)$s->sale_date,
                    'folio' => (string)($s->folio ?? ''),
                    'product' => (string)($s->product ?? ''),
                    'amount_cop' => (float)($s->amount_cop ?? 0),
                    'value_usd' => (float)($s->value_usd ?? 0),
                    'exchange_rate' => $s->exchange_rate ?? null,
                    'provider' => $s->provider ?? null,
                    'brand' => $s->brand ?? null,
                    'category_code' => (string)($s->classification ?? ''),
                    'commission_amount' => is_null($s->commission_amount) ? null : (float)$s->commission_amount,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::warning('buildMyCommissionsPayload sales detail error: '.$e->getMessage());
            $saleRows = [];
        }

        // Tickets & summary & days worked (best effort)
        $tickets = [];
        $ticketsSummary = null;
        $daysWorkedArr = [];
        try {
            if (Schema::connection('budget')->hasTable('tickets')) {
                $tq = DB::connection('budget')->table('tickets')->where('seller_id', $userId);
                if ($budgetId) $tq->where('budget_id', $budgetId);
                $tickets = $tq->get()->map(function($t){
                    return [
                        'folio' => $t->folio ?? '',
                        'ticket_usd' => (float)($t->ticket_usd ?? 0),
                        'ticket_cop' => (float)($t->ticket_cop ?? 0),
                        'avg_units_per_ticket' => (float)($t->avg_units_per_ticket ?? 0),
                        'lines_count' => (int)($t->lines_count ?? 0),
                        'units_count' => (int)($t->units_count ?? 0),
                        'sale_date' => (string)($t->sale_date ?? ''),
                    ];
                })->toArray();

                $ticketsSummary = [
                    'tickets_count' => count($tickets),
                    'avg_ticket_usd' => $tickets ? (array_sum(array_column($tickets,'ticket_usd')) / max(1, count($tickets))) : 0,
                    'avg_units_per_ticket' => $tickets ? (array_sum(array_column($tickets,'avg_units_per_ticket')) / max(1, count($tickets))) : 0,
                ];
            }

            if (Schema::connection('budget')->hasTable('sales')) {
                $dw = DB::connection('budget')->table('sales')
                    ->where('seller_id', $userId)
                    ->when($budgetId && Schema::connection('budget')->hasColumn('sales','budget_id'), fn($q)=> $q->where('budget_id', $budgetId))
                    ->selectRaw('DATE(sale_date) as date, COUNT(DISTINCT folio) as tickets_count, SUM(COALESCE(value_usd,0)) as sales_usd, SUM(COALESCE(amount_cop,0)) as sales_cop, SUM(COALESCE(lines_count,0)) as lines_count')
                    ->groupBy(DB::raw('DATE(sale_date)'))
                    ->orderByDesc(DB::raw('DATE(sale_date)'))
                    ->get();
                foreach ($dw as $d) $daysWorkedArr[] = ['date' => (string)$d->date, 'tickets_count' => (int)$d->tickets_count, 'sales_usd' => (float)$d->sales_usd, 'sales_cop' => (float)$d->sales_cop, 'lines_count' => (int)($d->lines_count ?? 0)];
            }
        } catch (\Throwable $e) {
            Log::warning('buildMyCommissionsPayload tickets/days error: '.$e->getMessage());
        }

        // assigned turns (optional)
        $assignedTurns = 0;
        try {
            if (Schema::connection('budget')->hasTable('assigned_turns')) {
                $assignedTurns = (int) DB::connection('budget')->table('assigned_turns')->where('user_id', $userId)->when($budgetId, fn($q)=> $q->where('budget_id', $budgetId))->count();
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Totals
        $totalsObj = [
            'total_sales_usd' => round(array_sum(array_column($resultCategories, 'sales_sum_usd')), 2),
            'total_commission_usd' => round(array_sum(array_column($resultCategories, 'commission_sum_usd')), 2),
            'total_commission_cop' => round((array_sum(array_column($resultCategories, 'commission_sum_usd')) * ($this->getAvgTrm() ?: 1)), 2),
            'avg_trm' => $this->getAvgTrm()
        ];

        // User object (from budget connection users table if present)
        $userObj = null;
        try {
            $userObj = DB::connection('budget')->table('users')->select('id','name','codigo_vendedor')->where('id', $userId)->first();
        } catch (\Throwable $e) { $userObj = null; }

        return [
            'categories' => $resultCategories,
            'sales' => $saleRows,
            'totals' => $totalsObj,
            'budget' => $budget ? (array)$budget : null,
            'user' => $userObj,
            'user_budget_usd' => round($userBudgetUsd,2),
            'tickets' => $tickets,
            'tickets_summary' => $ticketsSummary,
            'days_worked' => $daysWorkedArr,
            'assigned_turns_for_user' => $assignedTurns
        ];
    }

    /**
     * Build CSV from payload (simple implementation).
     */
    private function buildCsvFromCommissionsPayload(array $payload): string
    {
        $lines = [];
        $lines[] = "Categoría,PPTO_USD,Ventas_USD,%Cumpl,Comisión_USD";
        foreach ($payload['categories'] ?? [] as $c) {
            $lines[] = sprintf(
                '"%s",%s,%s,%s,%s',
                str_replace('"','""', $c['classification_name'] ?? $c['classification_code']),
                number_format($c['category_budget_usd_for_user'] ?? 0,2,'.',''),
                number_format($c['sales_sum_usd'] ?? 0,2,'.',''),
                number_format($c['pct_user_of_category_budget'] ?? 0,2,'.',''),
                number_format($c['commission_sum_usd'] ?? 0,2,'.','')
            );
        }
        $lines[] = "";
        $lines[] = "Detalle ventas";
        $lines[] = "fecha,folio,producto,proveedor,marca,usd,comision_cop";
        foreach ($payload['sales'] ?? [] as $s) {
            $lines[] = sprintf('"%s","%s","%s","%s","%s",%s,%s',
                $s['sale_date'] ?? '', $s['folio'] ?? '', str_replace('"','""',$s['product'] ?? ''), $s['provider'] ?? '', $s['brand'] ?? '', number_format($s['value_usd'] ?? 0,2,'.',''), is_null($s['commission_amount']) ? '' : number_format($s['commission_amount'],2,'.','')
            );
        }
        return implode("\n", $lines);
    }

    /**
     * Helper: get a sensible TRM (avg). Intenta tabla trm en budget, fallback a valor fijo.
     */
    private function getAvgTrm(): float
    {
        try {
            if (Schema::connection('budget')->hasTable('trm')) {
                $tr = DB::connection('budget')->table('trm')->orderByDesc('date')->value('value');
                if ($tr) return (float)$tr;
            }
        } catch (\Throwable $e) { }
        return 4200.0;
    }
    /* ============================
   REPORTS: cajeros / especialista
   ============================ */

public function specialistCheck(Request $request)
{
    $authUser = auth()->user();

    // 1️⃣ Obtener seller_code del usuario logueado (base principal)
    $sellerCode = $authUser->seller_code;

    if (!$sellerCode) {
        return response()->json([
            'is_specialist' => false
        ]);
    }

    // 2️⃣ Buscar el usuario REAL en la base budget
    $budgetUser = DB::connection('budget')
        ->table('users')
        ->where('codigo_vendedor', $sellerCode)
        ->first();

    if (!$budgetUser) {
        return response()->json([
            'is_specialist' => false
        ]);
    }

    // 3️⃣ Buscar si ese user_id está en advisor_specialists (también en budget)
    $specialist = DB::connection('budget')
        ->table('advisor_specialists')
        ->where('user_id', $budgetUser->id)
        ->latest()
        ->first();

    return response()->json([
        'is_specialist' => $specialist ? true : false,
        'specialist_row' => $specialist,
        'business_line' => $specialist->business_line ?? null,
    ]);
}

/**
 * GET /advisors/cashier-awards
 * Aggrega ventas por vendedor (cajero) para el presupuesto.
 * Estructura compatible con tu componente CommisionCashierUsers:
 *  - rows[] { user_id, name/nombre, ventas_usd, pct, premiacion, meta (opcional), pdv, note }
 *  - total_ventas, prize_at_120, prize_applied, cumplimiento, period
 */
public function cashierAwards(Request $r)
{
    $budgetId = $r->query('budget_id') ? (int)$r->query('budget_id') : null;

    // rango fallback
    [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);

    // total ventas (budget scope)
    $salesQ = DB::connection('budget')->table('sales')
        ->selectRaw('COALESCE(SUM(sales.value_usd),0) as total_usd');

    if (Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId) {
        $salesQ->where('sales.budget_id', $budgetId);
    } else {
        $salesQ->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
    }
    $totalVentas = (float)($salesQ->value('total_usd') ?? 0);

    // prize pool: intenta leer campo en budgets, fallback 0
    $budgetRow = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
    $prizePool = 0.0;
    if ($budgetRow) {
        $prizePool = (float)($budgetRow->cashier_prize ?? $budgetRow->prize_pool ?? 0.0);
    }

    // obtener ventas por vendedor
    $perSellerQ = DB::connection('budget')->table('sales')
        ->join('users','sales.seller_id','=','users.id')
        ->selectRaw('sales.seller_id as user_id, users.name as nombre, COALESCE(SUM(sales.value_usd),0) as ventas_usd')
        ->groupBy('sales.seller_id','users.name');

    if (Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId) {
        $perSellerQ->where('sales.budget_id', $budgetId);
    } else {
        $perSellerQ->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
    }

    $sellers = $perSellerQ->orderByDesc('ventas_usd')->get()->map(function($r) use ($totalVentas, $prizePool) {
        $ventas = (float)$r->ventas_usd;
        $pct = $totalVentas > 0 ? ($ventas / $totalVentas) * 100 : 0;
        $prem = $totalVentas > 0 ? round($prizePool * ($ventas / $totalVentas), 2) : 0.0;
        return [
            'user_id' => (int)$r->user_id,
            'nombre' => $r->nombre,
            'ventas_usd' => round($ventas,2),
            'pct' => round($pct,4),
            'premiacion' => round($prem,2),
            // campos opcionales que tu frontend muestra
            'meta' => null,
            'pdv' => null,
            'note' => null
        ];
    })->toArray();

    // Totales / periodo
    $period = $budgetRow ? ['start_date' => $budgetRow->start_date ?? null, 'end_date' => $budgetRow->end_date ?? null] : null;
    $prizeApplied = $prizePool; // actualmente el pool completo — si tienes topes adicionales, cámbialo aquí.
    $cumplimiento = 0; // si quieres un % general, define la fórmula (p.e. ventas/target). Lo dejo 0 por defecto.

    return response()->json([
        'rows' => $sellers,
        'total_ventas' => round($totalVentas,2),
        'prize_at_120' => round($prizePool,2),
        'prize_applied' => round($prizeApplied,2),
        'cumplimiento' => $cumplimiento,
        'period' => $period
    ]);
}

/**
 * GET /advisors/cashier/{userId}/categories
 * Detalle por categoría para el cajero (id => seller_id)
 * Devuelve { categories: [{ classification, sales_usd, sales_cop, pct_of_total }], summary: { total_sales_usd, tickets_count, total_sales_cop }, cashier: {id,name} }
 */
public function cashierCategories(Request $r, $userId)
{
    $userId = (int)$userId;
    $budgetId = $r->query('budget_id') ? (int)$r->query('budget_id') : null;
    [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);

    // obtener total ventas del cajero en el periodo (para pct)
    $totalQ = DB::connection('budget')->table('sales')->where('seller_id', $userId);
    if (Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId) $totalQ->where('budget_id', $budgetId);
    else $totalQ->whereBetween('sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
    $totalSalesUsd = (float)$totalQ->sum(DB::raw('COALESCE(value_usd,0)'));

    // agrupado por clasificación (prefer products.classification)
    $catQ = DB::connection('budget')
        ->table('sales')
        ->leftJoin('products','sales.product_id','=','products.id')
        ->selectRaw("COALESCE(CAST(products.classification AS CHAR), TRIM(COALESCE(products.classification_desc, 'sin_categoria'))) as classification, COALESCE(SUM(sales.value_usd),0) as sales_usd, COALESCE(SUM(sales.amount_cop),0) as sales_cop")
        ->where('sales.seller_id', $userId)
        ->groupBy(DB::raw('classification'));

    if (Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId) $catQ->where('sales.budget_id', $budgetId);
    else $catQ->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);

    $cats = $catQ->get()->map(function($c) use ($totalSalesUsd) {
        $sUsd = (float)$c->sales_usd;
        $pct = $totalSalesUsd > 0 ? ($sUsd / $totalSalesUsd) * 100 : 0;
        return [
            'classification' => (string)($c->classification ?? 'sin_categoria'),
            'sales_usd' => round($sUsd,2),
            'sales_cop' => round((float)$c->sales_cop,2),
            'pct_of_total' => round($pct,4)
        ];
    })->toArray();

    // resumen: tickets_count (distinct folio), totals
    $ticketsQ = DB::connection('budget')->table('sales')->where('seller_id', $userId);
    if (Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId) $ticketsQ->where('budget_id', $budgetId);
    else $ticketsQ->whereBetween('sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
    $ticketsCount = (int)$ticketsQ->distinct('folio')->count('folio');

    $cashier = DB::connection('budget')->table('users')->select('id','name')->where('id', $userId)->first();

    return response()->json([
        'categories' => $cats,
        'summary' => [
            'total_sales_usd' => round($totalSalesUsd,2),
            'tickets_count' => $ticketsCount,
            'total_sales_cop' => round((float)$ticketsQ->sum(DB::raw('COALESCE(amount_cop,0)')),2)
        ],
        'cashier' => $cashier ? (array)$cashier : ['id'=>$userId,'name'=>null]
    ]);
}
}