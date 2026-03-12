<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class CommissionLideres extends Controller
{
    private $fallbackPct = [
        'type_a' => 0.0007,
        'type_b' => 0.00035,
    ];

    private $defaultConfig = [
        'type_a' => ['pct_80' => 0.0007, 'pct_100' => 0.0011, 'pct_120' => 0.0013],
        'type_b' => ['pct_80' => 0.00035, 'pct_100' => 0.00056, 'pct_120' => 0.00065],
    ];

    private function resolveBudgetRange($budgetId)
    {
        $budget = DB::connection('budget')
            ->table('budgets')
            ->where('id', $budgetId)
            ->first();

        if (!$budget) {
            return [null, null];
        }

        $startDate = $budget->start_date
            ? Carbon::parse($budget->start_date)->startOfDay()
            : null;

        $endDate = $budget->end_date
            ? Carbon::parse($budget->end_date)->endOfDay()
            : null;

        return [$startDate, $endDate];
    }

    // ---------- CRUD (index/store/update/destroy) ----------
    public function index(Request $request)
    {
        if (!Schema::connection('budget')->hasTable('commission_leaders')) {
            return response()->json(['data' => [], 'message' => 'No existe la tabla commission_leaders.'], 200);
        }
        $leaders = DB::connection('budget')->table('commission_leaders')->orderBy('id', 'desc')->get();
        return response()->json(['data' => $leaders]);
    }

    public function storeLeader(Request $request)
    {
        if (!Schema::connection('budget')->hasTable('commission_leaders')) {
            return response()->json(['error' => 'No existe tabla commission_leaders.'], 400);
        }

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'type' => 'required|in:type_a,type_b',
            'commission_pct' => 'nullable|numeric|min:0',
            'target' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $type = $request->input('type');
        $pct = $request->has('commission_pct') ? floatval($request->input('commission_pct')) : ($this->fallbackPct[$type] ?? 0);

        $insert = [
            'name' => $request->input('name'),
            'type' => $type,
            'commission_pct' => $pct,
            'target' => $request->input('target', 0),
            'notes' => $request->input('notes', null),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        $id = DB::connection('budget')->table('commission_leaders')->insertGetId($insert);
        return response()->json(['message' => 'Líder creado', 'id' => $id], 201);
    }

    public function updateLeader(Request $request, $id)
    {
        if (!Schema::connection('budget')->hasTable('commission_leaders')) {
            return response()->json(['error' => 'No existe tabla commission_leaders.'], 400);
        }

        $v = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:191',
            'type' => 'sometimes|required|in:type_a,type_b',
            'commission_pct' => 'nullable|numeric|min:0',
            'target' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $row = DB::connection('budget')->table('commission_leaders')->where('id', $id)->first();
        if (!$row) return response()->json(['error' => 'Líder no encontrado'], 404);

        $update = [];
        if ($request->has('name')) $update['name'] = $request->input('name');
        if ($request->has('type')) $update['type'] = $request->input('type');

        if ($request->has('commission_pct')) {
            $val = $request->input('commission_pct');
            $update['commission_pct'] = $val !== null ? floatval($val) : ($this->fallbackPct[$request->input('type', $row->type)] ?? 0);
        }

        if ($request->has('target')) $update['target'] = $request->input('target');
        if ($request->has('notes')) $update['notes'] = $request->input('notes');
        $update['updated_at'] = Carbon::now();

        DB::connection('budget')->table('commission_leaders')->where('id', $id)->update($update);
        return response()->json(['message' => 'Líder actualizado']);
    }

    public function destroyLeader($id)
    {
        if (!Schema::connection('budget')->hasTable('commission_leaders')) {
            return response()->json(['error' => 'No existe tabla commission_leaders.'], 400);
        }
        $deleted = DB::connection('budget')->table('commission_leaders')->where('id', $id)->delete();
        if (!$deleted) return response()->json(['error' => 'Líder no encontrado'], 404);
        return response()->json(['message' => 'Líder eliminado']);
    }

    // ---------- Ausencias ----------
    public function addAbsence(Request $request, $leaderId)
    {
        if (!Schema::connection('budget')->hasTable('commission_leader_absences')) {
            return response()->json(['error' => 'No existe tabla commission_leader_absences.'], 400);
        }

        $v = Validator::make($request->all(), [
            'absent_date' => 'required|date',
            'reason' => 'nullable|string|max:255',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $leader = DB::connection('budget')->table('commission_leaders')->where('id', $leaderId)->first();
        if (!$leader) return response()->json(['error' => 'Líder no encontrado'], 404);

        $date = Carbon::parse($request->input('absent_date'))->toDateString();

        try {
            $exists = DB::connection('budget')->table('commission_leader_absences')
                ->where('leader_id', $leaderId)
                ->whereDate('absent_date', $date)
                ->first();
            if ($exists) return response()->json(['error' => 'Ya existe una ausencia para esa fecha.'], 422);

            $id = DB::connection('budget')->table('commission_leader_absences')->insertGetId([
                'leader_id' => $leaderId,
                'absent_date' => $date,
                'reason' => $request->input('reason', null),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'No se pudo agregar ausencia.', 'detail' => $e->getMessage()], 400);
        }

        return response()->json(['message' => 'Ausencia agregada', 'id' => $id], 201);
    }

    public function listAbsences($leaderId)
    {
        if (!Schema::connection('budget')->hasTable('commission_leader_absences')) {
            return response()->json(['data' => []]);
        }
        $abs = DB::connection('budget')->table('commission_leader_absences')->where('leader_id', $leaderId)->orderBy('absent_date', 'desc')->get();
        return response()->json(['data' => $abs]);
    }

    public function deleteAbsence($leaderId, $absenceId)
    {
        if (!Schema::connection('budget')->hasTable('commission_leader_absences')) {
            return response()->json(['error' => 'No existe tabla de ausencias.'], 400);
        }
        $deleted = DB::connection('budget')->table('commission_leader_absences')->where('leader_id', $leaderId)->where('id', $absenceId)->delete();
        if (!$deleted) return response()->json(['error' => 'Ausencia no encontrada'], 404);
        return response()->json(['message' => 'Ausencia eliminada']);
    }

    // ---------- Config ----------
    public function getConfig()
    {
        if (!Schema::connection('budget')->hasTable('commission_leader_config')) {
            return response()->json($this->defaultConfig);
        }
        $row = DB::connection('budget')->table('commission_leader_config')->orderBy('id','desc')->first();
        if (!$row || empty($row->config)) return response()->json($this->defaultConfig);
        $cfg = json_decode($row->config, true);
        return response()->json(is_array($cfg) ? $cfg : $this->defaultConfig);
    }

    public function saveConfig(Request $request)
    {
        $v = Validator::make($request->all(), [
            'type_a.pct_80' => 'required|numeric|min:0',
            'type_a.pct_100' => 'required|numeric|min:0',
            'type_a.pct_120' => 'required|numeric|min:0',
            'type_b.pct_80' => 'required|numeric|min:0',
            'type_b.pct_100' => 'required|numeric|min:0',
            'type_b.pct_120' => 'required|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        $cfg = [
            'type_a' => [
                'pct_80' => floatval($request->input('type_a.pct_80')),
                'pct_100' => floatval($request->input('type_a.pct_100')),
                'pct_120' => floatval($request->input('type_a.pct_120')),
            ],
            'type_b' => [
                'pct_80' => floatval($request->input('type_b.pct_80')),
                'pct_100' => floatval($request->input('type_b.pct_100')),
                'pct_120' => floatval($request->input('type_b.pct_120')),
            ]
        ];

        if (!Schema::connection('budget')->hasTable('commission_leader_config')) {
            return response()->json(['message' => 'Tabla commission_leader_config no existe, configuración no persistida.', 'config' => $cfg]);
        }

        $id = DB::connection('budget')->table('commission_leader_config')->insertGetId([
            'config' => json_encode($cfg),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json(['message' => 'Configuración guardada', 'id' => $id, 'config' => $cfg]);
    }

    // ---------- Helpers internos ----------
    protected function normalizeKey($k) { return strtoupper(trim((string)$k)); }

    /**
     * Sum excluded sales for a PDV on certain dates.
     * If $pdvKey is null or 'ALL' it sums across all PDVs.
     */
   protected function sumExcludedForPdvAndDates(?string $pdvKey, array $dates, $budgetId = null, $dateFrom = null, $dateTo = null)
{
    if (empty($dates)) return 0;

    // normalize date range
    if ($dateFrom && $dateTo) {
        try {
            $start = Carbon::parse($dateFrom)->startOfDay();
            $end = Carbon::parse($dateTo)->endOfDay();
        } catch (\Exception $e) {
            $start = $end = null;
        }
    } else {
        [$start, $end] = $this->resolveBudgetRange($budgetId);
    }

    // Build PDV candidate keys so we match both 'DEPARTURES' <-> 'COLS1' etc.
    $candidates = [];
    if ($pdvKey === null || strtoupper(trim($pdvKey)) === 'ALL') {
        // leave empty -> match all PDVs
        $candidates = [];
    } else {
        $k = $this->normalizeKey($pdvKey);
        $candidates[] = $k;
        // common mappings
        if ($k === 'DEPARTURES') $candidates[] = 'COLS1';
        if ($k === 'ARRIVALS') $candidates[] = 'COLS2';
        if ($k === 'COLS1') $candidates[] = 'DEPARTURES';
        if ($k === 'COLS2') $candidates[] = 'ARRIVALS';
    }

    $q = DB::connection('budget')
        ->table('sales')
        ->selectRaw("
            SUM(
                COALESCE(
                    value_usd,
                    (CASE WHEN exchange_rate IS NOT NULL AND exchange_rate>0 
                        THEN amount/exchange_rate END),
                    amount
                )
            ) as excluded
        ");

    // apply pdv filter if candidates exist
    if (count($candidates) > 0) {
        $q->where(function($sub) use ($candidates) {
            foreach ($candidates as $c) {
                $sub->orWhereRaw('UPPER(TRIM(COALESCE(pdv, \'\'))) = ?', [$c]);
            }
        });
    } else {
        // no pdv filter -> allow all
    }

    if (isset($start) && isset($end)) {
        $q->whereBetween('sale_date', [
            $start->toDateTimeString(),
            $end->toDateTimeString()
        ]);
    }

    $datesNormalized = array_map(function($d){
        return Carbon::parse($d)->toDateString();
    }, $dates);

    $q->where(function($sub) use ($datesNormalized) {
        foreach ($datesNormalized as $d) {
            $sub->orWhereDate('sale_date', $d);
        }
    });

    try {
        return floatval($q->value('excluded') ?? 0);
    } catch (\Exception $e) {
        return 0;
    }
}

    /**
     * Compute TRM average for a date range.
     * Strategy: avg(exchange_rate) where exchange_rate>0
     * Fallback: avg(amount/value_usd) when value_usd>0
     */
    protected function computeTrmAverage($dateFrom = null, $dateTo = null)
    {
        if (!Schema::connection('budget')->hasTable('sales')) return null;

        [$start, $end] = [null, null];
        if ($dateFrom && $dateTo) {
            try {
                $start = Carbon::parse($dateFrom)->startOfDay();
                $end = Carbon::parse($dateTo)->endOfDay();
            } catch (\Exception $e) {
                $start = $end = null;
            }
        }

        $q = DB::connection('budget')->table('sales')
            ->selectRaw("
                AVG(
                  NULLIF(
                    CASE
                      WHEN exchange_rate IS NOT NULL AND exchange_rate > 0 THEN exchange_rate
                      WHEN value_usd IS NOT NULL AND value_usd > 0 AND amount IS NOT NULL AND amount > 0 THEN amount / value_usd
                      ELSE NULL
                    END
                  , 0)
                ) as trm_avg
            ");

        if ($start && $end) {
            $q->whereBetween('sale_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
        }

        try {
            $row = $q->first();
            $val = $row->trm_avg ?? null;
            return is_numeric($val) && $val > 0 ? floatval($val) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ---------- Cálculo comisiones (principal) ----------
    public function calculateCommissions(Request $request)
    {
        $payload = array_merge($request->query(), $request->all());

        $v = Validator::make($payload, [
            'budget_id' => 'nullable|integer',
            'budget_amount' => 'nullable|numeric|min:0',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'arrivals_pct' => 'nullable|numeric|min:0|max:100',
            'departures_pct' => 'nullable|numeric|min:0|max:100',
            'leaders' => 'nullable|array',
            'leaders.*.name' => 'required_with:leaders|string',
            'leaders.*.type' => 'required_with:leaders|in:type_a,type_b',
            'leaders.*.commission_pct' => 'nullable|numeric|min:0',
            'leaders.*.absences' => 'nullable|array',
            'persist' => 'nullable|boolean',
            'trm_avg' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        // dates
        $budgetId = $payload['budget_id'] ?? null;
        $dateFromInput = $payload['date_from'] ?? null;
        $dateToInput = $payload['date_to'] ?? null;

        if ($budgetId && (!$dateFromInput || !$dateToInput)) {
            if (Schema::connection('budget')->hasTable('budgets')) {
                $b = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
                if ($b) {
                    $dateFromInput = $dateFromInput ?: ($b->start_date ?? null);
                    $dateToInput = $dateToInput ?: ($b->end_date ?? null);
                }
            }
        }

        $dateFrom = $dateFromInput ? Carbon::parse($dateFromInput)->startOfDay() : null;
        $dateTo = $dateToInput ? Carbon::parse($dateToInput)->endOfDay() : null;

        $budgetAmountOverride = isset($payload['budget_amount']) ? $payload['budget_amount'] : null;

        $arrivalsPct = $payload['arrivals_pct'] ?? null;
        $departuresPct = $payload['departures_pct'] ?? null;

        if ($budgetId && ($arrivalsPct === null || $departuresPct === null)) {
            $split = DB::connection('budget')
                ->table('budget_store_split')
                ->where('budget_id', $budgetId)
                ->first();

            if ($split) {
                $arrivalsPct = $split->arrivals_pct;
                $departuresPct = $split->departures_pct;
            }
        }

        $arrivalsPct = $arrivalsPct ?? 60;
        $departuresPct = $departuresPct ?? 40;

        $trmAvgInput = $payload['trm_avg'] ?? null;
        $trmProvided = is_numeric($trmAvgInput) && $trmAvgInput > 0 ? floatval($trmAvgInput) : null;

        // If not provided, try compute from sales in range
        if ($trmProvided === null) {
            $computed = $this->computeTrmAverage($dateFrom ? $dateFrom->toDateString() : null, $dateTo ? $dateTo->toDateString() : null);
            if ($computed !== null) $trmProvided = $computed;
        }

        // obtain budget amount
        $budgetAmount = null;
        if ($budgetAmountOverride !== null) {
            $budgetAmount = floatval($budgetAmountOverride);
        } elseif ($budgetId) {
            if (Schema::connection('budget')->hasTable('budgets')) {
                $b = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();
                if ($b) $budgetAmount = floatval($b->target_amount ?? $b->total ?? $b->amount ?? 0);
            }
        }

        // leaders (body or persisted)
        $leaders = $payload['leaders'] ?? null;
        $persist = (bool)($payload['persist'] ?? false);

        if (!$leaders && $persist) {
            if (!Schema::connection('budget')->hasTable('commission_leaders')) {
                return response()->json(['error' => 'No existe tabla commission_leaders para persistir.'], 400);
            }
            $rows = DB::connection('budget')->table('commission_leaders')->get();
            $leaders = $rows->map(function ($r) {
                return [
                    'id' => $r->id,
                    'name' => $r->name,
                    'type' => $r->type,
                    'commission_pct' => isset($r->commission_pct) ? floatval($r->commission_pct) : null,
                    'pdvs' => ['DEPARTURES','ARRIVALS'],
                    'target' => floatval($r->target ?? 0),
                    'notes' => $r->notes ?? null,
                    'absences' => [],
                ];
            })->toArray();
        }

        if (!$leaders || !is_array($leaders) || count($leaders) === 0) {
            return response()->json([
                'error' => 'No se proporcionaron líderes (leaders) y persist != true o no hay líderes guardados.',
                'hint' => 'Envía leaders en el body o guarda líderes en la tabla y usa persist=true'
            ], 400);
        }

        // normalize leaders
        $leadersNormalized = array_map(function ($l) {
            $type = $l['type'] ?? 'type_b';
            return [
                'id' => $l['id'] ?? null,
                'name' => $l['name'] ?? ('Líder ' . uniqid()),
                'type' => in_array($type, ['type_a', 'type_b']) ? $type : 'type_b',
                'commission_pct' => isset($l['commission_pct']) ? floatval($l['commission_pct']) : null,
                'pdvs' => ['DEPARTURES','ARRIVALS'],
                'target' => isset($l['target']) ? floatval($l['target']) : 0,
                'notes' => $l['notes'] ?? null,
                'absences' => isset($l['absences']) && is_array($l['absences']) ? array_values(array_map(function($d){ return Carbon::parse($d)->toDateString(); }, $l['absences'])) : [],
            ];
        }, $leaders);

        // read config
        $config = $this->defaultConfig;
        if (Schema::connection('budget')->hasTable('commission_leader_config')) {
            $row = DB::connection('budget')->table('commission_leader_config')->orderBy('id','desc')->first();
            if ($row && !empty($row->config)) {
                $decoded = json_decode($row->config, true);
                if (is_array($decoded)) $config = array_replace_recursive($config, $decoded);
            }
        }

        // --- Totales por PDV (ventas) ---
        $perStore = [];
        if (Schema::connection('budget')->hasTable('sales')) {
            $salesQuery = DB::connection('budget')->table('sales')
                ->selectRaw("UPPER(TRIM(COALESCE(pdv,''))) AS pdv_key,
                    SUM(
                        COALESCE(
                            value_usd,
                            (CASE WHEN exchange_rate IS NOT NULL AND exchange_rate>0 THEN amount / exchange_rate ELSE NULL END),
                            amount
                        )
                    ) AS total_sales")
                ->whereRaw("COALESCE(pdv, '') <> ''");

            if ($budgetId) {
                [$startDate, $endDate] = $this->resolveBudgetRange($budgetId);

                if ($startDate && $endDate) {
                    $salesQuery->whereBetween('sale_date', [
                        $startDate->toDateTimeString(),
                        $endDate->toDateTimeString()
                    ]);
                }
            } elseif ($dateFrom && $dateTo) {
                $salesQuery->whereBetween('sale_date', [$dateFrom->toDateTimeString(), $dateTo->toDateTimeString()]);
            }

            $salesQuery->groupBy(DB::raw("UPPER(TRIM(COALESCE(pdv,'')))"));

            try {
                $rows = $salesQuery->get();
                foreach ($rows as $r) {
                    $perStore[(string)$r->pdv_key] = floatval($r->total_sales ?? 0);
                }
            } catch (\Exception $e) {
                $perStore = [];
            }
        }

        // normalize keys
        $perStoreNormalized = [];
        foreach ($perStore as $k => $v) {
            $perStoreNormalized[$this->normalizeKey($k)] = floatval($v);
        }

        // mapping
        $cols1 = $perStoreNormalized['COLS1'] ?? 0.0;
        $cols2 = $perStoreNormalized['COLS2'] ?? 0.0;
        $perStoreNormalized['COLS1'] = $cols1;
        $perStoreNormalized['COLS2'] = $cols2;
        $perStoreNormalized['DEPARTURES'] = $perStoreNormalized['DEPARTURES'] ?? $perStoreNormalized['COLS1'] ?? 0.0;
        $perStoreNormalized['ARRIVALS']   = $perStoreNormalized['ARRIVALS'] ?? $perStoreNormalized['COLS2'] ?? 0.0;

        // store targets
        $storeTargets = ['DEPARTURES' => null, 'ARRIVALS' => null, 'COLS1' => null, 'COLS2' => null];
        if ($budgetAmount !== null) {
            $storeTargets['COLS1'] = round(($budgetAmount * floatval($departuresPct) / 100), 2);
            $storeTargets['COLS2'] = round(($budgetAmount * floatval($arrivalsPct) / 100), 2);
            $storeTargets['DEPARTURES'] = $storeTargets['COLS1'];
            $storeTargets['ARRIVALS'] = $storeTargets['COLS2'];
        }

        // perStoreResult with pct & bracket
        $perStoreResult = [];
        foreach (['DEPARTURES','ARRIVALS','COLS1','COLS2'] as $pdvKey) {
            $tot = floatval($perStoreNormalized[$pdvKey] ?? 0);
            $target = $storeTargets[$pdvKey] ?? null;
            $pctAchieved = ($target && $target > 0) ? ($tot / $target) * 100 : null;
            $bracket = 'lt80';
            if ($pctAchieved !== null) {
                if ($pctAchieved >= 120) $bracket = 'gte120';
                elseif ($pctAchieved >= 100) $bracket = '100_119';
                elseif ($pctAchieved >= 80) $bracket = '80_99';
                else $bracket = 'lt80';
            }
            $perStoreResult[$pdvKey] = [
                'pdv' => $pdvKey,
                'total_sales' => round($tot, 2),
                'target' => $target !== null ? round($target, 2) : null,
                'pct_achieved' => $pctAchieved !== null ? round($pctAchieved, 2) : null,
                'bracket' => $bracket,
                'meets_target' => ($pctAchieved !== null) ? ($pctAchieved >= 80) : false,
                'commission_total_usd' => 0.0,
                'commission_total_cop' => $trmProvided ? 0.0 : null,
            ];
        }

        // absences persisted
        $absencesByLeader = [];
        if ($persist && Schema::connection('budget')->hasTable('commission_leader_absences')) {
            $leaderIds = array_values(array_filter(array_map(function($l){ return $l['id'] ?? null; }, $leadersNormalized)));
            if (count($leaderIds)) {
                $rows = DB::connection('budget')->table('commission_leader_absences')
                    ->whereIn('leader_id', $leaderIds)
                    ->get();
                foreach ($rows as $r) {
                    $absencesByLeader[$r->leader_id][] = Carbon::parse($r->absent_date)->toDateString();
                }
            }
        }

        // --- Calcular por líder (separado departures / arrivals y total) ---
        $perLeader = [];
        foreach ($leadersNormalized as $leader) {
            $leaderPdvs = ['DEPARTURES','ARRIVALS'];

            $leaderTotals = [
                'sales_departures' => 0.0,
                'sales_arrivals' => 0.0,
                'sales_total' => 0.0,
                'excluded_departures' => 0.0,
                'excluded_arrivals' => 0.0,
                'excluded_total' => 0.0,
                'effective_departures' => 0.0,
                'effective_arrivals' => 0.0,
                'effective_total' => 0.0,
                'commission_usd_departures' => 0.0,
                'commission_usd_arrivals' => 0.0,
                'commission_usd_total' => 0.0,
            ];

            $detailPdvs = [];

            // combine absences
            $absDates = $leader['absences'] ?? [];
            if ($persist && $leader['id'] && isset($absencesByLeader[$leader['id']])) {
                $absDates = array_values(array_unique(array_merge($absDates, $absencesByLeader[$leader['id']])));
            }

            foreach ($leaderPdvs as $pdvKey) {
                $pdvTotal = floatval($perStoreNormalized[$pdvKey] ?? 0);
                $pdvTarget = $storeTargets[$pdvKey] ?? null;
                $pdvPct = ($pdvTarget && $pdvTarget > 0) ? ($pdvTotal / $pdvTarget) * 100 : null;

                $storeBracket = $perStoreResult[$pdvKey]['bracket'] ?? 'lt80';
                $storePctAchieved = $perStoreResult[$pdvKey]['pct_achieved'] ?? $pdvPct;

                $excludedForThisPdv = 0.0;
                if (!empty($absDates) && Schema::connection('budget')->hasTable('sales')) {
                    $excludedForThisPdv = $this->sumExcludedForPdvAndDates($pdvKey, $absDates, $budgetId, $dateFrom ? $dateFrom->toDateString() : null, $dateTo ? $dateTo->toDateString() : null);
                }

                $pdvEffective = max(0, $pdvTotal - $excludedForThisPdv);

                // decide pct
                $pctToApply = 0.0;
                if ($storePctAchieved !== null && $storePctAchieved >= 80) {
                    if ($storePctAchieved >= 120) $pctKey = 'pct_120';
                    elseif ($storePctAchieved >= 100) $pctKey = 'pct_100';
                    else $pctKey = 'pct_80';
                    $pctToApply = $config[$leader['type']][$pctKey] ?? ($leader['commission_pct'] ?? ($this->fallbackPct[$leader['type']] ?? 0));
                } else {
                    $pctToApply = 0.0;
                }

                $pdvCommissionUsd = $pdvEffective * floatval($pctToApply);

                // Accumulate per-type
                if ($pdvKey === 'DEPARTURES') {
                    $leaderTotals['sales_departures'] += $pdvTotal;
                    $leaderTotals['excluded_departures'] += $excludedForThisPdv;
                    $leaderTotals['effective_departures'] += $pdvEffective;
                    $leaderTotals['commission_usd_departures'] += $pdvCommissionUsd;
                } else {
                    $leaderTotals['sales_arrivals'] += $pdvTotal;
                    $leaderTotals['excluded_arrivals'] += $excludedForThisPdv;
                    $leaderTotals['effective_arrivals'] += $pdvEffective;
                    $leaderTotals['commission_usd_arrivals'] += $pdvCommissionUsd;
                }

                $detailPdvs[$pdvKey] = [
                    'pdv' => $pdvKey,
                    'total_sales' => round($pdvTotal, 2),
                    'target' => $pdvTarget !== null ? round($pdvTarget, 2) : null,
                    'pct_achieved' => $storePctAchieved !== null ? round($storePctAchieved, 2) : null,
                    'bracket' => $storeBracket,
                    'excluded_by_absences' => round($excludedForThisPdv, 2),
                    'effective_sales' => round($pdvEffective, 2),
                    'commission_pct_applied' => round($pctToApply, 8),
                    'commission_usd' => round($pdvCommissionUsd, 2),
                    'commission_cop' => $trmProvided ? round($pdvCommissionUsd * $trmProvided, 2) : null,
                ];
            }

            // totals
            $leaderTotals['sales_total'] = $leaderTotals['sales_departures'] + $leaderTotals['sales_arrivals'];
            $leaderTotals['excluded_total'] = $leaderTotals['excluded_departures'] + $leaderTotals['excluded_arrivals'];
            $leaderTotals['effective_total'] = $leaderTotals['effective_departures'] + $leaderTotals['effective_arrivals'];
            $leaderTotals['commission_usd_total'] = $leaderTotals['commission_usd_departures'] + $leaderTotals['commission_usd_arrivals'];

            // prepare final leader row
            $perLeader[] = [
                'id' => $leader['id'] ?? null,
                'identification' => $leader['id'] ?? null,
                'name' => $leader['name'],
                'type' => $leader['type'],
                'pdvs' => ['DEPARTURES','ARRIVALS'],
                'detail_pdvs' => $detailPdvs,
                // separated numbers for UI convenience
                'sales_departures' => round($leaderTotals['sales_departures'], 2),
                'sales_arrivals' => round($leaderTotals['sales_arrivals'], 2),
                'sales_total' => round($leaderTotals['sales_total'], 2),
                'excluded_departures' => round($leaderTotals['excluded_departures'], 2),
                'excluded_arrivals' => round($leaderTotals['excluded_arrivals'], 2),
                'excluded_total' => round($leaderTotals['excluded_total'], 2),
                'effective_departures' => round($leaderTotals['effective_departures'], 2),
                'effective_arrivals' => round($leaderTotals['effective_arrivals'], 2),
                'effective_total' => round($leaderTotals['effective_total'], 2),
                'commission_usd_departures' => round($leaderTotals['commission_usd_departures'], 2),
                'commission_usd_arrivals' => round($leaderTotals['commission_usd_arrivals'], 2),
                'commission_usd_total' => round($leaderTotals['commission_usd_total'], 2),
                'commission_cop_total' => $trmProvided ? round($leaderTotals['commission_usd_total'] * $trmProvided, 2) : null,
                'commission_config_used' => $config[$leader['type']] ?? null,
                'absences' => array_values($leader['absences'] ?? []),
                'notes' => $leader['notes'] ?? null,
            ];
        }

        // --- Agregar comisión total por tienda ---
        $storeCommissionTotalsUsd = [
            'DEPARTURES' => 0.0,
            'ARRIVALS' => 0.0,
            'COLS1' => 0.0,
            'COLS2' => 0.0,
        ];
        foreach ($perLeader as $pl) {
            foreach ($pl['detail_pdvs'] as $pdvKey => $pdvRow) {
                if (!isset($storeCommissionTotalsUsd[$pdvKey])) $storeCommissionTotalsUsd[$pdvKey] = 0.0;
                $storeCommissionTotalsUsd[$pdvKey] += floatval($pdvRow['commission_usd'] ?? 0);
            }
        }

        foreach (['DEPARTURES','ARRIVALS','COLS1','COLS2'] as $pdvKey) {
            $perStoreResult[$pdvKey]['commission_total_usd'] = round($storeCommissionTotalsUsd[$pdvKey] ?? 0.0, 2);
            $perStoreResult[$pdvKey]['commission_total_cop'] = $trmProvided ? round(($storeCommissionTotalsUsd[$pdvKey] ?? 0.0) * $trmProvided, 2) : null;
        }

        // --- Totales generales ---
        $totalCommissionUsd = 0.0;
        foreach ($perLeader as $pl) $totalCommissionUsd += floatval($pl['commission_usd_total'] ?? $pl['commission_usd'] ?? 0);
        $totalCommissionUsd = round($totalCommissionUsd, 2);
        $totalCommissionCop = $trmProvided ? round($totalCommissionUsd * $trmProvided, 2) : null;

        // --- Sales summary ---
        $departuresPres = $storeTargets['DEPARTURES'] ?? null;
        $arrivalsPres   = $storeTargets['ARRIVALS'] ?? null;
        $departuresReal = $perStoreResult['DEPARTURES']['total_sales'] ?? 0;
        $arrivalsReal   = $perStoreResult['ARRIVALS']['total_sales'] ?? 0;

        $salesSummary = [
            'DEPARTURES' => [
                'presupuesto' => $departuresPres,
                'real' => $departuresReal,
                'pct_achieved' => ($departuresPres && $departuresPres > 0) ? round(($departuresReal / $departuresPres) * 100, 2) : null,
            ],
            'ARRIVALS' => [
                'presupuesto' => $arrivalsPres,
                'real' => $arrivalsReal,
                'pct_achieved' => ($arrivalsPres && $arrivalsPres > 0) ? round(($arrivalsReal / $arrivalsPres) * 100, 2) : null,
            ],
            'TOTAL' => [
                'presupuesto' => ($departuresPres ?? 0) + ($arrivalsPres ?? 0),
                'real' => $departuresReal + $arrivalsReal,
                'pct_achieved' => ((($departuresPres ?? 0) + ($arrivalsPres ?? 0)) > 0) ? round((($departuresReal + $arrivalsReal) / (($departuresPres ?? 0) + ($arrivalsPres ?? 0))) * 100, 2) : null,
            ],
        ];

        // ---------- Tables by type (unchanged) ----------
        $tablesByType = [
            'type_a' => ['rows' => [], 'totals_usd' => 0.0, 'totals_cop' => 0.0],
            'type_b' => ['rows' => [], 'totals_usd' => 0.0, 'totals_cop' => 0.0],
        ];

        foreach ($perLeader as $pl) {
            $type = $pl['type'] ?? 'type_b';
            $detail = $pl['detail_pdvs'] ?? [];
            foreach (['DEPARTURES','ARRIVALS','COLS1','COLS2'] as $pdvKey) {
                if (isset($detail[$pdvKey])) {
                    $row = [
                        'identification' => $pl['identification'] ?? null,
                        'name' => $pl['name'],
                        'pdv' => $pdvKey,
                        'commission_usd' => $detail[$pdvKey]['commission_usd'],
                        'trm_avg' => $trmProvided,
                        'commission_cop' => $detail[$pdvKey]['commission_cop'],
                    ];
                    $tablesByType[$type]['rows'][] = $row;
                    $tablesByType[$type]['totals_usd'] += floatval($detail[$pdvKey]['commission_usd'] ?? 0);
                    $tablesByType[$type]['totals_cop'] += floatval($detail[$pdvKey]['commission_cop'] ?? 0);
                }
            }
        }

        foreach (['type_a','type_b'] as $t) {
            $tablesByType[$t]['totals_usd'] = round($tablesByType[$t]['totals_usd'], 2);
            $tablesByType[$t]['totals_cop'] = $trmProvided ? round($tablesByType[$t]['totals_cop'], 2) : null;
        }

        // Build final response
        return response()->json([
            'meta' => [
                'budget_id' => $budgetId,
                'budget_amount' => $budgetAmount !== null ? round($budgetAmount, 2) : null,
                'arrivals_pct' => floatval($arrivalsPct),
                'departures_pct' => floatval($departuresPct),
                'date_from' => $dateFrom ? $dateFrom->toDateTimeString() : null,
                'date_to' => $dateTo ? $dateTo->toDateTimeString() : null,
                'trm_avg' => $trmProvided,
                'calculated_at' => Carbon::now()->toDateTimeString(),
            ],
            'perStore' => $perStoreResult,
            'perLeader' => $perLeader,
            'tables_by_type' => $tablesByType,
            'totals' => [
                'total_commission_usd' => $totalCommissionUsd,
                'total_commission_cop' => $totalCommissionCop,
            ],
            'sales_summary' => $salesSummary,
        ]);
    }

    public function saveStoreSplit(Request $request)
    {
        $v = Validator::make($request->all(), [
            'budget_id' => 'required|integer',
            'arrivals_pct' => 'required|numeric|min:0|max:100',
            'departures_pct' => 'required|numeric|min:0|max:100'
        ]);

        if ($v->fails()) {
            return response()->json(['errors'=>$v->errors()],422);
        }

        DB::connection('budget')
            ->table('budget_store_split')
            ->updateOrInsert(
                ['budget_id'=>$request->budget_id],
                [
                    'arrivals_pct'=>$request->arrivals_pct,
                    'departures_pct'=>$request->departures_pct,
                    'updated_at'=>Carbon::now(),
                    'created_at'=>Carbon::now()
                ]
            );

        return response()->json(['message'=>'% por categoria guardado']);
    }
    public function getStoreSplit($budgetId)
{
    $row = DB::connection('budget')
        ->table('budget_store_split')
        ->where('budget_id', $budgetId)
        ->first();

    if (!$row) {
        return response()->json([
            'arrivals_pct' => 60,
            'departures_pct' => 40
        ]);
    }

    return response()->json([
        'arrivals_pct' => floatval($row->arrivals_pct),
        'departures_pct' => floatval($row->departures_pct),
    ]);
}
}