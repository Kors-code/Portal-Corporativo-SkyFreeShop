<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\CommissionProfileEarnersExport;
use App\Models\Comisiones\CommissionProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;


class CommissionProfileController extends Controller
{
    public function index(Request $request)
    {
        $budgetId = $request->query('budget_id');
        $search = trim((string) $request->query('search', ''));

        $profiles = CommissionProfile::query()
            ->with(['rules', 'assignments'])
            ->when($budgetId, function ($q) use ($budgetId) {
                $q->where(function ($inner) use ($budgetId) {
                    $inner->where('budget_id', (int) $budgetId)
                        ->orWhereNull('budget_id');
                });
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('profile_type', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $userIds = $profiles
            ->flatMap(fn ($profile) => $profile->assignments->pluck('user_id'))
            ->unique()
            ->values();

        $users = DB::connection('budget')
            ->table('users')
            ->whereIn('id', $userIds)
            ->select('id', 'name', 'email', 'codigo_vendedor')
            ->get()
            ->keyBy('id');


            Log::info($profiles);
        return response()->json([
            'profiles' => $profiles->map(fn ($profile) => $this->serializeProfile($profile, $users)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedProfileData($request);
        $this->ensureBudgetIsOpen($data['profile']['budget_id'] ?? null);

        $profile = DB::connection('budget')->transaction(function () use ($data) {
            $profile = CommissionProfile::create($data['profile']);
            $this->syncRules($profile, $data['rules']);
            $this->syncUsers($profile, $data['user_ids']);

            return $profile->fresh(['rules', 'assignments']);
        });

        return response()->json(['profile' => $this->serializeProfile($profile)], 201);
    }

    public function update(Request $request, int $id)
    {
        $profile = CommissionProfile::findOrFail($id);
        $data = $this->validatedProfileData($request);
        $this->ensureBudgetIsOpen($profile->budget_id);
        $this->ensureBudgetIsOpen($data['profile']['budget_id'] ?? null);

        $profile = DB::connection('budget')->transaction(function () use ($profile, $data) {
            $profile->update($data['profile']);
            $this->syncRules($profile, $data['rules']);
            $this->syncUsers($profile, $data['user_ids']);

            return $profile->fresh(['rules', 'assignments']);
        });

        return response()->json(['profile' => $this->serializeProfile($profile)]);
    }

    public function destroy(int $id)
    {
        $profile = CommissionProfile::findOrFail($id);
        $this->ensureBudgetIsOpen($profile->budget_id);
        $profile->delete();

        return response()->json(['message' => 'Perfil eliminado']);
    }

    public function options(Request $request)
    {
        $providers = DB::connection('budget')
            ->table('products')
            ->whereNotNull('provider_name')
            ->whereRaw("TRIM(provider_name) <> ''")
            ->selectRaw('TRIM(provider_name) as name')
            ->distinct()
            ->orderBy('name')
            ->limit(5000)
            ->pluck('name');

        $categories = DB::connection('budget')
            ->table('categories')
            ->select('id', 'classification_code as code', 'name')
            ->orderBy('name')
            ->get();

        $brands = DB::connection('budget')
            ->table('products')
            ->whereNotNull('brand')
            ->whereRaw("TRIM(brand) <> ''")
            ->selectRaw('TRIM(brand) as name')
            ->distinct()
            ->orderBy('name')
            ->limit(5000)
            ->pluck('name');

        $products = DB::connection('budget')
            ->table('products')
            ->whereNotNull('product_code')
            ->whereRaw("TRIM(product_code) <> ''")
            ->selectRaw('TRIM(product_code) as code, MAX(description) as description, MAX(brand) as brand, MAX(sku_mia) as sku_mia, MAX(upc) as upc, MAX(upc2) as upc2, MAX(upc3) as upc3')
            ->groupBy(DB::raw('TRIM(product_code)'))
            ->orderBy('code')
            ->limit(25000)
            ->get();

        $users = DB::connection('budget')
            ->table('users')
            ->select('id', 'name', 'email', 'codigo_vendedor')
            ->orderBy('name')
            ->limit(500)
            ->get();

        return response()->json([
            'providers' => $providers,
            'brands' => $brands,
            'products' => $products,
            'categories' => $categories,
            'users' => $users,
            'roles' => DB::connection('budget')
                ->table('roles')
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
            'profile_types' => [
                ['value' => 'degustadora', 'label' => 'Degustadora'],
                ['value' => 'asesor_especializado', 'label' => 'Asesor especializado'],
            ],
            'rule_types' => [
                ['value' => 'custom', 'label' => 'Filtros combinados'],
                ['value' => 'provider', 'label' => 'Proveedor'],
                ['value' => 'category', 'label' => 'Categoria'],
                ['value' => 'brand', 'label' => 'Sky Marca'],
                ['value' => 'sku', 'label' => 'SKU'],
                ['value' => 'provider_category', 'label' => 'Proveedor + categoria'],
                ['value' => 'provider_brand', 'label' => 'Proveedor + marca'],
            ],
        ]);
    }

    public function summary(Request $request, int $id)
    {
        $profile = CommissionProfile::with(['rules', 'assignments'])->findOrFail($id);
        $budgetId = $request->query('budget_id') ? (int) $request->query('budget_id') : (int) $profile->budget_id;

        $budget = $budgetId
            ? DB::connection('budget')->table('budgets')->where('id', $budgetId)->first()
            : null;

        if (! $profile->is_active) {
            return response()->json([
                'profile' => $this->serializeProfile($profile),
                'budget' => $budget,
                'rows' => [],
                'totals' => [
                    'sales_usd' => 0,
                    'sales_cop' => 0,
                    'commission_usd' => 0,
                    'sales_count' => 0,
                ],
                'message' => 'Perfil deshabilitado para calculo.',
            ]);
        }

        $rows = $this->buildRowsForProfile($profile, $budgetId, $budget);

        return response()->json([
            'profile' => $this->serializeProfile($profile),
            'budget' => $budget,
            'rows' => $rows,
            'totals' => $this->totalsForRows($rows),
        ]);
    }

    public function earners(Request $request)
    {
        $budgetId = $request->query('budget_id') ? (int) $request->query('budget_id') : null;
        $profileId = $request->query('profile_id') ? (int) $request->query('profile_id') : null;

        $budget = $budgetId
            ? DB::connection('budget')->table('budgets')->where('id', $budgetId)->first()
            : null;

        $profiles = CommissionProfile::with(['rules', 'assignments'])
            ->where('is_active', true)
            ->when($profileId, fn ($q) => $q->where('id', $profileId))
            ->when($budgetId, function ($q) use ($budgetId) {
                $q->where(function ($inner) use ($budgetId) {
                    $inner->where('budget_id', $budgetId)->orWhereNull('budget_id');
                });
            })
            ->orderBy('name')
            ->get();

        $rows = [];
        foreach ($profiles as $profile) {
            foreach ($this->buildRowsForProfile($profile, $budgetId ?: $profile->budget_id, $budget) as $row) {
                $rows[] = array_merge($row, [
                    'profile_id' => $profile->id,
                    'profile_name' => $profile->name,
                    'profile_type' => $profile->profile_type,
                ]);
            }
        }

        return response()->json([
            'rows' => $rows,
            'totals' => $this->totalsForRows($rows),
        ]);
    }

    public function myCommissions(Request $request)
    {
        $request->validate(['budget_id' => ['nullable', 'integer', 'min:1']]);

        $sellerCode = trim((string) ($request->user()->seller_code ?? ''));
        $userId = $sellerCode !== ''
            ? DB::connection('budget')->table('users')->where('codigo_vendedor', $sellerCode)->value('id')
            : null;

        if (! $userId) {
            return response()->json([
                'budgets' => [],
                'selected_budget_id' => null,
                'source' => null,
                'rows' => [],
                'totals' => $this->totalsForRows([]),
            ]);
        }

        $userId = (int) $userId;
        $snapshotBudgetIds = DB::connection('budget')
            ->table('commission_profile_snapshots')
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('budget_id');
        $assignedProfileIds = CommissionProfile::query()
            ->where('is_active', true)
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $userId))
            ->pluck('id');

        $budgets = DB::connection('budget')
            ->table('budgets')
            ->select('id', 'name', 'start_date', 'end_date', 'is_closed', 'closed_at')
            ->where(function ($query) use ($snapshotBudgetIds, $assignedProfileIds) {
                $query->whereIn('id', $snapshotBudgetIds);

                if ($assignedProfileIds->isNotEmpty()) {
                    $query->orWhere(function ($open) use ($assignedProfileIds) {
                        $open->where(function ($status) {
                            $status->whereNull('is_closed')->orWhere('is_closed', false);
                        })->whereDate('start_date', '<=', now('America/Bogota')->toDateString())
                            ->whereExists(function ($profile) use ($assignedProfileIds) {
                                $profile->selectRaw('1')
                                    ->from('commission_profiles')
                                    ->whereIn('id', $assignedProfileIds)
                                    ->where(function ($scope) {
                                        $scope->whereNull('budget_id')
                                            ->orWhereColumn('budget_id', 'budgets.id');
                                    });
                            });
                    });
                }
            })
            ->orderByDesc('start_date')
            ->get();

        $budgetId = $request->query('budget_id')
            ? (int) $request->query('budget_id')
            : (int) ($budgets->first()->id ?? 0);
        $budget = $budgets->firstWhere('id', $budgetId);

        if ($request->query('budget_id') && ! $budget) {
            abort(404, 'Presupuesto no disponible para este usuario.');
        }

        $rows = [];
        $source = null;
        if ($budget) {
            if ($budget->is_closed) {
                $source = 'snapshot';
                $snapshots = DB::connection('budget')
                    ->table('commission_profile_snapshots')
                    ->where('budget_id', $budgetId)
                    ->where('user_id', $userId)
                    ->orderBy('profile_name_snapshot')
                    ->get();

                foreach ($snapshots as $snapshot) {
                    $rows[] = [
                        'profile_id' => $snapshot->commission_profile_id,
                        'profile_name' => $snapshot->profile_name_snapshot,
                        'profile_type' => $snapshot->profile_type_snapshot,
                        'sales_count' => (int) $snapshot->sales_count,
                        'units' => (float) $snapshot->units,
                        'assigned_turns' => (float) $snapshot->assigned_turns,
                        'target_usd' => (float) $snapshot->target_usd,
                        'sales_usd' => (float) $snapshot->sales_usd,
                        'sales_cop' => (float) $snapshot->sales_cop,
                        'avg_trm' => $snapshot->avg_trm === null ? null : (float) $snapshot->avg_trm,
                        'fulfillment_pct' => $snapshot->fulfillment_pct === null ? null : (float) $snapshot->fulfillment_pct,
                        'eligible' => (bool) $snapshot->eligible,
                        'applied_commission_pct' => (float) $snapshot->applied_commission_pct,
                        'commission_usd' => (float) $snapshot->commission_usd,
                        'commission_cop' => (float) $snapshot->commission_cop,
                        'rules' => json_decode($snapshot->rules_snapshot ?? '[]', true) ?: [],
                    ];
                }
            } else {
                $source = 'live';
                $profiles = CommissionProfile::with(['rules', 'assignments'])
                    ->whereIn('id', $assignedProfileIds)
                    ->where(function ($query) use ($budgetId) {
                        $query->where('budget_id', $budgetId)->orWhereNull('budget_id');
                    })
                    ->orderBy('name')
                    ->get();

                foreach ($profiles as $profile) {
                    foreach ($this->buildRowsForProfile($profile, $budgetId, $budget, $userId) as $row) {
                        $rows[] = array_merge($row, [
                            'profile_id' => $profile->id,
                            'profile_name' => $profile->name,
                            'profile_type' => $profile->profile_type,
                        ]);
                    }
                }
            }
        }

        return response()->json([
            'budgets' => $budgets,
            'selected_budget_id' => $budget?->id,
            'source' => $source,
            'rows' => $rows,
            'totals' => $this->totalsForRows($rows),
        ]);
    }

    public function exportEarners(Request $request)
    {
        $response = $this->earners($request);
        $payload = $response->getData(true);

        $rows = array_map(fn ($row) => [
            $row['profile_name'] ?? '',
            str_replace('_', ' ', $row['profile_type'] ?? ''),
            $row['user_name'] ?? '',
            $row['seller_code'] ?? '',
            $row['sales_count'] ?? 0,
            $row['units'] ?? 0,
            $row['target_usd'] ?? '',
            $row['sales_usd'] ?? 0,
            $row['sales_cop'] ?? 0,
            $row['fulfillment_pct'] ?? '',
            $row['applied_commission_pct'] ?? 0,
            $row['commission_usd'] ?? 0,
            ($row['eligible'] ?? false) ? 'Comisiona' : 'No aplica',
        ], $payload['rows'] ?? []);

        return Excel::download(new CommissionProfileEarnersExport($rows), 'perfiles-comisionables.xlsx');
    }

    private function buildRowsForProfile(CommissionProfile $profile, ?int $budgetId, $budget, ?int $onlyUserId = null): array
    {
        $rows = [];
        $categoryCodes = $profile->rules
            ->pluck('category_code')
            ->filter(fn ($code) => $code !== null && $code !== '')
            ->map(fn ($code) => (string) $code)
            ->unique()
            ->values();

        $categoryNamesByCode = $categoryCodes->isNotEmpty()
            ? DB::connection('budget')
                ->table('categories')
                ->whereIn(DB::raw('CAST(classification_code AS CHAR)'), $categoryCodes)
                ->pluck('name', 'classification_code')
                ->mapWithKeys(fn ($name, $code) => [(string) $code => $name])
                ->all()
            : [];

        foreach ($profile->assignments as $assignment) {
            if ($onlyUserId !== null && (int) $assignment->user_id !== $onlyUserId) {
                continue;
            }
            // Primera pasada: solo traemos las ventas de cada regla, sin decidir tramo todavia.
            $ruleSales = [];
            $assignedTurns = $this->assignedTurnsForUser($assignment->user_id, $budgetId);
            foreach ($profile->rules as $rule) {
                $query = DB::connection('budget')
                    ->table('sales')
                    ->leftJoin('products', 'products.id', '=', 'sales.product_id')
                    ->leftJoin('users', 'users.id', '=', 'sales.seller_id')
                    ->where('sales.seller_id', $assignment->user_id);

                $this->applyBudgetFilter($query, $budgetId, $budget);
                $this->applySingleRule($query, $rule);

                $sales = $query
                    ->selectRaw('COUNT(*) as rows_count')
                    ->selectRaw('COALESCE(SUM(sales.value_usd), 0) as sales_usd')
                    ->selectRaw('COALESCE(SUM(sales.amount_cop), 0) as sales_cop')
                    ->selectRaw('COALESCE(SUM(sales.quantity), 0) as units')
                    ->selectRaw('MAX(users.name) as user_name')
                    ->selectRaw('MAX(users.codigo_vendedor) as seller_code')
                    ->first();

                $ruleSales[] = ['rule' => $rule, 'sales' => $sales];
            }

            $personSalesUsd = array_sum(array_map(fn ($rs) => (float) ($rs['sales']->sales_usd ?? 0), $ruleSales));
            $personTargetUsd = array_sum(array_map(
                fn ($rs) => $this->effectiveRuleTargetUsd($rs['rule'], $assignedTurns),
                $ruleSales
            ));
            $personFulfillment = $personTargetUsd > 0 ? round(($personSalesUsd / $personTargetUsd) * 100, 2) : null;

            // Segunda pasada: cada regla tiene su meta propia y decide su tramo 80/100/120.
            $ruleRows = [];
            foreach ($ruleSales as $entry) {
                $rule = $entry['rule'];
                $sales = $entry['sales'];

                $salesUsd = (float) ($sales->sales_usd ?? 0);
                $ruleParticipationPct = (float) ($rule->participation_pct ?? 0);
                $ruleBaseTargetUsd = (float) ($rule->target_amount_usd ?? 0);
                $ruleTargetTurns = (float) ($rule->target_turns ?? 0);
                $ruleTargetUsd = $this->effectiveRuleTargetUsd($rule, $assignedTurns);
                $ruleFulfillment = $ruleTargetUsd > 0 ? round(($salesUsd / $ruleTargetUsd) * 100, 2) : null;
                $appliedPct = $this->resolveRuleCommissionPct($rule, $ruleFulfillment);
                $eligible = $salesUsd > 0 && $appliedPct > 0;
                $commissionUsd = $eligible ? round($salesUsd * ($appliedPct / 100), 2) : 0.0;
                $salesCop = (float) ($sales->sales_cop ?? 0);
                $avgTrm = $salesUsd > 0 && $salesCop > 0
                    ? round($salesCop / $salesUsd, 2)
                    : null;
                $commissionCop = $avgTrm !== null
                    ? round($commissionUsd * $avgTrm, 2)
                    : 0.0;

                $ruleRows[] = [
                    'rule_id' => $rule->id,
                    'rule_type' => $rule->rule_type,
                    'provider_name' => $rule->provider_name,
                    'brand' => $rule->brand,
                    'product_code' => $rule->product_code,
                    'category_code' => $rule->category_code,
                    'category_name' => isset($rule->category_code)
                        ? ($categoryNamesByCode[(string) $rule->category_code] ?? null)
                        : null,
                    'participation_pct' => round($ruleParticipationPct, 4),
                    'base_target_usd' => round($ruleBaseTargetUsd, 2),
                    'target_usd' => $ruleTargetUsd,
                    'target_turns' => $ruleTargetTurns > 0 ? $ruleTargetTurns : null,
                    'assigned_turns' => $assignedTurns,
                    'sales_count' => (int) ($sales->rows_count ?? 0),
                    'units' => (float) ($sales->units ?? 0),
                    'sales_usd' => round($salesUsd, 2),
                    'sales_cop' => round($salesCop, 2),
                    'avg_trm' => $avgTrm,
                    'fulfillment_pct' => $ruleFulfillment,
                    'eligible' => $eligible,
                    'applied_commission_pct' => $appliedPct,
                    'commission_usd' => $commissionUsd,
                    'commission_cop' => $commissionCop,
                ];
            }

            $user = DB::connection('budget')
                ->table('users')
                ->select('name', 'codigo_vendedor')
                ->where('id', $assignment->user_id)
                ->first();

            $salesUsd = array_sum(array_column($ruleRows, 'sales_usd'));
            $salesCop = array_sum(array_column($ruleRows, 'sales_cop'));
            $commissionUsd = array_sum(array_column($ruleRows, 'commission_usd'));
            $commissionCop = array_sum(array_column($ruleRows, 'commission_cop'));
            $avgTrm = $salesUsd > 0 && $salesCop > 0
                ? round($salesCop / $salesUsd, 2)
                : null;
            $ruleTargetUsd = array_sum(array_column($ruleRows, 'target_usd'));
            $weightedPct = $salesUsd > 0
                ? array_sum(array_map(fn ($ruleRow) => $ruleRow['sales_usd'] * $ruleRow['applied_commission_pct'], $ruleRows)) / $salesUsd
                : 0.0;

            $rows[] = [
                'user_id' => $assignment->user_id,
                'user_name' => $user->name ?? null,
                'seller_code' => $user->codigo_vendedor ?? null,
                'sales_count' => (int) array_sum(array_column($ruleRows, 'sales_count')),
                'units' => (float) array_sum(array_column($ruleRows, 'units')),
                'assigned_turns' => $assignedTurns,
                'target_usd' => round($ruleTargetUsd, 2),
                'sales_usd' => round($salesUsd, 2),
                'sales_cop' => round($salesCop, 2),
                'avg_trm' => $avgTrm,
                'fulfillment_pct' => $personFulfillment,
                'eligible' => $commissionUsd > 0,
                'applied_commission_pct' => round($weightedPct, 4),
                'commission_usd' => round($commissionUsd, 2),
                'commission_cop' => round($commissionCop, 2),
                'rules' => $ruleRows,
            ];
        }

        return $rows;
    }

    private function totalsForRows(array $rows): array
    {
        $salesUsd = array_sum(array_column($rows, 'sales_usd'));
        $salesCop = array_sum(array_column($rows, 'sales_cop'));

        return [
            'sales_usd' => round($salesUsd, 2),
            'sales_cop' => round($salesCop, 2),
            'target_usd' => round(array_sum(array_column($rows, 'target_usd')), 2),
            'commission_usd' => round(array_sum(array_column($rows, 'commission_usd')), 2),
            'commission_cop' => round(array_sum(array_column($rows, 'commission_cop')), 2),
            'avg_trm' => $salesUsd > 0 && $salesCop > 0 ? round($salesCop / $salesUsd, 2) : null,
            'sales_count' => array_sum(array_column($rows, 'sales_count')),
        ];
    }

    private function validatedProfileData(Request $request): array
    {
        $payload = $request->validate([
            'budget_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'profile_type' => ['required', 'string', Rule::in(['degustadora', 'asesor_especializado'])],
            'category_role_id' => ['nullable', 'integer'],
            'commission_mode' => ['nullable', 'string', Rule::in(['fixed'])],
            'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_percentage100' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_percentage120' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_fulfillment_pct' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'target_amount_usd' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'note' => ['nullable', 'string'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*.rule_type' => ['nullable', 'string', Rule::in(['custom', 'provider', 'category', 'brand', 'sku', 'provider_category', 'provider_brand'])],
            'rules.*.provider_name' => ['nullable', 'string', 'max:160'],
            'rules.*.brand' => ['nullable', 'string', 'max:120'],
            'rules.*.category_id' => ['nullable', 'integer'],
            'rules.*.category_code' => ['nullable', 'string', 'max:60'],
            'rules.*.product_code' => ['nullable', 'string', 'max:80'],
            'rules.*.participation_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rules.*.target_amount_usd' => ['nullable', 'numeric', 'min:0'],
            'rules.*.target_turns' => ['nullable', 'numeric', 'min:0'],
            'rules.*.commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.*.commission_percentage100' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rules.*.commission_percentage120' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        foreach ($payload['rules'] as $rule) {
            if (! $this->ruleHasAtLeastOneFilter($rule)) {
                abort(422, 'Cada regla debe tener al menos un filtro: proveedor, marca, categoria o SKU.');
            }
        }

        return [
            'profile' => [
                'budget_id' => $payload['budget_id'] ?? null,
                'name' => $payload['name'],
                'profile_type' => $payload['profile_type'],
                'commission_mode' => $payload['commission_mode'] ?? 'fixed',
                'category_role_id' => $payload['category_role_id'] ?? null,
                'commission_percentage' => $payload['commission_percentage'],
                'commission_percentage100' => $payload['commission_percentage100'] ?? 0,
                'commission_percentage120' => $payload['commission_percentage120'] ?? 0,
                'minimum_fulfillment_pct' => $payload['minimum_fulfillment_pct'] ?? null,
                'target_amount_usd' => $payload['target_amount_usd'] ?? null,
                'is_active' => $payload['is_active'] ?? true,
                'valid_from' => $payload['valid_from'] ?? null,
                'valid_to' => $payload['valid_to'] ?? null,
                'note' => $payload['note'] ?? null,
                'created_by' => auth()->id(),
            ],
            'rules' => $payload['rules'],
            'user_ids' => array_values(array_unique(array_map('intval', $payload['user_ids']))),
        ];
    }

    private function syncRules(CommissionProfile $profile, array $rules): void
    {
        $profile->rules()->delete();

        foreach ($rules as $rule) {
            $categoryCode = $this->nullableTrim($rule['category_code'] ?? null);
            if (! $categoryCode && ! empty($rule['category_id'])) {
                $categoryCode = DB::connection('budget')
                    ->table('categories')
                    ->where('id', (int) $rule['category_id'])
                    ->value('classification_code');
            }

            $profile->rules()->create([
                'rule_type' => $this->classifyRuleType($rule),
                'provider_name' => $this->nullableTrim($rule['provider_name'] ?? null),
                'brand' => $this->nullableTrim($rule['brand'] ?? null),
                'category_id' => $rule['category_id'] ?? null,
                'category_code' => $categoryCode,
                'product_code' => $this->nullableTrim($rule['product_code'] ?? null),
                'participation_pct' => $rule['participation_pct'] ?? 0,
                'target_amount_usd' => $rule['target_amount_usd'] ?? null,
                'target_turns' => $rule['target_turns'] ?? null,
                'commission_percentage' => $rule['commission_percentage'] ?? 0,
                'commission_percentage100' => $rule['commission_percentage100'] ?? 0,
                'commission_percentage120' => $rule['commission_percentage120'] ?? 0,
            ]);
        }
    }

    private function syncUsers(CommissionProfile $profile, array $userIds): void
    {
        $profile->assignments()->delete();

        foreach ($userIds as $userId) {
            $profile->assignments()->create(['user_id' => $userId]);
        }
    }

    private function serializeProfile(CommissionProfile $profile, $users = null): array
    {
        $users ??= collect();

        return [
            'id' => $profile->id,
            'budget_id' => $profile->budget_id,
            'name' => $profile->name,
            'profile_type' => $profile->profile_type,
            'commission_mode' => $profile->commission_mode,
            'category_role_id' => $profile->category_role_id,
            'commission_percentage' => (float) $profile->commission_percentage,
            'commission_percentage100' => (float) $profile->commission_percentage100,
            'commission_percentage120' => (float) $profile->commission_percentage120,
            'minimum_fulfillment_pct' => $profile->minimum_fulfillment_pct === null ? null : (float) $profile->minimum_fulfillment_pct,
            'target_amount_usd' => $profile->target_amount_usd === null ? null : (float) $profile->target_amount_usd,
            'is_active' => (bool) $profile->is_active,
            'valid_from' => optional($profile->valid_from)->toDateString(),
            'valid_to' => optional($profile->valid_to)->toDateString(),
            'note' => $profile->note,
            'rules' => $profile->rules->map(fn ($rule) => [
                'id' => $rule->id,
                'rule_type' => $rule->rule_type,
                'provider_name' => $rule->provider_name,
                'brand' => $rule->brand,
                'product_code' => $rule->product_code,
                'category_id' => $rule->category_id,
                'category_code' => $rule->category_code,
                'participation_pct' => (float) ($rule->participation_pct ?? 0),
                'target_amount_usd' => $rule->target_amount_usd === null ? null : (float) $rule->target_amount_usd,
                'target_turns' => $rule->target_turns === null ? null : (float) $rule->target_turns,
                'commission_percentage' => (float) $rule->commission_percentage,
                'commission_percentage100' => (float) $rule->commission_percentage100,
                'commission_percentage120' => (float) $rule->commission_percentage120,
            ])->values(),
            'users' => $profile->assignments->map(function ($assignment) use ($users) {
                $user = $users->get($assignment->user_id);

                return [
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'name' => $user->name ?? null,
                    'email' => $user->email ?? null,
                    'codigo_vendedor' => $user->codigo_vendedor ?? null,
                ];
            })->values(),
        ];
    }

    private function applyBudgetFilter($query, ?int $budgetId, $budget): void
    {
        if (! $budgetId) {
            return;
        }

        if (Schema::connection('budget')->hasColumn('sales', 'budget_id')) {
            $query->where('sales.budget_id', $budgetId);
            return;
        }

        if ($budget && $budget->start_date && $budget->end_date) {
            $query->whereBetween('sales.sale_date', [$budget->start_date, $budget->end_date]);
        }
    }

    private function ensureBudgetIsOpen(?int $budgetId): void
    {
        if (! $budgetId) {
            return;
        }

        $budget = DB::connection('budget')
            ->table('budgets')
            ->where('id', $budgetId)
            ->first();

        if ($budget && (bool) ($budget->is_closed ?? false)) {
            abort(422, 'Este presupuesto ya esta cerrado y no permite modificar perfiles de comision.');
        }
    }

    private function applyProfileRules($query, $rules): void
    {
        $query->where(function ($outer) use ($rules) {
            foreach ($rules as $rule) {
                $outer->orWhere(function ($inner) use ($rule) {
                    if ($this->hasValue($rule->provider_name ?? null)) {
                        $inner->whereRaw('UPPER(TRIM(products.provider_name)) = ?', [mb_strtoupper(trim((string) $rule->provider_name))]);
                    }

                    if ($this->hasValue($rule->category_code ?? null)) {
                        $inner->whereIn(DB::raw('CAST(products.classification AS CHAR)'), $this->categoryCodesForRule($rule));
                    }

                    if ($this->hasValue($rule->brand ?? null)) {
                        $inner->whereRaw('UPPER(TRIM(products.brand)) = ?', [mb_strtoupper(trim((string) $rule->brand))]);
                    }

                    if ($this->hasValue($rule->product_code ?? null)) {
                        $this->applyProductCodeFilter($inner, (string) $rule->product_code);
                    }
                });
            }
        });
    }

    private function applySingleRule($query, $rule): void
    {
        if ($this->hasValue($rule->provider_name ?? null)) {
            $query->whereRaw('UPPER(TRIM(products.provider_name)) = ?', [mb_strtoupper(trim((string) $rule->provider_name))]);
        }

        if ($this->hasValue($rule->category_code ?? null)) {
            $query->whereIn(DB::raw('CAST(products.classification AS CHAR)'), $this->categoryCodesForRule($rule));
        }

        if ($this->hasValue($rule->brand ?? null)) {
            $query->whereRaw('UPPER(TRIM(products.brand)) = ?', [mb_strtoupper(trim((string) $rule->brand))]);
        }

        if ($this->hasValue($rule->product_code ?? null)) {
            $this->applyProductCodeFilter($query, (string) $rule->product_code);
        }
    }

    private function ruleHasAtLeastOneFilter(array $rule): bool
    {
        return $this->hasValue($rule['provider_name'] ?? null)
            || $this->hasValue($rule['brand'] ?? null)
            || $this->hasValue($rule['category_code'] ?? null)
            || ! empty($rule['category_id'])
            || $this->hasValue($rule['product_code'] ?? null);
    }

    private function classifyRuleType(array $rule): string
    {
        $hasProvider = $this->hasValue($rule['provider_name'] ?? null);
        $hasBrand = $this->hasValue($rule['brand'] ?? null);
        $hasCategory = $this->hasValue($rule['category_code'] ?? null) || ! empty($rule['category_id']);
        $hasProduct = $this->hasValue($rule['product_code'] ?? null);
        $count = ($hasProvider ? 1 : 0) + ($hasBrand ? 1 : 0) + ($hasCategory ? 1 : 0) + ($hasProduct ? 1 : 0);

        if ($count !== 1) {
            return 'custom';
        }

        if ($hasProduct) {
            return 'sku';
        }

        if ($hasProvider) {
            return 'provider';
        }

        if ($hasBrand) {
            return 'brand';
        }

        return 'category';
    }

    private function nullableTrim($value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function hasValue($value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    private function applyProductCodeFilter($query, string $productCode): void
    {
        $normalized = mb_strtoupper(trim($productCode));

        $query->where(function ($inner) use ($normalized) {
            foreach (['product_code', 'sku_mia', 'upc', 'upc2', 'upc3'] as $column) {
                $inner->orWhereRaw("UPPER(TRIM(products.{$column})) = ?", [$normalized]);
            }
        });
    }

    private function assignedTurnsForUser(int $userId, ?int $budgetId): float
    {
        if (! $budgetId || ! Schema::connection('budget')->hasTable('budget_user_turns')) {
            return 0.0;
        }

        return (float) DB::connection('budget')
            ->table('budget_user_turns')
            ->where('budget_id', $budgetId)
            ->where('user_id', $userId)
            ->sum('assigned_turns');
    }

    private function effectiveRuleTargetUsd($rule, float $assignedTurns): float
    {
        $baseTargetUsd = (float) ($rule->target_amount_usd ?? 0);
        $targetTurns = (float) ($rule->target_turns ?? 0);

        if ($baseTargetUsd <= 0) {
            return 0.0;
        }

        if ($targetTurns <= 0) {
            return round($baseTargetUsd, 2);
        }

        return round($baseTargetUsd * ($assignedTurns / $targetTurns), 2);
    }

    private function categoryCodesForRule($rule): array
    {
        $code = mb_strtolower(trim((string) ($rule->category_code ?? '')));

        return match ($code) {
            'fragancias', 'fragrances', 'fragances' => ['10', '11', '12'],
            default => [(string) ($rule->category_code ?? '')],
        };
    }

    private function ruleTargetAmount(float $profileTargetUsd, float $participationPct, bool $hasRuleParticipationPlan): float
    {
        if ($profileTargetUsd <= 0) {
            return 0.0;
        }

        if ($hasRuleParticipationPlan && $participationPct <= 0) {
            return 0.0;
        }

        if ($participationPct <= 0) {
            return round($profileTargetUsd, 2);
        }

        return round($profileTargetUsd * ($participationPct / 100), 2);
    }

    private function resolveRuleCommissionPct($rule, ?float $fulfillment): float
    {
        $pct80 = (float) ($rule->commission_percentage ?? 0);
        $pct100 = (float) ($rule->commission_percentage100 ?? 0);
        $pct120 = (float) ($rule->commission_percentage120 ?? 0);

        if ($fulfillment !== null && $fulfillment >= 120) {
            return $pct120 ?: ($pct100 ?: $pct80);
        }

        if ($fulfillment !== null && $fulfillment >= 100) {
            return $pct100 ?: $pct80;
        }

        if ($fulfillment === null || $fulfillment >= 80) {
            return $pct80;
        }

        return 0.0;
    }

    private function calculateCategoryCommission(CommissionProfile $profile, ?int $budgetId, $sales, float $targetUsd, bool $eligible): array
    {
        if (! $eligible || $sales->isEmpty()) {
            return ['commission_usd' => 0.0, 'applied_pct' => 0.0, 'categories' => []];
        }

        $categoryCodes = $sales
            ->pluck('category_code')
            ->filter(fn ($code) => $code !== null && $code !== '')
            ->map(fn ($code) => (string) $code)
            ->unique()
            ->values();

        $configs = DB::connection('budget')
            ->table('category_commissions as cc')
            ->join('categories as c', 'c.id', '=', 'cc.category_id')
            ->where('cc.role_id', (int) $profile->category_role_id)
            ->when($budgetId, fn ($q) => $q->where('cc.budget_id', $budgetId))
            ->whereIn(DB::raw('CAST(c.classification_code AS CHAR)'), $categoryCodes)
            ->select(
                'c.classification_code',
                'c.name',
                'cc.participation_pct',
                'cc.commission_percentage',
                'cc.commission_percentage100',
                'cc.commission_percentage120'
            )
            ->get()
            ->keyBy(fn ($row) => (string) $row->classification_code);

        $totalCommission = 0.0;
        $weightedPct = 0.0;
        $categories = [];

        foreach ($sales as $row) {
            $code = (string) $row->category_code;
            $config = $configs->get($code);
            $categorySalesUsd = (float) $row->sales_usd;
            $categoryTargetUsd = $targetUsd > 0 && $config
                ? round($targetUsd * ((float) $config->participation_pct / 100), 2)
                : 0.0;
            $categoryFulfillment = $categoryTargetUsd > 0
                ? round(($categorySalesUsd / $categoryTargetUsd) * 100, 2)
                : null;
            $appliedPct = $this->resolveCategoryPct($config, $categoryFulfillment);
            $commissionUsd = round($categorySalesUsd * ($appliedPct / 100), 2);

            $totalCommission += $commissionUsd;
            $weightedPct += $categorySalesUsd > 0 ? ($appliedPct * $categorySalesUsd) : 0;
            $categories[] = [
                'category_code' => $code,
                'category_name' => $config->name ?? $code,
                'sales_usd' => round($categorySalesUsd, 2),
                'target_usd' => $categoryTargetUsd,
                'fulfillment_pct' => $categoryFulfillment,
                'applied_commission_pct' => $appliedPct,
                'commission_usd' => $commissionUsd,
            ];
        }

        $totalSalesUsd = (float) $sales->sum(fn ($row) => (float) $row->sales_usd);

        return [
            'commission_usd' => round($totalCommission, 2),
            'applied_pct' => $totalSalesUsd > 0 ? round($weightedPct / $totalSalesUsd, 4) : 0.0,
            'categories' => $categories,
        ];
    }

    private function resolveCategoryPct($config, ?float $fulfillment): float
    {
        if (! $config) {
            return 0.0;
        }

        $base = (float) ($config->commission_percentage ?? 0);
        $pct100 = (float) ($config->commission_percentage100 ?? 0);
        $pct120 = (float) ($config->commission_percentage120 ?? 0);

        if ($fulfillment !== null && $fulfillment >= 120) {
            return $pct120 ?: ($pct100 ?: $base);
        }

        if ($fulfillment !== null && $fulfillment >= 100) {
            return $pct100 ?: $base;
        }

        return $base;
    }
}
