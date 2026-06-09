<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'budget';

    public function up(): void
    {
        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_value DECIMAL(15,6) NULL'
        );
    }

    public function down(): void
    {
        DB::connection('budget')->statement(
            'ALTER TABLE category_commissions MODIFY participation_value DECIMAL(15,2) NULL'
        );
    }
};
