<?php

namespace App\Services;

use App\Http\Controllers\Api\CommissionProfileController;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BudgetClosureService
{
    public function closeBudget(int $budgetId, string $triggeredBy = 'manual', ?int $closedBy = null, bool $force = false): array
    {
        $budget = DB::connection('budget')->table('budgets')->where('id', $budgetId)->first();

        if (! $budget) {
            throw new RuntimeException("No existe el presupuesto {$budgetId}.");
        }

        if ((bool) ($budget->is_closed ?? false) && ! $force) {
            return [
                'status' => 'already_closed',
                'budget_id' => $budgetId,
                'closed_at' => $budget->closed_at ?? null,
            ];
        }

        $now = now('America/Bogota');
        $reviewUntil = $this->reviewUntilForBudget($budget);

        if (! $force && $reviewUntil && $now->copy()->startOfDay()->lt($reviewUntil->copy()->startOfDay())) {
            throw new RuntimeException("El presupuesto {$budgetId} esta en revision hasta {$reviewUntil->toDateString()}.");
        }

        $snapshot = $this->commissionProfileSnapshotPayload($budgetId, $now);

        DB::connection('budget')->transaction(function () use ($budgetId, $triggeredBy, $closedBy, $now, $reviewUntil, $snapshot) {
            DB::connection('budget')
                ->table('commission_profile_snapshots')
                ->where('budget_id', $budgetId)
                ->delete();

            foreach (array_chunk($snapshot['rows'], 500) as $chunk) {
                if (! empty($chunk)) {
                    DB::connection('budget')->table('commission_profile_snapshots')->insert($chunk);
                }
            }

            DB::connection('budget')->table('budget_closures')->insert([
                'budget_id' => $budgetId,
                'status' => 'closed',
                'triggered_by' => $triggeredBy,
                'closed_at' => $now,
                'closed_by' => $closedBy,
                'review_until' => $reviewUntil?->toDateString(),
                'summary_json' => json_encode($snapshot['summary'], JSON_UNESCAPED_UNICODE),
                'error_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $budgetUpdate = [
                'is_closed' => true,
                'closed_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::connection('budget')->hasColumn('budgets', 'closure_review_until')) {
                $budgetUpdate['closure_review_until'] = $reviewUntil?->toDateString();
            }

            if (Schema::connection('budget')->hasColumn('budgets', 'closure_snapshot_created_at')) {
                $budgetUpdate['closure_snapshot_created_at'] = $now;
            }

            DB::connection('budget')
                ->table('budgets')
                ->where('id', $budgetId)
                ->update($budgetUpdate);
        });

        return [
            'status' => 'closed',
            'budget_id' => $budgetId,
            'review_until' => $reviewUntil?->toDateString(),
            'snapshots_created' => count($snapshot['rows']),
            'summary' => $snapshot['summary'],
        ];
    }

    public function eligibleBudgetsForAutomaticClosure(?Carbon $date = null): array
    {
        $date ??= now('America/Bogota');
        $maxEndDate = $date->copy()->subDays(4)->toDateString();

        return DB::connection('budget')
            ->table('budgets')
            ->where('end_date', '<=', $maxEndDate)
            ->where(function ($query) {
                $query->whereNull('is_closed')->orWhere('is_closed', false);
            })
            ->orderBy('end_date')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function reviewUntilForBudget(object $budget): ?Carbon
    {
        if (empty($budget->end_date)) {
            return null;
        }

        return Carbon::parse($budget->end_date, 'America/Bogota')->addDays(4);
    }

    private function commissionProfileSnapshotPayload(int $budgetId, Carbon $calculatedAt): array
    {
        /** @var CommissionProfileController $controller */
        $controller = app(CommissionProfileController::class);
        $request = Request::create('/api/v1/commission-profiles/earners', 'GET', [
            'budget_id' => $budgetId,
        ]);

        $payload = $controller->earners($request)->getData(true);
        $rows = [];

        foreach (($payload['rows'] ?? []) as $row) {
            $rows[] = [
                'budget_id' => $budgetId,
                'commission_profile_id' => $row['profile_id'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'profile_name_snapshot' => $row['profile_name'] ?? null,
                'profile_type_snapshot' => $row['profile_type'] ?? null,
                'user_name_snapshot' => $row['user_name'] ?? null,
                'seller_code_snapshot' => $row['seller_code'] ?? null,
                'sales_count' => (int) ($row['sales_count'] ?? 0),
                'units' => (float) ($row['units'] ?? 0),
                'assigned_turns' => (float) ($row['assigned_turns'] ?? 0),
                'target_usd' => (float) ($row['target_usd'] ?? 0),
                'sales_usd' => (float) ($row['sales_usd'] ?? 0),
                'sales_cop' => (float) ($row['sales_cop'] ?? 0),
                'avg_trm' => $row['avg_trm'] ?? null,
                'fulfillment_pct' => $row['fulfillment_pct'] ?? null,
                'applied_commission_pct' => (float) ($row['applied_commission_pct'] ?? 0),
                'commission_usd' => (float) ($row['commission_usd'] ?? 0),
                'commission_cop' => (float) ($row['commission_cop'] ?? 0),
                'eligible' => (bool) ($row['eligible'] ?? false),
                'rules_snapshot' => json_encode($row['rules'] ?? [], JSON_UNESCAPED_UNICODE),
                'calculated_at' => $calculatedAt,
                'created_at' => $calculatedAt,
                'updated_at' => $calculatedAt,
            ];
        }

        return [
            'rows' => $rows,
            'summary' => [
                'commission_profiles' => [
                    'users' => count($rows),
                    'sales_usd' => round((float) ($payload['totals']['sales_usd'] ?? 0), 2),
                    'sales_cop' => round((float) ($payload['totals']['sales_cop'] ?? 0), 2),
                    'target_usd' => round((float) ($payload['totals']['target_usd'] ?? 0), 2),
                    'commission_usd' => round((float) ($payload['totals']['commission_usd'] ?? 0), 2),
                    'commission_cop' => round((float) ($payload['totals']['commission_cop'] ?? 0), 2),
                    'sales_count' => (int) ($payload['totals']['sales_count'] ?? 0),
                ],
            ],
        ];
    }
}
