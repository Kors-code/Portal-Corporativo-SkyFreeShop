<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('budget');

        if (!$schema->hasTable('bank_import_batches')) {
            $schema->create('bank_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('bank', 60);
                $table->string('source_type', 40)->default('bank_movements');
                $table->string('filename');
                $table->string('stored_path')->nullable();
                $table->string('checksum', 64)->nullable();
                $table->string('status', 40)->default('pending');
                $table->unsignedInteger('rows')->default(0);
                $table->unsignedInteger('rows_imported')->default(0);
                $table->unsignedInteger('rows_skipped')->default(0);
                $table->date('from_date')->nullable();
                $table->date('to_date')->nullable();
                $table->decimal('total_sale_amount', 18, 2)->default(0);
                $table->decimal('total_commission_amount', 18, 2)->default(0);
                $table->decimal('total_withholding_amount', 18, 2)->default(0);
                $table->decimal('total_income_amount', 18, 2)->default(0);
                $table->decimal('total_debit_amount', 18, 2)->default(0);
                $table->decimal('total_credit_amount', 18, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->longText('note')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['bank', 'created_at'], 'bank_import_batches_bank_created_idx');
                $table->index(['checksum', 'bank'], 'bank_import_batches_checksum_bank_idx');
                $table->index(['status', 'created_at'], 'bank_import_batches_status_created_idx');
            });
        }

        if (!$schema->hasTable('bank_movements')) {
            $schema->create('bank_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->string('bank', 60);
                $table->string('source_type', 40)->default('bank_movements');
                $table->unsignedInteger('row_number')->nullable();
                $table->date('movement_date')->nullable();
                $table->date('process_date')->nullable();
                $table->date('deposit_date')->nullable();
                $table->time('movement_time')->nullable();
                $table->string('account_number', 80)->nullable();
                $table->string('branch_code', 60)->nullable();
                $table->string('transaction_code', 80)->nullable();
                $table->string('reference', 120)->nullable();
                $table->string('receipt_number', 80)->nullable();
                $table->string('authorization_number', 80)->nullable();
                $table->string('terminal', 80)->nullable();
                $table->string('network', 80)->nullable();
                $table->string('card_type', 120)->nullable();
                $table->string('card_last_digits', 30)->nullable();
                $table->string('counterparty', 160)->nullable();
                $table->string('description', 255)->nullable();
                $table->string('movement_type', 60)->nullable();
                $table->string('category', 80)->nullable();
                $table->string('currency', 10)->default('COP');
                $table->decimal('sale_amount', 18, 2)->default(0);
                $table->decimal('commission_amount', 18, 2)->default(0);
                $table->decimal('withholding_amount', 18, 2)->default(0);
                $table->decimal('withholding_source_amount', 18, 2)->default(0);
                $table->decimal('withholding_vat_amount', 18, 2)->default(0);
                $table->decimal('withholding_ica_amount', 18, 2)->default(0);
                $table->decimal('vat_amount', 18, 2)->default(0);
                $table->decimal('consumption_tax_amount', 18, 2)->default(0);
                $table->decimal('tip_amount', 18, 2)->default(0);
                $table->decimal('income_amount', 18, 2)->default(0);
                $table->decimal('debit_amount', 18, 2)->default(0);
                $table->decimal('credit_amount', 18, 2)->default(0);
                $table->decimal('net_amount', 18, 2)->default(0);
                $table->boolean('is_sale')->default(false);
                $table->boolean('is_income')->default(false);
                $table->boolean('is_expense')->default(false);
                $table->boolean('is_excluded')->default(false);
                $table->string('exclude_reason')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();

                $table->foreign('batch_id', 'bank_movements_batch_fk')
                    ->references('id')
                    ->on('bank_import_batches')
                    ->cascadeOnDelete();
                $table->index(['batch_id', 'id'], 'bank_movements_batch_id_idx');
                $table->index(['bank', 'deposit_date'], 'bank_movements_bank_deposit_idx');
                $table->index(['bank', 'movement_date'], 'bank_movements_bank_movement_idx');
                $table->index(['transaction_code', 'category'], 'bank_movements_code_category_idx');
            });
        }

        if (!$schema->hasTable('bank_daily_summaries')) {
            $schema->create('bank_daily_summaries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->string('bank', 60);
                $table->date('summary_date');
                $table->unsignedInteger('movements_count')->default(0);
                $table->decimal('sale_amount', 18, 2)->default(0);
                $table->decimal('commission_amount', 18, 2)->default(0);
                $table->decimal('withholding_amount', 18, 2)->default(0);
                $table->decimal('income_amount', 18, 2)->default(0);
                $table->decimal('debit_amount', 18, 2)->default(0);
                $table->decimal('credit_amount', 18, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('batch_id', 'bank_daily_summaries_batch_fk')
                    ->references('id')
                    ->on('bank_import_batches')
                    ->cascadeOnDelete();
                $table->unique(['bank', 'summary_date', 'batch_id'], 'bank_daily_summaries_bank_date_batch_unique');
                $table->index(['summary_date', 'bank'], 'bank_daily_summaries_date_bank_idx');
            });
        }

        if (!$schema->hasTable('bank_cash_receipts')) {
            $schema->create('bank_cash_receipts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->string('bank', 60);
                $table->date('receipt_date');
                $table->unsignedInteger('receipt_number')->nullable();
                $table->decimal('sale_amount', 18, 2)->default(0);
                $table->decimal('commission_amount', 18, 2)->default(0);
                $table->decimal('withholding_amount', 18, 2)->default(0);
                $table->decimal('income_amount', 18, 2)->default(0);
                $table->string('generated_filename')->nullable();
                $table->string('generated_path')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('batch_id', 'bank_cash_receipts_batch_fk')
                    ->references('id')
                    ->on('bank_import_batches')
                    ->cascadeOnDelete();
                $table->index(['bank', 'receipt_date'], 'bank_cash_receipts_bank_date_idx');
                $table->index(['receipt_number', 'bank'], 'bank_cash_receipts_number_bank_idx');
            });
        }

        if (!$schema->hasTable('bank_classification_rules')) {
            $schema->create('bank_classification_rules', function (Blueprint $table) {
                $table->id();
                $table->string('bank', 60);
                $table->string('transaction_code', 80)->nullable();
                $table->string('description_contains', 160)->nullable();
                $table->string('category', 80);
                $table->boolean('counts_as_sale')->default(false);
                $table->boolean('counts_as_income')->default(false);
                $table->boolean('counts_as_expense')->default(false);
                $table->string('amount_target', 40)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('priority')->default(100);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['bank', 'is_active', 'priority'], 'bank_rules_bank_active_priority_idx');
                $table->index(['bank', 'transaction_code'], 'bank_rules_bank_code_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('budget');

        $schema->dropIfExists('bank_classification_rules');
        $schema->dropIfExists('bank_cash_receipts');
        $schema->dropIfExists('bank_daily_summaries');
        $schema->dropIfExists('bank_movements');
        $schema->dropIfExists('bank_import_batches');
    }
};
