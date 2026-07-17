<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;

class RolePermissionPresetService
{
    public const PRESETS = [
        'super_admin' => ['*'],
        'administrativo' => [
            'portal.view',
        ],
        'lider' => [
            'panel.view',
            'portal.view',
            'disciplines.view',
            'imports.create',
            'wishlist.view',
            'entregas.view',
            'entregas.manage',
            'visualizations.view',
        ],
        'seller' => [
            'portal.view',
            'budget.view',
            'commissions.user.view',
            'wishlist.view',
        ],
        'especializado' => [
            'portal.view',
            'panel.view',
            'budget.view',
            'budget.specialists.view',
            'commissions.asesorSpecialist.view',
        ],
        'cashier' => [
            'portal.view',
            'panel.view',
            'budget.view',
            'budget.cashier.view',
            'candidates.view',
            'wishlist.view',
        ],
        'adminpresupuesto' => [
            'portal.view',
            'panel.view',
            'budget.view',
            'budget.commissions.view',
            'budget.cashier.view',
            'budget.advisors.view',
            'budget.specialists.view',
            'commissions.asesorSpecialist.view',
        ],
    ];

    public function permissionIdsForRole(?string $role): array
    {
        $patterns = self::PRESETS[$role ?? ''] ?? [];

        if (in_array('*', $patterns, true)) {
            return Permission::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if (empty($patterns)) {
            return [];
        }

        return Permission::query()
            ->get(['id', 'name'])
            ->filter(fn (Permission $permission) => $this->matchesAnyPattern($permission->name, $patterns))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function syncUserToRolePreset(User $user): void
    {
        $user->permissions()->sync($this->permissionIdsForRole($user->role));
    }

    private function matchesAnyPattern(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matchesPattern($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $name, string $pattern): bool
    {
        $permission = strtolower(trim($name));
        $needle = strtolower(trim($pattern));

        if ($needle === '*') {
            return true;
        }

        if (str_ends_with($needle, '*')) {
            return str_starts_with($permission, substr($needle, 0, -1));
        }

        return $permission === $needle || str_starts_with($permission, $needle) || str_contains($permission, $needle);
    }
}
