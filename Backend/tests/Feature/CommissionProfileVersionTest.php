<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CommissionProfileController;
use App\Models\Comisiones\Budget;
use App\Models\Comisiones\CommissionProfile;
use App\Models\Comisiones\CommissionProfileFamily;
use App\Services\CommissionProfileVersionService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommissionProfileVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.budget', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('budget');

        Schema::connection('budget')->create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('target_amount');
            $table->integer('total_turns')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->nullable();
            $table->timestamps();
        });
        Schema::connection('budget')->create('category_commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id');
        });
        Schema::connection('budget')->create('commission_profile_families', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();
        });
        Schema::connection('budget')->create('commission_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->unsignedBigInteger('family_id')->nullable();
            $table->unsignedBigInteger('inherited_from_id')->nullable();
            $table->boolean('is_inherited')->default(false);
            $table->string('name');
            $table->string('profile_type');
            $table->string('commission_mode')->nullable();
            $table->unsignedBigInteger('category_role_id')->nullable();
            $table->decimal('commission_percentage')->nullable();
            $table->decimal('commission_percentage100')->nullable();
            $table->decimal('commission_percentage120')->nullable();
            $table->decimal('minimum_fulfillment_pct')->nullable();
            $table->decimal('target_amount_usd')->nullable();
            $table->text('note')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::connection('budget')->create('commission_profile_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
            $table->string('rule_type');
            $table->string('provider_name')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('category_code')->nullable();
            $table->string('brand')->nullable();
            $table->string('product_code')->nullable();
            $table->decimal('participation_pct')->default(0);
            $table->decimal('target_amount_usd')->nullable();
            $table->decimal('target_turns')->nullable();
            $table->decimal('commission_percentage')->default(0);
            $table->decimal('commission_percentage100')->default(0);
            $table->decimal('commission_percentage120')->default(0);
            $table->timestamps();
        });
        Schema::connection('budget')->create('commission_profile_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Budget::create(['name' => 'Septiembre', 'target_amount' => 1000,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    }

    private function makeProfile(int $budgetId): CommissionProfile
    {
        $family = CommissionProfileFamily::create(['status' => 'active']);
        $profile = CommissionProfile::create([
            'budget_id' => $budgetId, 'family_id' => $family->id,
            'name' => 'Fragancias', 'profile_type' => 'degustadora', 'is_active' => true,
        ]);
        $profile->rules()->create([
            'rule_type' => 'brand', 'brand' => 'A',
            'target_amount_usd' => 100, 'commission_percentage' => 1,
        ]);
        $profile->assignments()->create(['user_id' => 11]);

        return $profile;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rules_cannot_be_created_after_the_review_deadline(): void
    {
        Carbon::setTestNow('2026-10-04 00:00:00');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Termino la ventana de revision');
        app(CommissionProfileController::class)->store(Request::create('/api/v1/commission-profiles', 'POST', [
            'budget_id' => Budget::firstOrFail()->id,
            'name' => 'Fragancias',
            'profile_type' => 'degustadora',
            'commission_percentage' => 0,
            'rules' => [[
                'rule_type' => 'brand', 'brand' => 'A',
                'commission_percentage' => 1,
            ]],
            'user_ids' => [11],
        ]));
    }

    public function test_creating_a_profile_for_an_open_month_populates_existing_future_budgets(): void
    {
        $november = Budget::create(['name' => 'Noviembre', 'target_amount' => 1000,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
        $december = Budget::create(['name' => 'Diciembre', 'target_amount' => 1000,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-31']);
        $payload = [
            'budget_id' => $november->id,
            'name' => 'Fragancias',
            'profile_type' => 'degustadora',
            'commission_percentage' => 0,
            'rules' => [[
                'rule_type' => 'brand', 'brand' => 'A',
                'target_amount_usd' => 100, 'commission_percentage' => 1,
            ]],
            'user_ids' => [11],
        ];

        $response = app(CommissionProfileController::class)->store(
            Request::create('/api/v1/commission-profiles', 'POST', $payload)
        );
        $this->assertSame(201, $response->status());
        $created = CommissionProfile::findOrFail($response->getData(true)['profile']['id']);
        $decemberCopy = CommissionProfile::with(['rules', 'assignments'])
            ->where('budget_id', $december->id)->firstOrFail();
        $this->assertSame($created->family_id, $decemberCopy->family_id);
        $this->assertSame(11, $decemberCopy->assignments->first()->user_id);

        $payload['rules'][0]['target_amount_usd'] = 125;
        app(CommissionProfileController::class)->update(
            Request::create('/api/v1/commission-profiles/'.$created->id, 'PUT', $payload),
            $created->id
        );
        $this->assertEquals(125, $decemberCopy->fresh('rules')->rules->first()->target_amount_usd);
    }

    public function test_new_budget_inherits_rules_and_people_but_each_month_can_be_edited_independently(): void
    {
        $september = Budget::firstOrFail();
        $original = $this->makeProfile($september->id);

        $response = app(BudgetController::class)->store(Request::create('/api/v1/budgets', 'POST', [
            'name' => 'Octubre', 'target_amount' => 1000,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]));
        $this->assertSame(201, $response->status());

        $october = CommissionProfile::with(['rules', 'assignments'])
            ->where('budget_id', $response->getData(true)['id'])->firstOrFail();
        $this->assertSame($original->family_id, $october->family_id);
        $this->assertSame($original->id, $october->inherited_from_id);
        $this->assertTrue($october->is_inherited);
        $this->assertEquals(100, $october->rules->first()->target_amount_usd);
        $this->assertSame(11, $october->assignments->first()->user_id);

        $original->rules()->first()->update(['target_amount_usd' => 125]);
        app(CommissionProfileVersionService::class)
            ->refreshInheritedFutureVersions($original->fresh(['rules', 'assignments']));
        $this->assertEquals(125, $october->fresh('rules')->rules->first()->target_amount_usd);

        $october->update(['is_inherited' => false]);
        $october->rules()->first()->update(['target_amount_usd' => 200]);
        $original->rules()->first()->update(['target_amount_usd' => 150]);
        app(CommissionProfileVersionService::class)
            ->refreshInheritedFutureVersions($original->fresh(['rules', 'assignments']));
        $this->assertEquals(200, $october->fresh('rules')->rules->first()->target_amount_usd);
        $this->assertEquals(150, $original->fresh('rules')->rules->first()->target_amount_usd);
    }

    public function test_deactivation_and_logical_deletion_preserve_current_version_and_stop_future_copies(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $september = Budget::firstOrFail();
        $original = $this->makeProfile($september->id);
        $october = Budget::create(['name' => 'Octubre', 'target_amount' => 1000,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $service = app(CommissionProfileVersionService::class);
        $service->copyFromPreviousBudget($october);
        $copy = CommissionProfile::where('budget_id', $october->id)->firstOrFail();

        app(CommissionProfileController::class)->setLifecycleStatus(
            Request::create('/api/v1/commission-profiles/'.$original->id.'/status', 'PATCH', ['status' => 'inactive']),
            $original->id
        );
        $this->assertTrue($original->fresh()->is_active);
        $this->assertTrue($copy->fresh()->is_active);

        app(CommissionProfileController::class)->setLifecycleStatus(
            Request::create('/api/v1/commission-profiles/'.$copy->id.'/status', 'PATCH', ['status' => 'active']),
            $copy->id
        );
        $this->assertTrue($copy->fresh()->is_active);
        $november = Budget::create(['name' => 'Noviembre', 'target_amount' => 1000,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
        $service->copyFromPreviousBudget($november);
        $novemberCopy = CommissionProfile::where('budget_id', $november->id)->firstOrFail();

        app(CommissionProfileController::class)->destroy($copy->id);
        $this->assertTrue($copy->fresh()->is_active);
        $this->assertFalse($novemberCopy->fresh()->is_active);
        $this->assertSame(3, CommissionProfile::where('family_id', $original->family_id)->count());

        $december = Budget::create(['name' => 'Diciembre', 'target_amount' => 1000,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-31']);
        $service->copyFromPreviousBudget($december);
        $this->assertSame(0, CommissionProfile::where('budget_id', $december->id)->count());
    }

    public function test_new_profile_reaches_existing_future_budget_and_can_resume_after_a_paused_month(): void
    {
        $september = Budget::firstOrFail();
        $october = Budget::create(['name' => 'Octubre', 'target_amount' => 1000,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $service = app(CommissionProfileVersionService::class);
        $original = $this->makeProfile($september->id);

        $service->copyToExistingFutureBudgets($original);
        $octoberCopy = CommissionProfile::where('budget_id', $october->id)->firstOrFail();
        $this->assertSame($original->family_id, $octoberCopy->family_id);

        $service->setStatus($original, 'inactive');
        $november = Budget::create(['name' => 'Noviembre', 'target_amount' => 1000,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
        $service->copyFromPreviousBudget($november);
        $this->assertSame(0, CommissionProfile::where('budget_id', $november->id)->count());

        $service->setStatus($original, 'active');
        $this->assertSame($original->family_id,
            CommissionProfile::where('budget_id', $november->id)->firstOrFail()->family_id);
        $december = Budget::create(['name' => 'Diciembre', 'target_amount' => 1000,
            'start_date' => '2026-12-01', 'end_date' => '2026-12-31']);
        $service->copyFromPreviousBudget($december);
        $this->assertSame($original->family_id,
            CommissionProfile::where('budget_id', $december->id)->firstOrFail()->family_id);
    }

    public function test_deactivating_from_an_old_version_preserves_the_current_month(): void
    {
        Carbon::setTestNow('2026-10-16 12:00:00');
        $september = Budget::firstOrFail();
        $october = Budget::create(['name' => 'Octubre', 'target_amount' => 1000,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
        $november = Budget::create(['name' => 'Noviembre', 'target_amount' => 1000,
            'start_date' => '2026-11-01', 'end_date' => '2026-11-30']);
        $original = $this->makeProfile($september->id);
        $service = app(CommissionProfileVersionService::class);
        $service->copyToExistingFutureBudgets($original);

        $service->setStatus($original, 'inactive');

        $this->assertTrue(CommissionProfile::where('budget_id', $september->id)->firstOrFail()->is_active);
        $this->assertTrue(CommissionProfile::where('budget_id', $october->id)->firstOrFail()->is_active);
        $this->assertFalse(CommissionProfile::where('budget_id', $november->id)->firstOrFail()->is_active);
    }

    public function test_an_old_month_only_profile_is_not_resurrected_without_reactivation(): void
    {
        $august = Budget::create(['name' => 'Agosto', 'target_amount' => 1000,
            'start_date' => '2026-08-01', 'end_date' => '2026-08-31']);
        $this->makeProfile($august->id);
        $october = Budget::create(['name' => 'Octubre', 'target_amount' => 1000,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

        app(CommissionProfileVersionService::class)->copyFromPreviousBudget($october);

        $this->assertSame(0, CommissionProfile::where('budget_id', $october->id)->count());
    }

}
