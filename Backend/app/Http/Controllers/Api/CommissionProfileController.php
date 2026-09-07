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

        return response()->json([
            'profiles' => $profiles->map(fn ($profile) => $this->serializeProfile($profile, $users)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedProfileData($request);

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
        CommissionProfile::findOrFail($id)->delete();

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
            ->limit(300)
            ->pluck('name');

        $categories = DB::connection('budget')
            ->table('categories')
            ->select('id', 'classification_code as code', 'name')
            ->orderBy('name')
            ->get();

        $users = DB::connection('budget')
            ->table('users')
            ->select('id', 'name', 'email', 'codigo_vendedor')
            ->orderBy('name')
            ->limit(500)
            ->get();

        return response()->json([
            'providers' => $providers,
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
                ['value' => 'provider', 'label' => 'Proveedor'],
                ['value' => 'category', 'label' => 'Categoria'],
                ['value' => 'provider_category', 'label' => 'Proveedor + categoria'],
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
            $row['sales_usd'] ?? 0,
            $row['sales_cop'] ?? 0,
            $row['fulfillment_pct'] ?? '',
            $row['applied_commission_pct'] ?? 0,
            $row['commission_usd'] ?? 0,
            ($row['eligible'] ?? false) ? 'Comisiona' : 'No aplica',
        ], $payload['rows'] ?? []);

        return Excel::download(new CommissionProfileEarnersExport($rows), 'perfiles-comisionables.xlsx');
    }

    private function buildRowsForProfile(CommissionProfile $profile, ?int $budgetId, $budget): array
    {
        $rows = [];
        foreach ($profile->assignments as $assignment) {
            $ruleRows = [];
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

                $salesUsd = (float) ($sales->sales_usd ?? 0);
                $targetUsd = (float) ($profile->target_amount_usd ?? 0);
                $fulfillment = $targetUsd > 0 ? round(($salesUsd / $targetUsd) * 100, 2) : null;
                $appliedPct = $this->resolveRuleCommissionPct($rule, $fulfillment);
                $eligible = $salesUsd > 0 && $appliedPct > 0;
                $commissionUsd = $eligible ? round($salesUsd * ($appliedPct / 100), 2) : 0.0;

                $ruleRows[] = [
                    'rule_id' => $rule->id,
                    'rule_type' => $rule->rule_type,
                    'provider_name' => $rule->provider_name,
                    'category_code' => $rule->category_code,
                    'sales_count' => (int) ($sales->rows_count ?? 0),
                    'units' => (float) ($sales->units ?? 0),
                    'sales_usd' => round($salesUsd, 2),
                    'sales_cop' => round((float) ($sales->sales_cop ?? 0), 2),
                    'fulfillment_pct' => $fulfillment,
                    'eligible' => $eligible,
                    'applied_commission_pct' => $appliedPct,
                    'commission_usd' => $commissionUsd,
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
            $weightedPct = $salesUsd > 0
                ? array_sum(array_map(fn ($ruleRow) => $ruleRow['sales_usd'] * $ruleRow['applied_commission_pct'], $ruleRows)) / $salesUsd
                : 0.0;
            $targetUsd = (float) ($profile->target_amount_usd ?? 0);
            $fulfillment = $targetUsd > 0 ? round(($salesUsd / $targetUsd) * 100, 2) : null;

            $rows[] = [
                'user_id' => $assignment->user_id,
                'user_name' => $user->name ?? null,
                'seller_code' => $user->codigo_vendedor ?? null,
                'sales_count' => (int) array_sum(array_column($ruleRows, 'sales_count')),
                'units' => (float) array_sum(array_column($ruleRows, 'units')),
                'sales_usd' => round($salesUsd, 2),
                'sales_cop' => round($salesCop, 2),
                'fulfillment_pct' => $fulfillment,
                'eligible' => $commissionUsd > 0,
                'applied_commission_pct' => round($weightedPct, 4),
                'commission_usd' => round($commissionUsd, 2),
                'rules' => $ruleRows,
            ];
        }

        return $rows;
    }

    private function totalsForRows(array $rows): array
    {
        return [
            'sales_usd' => round(array_sum(array_column($rows, 'sales_usd')), 2),
            'sales_cop' => round(array_sum(array_column($rows, 'sales_cop')), 2),
            'commission_usd' => round(array_sum(array_column($rows, 'commission_usd')), 2),
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
            'rules.*.rule_type' => ['required', 'string', Rule::in(['provider', 'category', 'provider_category'])],
            'rules.*.provider_name' => ['nullable', 'string', 'max:160'],
            'rules.*.category_id' => ['nullable', 'integer'],
            'rules.*.category_code' => ['nullable', 'string', 'max:60'],
            'rules.*.commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.*.commission_percentage100' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'rules.*.commission_percentage120' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
        ]);

        foreach ($payload['rules'] as $rule) {
            if (in_array($rule['rule_type'], ['provider', 'provider_category'], true) && empty($rule['provider_name'])) {
                abort(422, 'El proveedor es obligatorio para reglas por proveedor.');
            }

            if (in_array($rule['rule_type'], ['category', 'provider_category'], true)
                && empty($rule['category_id'])
                && empty($rule['category_code'])
            ) {
                abort(422, 'La categoria es obligatoria para reglas por categoria.');
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
            $categoryCode = $rule['category_code'] ?? null;
            if (! $categoryCode && ! empty($rule['category_id'])) {
                $categoryCode = DB::connection('budget')
                    ->table('categories')
                    ->where('id', (int) $rule['category_id'])
                    ->value('classification_code');
            }

            $profile->rules()->create([
                'rule_type' => $rule['rule_type'],
                'provider_name' => $rule['provider_name'] ?? null,
                'category_id' => $rule['category_id'] ?? null,
                'category_code' => $categoryCode,
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
                'category_id' => $rule->category_id,
                'category_code' => $rule->category_code,
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

    private function applyProfileRules($query, $rules): void
    {
        $query->where(function ($outer) use ($rules) {
            foreach ($rules as $rule) {
                $outer->orWhere(function ($inner) use ($rule) {
                    if (in_array($rule->rule_type, ['provider', 'provider_category'], true)) {
                        $inner->whereRaw('UPPER(TRIM(products.provider_name)) = ?', [mb_strtoupper(trim((string) $rule->provider_name))]);
                    }

                    if (in_array($rule->rule_type, ['category', 'provider_category'], true)) {
                        $inner->whereRaw('CAST(products.classification AS CHAR) = ?', [(string) $rule->category_code]);
                    }
                });
            }
        });
    }

    private function applySingleRule($query, $rule): void
    {
        if (in_array($rule->rule_type, ['provider', 'provider_category'], true)) {
            $query->whereRaw('UPPER(TRIM(products.provider_name)) = ?', [mb_strtoupper(trim((string) $rule->provider_name))]);
        }

        if (in_array($rule->rule_type, ['category', 'provider_category'], true)) {
            $query->whereRaw('CAST(products.classification AS CHAR) = ?', [(string) $rule->category_code]);
        }
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
