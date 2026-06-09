<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'visualizations.view')->value('id');

        if (!$permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'visualizations.view',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', ['super_admin', 'lider'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
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

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'visualizations.view')->value('id');

        if (!$permissionId) {
            return;
        }

        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', function ($query) {
                $query->select('id')
                    ->from('roles')
                    ->whereIn('name', ['super_admin', 'lider']);
            })
            ->delete();

        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
