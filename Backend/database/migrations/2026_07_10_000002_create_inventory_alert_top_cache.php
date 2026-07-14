<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('budget');

        if (!$schema->hasTable('inventory_alert_top_cache')) {
            $schema->create('inventory_alert_top_cache', function (Blueprint $table) {
                $table->id();
                $table->string('cache_key', 64)->unique();
                $table->text('store_ids_json');
                $table->unsignedTinyInteger('months');
                $table->unsignedSmallInteger('limit');
                $table->longText('products_json');
                $table->timestamp('computed_at')->nullable();
                $table->timestamps();
                $table->index(['months', 'limit']);
                $table->index('computed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('budget')->dropIfExists('inventory_alert_top_cache');
    }
};
