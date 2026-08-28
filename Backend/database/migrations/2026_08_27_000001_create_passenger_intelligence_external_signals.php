<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('budget');

        if (!$schema->hasTable('passenger_intelligence_external_signals')) {
            $schema->create('passenger_intelligence_external_signals', function (Blueprint $table) {
                $table->id();
                $table->date('date_from');
                $table->date('date_to');
                $table->string('signal_type', 60);
                $table->string('name', 180);
                $table->string('location', 160)->default('Medellin / Antioquia');
                $table->string('source_name', 180);
                $table->string('source_url', 1000)->nullable();
                $table->date('source_published_at')->nullable();
                $table->string('expected_impact', 20)->default('medium');
                $table->string('impact_direction', 80)->default('increase_passenger_flow');
                $table->unsignedTinyInteger('impact_score')->default(50);
                $table->string('verification_status', 40)->default('verified');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['date_from', 'date_to', 'signal_type', 'name'], 'pi_ext_signals_period_name_uidx');
                $table->index(['date_from', 'date_to'], 'pi_ext_signals_dates_idx');
                $table->index(['signal_type', 'expected_impact'], 'pi_ext_signals_type_impact_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('budget')->dropIfExists('passenger_intelligence_external_signals');
    }
};
