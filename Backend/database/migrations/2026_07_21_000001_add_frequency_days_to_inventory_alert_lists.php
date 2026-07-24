<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('budget');

        if ($schema->hasTable('inventory_alert_lists') && !$schema->hasColumn('inventory_alert_lists', 'frequency_days')) {
            $schema->table('inventory_alert_lists', function (Blueprint $table) {
                $table->unsignedTinyInteger('frequency_days')->default(1)->after('auto_send');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('budget');

        if ($schema->hasTable('inventory_alert_lists') && $schema->hasColumn('inventory_alert_lists', 'frequency_days')) {
            $schema->table('inventory_alert_lists', function (Blueprint $table) {
                $table->dropColumn('frequency_days');
            });
        }
    }
};
