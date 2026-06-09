<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'budget';

    public function up(): void
    {
        DB::connection('budget')->statement(
            "UPDATE category_commissions cc
             LEFT JOIN budgets b ON b.id = cc.budget_id
             LEFT JOIN advisor_budgets ab ON ab.budget_id = cc.budget_id AND ab.role_id = cc.role_id
             SET cc.participation_value = ROUND(
                 (COALESCE(NULLIF(CASE WHEN cc.role_id IN (4, 5) THEN ab.budget_usd ELSE b.target_amount END, 0), b.target_amount, 0)
                 * COALESCE(cc.participation_pct, 0)) / 100
             )
             WHERE cc.participation_value IS NULL"
        );

        DB::connection('budget')->statement(
            'UPDATE category_commissions SET participation_value = ROUND(COALESCE(participation_value, 0))'
        );

        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_value DECIMAL(15,0) NULL'
        );
    }

    public function down(): void
    {
        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_value DECIMAL(15,6) NULL'
        );
    }
};
