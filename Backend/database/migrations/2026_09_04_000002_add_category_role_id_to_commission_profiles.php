<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('budget')->hasTable('commission_profiles')) {
            return;
        }

        if (! Schema::connection('budget')->hasColumn('commission_profiles', 'category_role_id')) {
            Schema::connection('budget')->table('commission_profiles', function (Blueprint $table) {
                $table->unsignedBigInteger('category_role_id')->nullable()->after('commission_mode')->index();
            });
        }

        $defaultRoleId = DB::connection('budget')->table('roles')->where('name', 'seller')->value('id')
            ?? DB::connection('budget')->table('roles')->orderBy('id')->value('id');

        if ($defaultRoleId) {
            DB::connection('budget')
                ->table('commission_profiles')
                ->whereNull('category_role_id')
                ->update(['category_role_id' => $defaultRoleId]);
        }
    }

    public function down(): void
    {
        if (
            Schema::connection('budget')->hasTable('commission_profiles')
            && Schema::connection('budget')->hasColumn('commission_profiles', 'category_role_id')
        ) {
            Schema::connection('budget')->table('commission_profiles', function (Blueprint $table) {
                $table->dropColumn('category_role_id');
            });
        }
    }
};
