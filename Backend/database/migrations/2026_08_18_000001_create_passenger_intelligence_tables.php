<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissions = [
        'passenger-intelligence.view',
        'passenger-intelligence.import',
        'passenger-intelligence.manage',
    ];

    public function up(): void
    {
        $this->createPermissions();

        $schema = Schema::connection('budget');

        if (!$schema->hasTable('passenger_intelligence_import_batches')) {
            $schema->create('passenger_intelligence_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('filename', 255);
                $table->string('checksum', 64);
                $table->string('source_type', 40)->default('excel');
                $table->string('status', 40)->default('processing');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->unsignedInteger('rows_imported')->default(0);
                $table->unsignedInteger('rows_skipped')->default(0);
                $table->decimal('total_pax', 16, 2)->default(0);
                $table->json('notes')->nullable();
                $table->unsignedBigInteger('imported_by')->nullable();
                $table->timestamps();

                $table->unique('checksum', 'pi_batches_checksum_uidx');
                $table->index(['period_start', 'period_end'], 'pi_batches_period_idx');
                $table->index('status', 'pi_batches_status_idx');
            });
        }

        if (!$schema->hasTable('passenger_intelligence_flights')) {
            $schema->create('passenger_intelligence_flights', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')
                    ->constrained('passenger_intelligence_import_batches')
                    ->cascadeOnDelete();
                $table->date('flight_date');
                $table->time('scheduled_time')->nullable();
                $table->dateTime('scheduled_at')->nullable();
                $table->string('direction', 16);
                $table->string('airline', 120)->nullable();
                $table->string('flight_code', 40)->nullable();
                $table->string('origin', 8)->nullable();
                $table->string('destination', 8)->nullable();
                $table->decimal('pax', 12, 2);
                $table->string('store', 80)->nullable();
                $table->string('source_sheet', 80);
                $table->unsignedInteger('source_row')->nullable();
                $table->string('source_row_uid', 64);
                $table->string('data_type', 24)->default('estimated');
                $table->string('source_name', 120)->default('PAX Excel');
                $table->timestamp('retrieved_at')->nullable();
                $table->timestamps();

                $table->unique('source_row_uid', 'pi_flights_row_uidx');
                $table->index(['flight_date', 'direction'], 'pi_flights_date_dir_idx');
                $table->index(['direction', 'scheduled_time'], 'pi_flights_dir_time_idx');
                $table->index(['airline', 'flight_code'], 'pi_flights_air_code_idx');
                $table->index(['origin', 'destination'], 'pi_flights_route_idx');
            });
        }

        if (!$schema->hasTable('passenger_intelligence_composition_profiles')) {
            $schema->create('passenger_intelligence_composition_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->string('direction', 16)->nullable();
                $table->decimal('colombian_pct', 6, 3);
                $table->decimal('foreign_pct', 6, 3);
                $table->string('source_name', 160);
                $table->string('source_url', 500)->nullable();
                $table->string('method', 80)->default('manual_official_profile');
                $table->string('confidence_level', 16)->default('MEDIUM');
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'direction', 'valid_from', 'valid_to'], 'pi_profiles_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('budget');
        $schema->dropIfExists('passenger_intelligence_composition_profiles');
        $schema->dropIfExists('passenger_intelligence_flights');
        $schema->dropIfExists('passenger_intelligence_import_batches');

        $permissionIds = DB::table('permissions')
            ->whereIn('name', $this->permissions)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('user_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    private function createPermissions(): void
    {
        $permissionIds = [];

        foreach ($this->permissions as $permissionName) {
            $permissionIds[$permissionName] = DB::table('permissions')->where('name', $permissionName)->value('id');

            if (!$permissionIds[$permissionName]) {
                $permissionIds[$permissionName] = DB::table('permissions')->insertGetId([
                    'name' => $permissionName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $viewRoles = ['super_admin', 'admin', 'adminpresupuesto', 'administrativo', 'lider'];
        $manageRoles = ['super_admin', 'admin', 'adminpresupuesto'];

        $this->grantToRoles([$permissionIds['passenger-intelligence.view']], $viewRoles);
        $this->grantToRoles([
            $permissionIds['passenger-intelligence.import'],
            $permissionIds['passenger-intelligence.manage'],
        ], $manageRoles);
    }

    private function grantToRoles(array $permissionIds, array $roleNames): void
    {
        $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (!$exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }
};
