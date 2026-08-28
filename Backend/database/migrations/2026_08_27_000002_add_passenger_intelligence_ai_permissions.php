<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'passenger-intelligence.forecast',
        'passenger-intelligence.signals.manage',
    ];

    public function up(): void
    {
        $permissionIds = [];

        foreach ($this->permissions as $permissionName) {
            $permissionIds[$permissionName] = DB::table('permissions')->where('name', $permissionName)->value('id');

            if (!$permissionIds[$permissionName]) {
                $permissionIds[$permissionName] = DB::table('permissions')->insertGetId([
                    'name' => $permissionName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->grantToRoles(array_values($permissionIds), ['super_admin', 'admin', 'adminpresupuesto']);
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    private function grantToRoles(array $permissionIds, array $roleNames): void
    {
        $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
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
};
