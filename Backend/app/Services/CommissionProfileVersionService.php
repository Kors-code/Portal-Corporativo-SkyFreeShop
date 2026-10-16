<?php

namespace App\Services;

use App\Models\Comisiones\Budget;
use App\Models\Comisiones\CommissionProfile;
use App\Models\Comisiones\CommissionProfileFamily;
use Illuminate\Support\Facades\DB;

class CommissionProfileVersionService
{
    public function copyFromPreviousBudget(Budget $budget): void
    {
        $previous = Budget::query()
            ->where('start_date', '<', $budget->start_date)
            ->orderByDesc('start_date')
            ->first();

        $sources = CommissionProfile::query()
            ->with(['rules', 'assignments', 'family'])
            ->leftJoin('budgets as source_budgets', 'source_budgets.id', '=', 'commission_profiles.budget_id')
            ->select('commission_profiles.*')
            ->where('commission_profiles.is_active', true)
            ->whereHas('family', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) use ($budget, $previous) {
                $query->whereNull('commission_profiles.budget_id');
                if ($previous) {
                    $query->orWhere('commission_profiles.budget_id', $previous->id);
                }
                $query->orWhere(function ($resumed) use ($budget) {
                    $resumed->where('source_budgets.start_date', '<', $budget->start_date)
                        ->whereHas('family', fn ($family) => $family->whereNotNull('status_changed_at'));
                });
            })
            ->orderBy('source_budgets.start_date')
            ->get()
            ->keyBy('family_id');

        foreach ($sources as $source) {
            $this->copyVersion($source, $budget);
        }
    }

    public function copyToExistingFutureBudgets(CommissionProfile $profile): void
    {
        if (! $profile->budget_id) {
            return;
        }

        $sourceBudget = Budget::findOrFail($profile->budget_id);
        Budget::query()
            ->where('start_date', '>', $sourceBudget->start_date)
            ->where(function ($query) {
                $query->whereNull('is_closed')->orWhere('is_closed', false);
            })
            ->orderBy('start_date')
            ->each(function (Budget $budget) use ($profile) {
                $this->copyVersion($profile, $budget);
            });
    }

    public function copyVersion(CommissionProfile $source, Budget $budget): ?CommissionProfile
    {
        if ($budget->is_closed || ! $source->family_id || $source->family?->status !== 'active') {
            return null;
        }

        if (CommissionProfile::query()
            ->where('family_id', $source->family_id)
            ->where('budget_id', $budget->id)
            ->exists()) {
            return null;
        }

        $source->loadMissing(['rules', 'assignments']);
        $copy = $source->replicate();
        $copy->budget_id = $budget->id;
        $copy->inherited_from_id = $source->id;
        $copy->is_inherited = true;
        $copy->save();

        foreach ($source->rules as $rule) {
            $copy->rules()->save($rule->replicate());
        }
        foreach ($source->assignments as $assignment) {
            $copy->assignments()->save($assignment->replicate());
        }

        return $copy;
    }

    public function refreshInheritedFutureVersions(CommissionProfile $source): void
    {
        $openBudgetIds = Budget::query()
            ->where(function ($query) {
                $query->whereNull('is_closed')->orWhere('is_closed', false);
            })->select('id');

        $children = CommissionProfile::query()
            ->where('inherited_from_id', $source->id)
            ->where('is_inherited', true)
            ->whereIn('budget_id', $openBudgetIds)
            ->get();

        $source->loadMissing(['rules', 'assignments']);
        foreach ($children as $child) {
            $child->fill($source->only([
                'name', 'profile_type', 'commission_mode', 'category_role_id',
                'commission_percentage', 'commission_percentage100', 'commission_percentage120',
                'minimum_fulfillment_pct', 'target_amount_usd', 'note',
            ]));
            $child->save();
            $child->rules()->delete();
            $child->assignments()->delete();
            foreach ($source->rules as $rule) {
                $child->rules()->save($rule->replicate());
            }
            foreach ($source->assignments as $assignment) {
                $child->assignments()->save($assignment->replicate());
            }
            $this->refreshInheritedFutureVersions($child->fresh(['rules', 'assignments']));
        }
    }

    public function setStatus(CommissionProfile $profile, string $status): CommissionProfileFamily
    {
        return DB::connection('budget')->transaction(function () use ($profile, $status) {
            $family = $profile->family;
            $sourceBudget = $profile->budget_id ? Budget::find($profile->budget_id) : Budget::query()
                ->whereDate('start_date', '<=', now('America/Bogota')->toDateString())
                ->whereDate('end_date', '>=', now('America/Bogota')->toDateString())
                ->first();
            $currentBudget = Budget::query()
                ->whereDate('start_date', '<=', now('America/Bogota')->toDateString())
                ->whereDate('end_date', '>=', now('America/Bogota')->toDateString())
                ->first();
            if ($currentBudget && (! $sourceBudget || $currentBudget->start_date > $sourceBudget->start_date)) {
                $sourceBudget = $currentBudget;
            }

            if (! $profile->budget_id && $sourceBudget && $family->status === 'active') {
                $this->copyVersion($profile, $sourceBudget);
            }
            $family->update(['status' => $status, 'status_changed_at' => now()]);
            if (! $profile->budget_id) {
                $profile->update(['is_active' => false]);
                if ($sourceBudget && $status === 'active') {
                    $this->copyVersion($profile, $sourceBudget);
                }
            }

            $future = $family->versions()->whereNotNull('budget_id');
            if ($sourceBudget) {
                $future->whereIn('budget_id', Budget::query()
                    ->where('start_date', $status === 'active' ? '>=' : '>', $sourceBudget->start_date)
                    ->where(function ($query) {
                        $query->whereNull('is_closed')->orWhere('is_closed', false);
                    })->select('id'));
            } else {
                $future->whereIn('budget_id', Budget::query()
                    ->whereDate('start_date', '>=', now('America/Bogota')->toDateString())
                    ->where(function ($query) {
                        $query->whereNull('is_closed')->orWhere('is_closed', false);
                    })->select('id'));
            }
            $future->update(['is_active' => $status === 'active']);

            if ($status === 'active') {
                $source = $profile->budget_id
                    ? ($family->versions()->where('budget_id', $sourceBudget?->id)->first() ?? $profile)
                    : $family->versions()->where('budget_id', $sourceBudget?->id)->first();
                if ($source) {
                    if ($sourceBudget && $source->budget_id !== $sourceBudget->id) {
                        $source = $this->copyVersion($source, $sourceBudget) ?? $source;
                    }
                    $this->copyToExistingFutureBudgets($source);
                }
            }

            return $family;
        });
    }
}
