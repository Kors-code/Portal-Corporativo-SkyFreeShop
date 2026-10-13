<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->create('commission_profile_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('profile_name_snapshot', 160)->nullable();
            $table->string('profile_type_snapshot', 80)->nullable();
            $table->string('user_name_snapshot', 180)->nullable();
            $table->string('seller_code_snapshot', 80)->nullable();
            $table->unsignedInteger('sales_count')->default(0);
            $table->decimal('units', 14, 4)->default(0);
            $table->decimal('assigned_turns', 10, 2)->default(0);
            $table->decimal('target_usd', 14, 2)->default(0);
            $table->decimal('sales_usd', 14, 2)->default(0);
            $table->decimal('sales_cop', 18, 2)->default(0);
            $table->decimal('avg_trm', 14, 4)->nullable();
            $table->decimal('fulfillment_pct', 10, 4)->nullable();
            $table->decimal('applied_commission_pct', 10, 4)->default(0);
            $table->decimal('commission_usd', 14, 2)->default(0);
            $table->decimal('commission_cop', 18, 2)->default(0);
            $table->boolean('eligible')->default(false);
            $table->json('rules_snapshot')->nullable();
            $table->dateTime('calculated_at');
            $table->timestamps();

            $table->unique(['budget_id', 'commission_profile_id', 'user_id'], 'cps_budget_profile_user_unique');
            $table->index(['budget_id', 'user_id']);
            $table->index(['budget_id', 'commission_profile_id'], 'cps_budget_profile_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('budget')->dropIfExists('commission_profile_snapshots');
    }
};
