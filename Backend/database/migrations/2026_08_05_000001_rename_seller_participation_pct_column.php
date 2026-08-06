<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::connection('budget')->hasColumn('category_commissions', 'particiaption_value_budget_Asesors')
            && !Schema::connection('budget')->hasColumn('category_commissions', 'particiaption_pct_sellers')
        ) {
            DB::connection('budget')->statement(
                'ALTER TABLE category_commissions CHANGE particiaption_value_budget_Asesors particiaption_pct_sellers DECIMAL(15,9) NULL'
            );
        }
    }

    public function down(): void
    {
        if (
            Schema::connection('budget')->hasColumn('category_commissions', 'particiaption_pct_sellers')
            && !Schema::connection('budget')->hasColumn('category_commissions', 'particiaption_value_budget_Asesors')
        ) {
            DB::connection('budget')->statement(
                'ALTER TABLE category_commissions CHANGE particiaption_pct_sellers particiaption_value_budget_Asesors DECIMAL(15,6) NULL'
            );
        }
    }
};
