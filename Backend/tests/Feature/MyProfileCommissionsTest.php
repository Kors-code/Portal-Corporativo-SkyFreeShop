<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CommissionProfileController;
use App\Models\User;
use App\Services\BudgetClosureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class MyProfileCommissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.budget', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('budget');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('seller_code')->nullable();
            $table->string('role')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
        });

        Schema::connection('budget')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('codigo_vendedor');
        });
        Schema::connection('budget')->create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('budget')->create('commission_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->string('name');
            $table->string('profile_type');
            $table->boolean('is_active')->default(true);
        });
        Schema::connection('budget')->create('commission_profile_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::connection('budget')->create('commission_profile_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
            $table->string('rule_type');
            $table->string('brand')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('category_code')->nullable();
            $table->string('product_code')->nullable();
            $table->decimal('target_amount_usd')->nullable();
            $table->decimal('target_turns')->nullable();
            $table->decimal('commission_percentage')->default(0);
            $table->decimal('commission_percentage100')->default(0);
            $table->decimal('commission_percentage120')->default(0);
        });
        Schema::connection('budget')->create('commission_profile_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('profile_name_snapshot');
            $table->string('profile_type_snapshot');
            $table->string('user_name_snapshot')->nullable();
            $table->string('seller_code_snapshot')->nullable();
            $table->unsignedInteger('sales_count');
            $table->decimal('units');
            $table->decimal('assigned_turns');
            $table->decimal('target_usd');
            $table->decimal('sales_usd');
            $table->decimal('sales_cop');
            $table->decimal('avg_trm')->nullable();
            $table->decimal('fulfillment_pct')->nullable();
            $table->boolean('eligible');
            $table->decimal('applied_commission_pct');
            $table->decimal('commission_usd');
            $table->decimal('commission_cop');
            $table->json('rules_snapshot');
            $table->dateTime('calculated_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('budget')->create('budget_closures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->string('status');
            $table->string('triggered_by');
            $table->dateTime('closed_at');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->date('review_until')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('error_json')->nullable();
            $table->timestamps();
        });
        Schema::connection('budget')->create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('classification_code');
            $table->string('name');
        });
        Schema::connection('budget')->create('products', function (Blueprint $table) {
            $table->id();
            $table->string('brand');
        });
        Schema::connection('budget')->create('sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('product_id');
            $table->string('folio');
            $table->decimal('value_usd');
            $table->decimal('amount_cop');
            $table->decimal('quantity');
        });
    }

    public function test_seller_sees_only_own_live_and_closed_profile_commissions(): void
    {
        $user = User::create([
            'name' => 'Maria',
            'email' => 'maria@example.test',
            'password' => 'test-password',
            'seller_code' => '101',
            'role' => 'seller',
        ]);
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'commission_profiles.my.view']);
        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission_id' => $permissionId]);

        $db = DB::connection('budget');
        $db->table('users')->insert([
            ['id' => 11, 'name' => 'Maria', 'codigo_vendedor' => '101'],
            ['id' => 22, 'name' => 'Otra persona', 'codigo_vendedor' => '202'],
        ]);
        $db->table('budgets')->insert([
            ['id' => 1, 'name' => 'Enero', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'is_closed' => false],
            ['id' => 2, 'name' => 'Historico', 'start_date' => '2025-12-01', 'end_date' => '2025-12-31', 'is_closed' => true],
            ['id' => 3, 'name' => 'Ajeno', 'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'is_closed' => true],
        ]);
        $db->table('commission_profiles')->insert([
            ['id' => 1, 'budget_id' => 1, 'name' => 'Marca A', 'profile_type' => 'degustadora', 'is_active' => true],
            ['id' => 2, 'budget_id' => 1, 'name' => 'Marca B', 'profile_type' => 'degustadora', 'is_active' => true],
        ]);
        $db->table('commission_profile_users')->insert([
            ['commission_profile_id' => 1, 'user_id' => 11],
            ['commission_profile_id' => 2, 'user_id' => 22],
        ]);
        $db->table('commission_profile_rules')->insert([
            ['commission_profile_id' => 1, 'rule_type' => 'brand', 'brand' => 'A', 'target_amount_usd' => 100, 'commission_percentage' => 1, 'commission_percentage100' => 2],
            ['commission_profile_id' => 2, 'rule_type' => 'brand', 'brand' => 'B', 'target_amount_usd' => 100, 'commission_percentage' => 1, 'commission_percentage100' => 2],
        ]);
        $db->table('products')->insert([
            ['id' => 1, 'brand' => 'A'],
            ['id' => 2, 'brand' => 'B'],
        ]);
        $db->table('sales')->insert([
            ['budget_id' => 1, 'seller_id' => 11, 'product_id' => 1, 'folio' => 'A-1', 'value_usd' => 100, 'amount_cop' => 400000, 'quantity' => 1],
            ['budget_id' => 1, 'seller_id' => 22, 'product_id' => 2, 'folio' => 'B-1', 'value_usd' => 300, 'amount_cop' => 1200000, 'quantity' => 3],
        ]);
        $db->table('commission_profile_snapshots')->insert([
            [
                'budget_id' => 2, 'commission_profile_id' => 99, 'user_id' => 11,
                'profile_name_snapshot' => 'Perfil eliminado', 'profile_type_snapshot' => 'degustadora',
                'sales_count' => 1, 'units' => 1, 'assigned_turns' => 20,
                'target_usd' => 50, 'sales_usd' => 80, 'sales_cop' => 320000,
                'avg_trm' => 4000, 'fulfillment_pct' => 160, 'eligible' => true,
                'applied_commission_pct' => 2, 'commission_usd' => 1.6, 'commission_cop' => 6400,
                'rules_snapshot' => json_encode([['sales_usd' => 80, 'commission_usd' => 1.6]]),
            ],
            [
                'budget_id' => 3, 'commission_profile_id' => 88, 'user_id' => 22,
                'profile_name_snapshot' => 'Ajeno', 'profile_type_snapshot' => 'degustadora',
                'sales_count' => 1, 'units' => 1, 'assigned_turns' => 20,
                'target_usd' => 50, 'sales_usd' => 300, 'sales_cop' => 1200000,
                'avg_trm' => 4000, 'fulfillment_pct' => 600, 'eligible' => true,
                'applied_commission_pct' => 2, 'commission_usd' => 6, 'commission_cop' => 24000,
                'rules_snapshot' => '[]',
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/commission-profiles/my?budget_id=1')
            ->assertOk()
            ->assertJsonCount(2, 'budgets')
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('source', 'live')
            ->assertJsonPath('rows.0.profile_name', 'Marca A')
            ->assertJsonPath('rows.0.sales_usd', 100);

        $this->getJson('/api/v1/commission-profiles/my?budget_id=2')
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('source', 'snapshot')
            ->assertJsonPath('rows.0.profile_name', 'Perfil eliminado');

        $this->getJson('/api/v1/commission-profiles/my?budget_id=3')->assertNotFound();
    }

    public function test_calculated_snapshot_remains_visible_after_profile_is_deleted(): void
    {
        $user = User::create([
            'name' => 'Maria',
            'email' => 'maria-history@example.test',
            'password' => 'test-password',
            'seller_code' => '101',
            'role' => 'seller',
        ]);
        $permissionId = DB::table('permissions')->insertGetId(['name' => 'commission_profiles.my.view']);
        DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission_id' => $permissionId]);

        $db = DB::connection('budget');
        $db->table('users')->insert(['id' => 11, 'name' => 'Maria', 'codigo_vendedor' => '101']);
        $db->table('budgets')->insert([
            'id' => 4, 'name' => 'Presupuesto historico',
            'start_date' => '2025-01-01', 'end_date' => '2025-01-31', 'is_closed' => false,
        ]);
        $db->table('commission_profiles')->insert([
            'id' => 7, 'budget_id' => 4, 'name' => 'Perfil eliminado',
            'profile_type' => 'degustadora', 'is_active' => true,
        ]);
        $db->table('commission_profile_users')->insert(['commission_profile_id' => 7, 'user_id' => 11]);
        $db->table('commission_profile_rules')->insert([
            'commission_profile_id' => 7, 'rule_type' => 'brand', 'brand' => 'A',
            'target_amount_usd' => 100, 'commission_percentage' => 1,
            'commission_percentage100' => 2,
        ]);
        $db->table('products')->insert(['id' => 1, 'brand' => 'A']);
        $db->table('sales')->insert([
            'budget_id' => 4, 'seller_id' => 11, 'product_id' => 1, 'folio' => 'H-1',
            'value_usd' => 100, 'amount_cop' => 400000, 'quantity' => 1,
        ]);

        $this->actingAs($user)->getJson('/api/v1/commission-profiles/my?budget_id=4')
            ->assertOk()->assertJsonPath('source', 'live');

        $this->assertSame(1, app(BudgetClosureService::class)->closeBudget(4)['snapshots_created']);
        $db->table('commission_profiles')->where('id', 7)->delete();

        $this->getJson('/api/v1/commission-profiles/my?budget_id=4')
            ->assertOk()
            ->assertJsonPath('source', 'snapshot')
            ->assertJsonPath('rows.0.profile_name', 'Perfil eliminado')
            ->assertJsonPath('rows.0.sales_usd', 100)
            ->assertJsonPath('rows.0.rules.0.sales_usd', 100);

        $adminHistory = app(CommissionProfileController::class)
            ->earners(Request::create('/api/v1/commission-profiles/earners', 'GET', ['budget_id' => 4]))
            ->getData(true);
        $this->assertSame('Perfil eliminado', $adminHistory['rows'][0]['profile_name']);
        $this->assertEquals(2, $adminHistory['totals']['commission_usd']);
    }
}
