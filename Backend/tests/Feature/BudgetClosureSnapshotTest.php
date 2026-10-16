<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CommissionProfileController;
use App\Services\BudgetClosureService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BudgetClosureSnapshotTest extends TestCase
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

        Schema::connection('budget')->create('budgets', function (Blueprint $table) {
            $table->id();
            $table->date('end_date');
            $table->boolean('is_closed')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->date('closure_review_until')->nullable();
            $table->dateTime('closure_snapshot_created_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('budget')->create('commission_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
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
        Schema::connection('budget')->create('commission_profile_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('profile_name_snapshot')->nullable();
            $table->string('profile_type_snapshot')->nullable();
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
            $table->decimal('applied_commission_pct');
            $table->decimal('commission_usd');
            $table->decimal('commission_cop');
            $table->boolean('eligible');
            $table->json('rules_snapshot');
            $table->dateTime('calculated_at');
            $table->timestamps();
        });

        DB::connection('budget')->table('budgets')->insert([
            'id' => 22,
            'end_date' => '2026-09-30',
            'is_closed' => false,
        ]);
        DB::connection('budget')->table('commission_profiles')->insert([
            'id' => 7,
            'name' => 'Perfil historico',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_closure_saves_rules_and_commissions_independently_of_later_profile_deletion(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $controller = Mockery::mock(CommissionProfileController::class);
        $controller->shouldReceive('earners')->once()->andReturn(response()->json([
            'rows' => [[
                'profile_id' => 7,
                'profile_name' => 'Perfil historico',
                'profile_type' => 'degustadora',
                'user_id' => 11,
                'user_name' => 'Maria',
                'seller_code' => '101',
                'sales_count' => 2,
                'units' => 3,
                'assigned_turns' => 24,
                'target_usd' => 240,
                'sales_usd' => 300,
                'sales_cop' => 1200000,
                'avg_trm' => 4000,
                'fulfillment_pct' => 125,
                'applied_commission_pct' => 2,
                'commission_usd' => 6,
                'commission_cop' => 24000,
                'eligible' => true,
                'rules' => [['rule_id' => 9, 'target_usd' => 240, 'sales_usd' => 300, 'commission_usd' => 6]],
            ]],
            'totals' => ['sales_usd' => 300, 'sales_cop' => 1200000, 'target_usd' => 240,
                'commission_usd' => 6, 'commission_cop' => 24000, 'sales_count' => 2],
        ]));
        $this->app->instance(CommissionProfileController::class, $controller);

        $service = app(BudgetClosureService::class);
        $this->assertSame([22], $service->eligibleBudgetsForAutomaticClosure(Carbon::parse('2026-10-04')));
        $result = $service->closeBudget(22);
        $this->assertSame(1, $result['snapshots_created']);
        $this->assertSame('already_closed', $service->closeBudget(22)['status']);

        DB::connection('budget')->table('commission_profiles')->where('id', 7)->delete();
        $snapshot = DB::connection('budget')->table('commission_profile_snapshots')->first();
        $this->assertSame('Perfil historico', $snapshot->profile_name_snapshot);
        $this->assertEquals(6, $snapshot->commission_usd);
        $this->assertSame(9, json_decode($snapshot->rules_snapshot, true)[0]['rule_id']);
        $this->assertSame(1, DB::connection('budget')->table('commission_profile_snapshots')->count());
        $this->assertSame(1, DB::connection('budget')->table('budget_closures')->count());
        $this->assertEquals(1, DB::connection('budget')->table('budgets')->value('is_closed'));
    }

    public function test_closure_is_rejected_during_review_window_without_writing_a_snapshot(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');

        try {
            app(BudgetClosureService::class)->closeBudget(22);
            $this->fail('El cierre debio rechazarse antes del dia 4.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('en revision', $exception->getMessage());
        }

        $this->assertSame([], app(BudgetClosureService::class)->eligibleBudgetsForAutomaticClosure(Carbon::parse('2026-10-03')));
        $this->assertSame(0, DB::connection('budget')->table('commission_profile_snapshots')->count());
        $this->assertSame(0, DB::connection('budget')->table('budget_closures')->count());
        $this->assertEquals(0, DB::connection('budget')->table('budgets')->value('is_closed'));
    }
}
