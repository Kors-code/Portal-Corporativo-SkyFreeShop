<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CommissionProfileFamilyMigrationTest extends TestCase
{
    public function test_existing_profiles_are_linked_without_changing_rules_or_assignments(): void
    {
        Config::set('database.connections.budget', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('budget');

        Schema::connection('budget')->create('commission_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->string('name');
            $table->boolean('is_active');
            $table->timestamps();
        });
        Schema::connection('budget')->create('commission_profile_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
        });
        Schema::connection('budget')->create('commission_profile_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
            $table->unsignedBigInteger('user_id');
        });

        $db = DB::connection('budget');
        $db->table('commission_profiles')->insert([
            ['id' => 7, 'budget_id' => 22, 'name' => 'Activo', 'is_active' => true],
            ['id' => 8, 'budget_id' => 22, 'name' => 'Inactivo', 'is_active' => false],
        ]);
        $db->table('commission_profile_rules')->insert(['commission_profile_id' => 7]);
        $db->table('commission_profile_users')->insert(['commission_profile_id' => 7, 'user_id' => 11]);

        $migration = require database_path('migrations/2026_10_16_000001_add_commission_profile_families.php');
        $migration->up();

        $this->assertSame(2, $db->table('commission_profile_families')->count());
        $active = $db->table('commission_profiles')->where('id', 7)->first();
        $inactive = $db->table('commission_profiles')->where('id', 8)->first();
        $this->assertNotNull($active->family_id);
        $this->assertNotEquals($active->family_id, $inactive->family_id);
        $this->assertSame('active', $db->table('commission_profile_families')->where('id', $active->family_id)->value('status'));
        $this->assertSame('inactive', $db->table('commission_profile_families')->where('id', $inactive->family_id)->value('status'));
        $this->assertSame(1, $db->table('commission_profile_rules')->count());
        $this->assertSame(1, $db->table('commission_profile_users')->count());
    }
}
