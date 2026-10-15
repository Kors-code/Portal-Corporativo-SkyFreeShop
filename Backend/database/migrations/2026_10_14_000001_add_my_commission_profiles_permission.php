<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'commission_profiles.my.view';

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = DB::table('roles')->whereIn(DB::raw('LOWER(name)'), ['seller', 'vendedor'])->pluck('id');
        foreach ($roleIds as $roleId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();
            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $userIds = DB::table('users')->whereRaw('LOWER(role) = ?', ['seller'])->pluck('id');
        foreach ($userIds as $userId) {
            $exists = DB::table('user_permissions')
                ->where('user_id', $userId)
                ->where('permission_id', $permissionId)
                ->exists();
            if (! $exists) {
                DB::table('user_permissions')->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');
        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('user_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
