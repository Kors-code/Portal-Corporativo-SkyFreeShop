<?php

namespace App\Services;

use App\Models\Comisiones\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Query\Expression;
use Carbon\Carbon;

class CommissionService
{
    // fallback total turns
    protected int $TOTAL_TURNS = 315;

    // fragancias handling
    const FRAG_KEY = 'fragancias';
    const FRAG_CODES = [10, 11, 12];
    protected int $MIN_PCT_TO_QUALIFY = 80;

    /**
     * Helper: conexión a la BD de budgets.
     */
    protected function budgetDB()
    {
        return DB::connection('budget');
    }

    /**
     * Genera comisiones para un presupuesto específico (entry point público).
     */
    public function generateForBudget(int $budgetId): array
    {
        Log::info('[COMMISSION] Starting generation (by budget)', ['budget_id' => $budgetId]);

        $budget = $this->budgetDB()->table('budgets')->where('id', $budgetId)->first();

        if (!$budget) {
            Log::warning('[COMMISSION] Budget not found', ['budget_id' => $budgetId]);
            return ['status' => 'budget_not_found'];
        }

        return $this->processBudget($budget);
    }

    /**
     * Genera para el presupuesto activo (si existe).
     */
    public function generateForActiveBudget(): array
    {
        $today = now()->toDateString();

        Log::info('[COMMISSION] Starting generation (active budget)', ['date' => $today]);

        $budget = $this->budgetDB()
            ->table('budgets')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        if (!$budget) {
            Log::warning('[COMMISSION] No active budget found');
            return ['status' => 'no_budget'];
        }

        return $this->processBudget($budget);
    }

    /**
     * Procesamiento de un presupuesto (recibe stdClass desde budgetDB).
     */
    protected function processBudget($budget): array
    {
        $result = $this->processBudgetForUsers($budget, null);

        return $result + [
            'status' => $result['status'] ?? 'ok'
        ];
    }

    /**
     * Procesa el presupuesto calculando y guardando agregados en la conexión 'budget'.
     *
     * Nota clave: TODAS las consultas a tablas que viven en la BD de presupuestos
     * se hacen a través de $this->budgetDB() para evitar usar la conexión por defecto.
     */
    protected function processBudgetForUsers($budget, ?array $onlyUserIds = null): array
    {
        $budgetId = $budget->id ?? null;
        Log::info('[COMMISSION] processBudgetForUsers', ['budget_id' => $budgetId, 'onlyUserIds' => $onlyUserIds]);

        // 1) total turns: prefer budget.total_turns; si no existe, calcular desde budget_user_turns; sino fallback
        $totalTurns = $budget->total_turns ?? $this->budgetDB()->table('budget_user_turns')->where('budget_id', $budgetId)->sum('assigned_turns');
        if (empty($totalTurns) || $totalTurns <= 0) {
            $totalTurns = $this->TOTAL_TURNS;
        }

        // check column existence in budget connection
        $hasBudgetIdCol = false;
        try {
            $hasBudgetIdCol = Schema::connection('budget')->hasColumn('sales', 'budget_id');
        } catch (\Throwable $e) {
            // si falla el Schema en la conexión budget, asumimos que no existe la columna
            $hasBudgetIdCol = false;
        }

        // total USD and COP to determine provisional (respect budget_id if column exists)
        $totalUsdQuery = $this->budgetDB()->table('sales')
            ->whereBetween('sale_date', [$budget->start_date, $budget->end_date]);

        $totalCopQuery = $this->budgetDB()->table('sales')
            ->whereBetween('sale_date', [$budget->start_date, $budget->end_date]);

        if ($hasBudgetIdCol) {
            $totalUsdQuery->where('sales.budget_id', $budgetId);
            $totalCopQuery->where('sales.budget_id', $budgetId);
        }

        $totalUsd = (float) $totalUsdQuery->sum(new Expression('COALESCE(value_usd,0)'));
        $totalCop = (float) $totalCopQuery->sum(new Expression('COALESCE(amount_cop,0)'));

        $pct = ($budget->target_amount > 0) ? ($totalUsd / $budget->target_amount) * 100 : 0;
        $isProvisional = $pct < $this->MIN_PCT_TO_QUALIFY;

        Log::info('[COMMISSION] Budget progress', [
            'budget_id' => $budgetId,
            'total_sales_usd' => round($totalUsd, 2),
            'total_sales_cop' => round($totalCop, 2),
            'pct' => round($pct, 2),
            'is_provisional' => $isProvisional,
            'total_turns' => $totalTurns
        ]);

        // Usar transacción en la conexión 'budget' (donde se guardan los agregados)
        $conn = $this->budgetDB();
        $conn->beginTransaction();

        try {
            // ventas agregadas por seller + grupo (USD + COP)
            $caseFrag = $this->getSqlClassificationCase();

            $salesByUserGroupQuery = $this->budgetDB()->table('sales')
                ->select(new Expression("
                    {$caseFrag} AS classification,
                    sales.seller_id,
                    SUM(COALESCE(sales.value_usd,0)) AS sales_usd,
                    SUM(COALESCE(sales.amount_cop,0)) AS sales_cop
                "))
                ->leftJoin('products', 'sales.product_id', '=', 'products.id')
                ->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date]);

            if ($hasBudgetIdCol) {
                $salesByUserGroupQuery->where('sales.budget_id', $budgetId);
            }

            if (is_array($onlyUserIds) && !empty($onlyUserIds)) {
                $salesByUserGroupQuery->whereIn('sales.seller_id', $onlyUserIds);
            }

            $salesByUserGroupRows = $salesByUserGroupQuery
                ->groupBy(new Expression($caseFrag), 'sales.seller_id')
                ->get();
                
                
                Log::info('DEBUG SALES GROUPS RAW', 
                    $salesByUserGroupRows->map(function ($r) {
                        return [
                            'seller_id' => $r->seller_id,
                            'classification_raw' => $r->classification
                        ];
                    })->toArray()
                );


            $salesByUserGroup = []; // [user_id][group] => ['sales_usd'=>..., 'sales_cop'=>...]
            $userIds = [];
            foreach ($salesByUserGroupRows as $r) {
                $grp = $this->normalizeClassification($r->classification);
                $uid = (int)$r->seller_id;
                $userIds[$uid] = true;
                $salesByUserGroup[$uid][$grp] = [
                    'sales_usd' => (float)$r->sales_usd,
                    'sales_cop' => (float)$r->sales_cop
                ];
            Log::info('DEBUG NORMALIZED GROUP', [
    'seller_id' => $r->seller_id,
    'raw' => $r->classification,
    'normalized' => $grp
]);
            }
            
            


            // 2) categories + participation desde category_commissions según budget (en conexión 'budget')
            $categoriesWithParticipation = $this->budgetDB()->table('categories as c')
                ->join('category_commissions as cc', function ($join) use ($budgetId) {
                    $join->on('cc.category_id', '=', 'c.id')
                        ->where('cc.budget_id', $budgetId);
                })
                ->select(
                    'c.id',
                    'c.classification_code',
                    new Expression('MAX(cc.participation_pct) as participation_pct')
                )
                ->groupBy('c.id', 'c.classification_code')
                ->get();


                Log::info('DEBUG BUDGET CATEGORIES',
                    collect($categoriesWithParticipation)->map(function ($c) {
                        return [
                            'category_id' => $c->id,
                            'classification_code' => $c->classification_code,
                            'normalized' => $this->normalizeClassification($c->classification_code)
                        ];
                    })->toArray()
                );

            $categoryGroupMap = []; // group => ['category_ids'=>[], 'participation_pct'=>SUM]
            foreach ($categoriesWithParticipation as $c) {
                $grp = $this->normalizeClassification($c->classification_code);
                $pctVal = (float)$c->participation_pct;
                if (!isset($categoryGroupMap[$grp])) {
                    $categoryGroupMap[$grp] = [
                        'category_ids' => [$c->id],
                        'participation_pct' => $pctVal
                    ];
                } else {
                    $categoryGroupMap[$grp]['category_ids'][] = $c->id;
                    // Si hay múltiples filas por alguna razón, sumamos (esto imita lógica previa)
                    $categoryGroupMap[$grp]['participation_pct'] += $pctVal;
                }
            }
            Log::info('[COMMISSION] Category groups', ['budget_id' => $budgetId, 'categoryGroupMap' => $categoryGroupMap]);

            // Debug frag mapping
            $fragCategoryIds = [];
            $fragCategoryRaw = [];
            foreach ($categoriesWithParticipation as $c) {
                $grp = $this->normalizeClassification($c->classification_code);
                if ($grp === self::FRAG_KEY) {
                    $fragCategoryIds[] = $c->id;
                    $fragCategoryRaw[$c->id] = $c->classification_code;
                }
            }
            Log::info('[COMMISSION] Frag mapping debug', [
                'budget_id' => $budgetId,
                'frag_category_ids' => $fragCategoryIds,
                'frag_category_raw_codes' => $fragCategoryRaw
            ]);

            // 3) assigned_turns (en conexión 'budget')
            $assignedTurnsByUser = [];
            if (!empty($userIds)) {
                $assignedRows = $this->budgetDB()->table('budget_user_turns')
                    ->where('budget_id', $budgetId)
                    ->whereIn('user_id', array_keys($userIds))
                    ->pluck('assigned_turns', 'user_id'); // [user_id => assigned_turns]

                foreach ($userIds as $uid => $_) {
                    $assignedTurnsByUser[$uid] = (int)($assignedRows[$uid] ?? 0);
                }
            }

            // 4) pctUserByGroup (usar categoryGroupMap)
            $pctUserByGroup = [];
            foreach ($assignedTurnsByUser as $uid => $assigned) {
                $userBudgetUsd = $totalTurns > 0 ? round($budget->target_amount * ($assigned / $totalTurns), 2) : 0.0;

                foreach ($categoryGroupMap as $grp => $meta) {
                    $participation = $meta['participation_pct'] ?? 0;
                    $categoryBudgetForUser = $userBudgetUsd * ($participation / 100);

                    $salesUsd = $salesByUserGroup[$uid][$grp]['sales_usd'] ?? 0.0;
                    $salesCop = $salesByUserGroup[$uid][$grp]['sales_cop'] ?? 0.0;

                    if ($categoryBudgetForUser > 0) {
                        $pctVal = round(($salesUsd / $categoryBudgetForUser) * 100, 2);
                    } else {
                        $pctVal = null;
                    }
                    $pctUserByGroup[$uid][$grp] = [
                        'pct' => $pctVal,
                        'category_budget_for_user' => $categoryBudgetForUser,
                        'sales_usd' => $salesUsd,
                        'sales_cop' => $salesCop
                    ];
                }
            }

            // DEBUG: log resumido para inspección rápida
            Log::info('[COMMISSION] Debug snapshot', [
                'budget_id' => $budgetId,
                'users_count' => count($userIds),
                'sample_userIds' => array_slice(array_keys($userIds), 0, 6),
                'category_groups' => array_keys($categoryGroupMap),
                'assignedTurnsByUser_sample' => array_slice($assignedTurnsByUser, 0, 8)
            ]);

            // 5) build ratesByGroupByRole and rules map
            $allCategoryIds = collect($categoriesWithParticipation)->pluck('id')->all();
            if (empty($allCategoryIds)) {
                // si no hay categories en este budget, no hay nada que hacer
                Log::warning('[COMMISSION] No categories found for budget', ['budget_id' => $budgetId]);
                $conn->commit();
                return [
                    'status' => 'ok',
                    'users_processed' => 0,
                    'total_sales_usd' => round($totalUsd, 2),
                    'total_sales_cop' => round($totalCop, 2),
                    'pct' => round($pct, 2),
                    'is_provisional' => $isProvisional,
                ];
            }

            // IMPORTANT: filtrar CategoryCommission por budget_id (FIX) usando conexión 'budget'
            $categoryCommissions = $this->budgetDB()->table('category_commissions')
                ->whereIn('category_id', $allCategoryIds)
                ->where('budget_id', $budgetId)
                ->get();

            // map category_id -> group
            $categoryIdToGroup = [];
            foreach ($categoriesWithParticipation as $c) {
                $categoryIdToGroup[$c->id] = $this->normalizeClassification($c->classification_code);
            }

            $ratesByGroupByRole = []; // [role_id][group] => ['base','pct100','pct120']
            $ruleByRoleCategory = [];  // [role_id][category_id] => ruleRow (stdClass)
            foreach ($categoryCommissions as $row) {
                $roleId = (int)$row->role_id;
                $catId = (int)$row->category_id;
                $group = $categoryIdToGroup[$catId] ?? null;
            
                if (!$group) continue;



                // store rule row for potential rule selection
                $ruleByRoleCategory[$roleId][$catId] = $row;

                if (!isset($ratesByGroupByRole[$roleId][$group])) {
                    $ratesByGroupByRole[$roleId][$group] = [
                        'base' => $row->commission_percentage,
                        'pct100' => $row->commission_percentage100,
                        'pct120' => $row->commission_percentage120,
                    ];
                } else {
                    // prefer most generous per slot
                    foreach (['base','pct100','pct120'] as $k) {
                        $val = $k === 'base' ? $row->commission_percentage : ($k === 'pct100' ? $row->commission_percentage100 : $row->commission_percentage120);
                        if (!is_null($val) && (is_null($ratesByGroupByRole[$roleId][$group][$k]) || $val > $ratesByGroupByRole[$roleId][$group][$k])) {
                            $ratesByGroupByRole[$roleId][$group][$k] = $val;
                        }
                    }
                }
            }

            // DEBUG: verificación rápida de reglas
            Log::info('[COMMISSION] Rules snapshot', [
                'budget_id' => $budgetId,
                'categories_count' => count($allCategoryIds),
                'category_commissions_count' => $categoryCommissions->count(),
                'rates_preview' => array_slice($ratesByGroupByRole, 0, 6)
            ]);

            // 6) compute and upsert (usar conexión 'budget' para upserts)
            $usersProcessed = [];
            foreach ($pctUserByGroup as $uid => $groups) {
                $userTotalsSalesUsd = 0.0;
                $userTotalsSalesCop = 0.0;
                $userTotalsCommissionCop = 0.0;

                $userModel = User::find($uid); // User en conexión app por defecto
                $userRole = $this->resolveRoleModelForUserAtDate($userModel, $budget->end_date ?? $budget->end_date);
                $roleId = $userRole ? (int)$userRole->id : null;

                foreach ($groups as $grp => $entry) {
                    $salesUsd = (float)($entry['sales_usd'] ?? 0.0);
                    $salesCop = (float)($entry['sales_cop'] ?? 0.0);
                    $pctUser = $entry['pct'];

                    // find rates for role+group
                    $rates = $roleId ? ($ratesByGroupByRole[$roleId][$grp] ?? null) : null;
                    if (!$rates) {
                        // fallback: find any rule for a category in the group for this role
                        $possibleCatIds = $categoryGroupMap[$grp]['category_ids'] ?? [];
                        $foundRule = null;
                        foreach ($possibleCatIds as $cid) {
                            if (isset($ruleByRoleCategory[$roleId][$cid])) {
                                $foundRule = $ruleByRoleCategory[$roleId][$cid];
                                break;
                            }
                        }
                        if ($foundRule) {
                            $rates = [
                                'base' => $foundRule->commission_percentage,
                                'pct100' => $foundRule->commission_percentage100,
                                'pct120' => $foundRule->commission_percentage120,
                            ];
                        }
                    }

                    if (!$rates) {
                        Log::debug('[COMMISSION] no rates for role+group', ['budget_id' => $budgetId, 'user_id' => $uid, 'group' => $grp]);
                        $appliedPct = 0.0;
                    } else {
                        $appliedPct = 0.0;
                        if (!is_null($pctUser) && $pctUser >= $this->MIN_PCT_TO_QUALIFY) {
                            Log::info('DEBUG PCT USER', [
    'user_id' => $uid,
    'group' => $grp,
    'sales_usd' => $salesUsd,
    'category_budget_for_user' => $entry['category_budget_for_user'],
    'pctUser' => $pctUser
]);

                            if ($pctUser >= 120) {
                                $appliedPct = $rates['pct120'] ?? $rates['pct100'] ?? $rates['base'] ?? 0.0;
                            } elseif ($pctUser >= 100) {
                                $appliedPct = $rates['pct100'] ?? $rates['base'] ?? 0.0;
                            } else {
                                $appliedPct = $rates['base'] ?? 0.0;
                            }
                        } // else remains 0
                    }

                    // compute commission_cop (usar trm global si hace falta)
                    $commissionCop = 0.0;
                    if ($salesCop > 0) {
                        $commissionCop = round($salesCop * ((float)$appliedPct / 100), 2);
                    } elseif ($salesUsd > 0) {
                        $trmGlobal = ($totalUsd > 0 && $totalCop > 0) ? ($totalCop / $totalUsd) : null;
                        if ($trmGlobal && $trmGlobal > 0) {
                            $commissionCop = round(($salesUsd * ((float)$appliedPct / 100)) * $trmGlobal, 2);
                        } else {
                            $commissionCop = 0.0;
                        }
                    }

                    // upsert into budget_user_category_totals (connection 'budget')
                    $this->budgetDB()->table('budget_user_category_totals')->updateOrInsert(
                        [
                            'budget_id' => $budgetId,
                            'user_id' => $uid,
                            'category_group' => $grp,
                        ],
                        [
                            'sales_usd' => $salesUsd,
                            'sales_cop' => $salesCop,
                            'commission_cop' => $commissionCop,
                            'applied_pct' => $appliedPct,
                            'updated_at' => now(),
                        ]
                    );

                    $userTotalsSalesUsd += $salesUsd;
                    $userTotalsSalesCop += $salesCop;
                    $userTotalsCommissionCop += $commissionCop;
                } // end groups for user

                // upsert totals in budget_user_totals (connection 'budget')
                $this->budgetDB()->table('budget_user_totals')->updateOrInsert(
                    ['budget_id' => $budgetId, 'user_id' => $uid],
                    [
                        'total_sales_usd' => $userTotalsSalesUsd,
                        'total_sales_cop' => $userTotalsSalesCop,
                        'total_commission_cop' => $userTotalsCommissionCop,
                        'updated_at' => now(),
                    ]
                );

                $usersProcessed[] = $uid;
            } // end users loop

            $conn->commit();

            Log::info('[COMMISSION] Finished processBudgetForUsers (aggregated mode)', [
                'budget_id' => $budgetId,
                'users_processed' => count($usersProcessed),
                'pct' => round($pct,2),
                'is_provisional' => $isProvisional,
            ]);

            return [
                'status' => 'ok',
                'users_processed' => count($usersProcessed),
                'total_sales_usd' => round($totalUsd, 2),
                'total_sales_cop' => round($totalCop, 2),
                'pct' => round($pct, 2),
                'is_provisional' => $isProvisional,
            ];
        } catch (\Throwable $e) {
            $conn->rollBack();
            Log::error('[COMMISSION] Fatal error', [
                'budget_id' => $budgetId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Recalcula agregados para un usuario y presupuesto.
     * Usa conexiones 'budget' para borrar y volver a generar.
     */
    public function recalcForUserAndBudget(int $userId, int $budgetId): void
    {
        Log::info('[COMMISSION] Recalc aggregated for user+budget', ['user_id' => $userId, 'budget_id' => $budgetId]);

        $budget = $this->budgetDB()->table('budgets')->where('id', $budgetId)->first();
        if (!$budget) {
            Log::error('[COMMISSION] Recalc failed - budget not found', ['budget_id' => $budgetId]);
            throw new \RuntimeException("Budget {$budgetId} not found");
        }

        $conn = $this->budgetDB();
        $conn->beginTransaction();
        try {
            $conn->table('budget_user_category_totals')->where('budget_id', $budgetId)->where('user_id', $userId)->delete();
            $conn->table('budget_user_totals')->where('budget_id', $budgetId)->where('user_id', $userId)->delete();

            $result = $this->processBudgetForUsers($budget, [$userId]);

            Log::info('[COMMISSION] Recalc aggregated finished', ['result' => $result, 'budget_id' => $budgetId]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            Log::error('[COMMISSION] Recalc failed', [
                'user_id' => $userId,
                'budget_id' => $budgetId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ---------- Helpers ----------

    /**
     * Normaliza classification_code en un "grupo" seguro.
     */
    private function normalizeClassification($raw)
    {
        $raw = (string) ($raw ?? '');
        $raw = trim($raw);
        if ($raw === '') return 'sin_categoria';

        // eliminar acentos básicos para comparación
        $normalized = iconv('UTF-8', 'ASCII//TRANSLIT', $raw);
        $normalized = mb_strtolower(trim($normalized));

        // 1) intentar extraer un número (ej: "10", "10 - Fragancias", "010", "22a")
        if (preg_match('/\b(\d{1,5})\b/', $normalized, $m)) {
            $num = (int)$m[1];
            if (in_array($num, self::FRAG_CODES, true)) {
                return self::FRAG_KEY;
            }
            // devolver el número como string para agrupar por código (ej "22")
            return (string)$num;
        }

        // 2) fallback para valores textuales: solo aceptar nombres frag/perf exactos o muy controlados
        $acceptedFragNames = [
            'fragancias','fragancia','fragancias perfumeria','perfumeria','perfumeria fragancias',
            'fragancias/perfumeria','perfumeria','fragancias y perfumeria','perfumería'
        ];
        // normalizar variantes (sin acentos, espacios multiples)
        $clean = preg_replace('/\s+/', ' ', $normalized);

        if (in_array($clean, $acceptedFragNames, true)) {
            return self::FRAG_KEY;
        }

        // 3) devolver texto normalizado (usado como grupo) - sin caracteres especiales repetidos
        $clean = preg_replace('/[^a-z0-9\-_ ]+/', '', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return trim($clean);
    }

    /**
     * CASE SQL más estricto (se usa en queries a la BD 'budget').
     */
    private function getSqlClassificationCase(): string
    {
        $codes = implode('|', array_map('intval', self::FRAG_CODES));

        // patrón que detecta el código numérico como token o el número aislado dentro del campo
        $numRegexp = "(^|[^0-9])(?:{$codes})([^0-9]|$)";

        // patrón que detecta frag/perf como palabra (token) - reduce falsos positivos
        $wordRegexp = "(^|[^a-zA-Z0-9])(frag|perf|perfume|perfumeria)([^a-zA-Z0-9]|$)";

        // NOTA: la CASE devuelve el valor tal cual; luego normalizeClassification lo convertirá a 'fragancias' si aplica.
        return "CASE
            WHEN CAST(products.classification AS CHAR) REGEXP '{$numRegexp}' THEN '" . self::FRAG_KEY . "'
            WHEN LOWER(CAST(products.classification AS CHAR)) REGEXP '{$wordRegexp}' THEN '" . self::FRAG_KEY . "'
            ELSE TRIM(COALESCE(products.classification, 'sin_categoria'))
        END";
    }

    /**
     * Busca el role model activo del usuario en la fecha dada (si existe).
     */
    protected function resolveRoleModelForUserAtDate(User $user, $date)
    {
        if (!$user) return null;

        if (method_exists($user, 'roleAtDate')) {
            $r = $user->roleAtDate($date);
            if ($r instanceof \App\Models\Role) return $r;
            if ($r && isset($r->role)) return $r->role;
        }

        if (method_exists($user, 'roles')) {
            $pivot = $user->roles()->with('role')->orderByDesc('start_date')->first();
            if ($pivot && $pivot->role) return $pivot->role;
        }

        return $user->role ?? null;
    }
}
