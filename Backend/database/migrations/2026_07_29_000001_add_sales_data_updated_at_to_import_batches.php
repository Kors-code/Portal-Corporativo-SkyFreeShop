<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('budget')->hasTable('import_batches')) {
            return;
        }

        Schema::connection('budget')->table('import_batches', function (Blueprint $table) {
            if (!Schema::connection('budget')->hasColumn('import_batches', 'sales_data_updated_at')) {
                $table->timestamp('sales_data_updated_at')->nullable()->after('import_date')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('budget')->hasTable('import_batches')) {
            return;
        }

        Schema::connection('budget')->table('import_batches', function (Blueprint $table) {
            if (Schema::connection('budget')->hasColumn('import_batches', 'sales_data_updated_at')) {
                $table->dropIndex(['sales_data_updated_at']);
                $table->dropColumn('sales_data_updated_at');
            }
        });
    }
};
