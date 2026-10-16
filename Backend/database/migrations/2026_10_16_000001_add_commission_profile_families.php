<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('budget')->create('commission_profile_families', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('budget')->table('commission_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('family_id')->nullable()->index();
            $table->unsignedBigInteger('inherited_from_id')->nullable();
            $table->boolean('is_inherited')->default(false);
            $table->unique(['family_id', 'budget_id'], 'commission_profiles_family_budget_unique');
        });

        DB::connection('budget')->table('commission_profiles')->orderBy('id')->chunkById(500, function ($profiles) {
            foreach ($profiles as $profile) {
                $familyId = DB::connection('budget')->table('commission_profile_families')->insertGetId([
                    'status' => $profile->is_active ? 'active' : 'inactive',
                    'created_at' => $profile->created_at ?? now(),
                    'updated_at' => now(),
                ]);
                DB::connection('budget')->table('commission_profiles')
                    ->where('id', $profile->id)->update(['family_id' => $familyId]);
            }
        });
    }

    public function down(): void
    {
        Schema::connection('budget')->table('commission_profiles', function (Blueprint $table) {
            $table->dropUnique('commission_profiles_family_budget_unique');
            $table->dropColumn(['family_id', 'inherited_from_id', 'is_inherited']);
        });
        Schema::connection('budget')->dropIfExists('commission_profile_families');
    }
};
