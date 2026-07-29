<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        'inventarios.cobertura',
        'inventarios.alertas',
        'inventarios.importes',
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permissionName) {
            $exists = DB::table('permissions')->where('name', $permissionName)->exists();

            if ($exists) {
                DB::table('permissions')
                    ->where('name', $permissionName)
                    ->update(['updated_at' => now()]);

                continue;
            }

            DB::table('permissions')->insert([
                'name' => $permissionName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $legacyIds = DB::table('permissions')
            ->whereIn('name', [
                'inventarios.alertas_cobertura',
                'inventory-alerts.view',
                'inventory-alerts.manage',
            ])
            ->pluck('id');

        if ($legacyIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $legacyIds)->delete();
            DB::table('user_permissions')->whereIn('permission_id', $legacyIds)->delete();
            DB::table('permissions')->whereIn('id', $legacyIds)->delete();
        }
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
};
