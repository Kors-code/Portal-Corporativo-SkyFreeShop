<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comisiones\Budget;
use App\Models\Comisiones\Sale;
use App\Models\Comisiones\User;
use App\Models\Comisiones\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
// Use Para Excel
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CommissionReportExport;
use App\Exports\CommissionSellerDetailExport;
use App\Imports\AssignTurnsImport;
class CommissionReportController extends Controller
{
    // fallback total turns
    protected int $TOTAL_TURNS = 315;

    // fragancias handling
    const FRAG_KEY = 'fragancias';
    const FRAG_CODES = [10, 11, 12];
    protected int $MIN_PCT_TO_QUALIFY = 80;

    public function myCommissions(Request $request)
{
    $mainUser = auth()->user();

    if (!$mainUser) {
        return response()->json(['message' => 'No autenticado'], 401);
    }

    if (!$mainUser->seller_code) {
        return response()->json([
            'message' => 'Tu usuario no tiene seller_code asignado'
        ], 422);
    }

    // Buscar el vendedor REAL en la BD de comisiones (budget)
    $budgetUser = DB::connection('budget')
        ->table('users')
        ->where('codigo_vendedor', $mainUser->seller_code)
        ->first();

    if (!$budgetUser) {
        return response()->json([
            'message' => 'No existe vendedor en sistema de comisiones con ese código'
        ], 404);
    }
    

    // Usamos el ID correcto de la BD budget
    return $this->bySellerDetail($request, $budgetUser->id);
}
public function myExport(Request $request)
{
    $mainUser = auth()->user();

    if (!$mainUser) {
        return response()->json(['message' => 'No autenticado'], 401);
    }

    if (!$mainUser->seller_code) {
        return response()->json([
            'message' => 'Tu usuario no tiene seller_code asignado'
        ], 422);
    }

    // Buscar el vendedor REAL en la BD budget
    $budgetUser = DB::connection('budget')
        ->table('users')
        ->where('codigo_vendedor', $mainUser->seller_code)
        ->first();

    if (!$budgetUser) {
        return response()->json([
            'message' => 'No existe vendedor en sistema de comisiones con ese código'
        ], 404);
    }

    // Aquí llamamos tu función existente
    return $this->exportSellerDetail($request, $budgetUser->id);
}



    private function categoryOrder(string $code): int
    {
        return match ($code) {
            '13' => 1,
            '14' => 2,
            '15' => 3,
            '16' => 4,
            '18' => 5,
            self::FRAG_KEY => 6,
            '19' => 7,
            '17' => 8,
            '22' => 9,
            '21' => 10,
            default => 999,
        };
    }

    private function categoryName(string $code): string
    {
        return match ($code) {
            '19' => 'Gifts',
            '15' => 'Joyeria',
            '16' => 'Gafas',
            '22' => 'Chocolates',
            '18' => 'Licores',
            self::FRAG_KEY => 'Fragancias',
            '13' => 'Skin care',
            '17' => 'Tabaco',
            '14' => 'Relojes',
            '21' => 'Electrónicos',
            default => strtoupper($code),
        };
    }

    protected function resolveBudget(Request $request, ?int $routeBudgetId = null): Budget
    {
        $budgetId = $routeBudgetId ?? $request->query('budget_id');

        if (!$budgetId) {
            abort(422, "budget_id es obligatorio para esta operación.");
        }

        $budget = Budget::find($budgetId);

        abort_if(!$budget, 404, "Presupuesto {$budgetId} no encontrado");

        return $budget;
    }

    private function normalizeClassification($raw)
    {
        $raw = (string) ($raw ?? '');
        $raw = trim($raw);
        if ($raw === '') return 'sin_categoria';

        if (is_numeric($raw) && in_array((int)$raw, self::FRAG_CODES, true)) {
            return self::FRAG_KEY;
        }

        $low = mb_strtolower($raw);

        if (str_contains($low, 'frag') || str_contains($low, 'perf')) {
            return self::FRAG_KEY;
        }

        return trim($low);
    }

    private function getSqlClassificationCase(): string
    {
        $codes = implode('|', array_map('intval', self::FRAG_CODES));

        return "CASE
            WHEN CAST(products.classification AS CHAR) REGEXP '^(?:{$codes})$' THEN '" . self::FRAG_KEY . "'
            WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%frag%' THEN '" . self::FRAG_KEY . "'
            WHEN LOWER(CAST(products.classification AS CHAR)) LIKE '%perf%' THEN '" . self::FRAG_KEY . "'
            ELSE TRIM(COALESCE(products.classification, 'sin_categoria'))
        END";
    }

    /**
     * Fast report using aggregated tables:
     *  - budget_user_category_totals (per budget,user,category_group)
     *  - budget_user_totals (per budget,user)
     *
     * Avoids per-sale `commissions` scans and is optimized for speed.
     */
    public function bySeller(Request $request)
    {
        // Accept either budget_ids[] (array) or budget_id (single)
        $budgetIds = $request->query('budget_ids');
        if (!$budgetIds) {
            $single = $request->query('budget_id');
            $budgetIds = $single ? [$single] : [];
        }

        // normalize to ints & filter empties
        $budgetIds = array_values(array_filter(array_map('intval', (array)$budgetIds)));

        if (empty($budgetIds)) {
            abort(422, 'Debe seleccionar al menos un presupuesto');
        }

        // Use Eloquent for budgets so we have consistent fields
        $budgets = Budget::whereIn('id', $budgetIds)->orderBy('start_date')->get();
        abort_if($budgets->isEmpty(), 404, 'Presupuestos no encontrados');

        $isSingleBudget = count($budgetIds) === 1;
        $singleBudget = $isSingleBudget ? $budgets->first() : null;

        // total turns: prefer single budget value, otherwise sum budgets or fallback
        $totalTurns = $isSingleBudget
            ? ($singleBudget->total_turns ?? $this->TOTAL_TURNS)
            : ($budgets->sum('total_turns') ?: $this->TOTAL_TURNS);

        $roleName = $request->query('role_name');

        $startDate = $budgets->min('start_date');
        $endDate   = $budgets->max('end_date');

        // aggregated subqueries
       $butTotalsSub = DB::connection('budget')->table('budget_user_totals')
            ->selectRaw('user_id,
                         COALESCE(SUM(total_sales_cop),0) as total_sales_cop,
                         COALESCE(SUM(total_sales_usd),0) as total_sales_usd,
                         COALESCE(SUM(total_commission_cop),0) as total_commission_cop')
            ->whereIn('budget_id', $budgetIds)
            ->groupBy('user_id');

        $butTurnsSub  = DB::connection('budget')->table('budget_user_turns')
            ->selectRaw('user_id, COALESCE(SUM(assigned_turns),0) as assignedTurns')
            ->whereIn('budget_id', $budgetIds)
            ->groupBy('user_id');

        $query = User::query()
            ->selectRaw("users.id AS user_id,
                         users.name AS seller,
                         users.codigo_vendedor AS seller_code,
                         COALESCE(but.assignedTurns,0) AS assignedTurns,
                         COALESCE(butot.total_sales_cop,0) AS total_sales_cop,
                         COALESCE(butot.total_sales_usd,0) AS total_sales_usd,
                         COALESCE(butot.total_commission_cop,0) AS total_commission_cop")
            ->leftJoinSub($butTurnsSub, 'but', function ($join) {
                $join->on('but.user_id', '=', 'users.id');
            })
            ->leftJoinSub($butTotalsSub, 'butot', function ($join) {
                $join->on('butot.user_id', '=', 'users.id');
            })
            ->orderByDesc('butot.total_sales_cop');
                    // 🔒 SOLO USUARIOS CON ROL VENDEDOR ACTIVO EN EL PERIODO
            $query->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))
                  ->from('user_roles as ur')
                  ->join('roles as r', 'r.id', '=', 'ur.role_id')
                  ->whereColumn('ur.user_id', 'users.id')
                  ->where('r.name', 'Vendedor') // 👈 AJUSTA si tu rol se llama diferente
                  ->where('ur.start_date', '<=', $endDate)
                  ->where(function ($q2) use ($startDate) {
                      $q2->whereNull('ur.end_date')
                         ->orWhere('ur.end_date', '>=', $startDate);
                  });
            });
            
                        // SOLO VENDEDORES CON VENTAS
            $query->where(function ($q) {
                $q->where('butot.total_sales_cop', '>', 0)
                  ->orWhere('butot.total_sales_usd', '>', 0);
            });
            


        if ($roleName) {
            $userIdsWithRole = DB::connection('budget')->table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('roles.name', $roleName)
                ->where('user_roles.start_date', '<=', $endDate)
                ->where(function ($q) use ($startDate) {
                    $q->whereNull('user_roles.end_date')
                      ->orWhere('user_roles.end_date', '>=', $startDate);
                })
                ->pluck('user_roles.user_id');
        
            $query->whereIn('users.id', $userIdsWithRole);
        }


        // aggregate target_amount across budgets for multi-budget calculations
        $targetAmount = $budgets->sum('target_amount');

        // categories participation: read from category_commissions filtered by selected budgets and AVG per classification
$categoriesModelRows = DB::connection('budget')
    ->table('category_commissions')
    ->join('categories', 'categories.id', '=', 'category_commissions.category_id')
    ->whereIn('category_commissions.budget_id', $budgetIds)
    ->select(
        'categories.classification_code',
        DB::raw('AVG(COALESCE(category_commissions.participation_pct,0)) as participation_pct')
    )
    ->groupBy('categories.classification_code')
    ->get();

$participationByCode = [];
foreach ($categoriesModelRows as $c) {
    $key = $this->normalizeClassification($c->classification_code);
    // asignamos (no sumamos) el avg por clasificación — evita multiplicaciones al pedir varios budgets
    $participationByCode[$key] = (float)($c->participation_pct ?? 0.0);
}


        // aggregated category totals for the budgets (global across users)
            $categoryTotalsQuery = DB::connection('budget')->table('budget_user_category_totals')
            ->selectRaw("category_group AS classification, SUM(sales_usd) AS sales_usd, SUM(sales_cop) AS sales_cop, SUM(commission_cop) AS commission_cop")
            ->whereIn('budget_user_category_totals.budget_id', $budgetIds)
            ->groupBy('category_group');

        $categoryTotals = $categoryTotalsQuery->get();

        $categoriesSummary = [];
        foreach ($categoryTotals as $r) {
            $classification = $this->normalizeClassification($r->classification);
            $categoryName = $this->categoryName($classification);
            if ($categoryName === '') {
                continue;
            }
            $participation = isset($participationByCode[$classification]) ? (float)$participationByCode[$classification] : 0.0;

            // Use aggregated targetAmount (multi) or budget target (single)
            $categoryBudgetUsd = round($targetAmount * ($participation / 100), 2);

            $salesUsd = (float)$r->sales_usd;
            $salesCop = (float)$r->sales_cop;

            $pctOfCategory = $categoryBudgetUsd > 0 ? round(($salesUsd / $categoryBudgetUsd) * 100, 2) : null;
            $qualifies = ($pctOfCategory !== null) && ($pctOfCategory >= $this->MIN_PCT_TO_QUALIFY);

            $commissionCop = (float)$r->commission_cop;
            $commissionUsd = null;
            if ($salesUsd > 0 && $salesCop > 0) {
                $trm = $salesCop / $salesUsd;
                if ($trm > 0) $commissionUsd = round($commissionCop / $trm, 2);
            }

            $categoriesSummary[$classification] = [
                'classification' => $classification,
                'participation_pct' => $participation,
                'category_budget_usd' => $categoryBudgetUsd,
                'sales_usd' => round($salesUsd, 2),
                'sales_cop' => round($salesCop, 2),
                'pct_of_category' => $pctOfCategory,
                'qualifies' => $qualifies,
                'commission_cop' => round($commissionCop, 2),
                'commission_usd' => $commissionUsd,
            ];
        }

        //
        // TICKETS (we still compute from sales; acceptable for reporting)
        //
        $folioRaw = "COALESCE(sales.folio, CONCAT('folio_null_', DATE(sales.sale_date), '_', COALESCE(sales.pdv, '')))";
        $budgetIdsForSales = $budgetIds;

        $ticketRowsQuery = Sale::selectRaw("sales.seller_id, {$folioRaw} AS folio_key,
        SUM(COALESCE(sales.amount_cop,0)) AS ticket_cop,
        SUM(COALESCE(sales.value_usd,0)) AS ticket_usd,
        SUM(COALESCE(sales.quantity,1)) AS units_count")
        ->whereBetween('sales.sale_date', [$startDate, $endDate])
        ->whereExists(function ($q) {
            $q->select(DB::raw(1))
              ->from('user_roles as ur')
              ->join('roles as r', 'r.id', '=', 'ur.role_id')
              ->whereColumn('ur.user_id', 'sales.seller_id')
              ->where('r.name', 'Vendedor')
              ->whereColumn('sales.sale_date', '>=', 'ur.start_date')
              ->where(function ($q2) {
                  $q2->whereNull('ur.end_date')
                     ->orWhereColumn('sales.sale_date', '<=', 'ur.end_date');
              });
        });


        if (Schema::hasColumn('sales','budget_id') && !empty($budgetIdsForSales)) {
            $ticketRowsQuery->whereIn('sales.budget_id', $budgetIdsForSales);
        }

        $ticketRows = $ticketRowsQuery
            ->groupBy('sales.seller_id', 'folio_key')
            ->get();

        $globalTicketRowsQuery = Sale::selectRaw("{$folioRaw} AS folio_key,
            SUM(COALESCE(sales.amount_cop,0)) AS ticket_cop,
            SUM(COALESCE(sales.value_usd,0)) AS ticket_usd,
            SUM(COALESCE(sales.quantity,1)) AS units_count")
        ->whereBetween('sales.sale_date', [$startDate, $endDate])
        ->whereExists(function ($q) {
            $q->select(DB::raw(1))
              ->from('user_roles as ur')
              ->join('roles as r', 'r.id', '=', 'ur.role_id')
              ->whereColumn('ur.user_id', 'sales.seller_id')
              ->where('r.name', 'Vendedor')
              ->whereColumn('sales.sale_date', '>=', 'ur.start_date')
              ->where(function ($q2) {
                  $q2->whereNull('ur.end_date')
                     ->orWhereColumn('sales.sale_date', '<=', 'ur.end_date');
              });
        });


        if (Schema::hasColumn('sales','budget_id') && !empty($budgetIdsForSales)) {
            $globalTicketRowsQuery->whereIn('sales.budget_id', $budgetIdsForSales);
        }

        $globalTicketRows = $globalTicketRowsQuery
            ->groupBy('folio_key')
            ->get();
        // aggregate tickets by seller
        $ticketsBySeller = [];
        foreach ($ticketRows as $t) {
            $sid = (int)$t->seller_id;
            if (!isset($ticketsBySeller[$sid])) {
                $ticketsBySeller[$sid] = [
                    'tickets_count' => 0,
                    'units_total' => 0,
                    'sum_ticket_usd' => 0.0,
                    'sum_ticket_cop' => 0.0,
                    'max_ticket_usd' => null,
                    'max_ticket_cop' => null,
                    'min_ticket_usd' => null,
                    'min_ticket_cop' => null,
                ];
            }
            $entry = &$ticketsBySeller[$sid];
            $entry['tickets_count'] += 1;
            $entry['sum_ticket_usd'] += (float)$t->ticket_usd;
            $entry['sum_ticket_cop'] += (float)$t->ticket_cop;
            $entry['units_total'] += (int)$t->units_count;

            $usd = (float)$t->ticket_usd;
            $cop = (float)$t->ticket_cop;

            if (is_null($entry['max_ticket_usd']) || $usd > $entry['max_ticket_usd']) $entry['max_ticket_usd'] = $usd;
            if (is_null($entry['min_ticket_usd']) || $usd < $entry['min_ticket_usd']) $entry['min_ticket_usd'] = $usd;

            if (is_null($entry['max_ticket_cop']) || $cop > $entry['max_ticket_cop']) $entry['max_ticket_cop'] = $cop;
            if (is_null($entry['min_ticket_cop']) || $cop < $entry['min_ticket_cop']) $entry['min_ticket_cop'] = $cop;
            unset($entry);
        }

        foreach ($ticketsBySeller as $sid => $t) {
            $tickets = $t['tickets_count'] ?: 1;
            $ticketsBySeller[$sid]['avg_ticket_usd'] = round($t['sum_ticket_usd'] / $tickets, 2);
            $ticketsBySeller[$sid]['avg_ticket_cop'] = round($t['sum_ticket_cop'] / $tickets, 2);
            $ticketsBySeller[$sid]['avg_units_per_ticket'] = $t['tickets_count'] > 0 ? round($t['units_total'] / $t['tickets_count'], 2) : null;
            unset($ticketsBySeller[$sid]['sum_ticket_usd'], $ticketsBySeller[$sid]['sum_ticket_cop'], $ticketsBySeller[$sid]['units_total']);
        }

        // global tickets summary
        $globalTicketsSummary = [
            'tickets_count' => 0,
            'avg_ticket_usd' => null,
            'avg_ticket_cop' => null,
            'max_ticket_usd' => null,
            'max_ticket_cop' => null,
            'min_ticket_usd' => null,
            'min_ticket_cop' => null,
            'avg_units_per_ticket' => null,
            'best_seller_by_avg_ticket' => null,
        ];

        $totalTicketsGlobal = $globalTicketRows->count();
        $totalUnitsGlobal = $globalTicketRows->sum(fn($r) => (int)$r->units_count);
        $totalUsdGlobal = $globalTicketRows->sum(fn($r) => (float)$r->ticket_usd);
        $totalCopGlobal = $globalTicketRows->sum(fn($r) => (float)$r->ticket_cop);

        if ($totalTicketsGlobal > 0) {
            $globalTicketsSummary['tickets_count'] = $totalTicketsGlobal;
            $globalTicketsSummary['avg_ticket_usd'] = round($totalUsdGlobal / $totalTicketsGlobal, 2);
            $globalTicketsSummary['avg_ticket_cop'] = round($totalCopGlobal / $totalTicketsGlobal, 2);
            $globalTicketsSummary['max_ticket_usd'] = $globalTicketRows->max('ticket_usd');
            $globalTicketsSummary['max_ticket_cop'] = $globalTicketRows->max('ticket_cop');
            $globalTicketsSummary['min_ticket_usd'] = $globalTicketRows->min('ticket_usd');
            $globalTicketsSummary['min_ticket_cop'] = $globalTicketRows->min('ticket_cop');
            $globalTicketsSummary['avg_units_per_ticket'] = $totalTicketsGlobal > 0 ? round($totalUnitsGlobal / $totalTicketsGlobal, 2) : null;

            $bestSid = null;
            $bestAvg = null;
            foreach ($ticketsBySeller as $sid => $m) {
                if (isset($m['avg_ticket_usd'])) {
                    if (is_null($bestAvg) || $m['avg_ticket_usd'] > $bestAvg) {
                        $bestAvg = $m['avg_ticket_usd'];
                        $bestSid = $sid;
                    }
                }
            }

            if ($bestSid !== null) {
                $bestUser = User::select('id','name')->find($bestSid);
                $globalTicketsSummary['best_seller_by_avg_ticket'] = $bestUser ? ['user_id' => $bestUser->id, 'seller' => $bestUser->name, 'avg_ticket_usd' => $bestAvg] : null;
            }
        }

        // --- SELLERS: use budget_user_totals + users table (fast) ---
        $rows = $query->get();

        // Attach ticket metrics
        $rows = $rows->map(function ($r) use ($ticketsBySeller) {
            $sid = (int)$r->user_id;
            $ticketMetrics = $ticketsBySeller[$sid] ?? [
                'tickets_count' => 0,
                'avg_ticket_usd' => null,
                'avg_ticket_cop' => null,
                'avg_units_per_ticket' => null,
                'max_ticket_usd' => null,
                'min_ticket_usd' => null,
            ];
            $r->tickets = $ticketMetrics;
            return $r;
        });

        // avg_trm per user using trms table (calculate only for users returned)
        if ($rows->isNotEmpty()) {
            $userIds = $rows->pluck('user_id')->unique()->values()->all();

            $saleDatesPerUserQuery = Sale::query()
                ->whereIn('seller_id', $userIds)
                ->whereBetween('sale_date', [$startDate, $endDate]);

            if (Schema::hasColumn('sales','budget_id') && !empty($budgetIds)) {
                $saleDatesPerUserQuery->whereIn('sales.budget_id', $budgetIds);
            }

            $saleDatesPerUser = $saleDatesPerUserQuery
                ->select('seller_id', 'sale_date')
                ->distinct()
                ->get()
                ->groupBy('seller_id')
                ->map(function ($g) {
                    return $g->pluck('sale_date')->unique()->values()->all();
                });

            $allDates = [];
            foreach ($saleDatesPerUser as $dates) {
                foreach ($dates as $d) {
                    $allDates[$d] = true;
                }
            }
            $allDates = array_keys($allDates);

            $trmByDate = [];
            if (!empty($allDates)) {
                $trmRows = DB::connection('budget')->table('trms')

                    ->select('date', DB::raw('AVG(value) as avg_value'))
                    ->whereIn('date', $allDates)
                    ->groupBy('date')
                    ->get();

                foreach ($trmRows as $t) {
                    $trmByDate[$t->date] = (float)$t->avg_value;
                }
            }

            $rows = $rows->map(function ($r) use ($saleDatesPerUser, $trmByDate) {
                $userId = $r->user_id;
                $dates = $saleDatesPerUser[$userId] ?? [];
                $vals = [];
                foreach ($dates as $d) {
                    if (isset($trmByDate[$d])) $vals[] = $trmByDate[$d];
                }
                if (!empty($vals)) {
                    $avg = array_sum($vals) / count($vals);
                    $r->avg_trm = round($avg, 2);
                } else {
                    $r->avg_trm = null;
                }
                return $r;
            });

            $rows = $rows->map(function ($r) {
                $commissionUsd = null;

                if (
                    isset($r->total_commission_cop) &&
                    $r->total_commission_cop > 0 &&
                    isset($r->avg_trm) &&
                    $r->avg_trm > 0
                ) {
                    $commissionUsd = round($r->total_commission_cop / $r->avg_trm, 2);
                }

                $r->total_commission_usd = $commissionUsd;
                return $r;
            });
        }

        // global progress based on Sale totals (USD)
        $totalUsdQuery = Sale::query()
            ->whereBetween('sale_date', [$startDate, $endDate]);

        if (Schema::hasColumn('sales', 'budget_id') && !empty($budgetIds)) {
            $totalUsdQuery->whereIn('sales.budget_id', $budgetIds);
        }

        $totalUsd = $totalUsdQuery->sum(DB::raw('COALESCE(value_usd,0)'));

        // For global pct computations use aggregated target amount (multi) or single budget target
        $pct = ($targetAmount > 0) ? round(($totalUsd / $targetAmount) * 100, 2) : 0;
        $isProvisionalGlobal = $pct < $this->MIN_PCT_TO_QUALIFY;

        $requiredUsd = round($targetAmount * ($this->MIN_PCT_TO_QUALIFY / 100), 2);
        $missingUsd = max(0, round($requiredUsd - $totalUsd, 2));

        // total assigned across budgets
        $totalAssigned = (int) DB::connection('budget')->table('budget_user_turns')
    ->whereIn('budget_id', $budgetIds)
    ->sum('assigned_turns');

        $remainingTurns = max(0, $totalTurns - $totalAssigned);

        // totals from aggregated table
        $totalCommissionCop = DB::connection('budget')->table('budget_user_totals')
            ->whereIn('budget_id', $budgetIds)
            ->sum('total_commission_cop');

        // Prefer commission USD derived from category-level commission_usd if present
        $totalCommissionUsd = null;
        try {
            $sumFromCategories = collect($categoriesSummary)->sum('commission_usd');
            if ($sumFromCategories > 0) {
                $totalCommissionUsd = round($sumFromCategories, 2);
            } elseif ($totalCommissionCop > 0 && $totalUsd > 0 && $totalCopGlobal > 0) {
                $trmGlobal = $totalCopGlobal > 0 ? ($totalCopGlobal / $totalUsd) : null;
                if ($trmGlobal && $trmGlobal > 0) $totalCommissionUsd = round($totalCommissionCop / $trmGlobal, 2);
            }
        } catch (\Throwable $e) {
            $totalCommissionUsd = null;
        }

        return response()->json([
            'active' => true,
            'currency' => 'COP',
            'budget' => [
                'ids' => $budgetIds,
                'name' => count($budgetIds) === 1 ? $budgets->first()->name: 'Múltiples presupuestos',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'target_amount' => $targetAmount,
                'min_pct_to_qualify' => $this->MIN_PCT_TO_QUALIFY,
                'total_turns' => $totalTurns
            ],
            'progress' => [
                'pct' => $pct,
                'min_pct' => $this->MIN_PCT_TO_QUALIFY,
                'missing_usd' => $missingUsd,
                'is_provisional_global' => $isProvisionalGlobal,
                'total_usd' => round($totalUsd, 2),
                'required_usd' => $requiredUsd,
                'total_commission_cop' => round($totalCommissionCop, 2),
                'total_commission_usd' => $totalCommissionUsd,
            ],
            'categories_summary' => array_values($categoriesSummary),
            'tickets_summary' => $globalTicketsSummary,
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => $remainingTurns,
            ],
            'sellers' => $rows
        ]);
    }

    /**
     * Detail per seller using aggregated tables where possible and fallback to sales for ticket-level rows.
     */
public function bySellerDetail(Request $request, $userId)
{
    // Accept either budget_ids[] (array) or budget_id (single)
    $budgetIds = $request->query('budget_ids');
    if (!$budgetIds) {
        $single = $request->query('budget_id');
        $budgetIds = $single ? [$single] : [];
    }

    // normalize to ints & filter empties
    $budgetIds = array_values(array_filter(array_map('intval', (array)$budgetIds)));

    if (empty($budgetIds)) {
        abort(422, 'Debe seleccionar al menos un presupuesto');
    }

    $budgets = Budget::whereIn('id', $budgetIds)->orderBy('start_date')->get();
    abort_if($budgets->isEmpty(), 404, 'Presupuestos no encontrados');

    $startDate = $budgets->min('start_date');
    $endDate   = $budgets->max('end_date');

    // 🔹 Fechas donde el usuario fue VENDEDOR
    $sellerRoleRanges = DB::connection('budget')
        ->table('user_roles')
        ->join('roles', 'roles.id', '=', 'user_roles.role_id')
        ->where('user_roles.user_id', $userId)
        ->where('roles.name', 'Vendedor')
        ->where('user_roles.start_date', '<=', $endDate)
        ->where(function ($q) use ($startDate) {
            $q->whereNull('user_roles.end_date')
              ->orWhere('user_roles.end_date', '>=', $startDate);
        })
        ->select('user_roles.start_date', 'user_roles.end_date')
        ->get();

    // Sales rows for the user
    $salesQuery = Sale::select(
            'sales.id as sale_id',
            'sales.sale_date',
            'sales.folio',
            'sales.pdv',
            'products.description as product',
            'products.classification as category_code',
            'products.classification_desc as category_desc',
            'products.brand as brand',
            'products.provider_name as provider',
            'sales.amount_cop',
            'sales.value_usd',
            'sales.exchange_rate'
        )
        ->leftJoin('products', 'sales.product_id', '=', 'products.id')
        ->where('sales.seller_id', $userId)
        ->whereBetween('sales.sale_date', [$startDate, $endDate]);

    if (Schema::hasColumn('sales','budget_id') && !empty($budgetIds)) {
        $salesQuery->whereIn('sales.budget_id', $budgetIds);
    }

    $sales = $salesQuery->orderBy('sales.sale_date')->get();
    $saleDates = $sales->pluck('sale_date')->unique()->values()->all();

    // user tickets (group by folio)
    $userTicketRowsQuery = Sale::selectRaw(
        "COALESCE(sales.folio, CONCAT('folio_null_', DATE(sales.sale_date), '_', COALESCE(sales.pdv, ''))) AS folio_key,
         SUM(COALESCE(sales.amount_cop,0)) AS ticket_cop,
         SUM(COALESCE(sales.value_usd,0)) AS ticket_usd,
         COUNT(*) AS lines_count,
         SUM(COALESCE(sales.quantity,1)) AS units_count,
         MIN(sales.sale_date) AS sale_date"
    )
    ->where('sales.seller_id', $userId)
    ->whereBetween('sales.sale_date', [$startDate, $endDate]);

    if (Schema::hasColumn('sales','budget_id') && !empty($budgetIds)) {
        $userTicketRowsQuery->whereIn('sales.budget_id', $budgetIds);
    }

    $userTicketRows = $userTicketRowsQuery
        ->groupBy(DB::raw("COALESCE(sales.folio, CONCAT('folio_null_', DATE(sales.sale_date), '_', COALESCE(sales.pdv, '')))"))
        ->orderByDesc('ticket_usd')
        ->get();

    $userTicketsList = $userTicketRows->map(function ($t) {
        return [
            'folio' => (string)$t->folio_key,
            'ticket_usd' => round((float)$t->ticket_usd, 2),
            'ticket_cop' => round((float)$t->ticket_cop, 2),
            'lines_count' => (int)$t->lines_count,
            'units_count' => (int)$t->units_count,
            'sale_date' => $t->sale_date,
        ];
    })->values();

    $userTicketsSummary = [
        'tickets_count' => $userTicketsList->count(),
        'avg_ticket_usd' => $userTicketsList->count() ? round($userTicketsList->avg('ticket_usd'), 2) : null,
        'avg_ticket_cop' => $userTicketsList->count() ? round($userTicketsList->avg('ticket_cop'), 2) : null,
        'avg_units_per_ticket' => $userTicketsList->count() ? round($userTicketsList->avg('units_count'), 2) : null,
        'max_ticket_usd' => $userTicketsList->count() ? $userTicketsList->max('ticket_usd') : null,
        'max_ticket_cop' => $userTicketsList->count() ? $userTicketsList->max('ticket_cop') : null,
        'min_ticket_usd' => $userTicketsList->count() ? $userTicketsList->min('ticket_usd') : null,
        'min_ticket_cop' => $userTicketsList->count() ? $userTicketsList->min('ticket_cop') : null,
    ];

    // avg trm for user from trms table
    $avgTrmForUser = null;
    if (!empty($saleDates)) {
        $trmRowsQuery = DB::connection('budget')->table('trms')->select('date', DB::raw('AVG(value) as avg_value'));
        $trmRowsQuery->whereIn('date', $saleDates);
        $trmRows = $trmRowsQuery->groupBy('date')->get();

        $trmValues = [];
        foreach ($trmRows as $t) $trmValues[] = (float)$t->avg_value;
        if (!empty($trmValues)) $avgTrmForUser = round(array_sum($trmValues) / count($trmValues), 2);
    }

    // categories for user from aggregated table (SUM across selected budgets)
    $userCategoryRowsQuery = DB::connection('budget')->table('budget_user_category_totals')
        ->whereIn('budget_id', $budgetIds)
        ->where('user_id', $userId)
        ->selectRaw('
            category_group,
            SUM(sales_usd) AS sales_usd,
            SUM(sales_cop) AS sales_cop,
            SUM(commission_cop) AS commission_cop,
            MAX(applied_pct) AS applied_pct
        ')
        ->groupBy('category_group');

    $userCategoryRows = $userCategoryRowsQuery->get();

    // total ventas USD del usuario
    $totalSalesUsdUser = $userCategoryRows->sum('sales_usd') ?: 1;
    
    
    // --- DÍAS LABORADOS: agrupar sales por fecha y devolver métricas por día ---
$daysWorked = [];
if ($sales->isNotEmpty()) {
    $grouped = $sales->groupBy(function ($s) {
        // normalizamos a string YYYY-MM-DD
        return Carbon::parse($s->sale_date)->toDateString();
    });

    foreach ($grouped as $date => $rows) {
        $sumUsd = (float) $rows->sum(fn($r) => $r->value_usd ?? 0);
        $sumCop = (float) $rows->sum(fn($r) => $r->amount_cop ?? 0);
        // tickets por día: consideramos folio distinto como ticket; si folio nullo consideramos pdv+fecha
        $ticketKeys = $rows->map(function($r) {
            return $r->folio ?? ('folio_null_' . (string) Carbon::parse($r->sale_date)->toDateString() . '_' . ($r->pdv ?? ''));
        })->unique();
        $ticketsCount = $ticketKeys->count();

        $daysWorked[] = [
            'date' => $date,
            'tickets_count' => $ticketsCount,
            'sales_usd' => round($sumUsd, 2),
            'sales_cop' => round($sumCop, 2),
            'lines_count' => $rows->count(),
        ];
    }

    // orden descendente por fecha (más reciente primero)
    usort($daysWorked, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
}

$daysWorkedCount = count($daysWorked);


    // -------------------------
    // read participation from category_commissions (per budget)
    // -------------------------
    $categoriesSummary = [];
    $categoryGroupMap = [];

    // Aggregate participation_pct by category classification across the selected budgets
    $categoriesModelRows = DB::connection('budget')
    ->table('category_commissions')
    ->join('categories', 'categories.id', '=', 'category_commissions.category_id')
    ->whereIn('category_commissions.budget_id', $budgetIds)
    ->select(
        'categories.id as category_id',
        'categories.classification_code',
        DB::raw('AVG(COALESCE(category_commissions.participation_pct,0)) as participation_pct')
    )
    ->groupBy('categories.id', 'categories.classification_code')
    ->get();


    foreach ($categoriesModelRows as $c) {
        $raw = (string) ($c->classification_code ?? '');
        $canonical = (is_numeric($raw) && in_array((int)$raw, self::FRAG_CODES, true))
            ? self::FRAG_KEY
            : $this->normalizeClassification($raw);

        $nameFromCode = $this->categoryName($canonical);
        $nameNormalized = $this->normalizeClassification($nameFromCode);

        $participation = (float) ($c->participation_pct ?? 0.0);
        $catId = $c->category_id ?? null;

        // helper to add participation to multiple keys
        $add = function($k, $val, $catIdInner = null) use (&$categoryGroupMap) {
            if ($k === '' || is_null($k)) return;
        
            $categoryGroupMap[$k] = [
                'category_id' => $catIdInner,
                'participation_pct' => (float) $val
            ];
        };


        $add($canonical, $participation, $catId);
        $add($raw, $participation, $catId);
        $add(mb_strtolower(trim($raw)), $participation, $catId);
        $add($nameNormalized, $participation, $catId);
    }

    // total participation (suma de participaciones) -> evita divisiones por 0
    $totalParticipation = array_sum(array_column($categoryGroupMap, 'participation_pct')) ?: 100;

    // helper normalizer
    $normalizedGroup = function ($v) {
        return $this->normalizeClassification($v);
    };

    $assignedToUser = (int) DB::connection('budget')->table('budget_user_turns')
        ->whereIn('budget_id', $budgetIds)
        ->where('user_id', $userId)
        ->sum('assigned_turns');

    $totalTurns = $budgets->sum('total_turns') ?: $this->TOTAL_TURNS;
    $totalTarget = $budgets->sum('target_amount');

    $userBudgetUsd = $totalTurns > 0
        ? round($totalTarget * ($assignedToUser / $totalTurns), 2)
        : 0.0;

    // Build categories summary using the *userBudgetUsd* to compute category budget for user
    foreach ($userCategoryRows as $r) {
        $rawGroup = (string) $r->category_group;
        $classificationNorm = $normalizedGroup($rawGroup);

        $salesUsd = (float)$r->sales_usd;
        $salesCop = (float)$r->sales_cop;
        $commissionCop = (float)$r->commission_cop;

        // Try several keys to find a participation_pct (tolerant lookup)
        $participationPct = 0.0;
        if (isset($categoryGroupMap[$classificationNorm])) {
            $participationPct = $categoryGroupMap[$classificationNorm]['participation_pct'];
        } elseif (isset($categoryGroupMap[$rawGroup])) {
            $participationPct = $categoryGroupMap[$rawGroup]['participation_pct'];
        } elseif (isset($categoryGroupMap[mb_strtolower(trim($rawGroup))])) {
            $participationPct = $categoryGroupMap[mb_strtolower(trim($rawGroup))]['participation_pct'];
        } else {
            // fallback trying name derived from normalized classification
            $fromName = $this->normalizeClassification($this->categoryName($classificationNorm));
            if (isset($categoryGroupMap[$fromName])) {
                $participationPct = $categoryGroupMap[$fromName]['participation_pct'];
            } else {
                $participationPct = 0.0;
            }
        }

        $categoryBudgetUsdForUser = round(
            $userBudgetUsd * ($participationPct / 100),
            2
        );

        $pctOfCategory = $categoryBudgetUsdForUser > 0 ? round(($salesUsd / $categoryBudgetUsdForUser) * 100, 2) : null;
        $qualifies = $pctOfCategory !== null && $pctOfCategory >= $this->MIN_PCT_TO_QUALIFY;

        // applied commission pct: prefer the stored applied_pct if exists, otherwise fallback to participationPct
        $appliedPct = isset($r->applied_pct) ? (float)$r->applied_pct : $participationPct;

        // compute commission USD: commission = sales_usd * (appliedPct / 100)
        $commissionUsd = null;
        if ($salesUsd > 0 && $appliedPct > 0) {
            $commissionUsd = round($salesUsd * ($appliedPct / 100), 2);
        } else {
            // fallback: if not enough USD info try COP->USD with trmUsed
            $trmUsed = null;
            if ($salesUsd > 0 && $salesCop > 0) {
                $trmUsed = $salesCop / $salesUsd;
            } elseif (!empty($avgTrmForUser)) {
                $trmUsed = $avgTrmForUser;
            }
            if ($commissionCop > 0 && !empty($trmUsed) && $trmUsed > 0) {
                $commissionUsd = round($commissionCop / $trmUsed, 2);
            }
        }

        // provide both raw classification (for lookup) and normalized key (for naming & grouping)
            $categoriesSummary[$classificationNorm . '::' . $rawGroup] = [
                // keep old "raw" code so sales lookups still work
                'classification_code' => $rawGroup,               // <-- raw value that appears in sales.category_code
                'classification_raw'  => $rawGroup,               // (alias, backwards compatible)
                'classification_key'  => $classificationNorm,    // <-- normalized key (fragancias, etc.)
                'category'            => $this->categoryName($classificationNorm),
            
                'sales_sum_usd' => round($salesUsd, 2),
                'sales_sum_cop' => round($salesCop, 2),
            
                'category_budget_usd_for_user' => $categoryBudgetUsdForUser,
                'pct_user_of_category_budget'  => $pctOfCategory,
            
                'applied_commission_pct' => $appliedPct,
                'commission_sum_usd'     => $commissionUsd,
                'commission_sum_cop'     => round($commissionCop, 2),
            
                'qualifies' => $qualifies,
            ];

    }

    // user totals from budget_user_totals (aggregate for selected budgets)
    $userTotals = DB::connection('budget')->table('budget_user_totals')
        ->whereIn('budget_id', $budgetIds)
        ->where('user_id', $userId)
        ->selectRaw('
            COALESCE(SUM(total_sales_usd),0) as total_sales_usd,
            COALESCE(SUM(total_sales_cop),0) as total_sales_cop,
            COALESCE(SUM(total_commission_cop),0) as total_commission_cop
        ')
        ->first();

    // Totals: ensure total_commission_usd computed from category-level commissions when possible
    $totalCommissionUsdFromCats = 0.0;
    foreach ($categoriesSummary as $c) {
        $totalCommissionUsdFromCats += ($c['commission_sum_usd'] ?? 0);
    }
    $totalCommissionUsdFromCats = round($totalCommissionUsdFromCats, 2);

    $totals = [
        'total_commission_cop' => $userTotals->total_commission_cop ?? 0,
        'total_sales_cop' => $userTotals->total_sales_cop ?? 0,
        'total_sales_usd' => $userTotals->total_sales_usd ?? 0,
        'avg_trm' => $avgTrmForUser,
        'total_commission_usd' => $totalCommissionUsdFromCats
    ];

    // ensure categories are ordered consistently
    uasort($categoriesSummary, function ($a, $b) {
        return $this->categoryOrder($a['classification_code'])
            <=> $this->categoryOrder($b['classification_code']);
    });

logger()->info('DEBUG CATEGORY', [
    'salesUsd' => $salesUsd,
    'userBudgetUsd' => $userBudgetUsd,
    'participationPct' => $participationPct,
    'categoryBudgetUsdForUser' => $categoryBudgetUsdForUser,
    'pctOfCategory' => $pctOfCategory
]);

    $user = User::select('id','name')->find($userId);
    
    

    return response()->json([
        'active' => true,
        'currency' => 'COP',
        'user' => $user,
        'sales' => $sales,
        'categories' => array_values($categoriesSummary),
        'totals' => $totals,
        'user_budget_usd' => $userBudgetUsd,
        'assigned_turns_for_user' => $assignedToUser,
        'days_worked' => $daysWorked,
        'days_count' => $daysWorkedCount,

        'budget' => [
            'ids' => $budgetIds,
            'name' => count($budgetIds) === 1 ? $budgets->first()->name : 'Múltiples presupuestos',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'target_amount' => $totalTarget,
            'total_turns' => $totalTurns
        ],
        'tickets' => $userTicketsList,
        'tickets_summary' => $userTicketsSummary,
    ]);
}


    /**
     * assignTurns: update budget_user_turns and refresh the aggregated user totals from category totals (fast).
     * Note: this endpoint works per single budgetId (same as before).
     */
    public function assignTurns(Request $request, $userId, $budgetId)
    {
        $budget = $this->resolveBudget($request, (int)$budgetId);

        $totalTurns = $budget->total_turns ?? $this->TOTAL_TURNS;

        $data = $request->validate([
            'assigned_turns' => ['required', 'integer', 'min:0']
        ]);

        $newValue = (int) $data['assigned_turns'];

        $totalAssignedExcept = DB::connection('budget')->table('budget_user_turns')
            ->where('budget_id', $budget->id)
            ->where('user_id', '!=', $userId)
            ->sum('assigned_turns');
        if ($totalAssignedExcept + $newValue > $totalTurns) {
            return response()->json([
                'message' => 'No hay suficientes turnos disponibles',
                'available' => max(0, $totalTurns - $totalAssignedExcept)
            ], 422);
        }

        DB::connection('budget')->table('budget_user_turns')->updateOrInsert(
            [
                'budget_id' => $budget->id,
                'user_id' => $userId
            ],
            [
                'assigned_turns' => $newValue,
                'updated_at' => now()
            ]
        );

        $totalAssigned = DB::connection('budget')->table('budget_user_turns')
            ->where('budget_id', $budget->id)
            ->sum('assigned_turns');

        // --- REFRESH user totals from category aggregates (fast) ---
        $agg = DB::connection('budget')->table('budget_user_category_totals')
            ->where('budget_id', $budget->id)
            ->where('user_id', $userId)
            ->selectRaw('COALESCE(SUM(sales_usd),0) AS total_sales_usd, COALESCE(SUM(sales_cop),0) AS total_sales_cop, COALESCE(SUM(commission_cop),0) AS total_commission_cop')
            ->first();

        DB::connection('budget')->table('budget_user_totals')->updateOrInsert(
            ['budget_id' => $budget->id, 'user_id' => $userId],
            [
                'total_sales_usd' => $agg->total_sales_usd ?? 0,
                'total_sales_cop' => $agg->total_sales_cop ?? 0,
                'total_commission_cop' => $agg->total_commission_cop ?? 0,
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Turnos asignados',
            'assigned_for_user' => $newValue,
            'turns' => [
                'total' => $totalTurns,
                'assigned_total' => $totalAssigned,
                'remaining' => max(0, $totalTurns - $totalAssigned)
            ]
        ]);
    }

    public function exportExcel(Request $request)
    {
        $response = $this->bySeller($request);

        $content = $response->getContent();
        $data = json_decode($content, true);

        if (!$data || !isset($data['active']) || !$data['active']) {
            return response()->json(['message' => 'No hay datos para exportar'], 422);
        }

        $sellers = [];
        foreach ($data['sellers'] as $s) {
            $sellers[] = [
                $s['seller_code'] ?? $s['seller_id']  ?? $s['codigo_vendedor'] ?? 'N/A', 
                $s['seller'] ?? null,
                $s['assignedTurns'] ?? 0,
                $s['total_sales_cop'] ?? 0,
                $s['total_sales_usd'] ?? 0,
                $s['total_commission_cop'] ?? 0,
                $s['avg_trm'] ?? null,
                $s['tickets']['tickets_count'] ?? null,
                $s['tickets']['avg_ticket_usd'] ?? null,
                $s['tickets']['avg_ticket_cop'] ?? null,
            ];
        }

        $categories = [];
        foreach ($data['categories_summary'] as $c) {
            $categories[] = [
                $c['classification'] ?? null,
                $c['participation_pct'] ?? null,
                $c['category_budget_usd'] ?? null,
                $c['sales_usd'] ?? null,
                $c['sales_cop'] ?? null,
                $c['pct_of_category'] ?? null,
                ($c['qualifies'] ?? false) ? 'Sí' : 'No',
                $c['applied_commission_pct'] ?? null,
                $c['projected_commission_usd'] ?? $c['commission_usd'] ?? null,
                $c['commission_cop'] ?? null,
            ];
        }

        $budgetIdsForFilename = $data['budget']['ids'] ?? [];
        $budgetIdStr = is_array($budgetIdsForFilename) ? implode('_', $budgetIdsForFilename) : (string)$budgetIdsForFilename;
        $budgetIdStr = $budgetIdStr ?: 'unknown';

        $filename = "commissions_budget_{$budgetIdStr}_" . date('Ymd_His') . ".xlsx";

        return Excel::download(new CommissionReportExport($sellers, $categories, [
            'budget' => $data['budget'] ?? null,
            'progress' => $data['progress'] ?? null
        ]), $filename);
    }

    public function exportSellerDetail(Request $request, $userId)
    {
        $response = $this->bySellerDetail($request, $userId);
        $data = json_decode($response->getContent(), true);

        if (!$data || empty($data['sales'])) {
            return response()->json(['message' => 'No hay datos para exportar'], 422);
        }

        $avgTrm = $data['totals']['avg_trm'] ?? 1;
        $sellerCode = $data['user']['code'] ?? $data['user']['id'] ?? '';


        $categories = [];
        foreach ($data['categories'] as $c) {
            $categories[] = [
                $c['category'],
                $c['sales_sum_usd'],
                $c['sales_sum_cop'],
                $c['category_budget_usd_for_user'],
                $c['pct_user_of_category_budget'],
                $c['applied_commission_pct'],
                $c['commission_sum_usd'],
                $c['commission_sum_cop'],
            ];
        }

        $sales = [];
        foreach ($data['sales'] as $s) {
            $cat = collect($data['categories'])->firstWhere(
                'classification_code',
                (string) $s['category_code']
            );

            $pct = $cat['applied_commission_pct'] ?? 0;

            $commissionCop =
                $s['amount_cop'] > 0
                    ? $s['amount_cop'] * ($pct / 100)
                    : ($s['value_usd'] * $avgTrm) * ($pct / 100);

            $sales[] = [
                $s['sale_date'],
                $sellerCode, 
                $s['folio'],
                $s['product'],
                $cat['category'] ?? 'Sin categoría',
                $s['value_usd'],
                $s['amount_cop'],
                round($commissionCop),
            ];
        }

        $filename = 'commission_detail_user_' . $userId . '_' . date('Ymd_His') . '.xlsx';

        return Excel::download(
            new CommissionSellerDetailExport(
                $categories,
                $sales,
                ['user' => $data['user'], 'budget' => $data['budget']]
            ),
            $filename
        );
    }
public function importTurnsByMonth(Request $request)
{
    $request->validate([
        'file' => 'required|file|mimes:xlsx,csv'
    ]);

    $import = new AssignTurnsByMonthImport();
    Excel::import($import, $request->file('file'));

    return response()->json([
        'message' => 'Importación finalizada',
        'errors' => $import->errors
    ]);
}
public function downloadTurnsTemplateV2()
{
    return Excel::download(new class implements \Maatwebsite\Excel\Concerns\FromArray {
        public function array(): array
        {
            return [
                ['mes', 'codigo_vendedor', 'assigned_turns'],
                ['2026-01', 'VEND001', 10],
                ['2026-01', 'VEND002', 15],
            ];
        }
    }, 'plantilla_turnos_por_mes.xlsx');
}


}
