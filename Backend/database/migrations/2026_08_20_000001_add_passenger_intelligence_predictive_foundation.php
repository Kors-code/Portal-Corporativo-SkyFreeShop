<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('budget');

        if (!$schema->hasTable('passenger_intelligence_source_files')) {
            $schema->create('passenger_intelligence_source_files', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 40)->default('onedrive');
                $table->string('drive_item_id', 191);
                $table->string('drive_id', 191)->nullable();
                $table->string('name', 255);
                $table->string('extension', 16)->nullable();
                $table->string('mime_type', 160)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->string('web_url', 1000)->nullable();
                $table->string('parent_path', 1000)->nullable();
                $table->string('e_tag', 255)->nullable();
                $table->string('c_tag', 255)->nullable();
                $table->timestamp('source_last_modified_at')->nullable();
                $table->timestamp('discovered_at')->nullable();
                $table->timestamp('downloaded_at')->nullable();
                $table->string('checksum', 64)->nullable();
                $table->string('status', 40)->default('discovered');
                $table->json('notes')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'drive_item_id'], 'pi_src_files_provider_item_uidx');
                $table->index(['provider', 'status'], 'pi_src_files_provider_status_idx');
                $table->index('source_last_modified_at', 'pi_src_files_modified_idx');
            });
        }

        if ($schema->hasTable('passenger_intelligence_import_batches')) {
            $schema->table('passenger_intelligence_import_batches', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('passenger_intelligence_import_batches', 'source_file_id')) {
                    $table->unsignedBigInteger('source_file_id')->nullable()->after('id');
                    $table->index('source_file_id', 'pi_batches_source_file_idx');
                }

                if (!$schema->hasColumn('passenger_intelligence_import_batches', 'observed_scope')) {
                    $table->string('observed_scope', 40)->nullable()->after('source_type');
                }

                if (!$schema->hasColumn('passenger_intelligence_import_batches', 'source_path')) {
                    $table->string('source_path', 1000)->nullable()->after('observed_scope');
                }

                if (!$schema->hasColumn('passenger_intelligence_import_batches', 'source_url')) {
                    $table->string('source_url', 1000)->nullable()->after('source_path');
                }
            });
        }

        if ($schema->hasTable('passenger_intelligence_flights')) {
            $schema->table('passenger_intelligence_flights', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('passenger_intelligence_flights', 'source_file_id')) {
                    $table->unsignedBigInteger('source_file_id')->nullable()->after('batch_id');
                    $table->index('source_file_id', 'pi_flights_source_file_idx');
                }

                if (!$schema->hasColumn('passenger_intelligence_flights', 'observed_scope')) {
                    $table->string('observed_scope', 40)->nullable()->after('data_type');
                    $table->index(['data_type', 'observed_scope'], 'pi_flights_data_scope_idx');
                }
            });
        }

        if (!$schema->hasTable('passenger_intelligence_monthly_facts')) {
            $schema->create('passenger_intelligence_monthly_facts', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('airport_iata', 8)->default('MDE');
                $table->string('direction', 16)->default('total');
                $table->string('fact_type', 80);
                $table->string('source_type', 80);
                $table->decimal('value', 18, 2)->default(0);
                $table->unsignedInteger('records_count')->default(0);
                $table->unsignedBigInteger('source_file_id')->nullable();
                $table->unsignedBigInteger('import_batch_id')->nullable();
                $table->string('source_name', 160)->nullable();
                $table->string('source_url', 1000)->nullable();
                $table->string('source_period', 80)->nullable();
                $table->string('confidence_level', 16)->default('MEDIUM');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['year', 'month', 'airport_iata', 'direction', 'fact_type', 'source_type'], 'pi_monthly_facts_lookup_uidx');
                $table->index(['airport_iata', 'year', 'month'], 'pi_monthly_facts_period_idx');
                $table->index(['fact_type', 'source_type'], 'pi_monthly_facts_type_idx');
            });
        }

        if (!$schema->hasTable('passenger_intelligence_commercial_exposure_rates')) {
            $schema->create('passenger_intelligence_commercial_exposure_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->string('airport_iata', 8)->default('MDE');
                $table->string('direction', 16)->default('total');
                $table->decimal('commercial_pax', 18, 2)->default(0);
                $table->decimal('official_airport_pax', 18, 2)->nullable();
                $table->decimal('exposure_pct', 8, 3)->nullable();
                $table->string('method', 80)->default('SKYFREE_OBSERVED_VS_AEROCIVIL');
                $table->unsignedBigInteger('commercial_fact_id')->nullable();
                $table->unsignedBigInteger('official_fact_id')->nullable();
                $table->string('confidence_level', 16)->default('MEDIUM');
                $table->json('notes')->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamps();

                $table->unique(['year', 'month', 'airport_iata', 'direction', 'method'], 'pi_exposure_lookup_uidx');
                $table->index(['airport_iata', 'year', 'month'], 'pi_exposure_period_idx');
            });
        }

        if (!$schema->hasTable('passenger_intelligence_flight_estimates')) {
            $schema->create('passenger_intelligence_flight_estimates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('flight_id')
                    ->constrained('passenger_intelligence_flights')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('composition_profile_id')->nullable();
                $table->unsignedBigInteger('exposure_rate_id')->nullable();
                $table->decimal('base_pax', 12, 2);
                $table->decimal('commercial_exposed_pax', 12, 2)->nullable();
                $table->decimal('colombian_pct', 6, 3)->nullable();
                $table->decimal('foreign_pct', 6, 3)->nullable();
                $table->decimal('colombian_pax', 12, 2)->nullable();
                $table->decimal('foreign_pax', 12, 2)->nullable();
                $table->string('estimation_method', 80)->default('BASELINE_MONTHLY_PROFILE');
                $table->string('confidence_level', 16)->default('MEDIUM');
                $table->string('model_version', 40)->default('baseline_v1');
                $table->json('input_sources')->nullable();
                $table->json('explanation')->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamps();

                $table->unique(['flight_id', 'model_version'], 'pi_flight_estimates_uidx');
                $table->index(['estimation_method', 'confidence_level'], 'pi_flight_estimates_method_idx');
            });
        }

        if (!$schema->hasTable('passenger_intelligence_forecast_runs')) {
            $schema->create('passenger_intelligence_forecast_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedSmallInteger('target_year');
                $table->unsignedTinyInteger('target_month');
                $table->string('airport_iata', 8)->default('MDE');
                $table->date('run_date');
                $table->date('cutoff_date')->nullable();
                $table->string('status', 40)->default('draft');
                $table->string('method', 80)->default('MONTH_TO_DATE_PLUS_HISTORY');
                $table->string('model_version', 40)->default('baseline_v1');
                $table->decimal('actual_pax_to_date', 18, 2)->nullable();
                $table->decimal('predicted_remaining_pax', 18, 2)->nullable();
                $table->decimal('predicted_total_pax', 18, 2)->nullable();
                $table->decimal('predicted_colombian_pct', 6, 3)->nullable();
                $table->decimal('predicted_foreign_pct', 6, 3)->nullable();
                $table->string('confidence_level', 16)->default('MEDIUM');
                $table->json('input_sources')->nullable();
                $table->json('explanation')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['airport_iata', 'target_year', 'target_month'], 'pi_forecast_period_idx');
                $table->index(['status', 'run_date'], 'pi_forecast_status_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('budget');

        $schema->dropIfExists('passenger_intelligence_forecast_runs');
        $schema->dropIfExists('passenger_intelligence_flight_estimates');
        $schema->dropIfExists('passenger_intelligence_commercial_exposure_rates');
        $schema->dropIfExists('passenger_intelligence_monthly_facts');

        if ($schema->hasTable('passenger_intelligence_flights')) {
            $schema->table('passenger_intelligence_flights', function (Blueprint $table) use ($schema) {
                if ($schema->hasColumn('passenger_intelligence_flights', 'observed_scope')) {
                    $table->dropIndex('pi_flights_data_scope_idx');
                    $table->dropColumn('observed_scope');
                }

                if ($schema->hasColumn('passenger_intelligence_flights', 'source_file_id')) {
                    $table->dropIndex('pi_flights_source_file_idx');
                    $table->dropColumn('source_file_id');
                }
            });
        }

        if ($schema->hasTable('passenger_intelligence_import_batches')) {
            $schema->table('passenger_intelligence_import_batches', function (Blueprint $table) use ($schema) {
                foreach (['source_url', 'source_path', 'observed_scope'] as $column) {
                    if ($schema->hasColumn('passenger_intelligence_import_batches', $column)) {
                        $table->dropColumn($column);
                    }
                }

                if ($schema->hasColumn('passenger_intelligence_import_batches', 'source_file_id')) {
                    $table->dropIndex('pi_batches_source_file_idx');
                    $table->dropColumn('source_file_id');
                }
            });
        }

        $schema->dropIfExists('passenger_intelligence_source_files');
    }
};
