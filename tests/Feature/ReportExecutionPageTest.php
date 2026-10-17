<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\LineItem;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Report execution: one page for what an activity spent and achieved in a quarter. */
class ReportExecutionPageTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026, 'locale' => 'en']);

        // an activity of the unit director's unit, with a distributed line and an indicator, cleared for the quarter
        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('indicators')->whereHas('lineItems.adjustedBudget')->firstOrFail();
        BudgetExecution::whereIn('line_item_id', $this->activity->lineItems()->pluck('id'))->where('month', 10)->delete();
        IndicatorProgress::whereIn('indicator_id', $this->activity->indicators()->pluck('id'))->where('quarter', 4)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function finalApprover(): User
    {
        return User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
    }

    public function test_the_old_addresses_lead_to_the_one_page(): void
    {
        $this->actingAs($this->finalApprover());

        $this->get('/monitoring/execution')->assertRedirect('/monitoring/report');
        $this->get('/monitoring/progress')->assertRedirect('/monitoring/report');
        $this->get(route('monitoring.report'))->assertOk()->assertSee('Report execution');
    }

    public function test_choosing_an_activity_shows_spending_and_indicators_together_with_a_summary(): void
    {
        $page = Volt::actingAs($this->finalApprover())->test('pages.monitoring.report-execution')
            ->set('month', 10)->set('activityId', $this->activity->id);

        $page->assertSee($this->activity->description)->assertSee('Spending')->assertSee('Indicators')->assertSee('Submit everything for verification');
        $summary = $page->viewData('summary');
        $this->assertSame($this->activity->lineItems()->whereHas('adjustedBudget')->count(), $summary['spending']['total']);
        $this->assertSame($this->activity->indicators()->count(), $summary['progress']['total']);
        $this->assertSame(0, $summary['spending']['Verified'] ?? 0, 'nothing is reported yet in the cleared quarter');
    }

    public function test_the_activity_must_be_in_the_users_scope(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('Director'); // a director without a unit sees nothing
        $this->actingAs($outsider);

        Volt::test('pages.monitoring.report-execution')->set('month', 10)->set('activityId', $this->activity->id)->assertForbidden();
    }

    public function test_submit_everything_tells_both_panels_and_each_sends_its_drafts_for_verification(): void
    {
        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        // the activity must be one of this director's
        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereIn('directorate_id', \App\Models\Directorate::find($director->directorate_id)->getSelfAndDescendantIds())
            ->whereHas('indicators')->whereHas('lineItems.adjustedBudget')->firstOrFail();
        $line = LineItem::where('activity_id', $this->activity->id)->whereHas('adjustedBudget')->firstOrFail();
        $indicator = Indicator::where('activity_id', $this->activity->id)->firstOrFail();
        BudgetExecution::where('line_item_id', $line->id)->where('month', 10)->delete();
        IndicatorProgress::where('indicator_id', $indicator->id)->where('quarter', 4)->delete();

        Volt::actingAs($director)->test('pages.monitoring.report-execution')->set('month', 10)->set('activityId', $this->activity->id)
            ->call('submitAll')->assertDispatched('submit-execution');

        $spending = Volt::actingAs($director)->test('pages.monitoring.execution-spending', ['activityId' => $this->activity->id, 'month' => 10])
            ->set("form.{$line->id}.committed", 100)->set("form.{$line->id}.paid", 50);
        $progress = Volt::actingAs($director)->test('pages.monitoring.execution-progress', ['activityId' => $this->activity->id, 'quarter' => 4]);
        $progress->call('saveProgress', $indicator->id, 5);

        // what each panel does when it hears the page's button
        $spending->call('submitEverything');
        $progress->call('submitEverything');

        $this->assertSame(BudgetExecution::SUBMITTED, BudgetExecution::where('line_item_id', $line->id)->where('month', 10)->value('status'));
        $this->assertSame('Submitted', IndicatorProgress::where('indicator_id', $indicator->id)->where('quarter', 4)->value('status'));
    }

    public function test_a_quarter_that_has_not_started_cannot_be_chosen(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00');

        Volt::actingAs($this->finalApprover())->test('pages.monitoring.report-execution')->set('month', 10)->assertSet('month', 5);
    }
}
