<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('budget');

        if (!$schema->hasTable('banks')) {
            $schema->create('banks', function (Blueprint $table) {
                $table->id();
                $table->string('code', 60)->unique();
                $table->string('name', 120);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('bank_accounts')) {
            $schema->create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bank_id');
                $table->string('account_number', 80);
                $table->string('account_type', 40)->nullable();
                $table->string('name', 160)->nullable();
                $table->string('accounting_account', 40)->nullable();
                $table->string('accounting_name', 160)->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('bank_id', 'bank_accounts_bank_fk')
                    ->references('id')
                    ->on('banks');
                $table->unique(['bank_id', 'account_number'], 'bank_accounts_bank_number_unique');
            });
        }

        if (!$schema->hasTable('bank_file_formats')) {
            $schema->create('bank_file_formats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bank_id');
                $table->string('code', 80)->unique();
                $table->string('name', 140);
                $table->string('source_type', 60);
                $table->string('parser_class', 160)->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('bank_id', 'bank_file_formats_bank_fk')
                    ->references('id')
                    ->on('banks');
                $table->index(['bank_id', 'source_type'], 'bank_formats_bank_source_idx');
            });
        }

        $this->seedMasterData();
        $this->addForeignColumns();
        $this->backfillBankRelations();
    }

    public function down(): void
    {
        $schema = Schema::connection('budget');

        foreach (['bank_cash_receipts', 'bank_daily_summaries', 'bank_movements', 'bank_import_batches'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $schema->table($table, function (Blueprint $table) use ($schema) {
                foreach (['file_format_id', 'bank_account_id', 'bank_id'] as $column) {
                    if ($schema->hasColumn($table->getTable(), $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        $schema->dropIfExists('bank_file_formats');
        $schema->dropIfExists('bank_accounts');
        $schema->dropIfExists('banks');
    }

    private function seedMasterData(): void
    {
        $now = now();
        $banks = [
            ['code' => 'davibank', 'name' => 'Davibank'],
            ['code' => 'davivienda', 'name' => 'Davivienda'],
            ['code' => 'bancolombia', 'name' => 'Bancolombia'],
            ['code' => 'bancodebogota', 'name' => 'Banco de Bogota'],
        ];

        foreach ($banks as $bank) {
            DB::connection('budget')->table('banks')->updateOrInsert(
                ['code' => $bank['code']],
                ['name' => $bank['name'], 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $bankIds = DB::connection('budget')->table('banks')->pluck('id', 'code');
        $accounts = [
            [
                'bank_code' => 'davibank',
                'account_number' => '6841002235',
                'account_type' => 'corriente',
                'name' => 'Davibank ventas tarjeta',
                'accounting_account' => '11102006',
                'accounting_name' => 'Cuenta corriente Colpatria 6841002235-pesos',
            ],
            [
                'bank_code' => 'davivienda',
                'account_number' => '475670049406',
                'account_type' => 'ahorros',
                'name' => 'Cta ahorro Davivienda 475670049406',
                'accounting_account' => '11200501',
                'accounting_name' => 'Cta ahorro Davivienda 475670049406',
            ],
            [
                'bank_code' => 'bancolombia',
                'account_number' => '024-000046-94',
                'account_type' => 'ahorros',
                'name' => 'Banc ahorro 4694',
                'accounting_account' => '11200502',
                'accounting_name' => 'Banc ahorro 4694',
            ],
            [
                'bank_code' => 'bancodebogota',
                'account_number' => '532444098',
                'account_type' => 'ahorros',
                'name' => 'Banco de Bogota Ahorros',
                'accounting_account' => '11102004',
                'accounting_name' => 'Banco de Bogota Ahorros',
            ],
        ];

        foreach ($accounts as $account) {
            $bankId = $bankIds[$account['bank_code']] ?? null;
            if (!$bankId) {
                continue;
            }

            DB::connection('budget')->table('bank_accounts')->updateOrInsert(
                ['bank_id' => $bankId, 'account_number' => $account['account_number']],
                [
                    'account_type' => $account['account_type'],
                    'name' => $account['name'],
                    'accounting_account' => $account['accounting_account'],
                    'accounting_name' => $account['accounting_name'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $formats = [
            ['bank_code' => 'davibank', 'code' => 'davibank_sales_csv', 'name' => 'Ventas Davibank CSV', 'source_type' => 'card_settlement'],
            ['bank_code' => 'davivienda', 'code' => 'davivienda_card_detail_html', 'name' => 'Consulta detallada Davivienda', 'source_type' => 'card_settlement'],
            ['bank_code' => 'bancolombia', 'code' => 'bancolombia_movements_csv', 'name' => 'Movimientos Bancolombia CSV', 'source_type' => 'account_movement'],
            ['bank_code' => 'bancodebogota', 'code' => 'bancodebogota_sales_csv', 'name' => 'Ventas Banco de Bogota CSV', 'source_type' => 'card_settlement'],
        ];

        foreach ($formats as $format) {
            $bankId = $bankIds[$format['bank_code']] ?? null;
            if (!$bankId) {
                continue;
            }

            DB::connection('budget')->table('bank_file_formats')->updateOrInsert(
                ['code' => $format['code']],
                [
                    'bank_id' => $bankId,
                    'name' => $format['name'],
                    'source_type' => $format['source_type'],
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function addForeignColumns(): void
    {
        $schema = Schema::connection('budget');

        foreach (['bank_import_batches', 'bank_movements', 'bank_daily_summaries', 'bank_cash_receipts'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $schema->table($tableName, function (Blueprint $table) use ($schema, $tableName) {
                if (!$schema->hasColumn($tableName, 'bank_id')) {
                    $table->unsignedBigInteger('bank_id')->nullable()->after('id');
                    $table->index('bank_id', "{$tableName}_bank_id_idx");
                }
            });
        }

        if ($schema->hasTable('bank_import_batches')) {
            $schema->table('bank_import_batches', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('bank_import_batches', 'file_format_id')) {
                    $table->unsignedBigInteger('file_format_id')->nullable()->after('bank_id');
                    $table->index('file_format_id', 'bank_batches_file_format_idx');
                }

                if (!$schema->hasColumn('bank_import_batches', 'bank_account_id')) {
                    $table->unsignedBigInteger('bank_account_id')->nullable()->after('file_format_id');
                    $table->index('bank_account_id', 'bank_batches_account_idx');
                }
            });
        }

        if ($schema->hasTable('bank_movements')) {
            $schema->table('bank_movements', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('bank_movements', 'bank_account_id')) {
                    $table->unsignedBigInteger('bank_account_id')->nullable()->after('bank_id');
                    $table->index('bank_account_id', 'bank_movements_account_idx');
                }
            });
        }
    }

    private function backfillBankRelations(): void
    {
        $bankIds = DB::connection('budget')->table('banks')->pluck('id', 'code');
        $formatIds = DB::connection('budget')->table('bank_file_formats')->pluck('id', 'code');
        $accountIds = DB::connection('budget')->table('bank_accounts')->pluck('id', 'account_number');

        foreach (['bank_import_batches', 'bank_movements', 'bank_daily_summaries', 'bank_cash_receipts'] as $table) {
            if (!Schema::connection('budget')->hasTable($table)) {
                continue;
            }

            $hasSourceType = Schema::connection('budget')->hasColumn($table, 'source_type');

            foreach ($bankIds as $code => $id) {
                DB::connection('budget')->table($table)
                    ->where(function ($query) use ($code, $hasSourceType) {
                        $query->where('bank', $code);

                        if ($code === 'davibank' && $hasSourceType) {
                            $query->orWhere('source_type', 'davibank_converter');
                        }
                    })
                    ->whereNull('bank_id')
                    ->update(['bank_id' => $id]);
            }
        }

        if (Schema::connection('budget')->hasTable('bank_import_batches')) {
            DB::connection('budget')->table('bank_import_batches')
                ->where('source_type', 'davibank_converter')
                ->whereNull('file_format_id')
                ->update([
                    'bank_id' => $bankIds['davibank'] ?? null,
                    'file_format_id' => $formatIds['davibank_sales_csv'] ?? null,
                    'bank_account_id' => $accountIds['6841002235'] ?? null,
                ]);
        }

        if (Schema::connection('budget')->hasTable('bank_movements')) {
            DB::connection('budget')->table('bank_movements')
                ->where('source_type', 'davibank_converter')
                ->whereNull('bank_account_id')
                ->update([
                    'bank_id' => $bankIds['davibank'] ?? null,
                    'bank_account_id' => $accountIds['6841002235'] ?? null,
                ]);
        }
    }
};
