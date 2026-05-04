<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->table('inventory_import_batches', function (Blueprint $table) {
            if (!Schema::connection('budget')->hasColumn('inventory_import_batches', 'to_date')) {
                $table->date('to_date')->nullable()->after('store_id');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_import_batches', 'rows_imported')) {
                $table->integer('rows_imported')->default(0)->after('to_date');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_import_batches', 'status')) {
                $table->string('status', 50)->default('completed')->after('rows_imported');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_import_batches', 'checksum')) {
                $table->string('checksum', 64)->nullable()->after('status');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_import_batches', 'notes')) {
                $table->text('notes')->nullable()->after('checksum');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_import_batches', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        Schema::connection('budget')->table('inventory_movements', function (Blueprint $table) {
            if (!Schema::connection('budget')->hasColumn('inventory_movements', 'store_id')) {
                $table->unsignedBigInteger('store_id')->nullable()->after('product_id');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_movements', 'movement_date')) {
                $table->date('movement_date')->nullable()->after('quantity');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_movements', 'snapshot_date')) {
                $table->date('snapshot_date')->nullable()->after('movement_date');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_movements', 'batch_id')) {
                $table->bigInteger('batch_id')->nullable()->after('snapshot_date');
            }

            if (!Schema::connection('budget')->hasColumn('inventory_movements', 'meta')) {
                $table->json('meta')->nullable()->after('note');
            }
        });

        Schema::connection('budget')->table('inventory', function (Blueprint $table) {
            $table->index(['store_id', 'toDate', 'product_id'], 'idx_inventory_store_date_product');
        });
    }

    public function down(): void
    {
        Schema::connection('budget')->table('inventory', function (Blueprint $table) {
            $table->dropIndex('idx_inventory_store_date_product');
        });

        Schema::connection('budget')->table('inventory_movements', function (Blueprint $table) {
            $dropColumns = [];

            foreach (['store_id', 'movement_date', 'snapshot_date', 'batch_id', 'meta'] as $column) {
                if (Schema::connection('budget')->hasColumn('inventory_movements', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });

        Schema::connection('budget')->table('inventory_import_batches', function (Blueprint $table) {
            $dropColumns = [];

            foreach (['to_date', 'rows_imported', 'status', 'checksum', 'notes', 'updated_at'] as $column) {
                if (Schema::connection('budget')->hasColumn('inventory_import_batches', $column)) {
                    $dropColumns[] = $column;
                }
            }

            if (!empty($dropColumns)) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
