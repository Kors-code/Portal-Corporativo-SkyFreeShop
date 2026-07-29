<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventarios.cobertura', 'inventarios.alertas', 'inventarios.importes'] as $permissionName) {
            $exists = DB::table('permissions')->where('name', $permissionName)->exists();

            if (! $exists) {
                DB::table('permissions')->insert([
                    'name' => $permissionName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['inventarios.cobertura', 'inventarios.alertas', 'inventarios.importes'])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('user_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
