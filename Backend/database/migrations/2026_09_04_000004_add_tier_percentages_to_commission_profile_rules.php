<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('budget')->hasTable('commission_profile_rules')) {
            return;
        }

        Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
            if (! Schema::connection('budget')->hasColumn('commission_profile_rules', 'commission_percentage')) {
                $table->decimal('commission_percentage', 8, 4)->default(0)->after('product_code');
            }

            if (! Schema::connection('budget')->hasColumn('commission_profile_rules', 'commission_percentage100')) {
                $table->decimal('commission_percentage100', 8, 4)->default(0)->after('commission_percentage');
            }

            if (! Schema::connection('budget')->hasColumn('commission_profile_rules', 'commission_percentage120')) {
                $table->decimal('commission_percentage120', 8, 4)->default(0)->after('commission_percentage100');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('budget')->hasTable('commission_profile_rules')) {
            return;
        }

        Schema::connection('budget')->table('commission_profile_rules', function (Blueprint $table) {
            foreach (['commission_percentage120', 'commission_percentage100', 'commission_percentage'] as $column) {
                if (Schema::connection('budget')->hasColumn('commission_profile_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
