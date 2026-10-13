<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('budget')->hasTable('commission_profile_rules')) {
            return;
        }

        Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
            if (! Schema::connection('budget')->hasColumn('commission_profile_rules', 'target_amount_usd')) {
                $table->decimal('target_amount_usd', 15, 2)->nullable()->after('participation_pct');
            }
        });

        DB::connection('budget')
            ->table('commission_profile_rules as r')
            ->join('commission_profiles as p', 'p.id', '=', 'r.commission_profile_id')
            ->whereNull('r.target_amount_usd')
            ->update([
                'r.target_amount_usd' => DB::raw(
                    'CASE WHEN COALESCE(r.participation_pct, 0) > 0 ' .
                    'THEN ROUND(COALESCE(p.target_amount_usd, 0) * (r.participation_pct / 100), 2) ' .
                    'ELSE p.target_amount_usd END'
                ),
            ]);
    }

    public function down(): void
    {
        if (
            Schema::connection('budget')->hasTable('commission_profile_rules')
            && Schema::connection('budget')->hasColumn('commission_profile_rules', 'target_amount_usd')
        ) {
            Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
                $table->dropColumn('target_amount_usd');
            });
        }
    }
};
