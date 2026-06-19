<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_pct DECIMAL(15,9) NOT NULL DEFAULT 0.000000000'
        );

        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_value DECIMAL(15,2) NULL'
        );
    }

    public function down(): void
    {
        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_pct DECIMAL(12,8) NOT NULL DEFAULT 0.00000000'
        );

        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_value DECIMAL(15,0) NULL'
        );
    }
};
