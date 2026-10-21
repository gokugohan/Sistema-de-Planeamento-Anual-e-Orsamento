<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\IndicatorProgress;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Report execution starts from a worklist of activities with where each stands, and an opened activity has a checklist and one send button. */
#[Group('office')]
class ReportExecutionWorklistTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);

        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('indicators')->whereHas('lineItems.adjustedBudget')->firstOrFail();
        BudgetExecution::whereIn('line_item_id', $this->activity->lineItems()->select('id'))->where('month', 10)->delete();
        IndicatorProgress::whereIn('indicator_id', $this->activity->indicators()->select('id'))->where('quarter', 4)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** October's spending of the activity in a state, and its Q4 indicators verified or not reported at all. */
    private function state(string $spending, bool $indicatorsVerified): void
    {
        foreach ($this->activity->lineItems()->whereHas('adjustedBudget')->get() as $line) {
            BudgetExecution::create(['line_item_id' => $line->id, 'month' => 10, 'quarter' => 4, 'executed' => 5, 'status' => $spending]);
        }
        if ($indicatorsVerified) {
            foreach ($this->activity->indicators as $i) {
                IndicatorProgress::create(['indicator_id' => $i->id, 'quarter' => 4, 'achieved_value' => 1, 'status' => 'Verified']);
            }
        }
    }

    private function page()
    {
        return Volt::actingAs(User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail())->test('pages.monitoring.report-execution');
    }

    private function row($page)
    {
        return $page->viewData('worklist')->firstWhere('id', $this->activity->id);
    }

    public function test_an_activity_with_nothing_reported_is_to_report(): void
    {
        $page = $this->page();

        $page->assertSee('To report');
        $row = $this->row($page);
        $this->assertSame('todo', $row->spendingState);
        $this->assertSame('todo', $row->progressState);
        $this->assertSame('todo', $row->overall);
    }

    public function test_submitted_spending_is_waiting_but_unreported_indicators_still_make_it_to_report(): void
    {
        $this->state(BudgetExecution::SUBMITTED, false);

        $row = $this->row($this->page());

        $this->assertSame('waiting', $row->spendingState);
        $this->assertSame('todo', $row->progressState);
        $this->assertSame('todo', $row->overall, 'an unreported part makes the whole activity to report');
    }

    public function test_everything_verified_is_done_and_a_returned_figure_is_flagged(): void
    {
        $this->state(BudgetExecution::VERIFIED, true);
        $this->assertSame('done', $this->row($this->page())->overall);

        BudgetExecution::whereIn('line_item_id', $this->activity->lineItems()->select('id'))->where('month', 10)->update(['status' => BudgetExecution::REJECTED]);
        $row = $this->row($this->page());
        $this->assertSame('returned', $row->spendingState);
        $this->assertSame('todo', $row->overall);
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $this->state(BudgetExecution::VERIFIED, true);
        $short = e(mb_substr($this->activity->description, 0, 30));

        $this->page()->call('setFilter', 'done')->assertSee($short);
        $this->page()->call('setFilter', 'todo')->assertDontSee($short);
        $this->page()->call('setFilter', 'nonsense')->assertSet('filter', 'all');
    }

    public function test_opening_an_activity_gives_a_checklist_a_way_back_and_one_send_button(): void
    {
        $page = $this->page()->call('$set', 'activityId', $this->activity->id);

        $page->assertSee('All activities')->assertSee('What to do for this activity')->assertSee('Send everything for verification')
            ->assertSee('Submit the narrative')->assertSee('Write the narrative');
        $page->call('$set', 'activityId', null)->assertSee('To report');
    }

    public function test_the_page_explains_that_the_quarter_follows_the_month(): void
    {
        $this->page()->assertSee('You report the executed amount for the month you choose')->assertSee('October – December');
    }

    public function test_spending_inputs_show_the_currency(): void
    {
        $html = $this->page()->call('$set', 'activityId', $this->activity->id)->html();

        $this->assertStringContainsString('aria-hidden="true">$</span>', $html);
    }
}
