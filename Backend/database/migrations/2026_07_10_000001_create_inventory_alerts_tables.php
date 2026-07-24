<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createPermissions();

        $schema = Schema::connection('budget');

        if (!$schema->hasTable('inventory_alert_lists')) {
            $schema->create('inventory_alert_lists', function (Blueprint $table) {
                $table->id();
                $table->string('name', 160);
                $table->boolean('is_active')->default(true);
                $table->boolean('auto_send')->default(true);
                $table->unsignedTinyInteger('frequency_days')->default(1);
                $table->unsignedTinyInteger('top_months')->default(3);
                $table->unsignedSmallInteger('top_limit')->default(50);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable('inventory_alert_list_stores')) {
            $schema->create('inventory_alert_list_stores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('list_id')->constrained('inventory_alert_lists')->cascadeOnDelete();
                $table->unsignedBigInteger('store_id');
                $table->timestamps();
                $table->unique(['list_id', 'store_id']);
                $table->index('store_id');
            });
        }

        if (!$schema->hasTable('inventory_alert_list_products')) {
            $schema->create('inventory_alert_list_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('list_id')->constrained('inventory_alert_lists')->cascadeOnDelete();
                $table->unsignedBigInteger('product_id');
                $table->string('source', 24)->default('manual');
                $table->timestamps();
                $table->unique(['list_id', 'product_id']);
                $table->index('product_id');
            });
        }

        if (!$schema->hasTable('inventory_alert_recipients')) {
            $schema->create('inventory_alert_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('list_id')->constrained('inventory_alert_lists')->cascadeOnDelete();
                $table->string('name', 160)->nullable();
                $table->string('email', 190);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['list_id', 'email']);
            });
        }

        if (!$schema->hasTable('inventory_alert_runs')) {
            $schema->create('inventory_alert_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('list_id')->nullable()->constrained('inventory_alert_lists')->nullOnDelete();
                $table->string('mode', 24);
                $table->string('status', 24)->default('running');
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->text('message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['list_id', 'mode', 'status']);
            });
        }

        if (!$schema->hasTable('inventory_alert_notifications')) {
            $schema->create('inventory_alert_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('run_id')->nullable()->constrained('inventory_alert_runs')->nullOnDelete();
                $table->foreignId('list_id')->constrained('inventory_alert_lists')->cascadeOnDelete();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('store_id')->nullable();
                $table->string('alert_level', 32);
                $table->string('notification_status', 24);
                $table->string('skip_reason', 190)->nullable();
                $table->decimal('stock_actual', 14, 2)->nullable();
                $table->decimal('maximo_mes', 14, 2)->nullable();
                $table->decimal('dias_disponibles', 14, 2)->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();
                $table->index(['list_id', 'product_id', 'store_id', 'alert_level', 'notification_status'], 'inventory_alert_notifications_lookup_idx');
                $table->index('notified_at');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('budget');
        $schema->dropIfExists('inventory_alert_notifications');
        $schema->dropIfExists('inventory_alert_runs');
        $schema->dropIfExists('inventory_alert_recipients');
        $schema->dropIfExists('inventory_alert_list_products');
        $schema->dropIfExists('inventory_alert_list_stores');
        $schema->dropIfExists('inventory_alert_lists');

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['inventory-alerts.view', 'inventory-alerts.manage'])
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

        foreach (['inventory-alerts.view', 'inventory-alerts.manage'] as $permissionName) {
            $permissionIds[$permissionName] = DB::table('permissions')->where('name', $permissionName)->value('id');

            if (!$permissionIds[$permissionName]) {
                $permissionIds[$permissionName] = DB::table('permissions')->insertGetId([
                    'name' => $permissionName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', ['super_admin'])
            ->pluck('id');

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
