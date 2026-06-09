<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = [];

        foreach (['entregas.view', 'entregas.manage'] as $permissionName) {
            $permissionIds[$permissionName] = DB::table('permissions')->where('name', $permissionName)->value('id');

            if (!$permissionIds[$permissionName]) {
                $permissionIds[$permissionName] = DB::table('permissions')->insertGetId([
                    'name' => $permissionName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', ['super_admin', 'lider'])
            ->pluck('id');

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

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['entregas.view', 'entregas.manage'])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->whereIn('permission_id', $permissionIds)
            ->whereIn('role_id', function ($query) {
                $query->select('id')
                    ->from('roles')
                    ->whereIn('name', ['super_admin', 'lider']);
            })
            ->delete();

        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
