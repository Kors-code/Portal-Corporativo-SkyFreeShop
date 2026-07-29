<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $sellerPermissions = [
        'portal.view',
        'panel.view',
        'budget.view',
        'commissions.user.view',
        'wishlist.view',
    ];

    private array $cashierPermissions = [
        'portal.view',
        'panel.view',
        'budget.view',
        'budget.cashier.view',
        'wishlist.view',
    ];

    public function up(): void
    {
        $permissionIds = $this->ensurePermissions(array_values(array_unique(array_merge(
            $this->sellerPermissions,
            $this->cashierPermissions
        ))));

        $this->grantToRoles(['seller', 'vendedor'], $this->sellerPermissions, $permissionIds);
        $this->grantToRoles(['cashier', 'cajero'], $this->cashierPermissions, $permissionIds);

        $this->grantToUsers(['seller'], $this->sellerPermissions, $permissionIds);
        $this->grantToUsers(['cashier'], $this->cashierPermissions, $permissionIds);
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_values(array_unique(array_merge(
                $this->sellerPermissions,
                $this->cashierPermissions
            ))))
            ->pluck('id', 'name');

        if ($permissionIds->isEmpty()) {
            return;
        }

        $this->revokeFromRoles(['seller', 'vendedor'], $this->sellerPermissions, $permissionIds);
        $this->revokeFromRoles(['cashier', 'cajero'], $this->cashierPermissions, $permissionIds);
        $this->revokeFromUsers(['seller'], $this->sellerPermissions, $permissionIds);
        $this->revokeFromUsers(['cashier'], $this->cashierPermissions, $permissionIds);
    }

    private function ensurePermissions(array $permissionNames): array
    {
        $ids = [];

        foreach ($permissionNames as $permissionName) {
            $id = DB::table('permissions')->where('name', $permissionName)->value('id');

            if (!$id) {
                $id = DB::table('permissions')->insertGetId([
                    'name' => $permissionName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $ids[$permissionName] = (int) $id;
        }

        return $ids;
    }

    private function grantToRoles(array $roleNames, array $permissionNames, array $permissionIds): void
    {
        $roleIds = DB::table('roles')
            ->whereIn(DB::raw('LOWER(name)'), array_map('strtolower', $roleNames))
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionNames as $permissionName) {
                $permissionId = $permissionIds[$permissionName] ?? null;

                if (!$permissionId) {
                    continue;
                }

                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (!$exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    private function grantToUsers(array $roles, array $permissionNames, array $permissionIds): void
    {
        $userIds = DB::table('users')
            ->whereIn(DB::raw('LOWER(role)'), array_map('strtolower', $roles))
            ->pluck('id');

        foreach ($userIds as $userId) {
            foreach ($permissionNames as $permissionName) {
                $permissionId = $permissionIds[$permissionName] ?? null;

                if (!$permissionId) {
                    continue;
                }

                $exists = DB::table('user_permissions')
                    ->where('user_id', $userId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (!$exists) {
                    DB::table('user_permissions')->insert([
                        'user_id' => $userId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    private function revokeFromRoles(array $roleNames, array $permissionNames, $permissionIds): void
    {
        $roleIds = DB::table('roles')
            ->whereIn(DB::raw('LOWER(name)'), array_map('strtolower', $roleNames))
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', collect($permissionNames)->map(fn ($name) => $permissionIds[$name] ?? null)->filter()->values())
            ->delete();
    }

    private function revokeFromUsers(array $roles, array $permissionNames, $permissionIds): void
    {
        $userIds = DB::table('users')
            ->whereIn(DB::raw('LOWER(role)'), array_map('strtolower', $roles))
            ->pluck('id');

        DB::table('user_permissions')
            ->whereIn('user_id', $userIds)
            ->whereIn('permission_id', collect($permissionNames)->map(fn ($name) => $permissionIds[$name] ?? null)->filter()->values())
            ->delete();
    }
};
