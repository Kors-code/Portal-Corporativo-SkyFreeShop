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
    private const FRAG_CODES = [10, 11, 12];
    private const FRAG_KEY = 'fragancias';
    private const DIAMANTES_KEY = 'diamantes';
    private const DEFAULT_MONT_NAMES = ['gifts', 'watches', 'jewerly', 'sunglasses', 'electronics'];
    private const DEFAULT_MONT_KEYS = ['19','14','15','16','21'];
    private const DEFAULT_PARBEL_KEYS = ['13', self::FRAG_KEY];
    private const ADVISOR_CATEGORY_ID = 15;

    private function applyAdvisorRoleForSaleDate($query, string $userColumn, string $saleDateColumn)
    {
        return $query
            ->whereExists(function ($q) use ($userColumn, $saleDateColumn) {
                $q->selectRaw('1')
                    ->from('user_roles as ur')
                    ->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->whereColumn('ur.user_id', $userColumn)
                    ->where(function ($roleQ) {
                        $roleQ->whereRaw('LOWER(r.name) = ?', ['vendedor'])
                            ->orWhereRaw('LOWER(r.name) LIKE ?', ['asesor%']);
                    })
                    ->whereColumn('ur.start_date', '<=', $saleDateColumn)
                    ->where(function ($dateQ) use ($saleDateColumn) {
                        $dateQ->whereNull('ur.end_date')
                            ->orWhereColumn('ur.end_date', '>=', $saleDateColumn);
                    });
            })
            ->whereNotExists(function ($q) use ($userColumn, $saleDateColumn) {
                $q->selectRaw('1')
                    ->from('user_roles as ur')
                    ->join('roles as r', 'r.id', '=', 'ur.role_id')
                    ->whereColumn('ur.user_id', $userColumn)
                    ->whereRaw('LOWER(r.name) = ?', ['cajero'])
                    ->whereColumn('ur.start_date', '<=', $saleDateColumn)
                    ->where(function ($dateQ) use ($saleDateColumn) {
                        $dateQ->whereNull('ur.end_date')
                            ->orWhereColumn('ur.end_date', '>=', $saleDateColumn);
                    });
            });
    }

    public function budgetSellers(Request $request)
    {
        $budgetId = (int) $request->query('budget_id');

        if (!$budgetId) {
            return response()->json([], 200);
        }

        $hasBudgetId = Schema::connection('budget')->hasColumn('sales', 'budget_id');
        [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);

        $query = DB::connection('budget')
            ->table('users')
            ->join('user_roles as ur', 'ur.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('sales', function ($join) use ($budgetId, $hasBudgetId, $startDate, $endDate) {
                $join->on('sales.seller_id', '=', 'users.id');
                if ($hasBudgetId) {
                    $join->where('sales.budget_id', '=', $budgetId);
                } else {
                    $join->whereBetween('sales.sale_date', [
                        $startDate->toDateTimeString(),
                        $endDate->toDateTimeString()
                    ]);
                }
            })
            ->select(
                'users.id',
                'users.name',
                'users.codigo_vendedor',
                DB::raw('SUM(COALESCE(sales.value_usd,0)) as total_usd')
            )
            ->where(function ($roleQ) {
                $roleQ->whereRaw('LOWER(r.name) = ?', ['vendedor'])
                    ->orWhereRaw('LOWER(r.name) LIKE ?', ['asesor%']);
            })
            ->where(function ($dateQ) use ($endDate) {
                $dateQ->whereNull('ur.start_date')
                    ->orWhere('ur.start_date', '<=', $endDate->toDateString());
            })
            ->where(function ($dateQ) use ($startDate) {
                $dateQ->whereNull('ur.end_date')
                    ->orWhere('ur.end_date', '>=', $startDate->toDateString());
            })
            ->whereNotExists(function ($q) use ($startDate, $endDate) {
                $q->selectRaw('1')
                    ->from('user_roles as cashier_ur')
                    ->join('roles as cashier_r', 'cashier_r.id', '=', 'cashier_ur.role_id')
                    ->whereColumn('cashier_ur.user_id', 'users.id')
                    ->whereRaw('LOWER(cashier_r.name) = ?', ['cajero'])
                    ->where(function ($dateQ) use ($endDate) {
                        $dateQ->whereNull('cashier_ur.start_date')
                            ->orWhere('cashier_ur.start_date', '<=', $endDate->toDateString());
                    })
                    ->where(function ($dateQ) use ($startDate) {
                        $dateQ->whereNull('cashier_ur.end_date')
                            ->orWhere('cashier_ur.end_date', '>=', $startDate->toDateString());
                    });
            })
            ->groupBy('users.id','users.name','users.codigo_vendedor');

        $rows = $query->orderBy('users.name')->get();

        return response()->json($rows);
    }

    public function getBudgetSellers(Request $request)
    {
        $budgetId = $request->query('budget_id') ? (int)$request->query('budget_id') : null;
        $onlyWithSales = filter_var($request->query('only_with_sales', false), FILTER_VALIDATE_BOOLEAN);

        [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);
        $hasBudgetIdColumn = Schema::connection('budget')->hasColumn('sales', 'budget_id');

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

        if ($budgetId && $hasBudgetIdColumn) {
            $q->where(function($qq) use ($budgetId) {
                $qq->where('sales.budget_id', $budgetId)
                   ->orWhereNull('sales.budget_id');
            });
        } else {
            $q->where(function($qq) use ($startDate, $endDate) {
                $qq->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
                   ->orWhereNull('sales.sale_date');
            });
        }

        $this->applyAdvisorRoleForSaleDate($q, 'users.id', 'sales.sale_date');

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

        if (is_numeric($raw) && in_array((int)$raw, self::FRAG_CODES, true)) {
            return self::FRAG_KEY;
        }

        $low = mb_strtolower($raw);

        if (strpos($low, 'frag') !== false || strpos($low, 'perf') !== false) {
            return self::FRAG_KEY;
        }

        return preg_replace('/\s+/', ' ', $low);
    }

    private function resolveBudgetRange($budgetId = null)
    {
        $q = DB::connection('budget')->table('budgets')->select('start_date', 'end_date');
        if ($budgetId) $q->where('id', $budgetId);

        $budgets = $q->get();

        if ($budgets->isEmpty()) {
            return [Carbon::now()->subYears(5)->startOfDay(), Carbon::now()->endOfDay()];
        }

        $minStart = null;
        $maxEnd = null;
        foreach ($budgets as $b) {
            if (!empty($b->start_date)) {
                try {
                    $dt = Carbon::parse($b->start_date);
                    if ($minStart === null || $dt->lessThan($minStart)) $minStart = $dt;
                } catch (\Throwable $e) {}
            }
            if (!empty($b->end_date)) {
                try {
                    $dt2 = Carbon::parse($b->end_date);
                    if ($maxEnd === null || $dt2->greaterThan($maxEnd)) $maxEnd = $dt2;
                } catch (\Throwable $e) {}
            }
        }

        if ($minStart === null) $minStart = Carbon::now()->subYears(5)->startOfDay();
        if ($maxEnd === null) $maxEnd = Carbon::now()->endOfDay();

        return [$minStart->startOfDay(), $maxEnd->endOfDay()];
    }

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

        $commissionRow = DB::connection('budget')
            ->table('category_commissions')
            ->where('category_id', self::ADVISOR_CATEGORY_ID)
            ->where('budget_id', $budgetId)
            ->when($roleId, fn($q) => $q->where('role_id', $roleId))
            ->selectRaw('AVG(COALESCE(participation_pct,0)) as participation_pct')
            ->first();
        $advisorPct = $commissionRow ? (float)($commissionRow->participation_pct ?? 0) : 0.0;
        $advisorPoolUsd = round($budgetTotal * ($advisorPct / 100), 2);

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
        ]);
    }

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

        return response()->json(['rows' => $savedRows->values()]);
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
        $businessLine = $data['business_line'] ?? 'montblanc';

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
        $includeInherited = filter_var($request->query('include_inherited', false), FILTER_VALIDATE_BOOLEAN);

        $query = DB::connection('budget')
            ->table('advisor_specialists as asp')
            ->leftJoin('users as u', 'u.id', '=', 'asp.user_id')
            ->where('asp.budget_id', $budgetId)
            ->select('asp.*', 'u.name as user_name');
        if ($line) $query->where('asp.business_line', $line);

        $rows = $query->orderByDesc('asp.valid_from')->get();

        if (!$includeInherited) {
            return response()->json($rows);
        }

        $active = $rows->first(fn ($row) => empty($row->valid_to));
        $inherited = null;

        if (!$active && $budgetId && $line) {
            $currentBudget = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();

            if ($currentBudget) {
                $inherited = DB::connection('budget')
                    ->table('advisor_specialists as asp')
                    ->join('budgets as b', 'b.id', '=', 'asp.budget_id')
                    ->leftJoin('users as u', 'u.id', '=', 'asp.user_id')
                    ->where('asp.business_line', $line)
                    ->where('b.start_date', '<', $currentBudget->start_date)
                    ->select('asp.*', 'u.name as user_name', 'b.name as budget_name', 'b.start_date as budget_start_date')
                    ->orderByDesc('b.start_date')
                    ->orderByDesc('asp.valid_from')
                    ->first();
            }
        }

        return response()->json([
            'rows' => $rows,
            'inherited' => $inherited,
        ]);
    }

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
     * NUEVA LÓGICA:
     *  - Total de la línea  -> advisor_budgets.budget_usd (por budget + role)
     *  - Budget por categoría -> total_línea * (category_commissions.participation_pct / 100)
     *  - % comisión          -> tier de category_commissions según cumplimiento (80/100/120)
     *  - Override            -> advisor_category_overrides.applied_commission_pct (pisa el tier)
     *  - Diamantes           -> separa de Jewelry (15) proporcional a ventas L'ARTIST
     *  - Fragancias          -> agrega codes 10/11/12 bajo FRAG_KEY (suma participation, promedia tiers)
     */
    public function activeSpecialistsSales(Request $request)
    {
        $data = $request->validate([
            'budget_id' => 'nullable|integer',
            'business_line' => 'nullable|string|in:montblanc,parbel',
            'user_id' => 'nullable|integer',
        ]);

        $budgetId = isset($data['budget_id']) ? (int) $data['budget_id'] : null;
        $businessLine = $data['business_line'] ?? null;
        $forcedUserId = isset($data['user_id']) ? (int) $data['user_id'] : null;

        if (!$budgetId) {
            return $this->emptyActiveResponse(null, $businessLine, null, 'budget_id es requerido.');
        }

        $roleId = null;
        if ($businessLine === 'parbel')    $roleId = 4;
        if ($businessLine === 'montblanc') $roleId = 5;

        // Especialista activo
        $specialist = null;
        if (!$forcedUserId) {
            $specQ = DB::connection('budget')->table('advisor_specialists')->whereNull('valid_to');
            if ($budgetId)     $specQ->where('budget_id', $budgetId);
            if ($businessLine) $specQ->where('business_line', $businessLine);
            $specialist = $specQ->first();
        } else {
            $specialist = DB::connection('budget')->table('advisor_specialists')
                ->where('user_id', $forcedUserId)
                ->when($budgetId, fn ($q) => $q->where('budget_id', $budgetId))
                ->when($businessLine, fn ($q) => $q->where('business_line', $businessLine))
                ->orderByDesc('valid_from')
                ->first();
        }

        $userId = $forcedUserId ?: ($specialist->user_id ?? null);

        if (!$userId) {
            return $this->emptyActiveResponse($specialist, $businessLine, $budgetId, 'No se encontró especialista activo (ni user_id provisto).');
        }

        $user = DB::connection('budget')->table('users')
            ->select('id', 'name', 'codigo_vendedor')->where('id', $userId)->first();
        $avgTrmForUser = $this->computeAvgTrmForUser($budgetId, $userId);
        // 1) Presupuesto total de la línea (advisor_budgets)
        $lineBudgetUsd = 0.0;
        if ($roleId) {
            $lineBudgetUsd = (float) (DB::connection('budget')->table('advisor_budgets')
                ->where('budget_id', $budgetId)
                ->where('role_id', $roleId)
                ->value('budget_usd') ?? 0);
        }

        // 2) Cargar participation + tiers por categoría desde category_commissions
        $byClass = $this->loadLineCategoryConfig($budgetId, $roleId, $businessLine);

        // Helper override
        $fetchOverridePct = function ($classification) use ($budgetId, $userId) {
            $val = DB::connection('budget')->table('advisor_category_overrides')
                ->where('budget_id', $budgetId)
                ->where('user_id', $userId)
                ->where('classification_code', (string) $classification)
                ->value('applied_commission_pct');
            return is_null($val) ? null : (float) $val;
        };

        $rows = [];
        $totals = ['sales_usd' => 0.0, 'sales_cop' => 0.0];

        if ($businessLine === 'montblanc') {
    // Detectar si ya hay una categoría real "Diamantes" en category_commissions
    $realDiamantesCode = null;
    foreach ($byClass as $code => $info) {
        if (mb_strtolower(trim($info['name'] ?? '')) === 'diamantes') {
            $realDiamantesCode = (string) $code;
            break;
        }
    }

    // Ventas por classification_code desde budget_user_category_totals
    $montKeys = array_values(array_filter(
        array_keys($byClass),
        fn ($k) => is_numeric($k)
    ));

    $salesByClass = [];
    if (!empty($montKeys)) {
        $sQ = DB::connection('budget')
            ->table('budget_user_category_totals')
            ->selectRaw('category_group AS classification, COALESCE(SUM(sales_usd),0) as sales_usd, COALESCE(SUM(sales_cop),0) as sales_cop')
            ->where('user_id', $userId)
            ->where('budget_id', $budgetId)
            ->whereIn(DB::raw('CAST(category_group AS CHAR)'), $montKeys)
            ->groupBy('category_group')
            ->get();
        foreach ($sQ as $r) {
            $salesByClass[(string) $r->classification] = [
                'sales_usd' => (float) $r->sales_usd,
                'sales_cop' => (float) $r->sales_cop,
            ];
        }
    }

    // Ventas L'ARTIST + classification 15 (lo que históricamente eran "diamantes")
    $diamSales = $this->fetchDiamantesSales($budgetId, $userId);

    if ($realDiamantesCode) {
        // Hay categoría real -> asignar las ventas allí y restar del jewelry agregado
        if (isset($salesByClass['15'])) {
            $salesByClass['15']['sales_usd'] = max(0, $salesByClass['15']['sales_usd'] - $diamSales['sales_usd']);
            $salesByClass['15']['sales_cop'] = max(0, $salesByClass['15']['sales_cop'] - $diamSales['sales_cop']);
        }
        // Si la categoría real ya tenía ventas propias por su classification_code, las sumamos
        $existing = $salesByClass[$realDiamantesCode] ?? ['sales_usd' => 0, 'sales_cop' => 0];
        $salesByClass[$realDiamantesCode] = [
            'sales_usd' => $existing['sales_usd'] + $diamSales['sales_usd'],
            'sales_cop' => $existing['sales_cop'] + $diamSales['sales_cop'],
        ];
        // NO se crea DIAMANTES_KEY sintético
    } else {
        // Comportamiento original: crear key sintético separando de Jewelry
        if (isset($salesByClass['15'])) {
            $salesByClass['15']['sales_usd'] = max(0, $salesByClass['15']['sales_usd'] - $diamSales['sales_usd']);
            $salesByClass['15']['sales_cop'] = max(0, $salesByClass['15']['sales_cop'] - $diamSales['sales_cop']);
        }
        $salesByClass[self::DIAMANTES_KEY] = $diamSales;

        $jewSalesUsd  = $salesByClass['15']['sales_usd'] ?? 0;
        $diamSalesUsd = $diamSales['sales_usd'] ?? 0;
        $totalJewSales = $jewSalesUsd + $diamSalesUsd;

        if ($totalJewSales > 0 && isset($byClass['15'])) {
            $jewPart  = $byClass['15']['participation_pct'];
            $diamPart = $jewPart * ($diamSalesUsd / $totalJewSales);

            if (!isset($byClass[self::DIAMANTES_KEY])) {
                $byClass[self::DIAMANTES_KEY] = [
                    'classification_code' => self::DIAMANTES_KEY,
                    'name' => 'Diamantes',
                    'participation_pct' => 0,
                    'pct_80'  => $byClass['15']['pct_80']  ?? 0,
                    'pct_100' => $byClass['15']['pct_100'] ?? 0,
                    'pct_120' => $byClass['15']['pct_120'] ?? 0,
                ];
            }

            $byClass[self::DIAMANTES_KEY]['participation_pct'] = $diamPart;
            $byClass['15']['participation_pct'] = max(0, $jewPart - $diamPart);
        }
    }

    foreach ($byClass as $code => $info) {
        $rows[] = $this->buildBreakdownRow(
            $code,
            $info,
            $salesByClass[$code] ?? ['sales_usd' => 0, 'sales_cop' => 0],
            $lineBudgetUsd,
            $fetchOverridePct((string)$code),
            $avgTrmForUser
        );
        $totals['sales_usd'] += $rows[count($rows)-1]['sales_usd'];
        $totals['sales_cop'] += $rows[count($rows)-1]['sales_cop'];
    }
} elseif ($businessLine === 'parbel') {
            $salesByClass = $this->fetchParbelSales($budgetId, $userId);

            foreach ($byClass as $code => $info) {
                $rows[] = $this->buildBreakdownRow(
                    $code,
                    $info,
                    $salesByClass[$code] ?? ['sales_usd' => 0, 'sales_cop' => 0],
                    $lineBudgetUsd,
                    $fetchOverridePct((string)$code)
                );
                $totals['sales_usd'] += $rows[count($rows)-1]['sales_usd'];
                $totals['sales_cop'] += $rows[count($rows)-1]['sales_cop'];
            }
        }

        $totals['sales_usd'] = round($totals['sales_usd'], 2);
        $totals['sales_cop'] = round($totals['sales_cop'], 2);

        $totalCommissionUsd = 0.0;
        $totalCommissionCop = 0.0;
        foreach ($rows as $r) {
            $totalCommissionUsd += (float) ($r['commission_usd'] ?? 0);
            if (!is_null($r['commission_cop'] ?? null)) {
                $totalCommissionCop += (float) $r['commission_cop'];
            }
        }

        $lineBudgetCop = $avgTrmForUser ? round($lineBudgetUsd * $avgTrmForUser, 2) : null;

        // Detalle real de ventas para el front
        $commissionController = new CommissionReportController();
        $detailRequest = Request::create('/', 'GET', ['budget_id' => $budgetId]);
        $detailRequest->setUserResolver(fn () => $request->user());
        $detailResponse = $commissionController->bySellerDetail($detailRequest, $userId);
        $detailData = $detailResponse->getData(true);

        return response()->json([
            'count' => count($rows),
            'specialist_user_id' => $userId,
            'specialist' => $user,
            'specialist_name' => $user->name ?? null,
            'business_line' => $businessLine,
            'budget_id' => $budgetId,
            'totals' => array_merge($totals, [
                'commission_usd' => round($totalCommissionUsd, 2),
                'commission_cop' => round($totalCommissionCop, 2),
                'avg_trm' => $avgTrmForUser,
            ]),
            'user_budget_usd' => round($lineBudgetUsd, 2),
            'user_budget_cop' => $lineBudgetCop,
            'avg_trm' => $avgTrmForUser,
            'breakdown' => $rows,
            'sales' => $detailData['sales'] ?? [],
            'detail_totals' => $detailData['totals'] ?? null,
            'tickets' => $detailData['tickets'] ?? [],
            'tickets_summary' => $detailData['tickets_summary'] ?? null,
            'days_worked' => $detailData['days_worked'] ?? [],
            'assigned_turns_for_user' => $detailData['assigned_turns_for_user'] ?? 0,
        ]);
    }

    /**
     * Carga participation_pct + tiers (80/100/120) por classification_code para budget+role.
     * Para Parbel agrupa 10/11/12 bajo FRAG_KEY (suma participation, promedia tiers).
     */
    private function loadLineCategoryConfig(int $budgetId, ?int $roleId, ?string $businessLine): array
    {
        if (!$roleId) return [];

        $rows = DB::connection('budget')
            ->table('category_commissions')
            ->join('categories', 'categories.id', '=', 'category_commissions.category_id')
            ->where('category_commissions.budget_id', $budgetId)
            ->where('category_commissions.role_id', $roleId)
            ->select(
                'categories.classification_code',
                'categories.name as category_name',
                'category_commissions.participation_pct',
                'category_commissions.commission_percentage as pct_80',
                'category_commissions.commission_percentage100 as pct_100',
                'category_commissions.commission_percentage120 as pct_120'
            )
            ->get();

        $byClass = [];
        foreach ($rows as $r) {
            $code = (string) $r->classification_code;
            if (!isset($byClass[$code])) {
                $byClass[$code] = [
                    'classification_code' => $code,
                    'name' => $r->category_name,
                    'participation_pct' => 0.0,
                    'pct_80_acc' => [],
                    'pct_100_acc' => [],
                    'pct_120_acc' => [],
                ];
            }
            $byClass[$code]['participation_pct'] += (float) $r->participation_pct;
            $byClass[$code]['pct_80_acc'][]  = (float) $r->pct_80;
            $byClass[$code]['pct_100_acc'][] = (float) $r->pct_100;
            $byClass[$code]['pct_120_acc'][] = (float) $r->pct_120;
        }

        // Reducir acumuladores a promedios
        foreach ($byClass as &$c) {
            $c['pct_80']  = $c['pct_80_acc']  ? array_sum($c['pct_80_acc'])  / count($c['pct_80_acc'])  : 0.0;
            $c['pct_100'] = $c['pct_100_acc'] ? array_sum($c['pct_100_acc']) / count($c['pct_100_acc']) : 0.0;
            $c['pct_120'] = $c['pct_120_acc'] ? array_sum($c['pct_120_acc']) / count($c['pct_120_acc']) : 0.0;
            unset($c['pct_80_acc'], $c['pct_100_acc'], $c['pct_120_acc']);
        }
        unset($c);

        // Parbel: agrupar fragancias (10/11/12) bajo FRAG_KEY
        if ($businessLine === 'parbel') {
            $fragPart = 0.0;
            $fragP80 = []; $fragP100 = []; $fragP120 = [];
            foreach (self::FRAG_CODES as $fc) {
                $k = (string) $fc;
                if (isset($byClass[$k])) {
                    $fragPart   += $byClass[$k]['participation_pct'];
                    $fragP80[]   = $byClass[$k]['pct_80'];
                    $fragP100[]  = $byClass[$k]['pct_100'];
                    $fragP120[]  = $byClass[$k]['pct_120'];
                    unset($byClass[$k]);
                }
            }
            if ($fragPart > 0 || !empty($fragP80)) {
                $byClass[self::FRAG_KEY] = [
                    'classification_code' => self::FRAG_KEY,
                    'name' => 'Fragancias',
                    'participation_pct' => $fragPart,
                    'pct_80'  => $fragP80  ? array_sum($fragP80)  / count($fragP80)  : 0.0,
                    'pct_100' => $fragP100 ? array_sum($fragP100) / count($fragP100) : 0.0,
                    'pct_120' => $fragP120 ? array_sum($fragP120) / count($fragP120) : 0.0,
                ];
            }
        }

        return $byClass;
    }

    /**
 * Calcula la TRM promedio del usuario en el periodo del presupuesto,
 * usando los días reales en que tuvo ventas y la tabla `trms`.
 */
private function computeAvgTrmForUser(int $budgetId, int $userId): ?float
{
    $hasBudgetIdCol = Schema::connection('budget')->hasColumn('sales', 'budget_id');

    $datesQ = DB::connection('budget')->table('sales')
        ->where('seller_id', $userId)
        ->select('sale_date')
        ->distinct();

    if ($hasBudgetIdCol) {
        $datesQ->where('budget_id', $budgetId);
    } else {
        [$start, $end] = $this->resolveBudgetRange($budgetId);
        $datesQ->whereBetween('sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
    }

    $saleDates = $datesQ->pluck('sale_date')->unique()->values()->all();
    if (empty($saleDates)) {
        return null;
    }

    $trmRows = DB::connection('budget')->table('trms')
        ->select('date', DB::raw('AVG(value) as avg_value'))
        ->whereIn('date', $saleDates)
        ->groupBy('date')
        ->get();

    $values = [];
    foreach ($trmRows as $t) {
        $values[] = (float) $t->avg_value;
    }

    if (empty($values)) {
        return null;
    }

    return round(array_sum($values) / count($values), 2);
}

    private function fetchDiamantesSales(int $budgetId, int $userId): array
    {
        $q = DB::connection('budget')->table('sales')
            ->join('products', 'sales.product_id', '=', 'products.id')
            ->where('sales.seller_id', $userId)
            ->where('products.provider_name', "L'ARTIST")
            ->where(DB::raw('CAST(products.classification AS CHAR)'), '15');

        if (Schema::connection('budget')->hasColumn('sales', 'budget_id')) {
            $q->where('sales.budget_id', $budgetId);
        } else {
            [$start, $end] = $this->resolveBudgetRange($budgetId);
            $q->whereBetween('sales.sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
        }

        $row = $q->selectRaw('COALESCE(SUM(sales.value_usd),0) as sales_usd, COALESCE(SUM(sales.amount_cop),0) as sales_cop')->first();

        return [
            'sales_usd' => (float) ($row->sales_usd ?? 0),
            'sales_cop' => (float) ($row->sales_cop ?? 0),
        ];
    }

    /**
     * Devuelve ventas Parbel agrupadas por '13' (skin) y FRAG_KEY (fragancias).
     */
    private function fetchParbelSales(int $budgetId, int $userId): array
    {
        $result = [
            '13' => ['sales_usd' => 0.0, 'sales_cop' => 0.0],
            self::FRAG_KEY => ['sales_usd' => 0.0, 'sales_cop' => 0.0],
        ];

        $hasBudgetIdCol = Schema::connection('budget')->hasColumn('sales', 'budget_id');
        [$start, $end] = $this->resolveBudgetRange($budgetId);

        // Skin (clasificación 13 o nombre LIKE skin)
        $skinQ = DB::connection('budget')->table('sales')
            ->join('products', 'sales.product_id', '=', 'products.id')
            ->where('sales.seller_id', $userId)
            ->where(function ($q) {
                $q->whereRaw("LOWER(CAST(products.classification AS CHAR)) LIKE '%skin%'")
                  ->orWhereRaw("CAST(products.classification AS CHAR) = '13'");
            });
        if ($hasBudgetIdCol) {
            $skinQ->where('sales.budget_id', $budgetId);
        } else {
            $skinQ->whereBetween('sales.sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
        }
        $skinRow = $skinQ->selectRaw('COALESCE(SUM(sales.value_usd),0) as sales_usd, COALESCE(SUM(sales.amount_cop),0) as sales_cop')->first();
        $result['13']['sales_usd'] = (float) ($skinRow->sales_usd ?? 0);
        $result['13']['sales_cop'] = (float) ($skinRow->sales_cop ?? 0);

        // Frag (provider PARBEL y clasificación frag)
        $fragQ = DB::connection('budget')->table('sales')
            ->join('products', 'sales.product_id', '=', 'products.id')
            ->where('sales.seller_id', $userId)
            ->where('products.provider_name', 'PARBEL')
            ->where(function ($q) {
                $q->whereRaw("CAST(products.classification AS CHAR) REGEXP '^(?:10|11|12)$'")
                  ->orWhereRaw("LOWER(CAST(products.classification AS CHAR)) LIKE '%frag%'")
                  ->orWhereRaw("LOWER(CAST(products.classification AS CHAR)) LIKE '%perf%'");
            });
        if ($hasBudgetIdCol) {
            $fragQ->where('sales.budget_id', $budgetId);
        } else {
            $fragQ->whereBetween('sales.sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
        }
        $fragRow = $fragQ->selectRaw('COALESCE(SUM(sales.value_usd),0) as sales_usd, COALESCE(SUM(sales.amount_cop),0) as sales_cop')->first();
        $result[self::FRAG_KEY]['sales_usd'] = (float) ($fragRow->sales_usd ?? 0);
        $result[self::FRAG_KEY]['sales_cop'] = (float) ($fragRow->sales_cop ?? 0);

        return $result;
    }

    /**
     * Construye una fila del breakdown.
     */
    private function buildBreakdownRow($code, array $info, array $sales, float $lineBudgetUsd, ?float $overridePct, ?float $trm = null): array
{
    $sUsd = round((float)($sales['sales_usd'] ?? 0), 2);
    $sCop = round((float)($sales['sales_cop'] ?? 0), 2);

    $participation = (float) ($info['participation_pct'] ?? 0);
    $catBudgetUsd = round($lineBudgetUsd * ($participation / 100.0), 2);
    $catBudgetCop = $trm ? round($catBudgetUsd * $trm, 2) : null;

    $pctBudget = $catBudgetUsd > 0 ? round(($sUsd / $catBudgetUsd) * 100, 4) : 0.0;

    $finalPct = $this->resolveCommissionTier($info, $pctBudget, $overridePct);
    $commUsd  = round($sUsd * ($finalPct / 100.0), 2);
    $commCop  = $trm ? round($commUsd * $trm, 2) : null;

    return [
        'classification_key'   => (string) $code,
        'classification_code'  => (string) $code,
        'classification_name'  => $info['name'] ?? (string) $code,
        'sales_usd'            => $sUsd,
        'sales_cop'            => $sCop,
        'category_budget_usd_for_user' => $catBudgetUsd,
        'category_budget_cop_for_user' => $catBudgetCop,
        'participation_pct'    => round($participation, 4),
        'pct_user_of_category_budget' => $pctBudget,
        'applied_commission_pct' => round($finalPct, 4),
        'commission_usd'       => $commUsd,
        'commission_cop'       => $commCop,
    ];
}

    /**
     * Resuelve el % comisión final:
     *  - override si existe
     *  - sino tier según cumplimiento (120/100/80)
     */
    private function resolveCommissionTier(array $info, float $pctBudget, ?float $overridePct): float
    {
        if (!is_null($overridePct)) return (float) $overridePct;
        if ($pctBudget >= 120 && ($info['pct_120'] ?? 0) > 0) return (float) $info['pct_120'];
        if ($pctBudget >= 100 && ($info['pct_100'] ?? 0) > 0) return (float) $info['pct_100'];
        if ($pctBudget >= 80  && ($info['pct_80']  ?? 0) > 0) return (float) $info['pct_80'];
        return 0.0;
    }

    private function emptyActiveResponse($specialist, ?string $businessLine, ?int $budgetId, string $message = ''): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'count' => 0,
            'message' => $message,
            'specialist' => $specialist,
            'specialist_name' => null,
            'business_line' => $businessLine,
            'budget_id' => $budgetId,
            'totals' => ['sales_usd' => 0, 'sales_cop' => 0],
            'user_budget_usd' => 0,
            'breakdown' => [],
            'sales' => [],
            'detail_totals' => null,
            'tickets' => [],
            'tickets_summary' => null,
            'days_worked' => [],
            'assigned_turns_for_user' => 0,
        ], 200);
    }

    /* ============================
       My commissions / reports
       ============================ */

    public function myCommissions(Request $r)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['message' => 'Unauthenticated'], 401);

        $businessLine = $r->query('business_line');
        $budgetId = $r->query('budget_id') ? (int)$r->query('budget_id') : null;

        $forwardedRequest = Request::create('/', 'GET', [
            'budget_id' => $budgetId,
            'business_line' => $businessLine,
            'user_id' => $userId,
        ]);

        return $this->activeSpecialistsSales($forwardedRequest);
    }

    public function exportMyCommissions(Request $r)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['message' => 'Unauthenticated'], 401);

        $businessLine = $r->query('business_line');
        $budgetId = $r->query('budget_id') ? (int)$r->query('budget_id') : null;

        $forwardedRequest = Request::create('/', 'GET', [
            'budget_id' => $budgetId,
            'business_line' => $businessLine,
            'user_id' => $userId,
        ]);

        $payload = $this->activeSpecialistsSales($forwardedRequest)->getData(true);

        $lines = [];
        $lines[] = "Categoria,PPTO_USD,Ventas_USD,%Cumpl,%Comision,Comision_USD";
        foreach ($payload['breakdown'] ?? [] as $c) {
            $lines[] = sprintf(
                '"%s",%s,%s,%s,%s,%s',
                str_replace('"','""', $c['classification_name'] ?? $c['classification_code']),
                number_format($c['category_budget_usd_for_user'] ?? 0,2,'.',''),
                number_format($c['sales_usd'] ?? 0,2,'.',''),
                number_format($c['pct_user_of_category_budget'] ?? 0,2,'.',''),
                number_format($c['applied_commission_pct'] ?? 0,2,'.',''),
                number_format($c['commission_usd'] ?? 0,2,'.','')
            );
        }

        $csv = implode("\n", $lines);
        $filename = 'mis_comisiones_user_'.$userId.'_budget_'.($budgetId ?? 'all').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\""
        ]);
    }

    private function getAvgTrm(): float
    {
        try {
            if (Schema::connection('budget')->hasTable('trms')) {
                $tr = DB::connection('budget')->table('trms')->orderByDesc('date')->value('value');
                if ($tr) return (float)$tr;
            }
        } catch (\Throwable $e) {}
        return 4200.0;
    }

    public function specialistCheck(Request $request)
    {
        $authUser = auth()->user();
        $sellerCode = $authUser->seller_code ?? null;

        if (!$sellerCode) {
            return response()->json(['is_specialist' => false]);
        }

        $budgetUser = DB::connection('budget')
            ->table('users')
            ->where('codigo_vendedor', $sellerCode)
            ->first();

        if (!$budgetUser) {
            return response()->json(['is_specialist' => false]);
        }

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

    public function cashierAwards(Request $r)
    {
        $budgetId = $r->query('budget_id') ? (int)$r->query('budget_id') : null;
        [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);

        $salesQ = DB::connection('budget')->table('sales')
            ->selectRaw('COALESCE(SUM(sales.value_usd),0) as total_usd');

        if (Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId) {
            $salesQ->where('sales.budget_id', $budgetId);
        } else {
            $salesQ->whereBetween('sales.sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
        }
        $totalVentas = (float)($salesQ->value('total_usd') ?? 0);

        $budgetRow = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
        $prizePool = 0.0;
        if ($budgetRow) {
            $prizePool = (float)($budgetRow->cashier_prize ?? $budgetRow->prize_pool ?? 0.0);
        }

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
                'meta' => null,
                'pdv' => null,
                'note' => null
            ];
        })->toArray();

        $period = $budgetRow ? ['start_date' => $budgetRow->start_date ?? null, 'end_date' => $budgetRow->end_date ?? null] : null;

        return response()->json([
            'rows' => $sellers,
            'total_ventas' => round($totalVentas,2),
            'prize_at_120' => round($prizePool,2),
            'prize_applied' => round($prizePool,2),
            'cumplimiento' => 0,
            'period' => $period
        ]);
    }

    public function cashierCategories(Request $r, $userId)
    {
        $userId = (int)$userId;
        $budgetId = $r->query('budget_id') ? (int)$r->query('budget_id') : null;
        [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);

        $totalQ = DB::connection('budget')->table('sales')->where('seller_id', $userId);
        if (Schema::connection('budget')->hasColumn('sales','budget_id') && $budgetId) $totalQ->where('budget_id', $budgetId);
        else $totalQ->whereBetween('sale_date', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]);
        $totalSalesUsd = (float)$totalQ->sum(DB::raw('COALESCE(value_usd,0)'));

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
