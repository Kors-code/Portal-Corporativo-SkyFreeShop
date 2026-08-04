<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::connection('budget')->table('banks')->updateOrInsert(
            ['code' => 'bancodebogota'],
            ['name' => 'Banco de Bogota', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        $bankId = DB::connection('budget')->table('banks')->where('code', 'bancodebogota')->value('id');
        if (! $bankId) {
            return;
        }

        DB::connection('budget')->table('bank_accounts')->updateOrInsert(
            ['bank_id' => $bankId, 'account_number' => '532444098'],
            [
                'account_type' => 'ahorros',
                'name' => 'Banco de Bogota Ahorros',
                'accounting_account' => '11102004',
                'accounting_name' => 'Banco de Bogota Ahorros',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::connection('budget')->table('bank_file_formats')->updateOrInsert(
            ['code' => 'bancodebogota_sales_csv'],
            [
                'bank_id' => $bankId,
                'name' => 'Ventas Banco de Bogota CSV',
                'source_type' => 'card_settlement',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        $bankId = DB::connection('budget')->table('banks')->where('code', 'bancodebogota')->value('id');
        if (! $bankId) {
            return;
        }

        DB::connection('budget')->table('bank_file_formats')->where('code', 'bancodebogota_sales_csv')->delete();
        DB::connection('budget')->table('bank_accounts')->where('bank_id', $bankId)->where('account_number', '532444098')->delete();
        DB::connection('budget')->table('banks')->where('id', $bankId)->delete();
    }
};
