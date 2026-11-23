<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\EconomicClassification;
use App\Models\FundingSource;
use App\Models\LineItem;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use App\Services\BudgetChangeRules;
use App\Services\PlanningWindow;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The rules on budget changes (Decreto-Lei n.º 42/2025, art. 35), checked on a revision that moves money: warnings for
 * money leaving salaries or development capital, for a move above 20 % of what the plan has in the category, and for
 * an account the year does not let grow; the revision must say what it does to the targets.
 */
#[Group('office')]
class BudgetChangeRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Activity $activity;

    private array $class;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        PlanningWindow::forgetTree();
        $this->class = EconomicClassification::pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        // an approved activity with $ 10 000 of goods and services and $ 5 000 of development capital
        $this->activity = Activity::where('fiscal_year', 2027)->where('status', Activity::STATUS_APPROVED)->firstOrFail();
        $this->activity->lineItems()->forceDelete();
        foreach (['20' => 10000, '50' => 5000] as $code => $amount) {
            LineItem::create(['activity_id' => $this->activity->id, 'code' => 'T-'.$code, 'economic_classification_id' => $this->class[$code],
                'funding_source_id' => FundingSource::defaultId(), 'account_code' => $code, 'estimated_amount' => $amount, 'is_test_data' => false]);
        }
        app(ActivityVersions::class)->approved($this->activity->fresh(), $this->admin);
        $this->activity = $this->activity->fresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function revise(array $amounts): Activity
    {
        app(ActivityWorkflow::class)->reopen($this->admin, $this->activity, 'Change of priorities');
        $activity = $this->activity->fresh();
        foreach ($amounts as $code => $amount) {
            $line = $activity->lineItems()->where('economic_classification_id', $this->class[$code])->first();
            $line
                ? $line->update(['estimated_amount' => $amount])
                : LineItem::create(['activity_id' => $activity->id, 'code' => 'T-'.$code, 'economic_classification_id' => $this->class[$code],
                    'funding_source_id' => FundingSource::defaultId(), 'account_code' => $code, 'estimated_amount' => $amount, 'is_test_data' => false]);
        }

        return $activity->fresh();
    }

    public function test_the_rule_is_off_by_default(): void
    {
        $activity = $this->revise(['50' => 0, '20' => 15000]);
        $this->assertNull(app(BudgetChangeRules::class)->check($activity));
        $this->assertSame([], app(ActivityWorkflow::class)->problems($activity));
    }

    public function test_money_leaving_development_capital_is_warned_and_the_effect_on_targets_is_asked(): void
    {
        (new PlanOptions(budgetChangeChecks: true))->save();
        $activity = $this->revise(['50' => 0, '20' => 15000]);

        $check = app(BudgetChangeRules::class)->check($activity);
        $this->assertTrue($check['moves_money']);
        $this->assertEqualsWithDelta(5000, $check['moved'], 0.01);
        $this->assertTrue(collect($check['warnings'])->contains(fn ($w) => str_contains($w, 'art. 35.2')));
        $this->assertContains('This revision moves money: say what it does to the targets.', app(ActivityWorkflow::class)->problems($activity));

        $activity->update(['revision_effect' => 'The bridge is postponed; the inspections target stays.']);
        $this->assertNotContains('This revision moves money: say what it does to the targets.', app(ActivityWorkflow::class)->problems($activity->fresh()));

        $this->actingAs($this->admin)->get(route('activities.budget-change', $activity))->assertOk()->assertSee('The bridge is postponed');
    }

    public function test_the_twenty_percent_limit_and_the_accounts_that_may_not_grow(): void
    {
        (new PlanOptions(budgetChangeChecks: true))->save();
        PlanOptions::setNoIncreaseCodes(2027, '40'); // the year's execution decree
        $rules = app(BudgetChangeRules::class);

        // a small move out of goods and services, which the plan has plenty of: no limit warning
        $small = $this->revise(['20' => 9900, '40' => 100]);
        $warnings = $rules->check($small)['warnings'];
        $this->assertFalse(collect($warnings)->contains(fn ($w) => str_contains($w, 'art. 35.3')));
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'Account 40 goes up')), 'the year does not let account 40 grow');

        // the whole development capital of the programme moves: above 20 % of it
        app(ActivityVersions::class)->approved($small, $this->admin);
        $small->forceFill(['status' => Activity::STATUS_APPROVED])->saveQuietly();
        $this->activity = $small->fresh();
        $big = $this->revise(['50' => 0, '20' => 14900]);
        $this->assertTrue(collect($rules->check($big)['warnings'])->contains(fn ($w) => str_contains($w, 'art. 35.3')));
    }

    public function test_moves_between_programs_of_the_infrastructure_fund_have_no_twenty_percent_limit(): void
    {
        (new PlanOptions(budgetChangeChecks: true))->save();
        // the same activity, paid from the Infrastructure Fund (Decreto-Lei n.º 26/2026 amended art. 35.3)
        $fi = FundingSource::create(['code' => 'FI', 'name' => 'Fundo das Infraestruturas', 'kind' => 'State', 'counts_in_title' => false, 'active' => true]);
        $this->activity->lineItems()->update(['funding_source_id' => $fi->id]);
        app(ActivityVersions::class)->approved($this->activity->fresh(), $this->admin);
        $this->activity = $this->activity->fresh();

        $warnings = app(BudgetChangeRules::class)->check($this->revise(['50' => 0, '20' => 15000]))['warnings'];
        $this->assertFalse(collect($warnings)->contains(fn ($w) => str_contains($w, 'art. 35.3')));
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains($w, 'art. 35.2')), 'development capital still may not leave');
    }
}
