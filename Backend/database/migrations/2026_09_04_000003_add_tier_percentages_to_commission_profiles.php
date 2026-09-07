<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('budget')->hasTable('commission_profiles')) {
            return;
        }

        Schema::connection('budget')->table('commission_profiles', function (Blueprint $table) {
            if (! Schema::connection('budget')->hasColumn('commission_profiles', 'commission_percentage100')) {
                $table->decimal('commission_percentage100', 8, 4)->default(0)->after('commission_percentage');
            }

            if (! Schema::connection('budget')->hasColumn('commission_profiles', 'commission_percentage120')) {
                $table->decimal('commission_percentage120', 8, 4)->default(0)->after('commission_percentage100');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('budget')->hasTable('commission_profiles')) {
            return;
        }

        Schema::connection('budget')->table('commission_profiles', function (Blueprint $table) {
            if (Schema::connection('budget')->hasColumn('commission_profiles', 'commission_percentage120')) {
                $table->dropColumn('commission_percentage120');
            }

            if (Schema::connection('budget')->hasColumn('commission_profiles', 'commission_percentage100')) {
                $table->dropColumn('commission_percentage100');
            }
        });
    }
};
