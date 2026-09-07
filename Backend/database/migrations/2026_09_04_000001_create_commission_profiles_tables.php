<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->create('commission_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('profile_type', 60)->default('otro')->index();
            $table->string('commission_mode', 30)->default('fixed');
            $table->unsignedBigInteger('category_role_id')->nullable()->index();
            $table->decimal('commission_percentage', 8, 4)->default(0);
            $table->decimal('commission_percentage100', 8, 4)->default(0);
            $table->decimal('commission_percentage120', 8, 4)->default(0);
            $table->decimal('minimum_fulfillment_pct', 8, 4)->nullable();
            $table->decimal('target_amount_usd', 15, 2)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['budget_id', 'is_active'], 'commission_profiles_budget_active_idx');
        });

        Schema::connection('budget')->create('commission_profile_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_profile_id')->constrained('commission_profiles')->cascadeOnDelete();
            $table->string('rule_type', 40)->index();
            $table->string('provider_name', 160)->nullable()->index();
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->string('category_code', 60)->nullable()->index();
            $table->string('brand', 120)->nullable()->index();
            $table->string('product_code', 80)->nullable()->index();
            $table->decimal('commission_percentage', 8, 4)->default(0);
            $table->decimal('commission_percentage100', 8, 4)->default(0);
            $table->decimal('commission_percentage120', 8, 4)->default(0);
            $table->timestamps();

            $table->index(['commission_profile_id', 'rule_type'], 'commission_profile_rules_profile_type_idx');
        });

        Schema::connection('budget')->create('commission_profile_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_profile_id')->constrained('commission_profiles')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['commission_profile_id', 'user_id'], 'commission_profile_users_unique');
        });

        foreach (['commission_profiles.view', 'commission_profiles.manage'] as $permissionName) {
            if (! DB::table('permissions')->where('name', $permissionName)->exists()) {
                DB::table('permissions')->insert([
                    'name' => $permissionName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('budget')->dropIfExists('commission_profile_users');
        Schema::connection('budget')->dropIfExists('commission_profile_rules');
        Schema::connection('budget')->dropIfExists('commission_profiles');

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['commission_profiles.view', 'commission_profiles.manage'])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('user_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
