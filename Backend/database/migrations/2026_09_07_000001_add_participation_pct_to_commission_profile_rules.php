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

        if (! Schema::connection('budget')->hasColumn('commission_profile_rules', 'participation_pct')) {
            Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
                $table->decimal('participation_pct', 8, 4)->default(0)->after('product_code');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::connection('budget')->hasTable('commission_profile_rules')
            && Schema::connection('budget')->hasColumn('commission_profile_rules', 'participation_pct')
        ) {
            Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
                $table->dropColumn('participation_pct');
            });
        }
    }
};
