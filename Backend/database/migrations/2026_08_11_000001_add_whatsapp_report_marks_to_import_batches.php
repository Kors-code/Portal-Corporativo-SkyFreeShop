<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->table('import_batches', function (Blueprint $table) {
            if (!Schema::connection('budget')->hasColumn('import_batches', 'whatsapp_reports_started_at')) {
                $table->timestamp('whatsapp_reports_started_at')->nullable()->after('sales_data_updated_at');
            }

            if (!Schema::connection('budget')->hasColumn('import_batches', 'whatsapp_reports_sent_at')) {
                $table->timestamp('whatsapp_reports_sent_at')->nullable()->after('whatsapp_reports_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('budget')->table('import_batches', function (Blueprint $table) {
            if (Schema::connection('budget')->hasColumn('import_batches', 'whatsapp_reports_sent_at')) {
                $table->dropColumn('whatsapp_reports_sent_at');
            }

            if (Schema::connection('budget')->hasColumn('import_batches', 'whatsapp_reports_started_at')) {
                $table->dropColumn('whatsapp_reports_started_at');
            }
        });
    }
};
