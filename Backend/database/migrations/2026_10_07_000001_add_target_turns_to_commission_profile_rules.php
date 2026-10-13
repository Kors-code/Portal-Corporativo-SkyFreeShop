<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('budget')->hasTable('commission_profile_rules')) {
            return;
        }

        Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
            if (! Schema::connection('budget')->hasColumn('commission_profile_rules', 'target_turns')) {
                $table->decimal('target_turns', 8, 2)->nullable()->after('target_amount_usd');
            }
        });
    }

    public function down(): void
    {
        if (
            Schema::connection('budget')->hasTable('commission_profile_rules')
            && Schema::connection('budget')->hasColumn('commission_profile_rules', 'target_turns')
        ) {
            Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
                $table->dropColumn('target_turns');
            });
        }
    }
};
