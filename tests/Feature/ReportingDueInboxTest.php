<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\LineItem;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\ApprovalInbox;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The units' monthly duty shows in their inbox: spending missing for the month that ended, windows about to close. */
#[Group('office')]
class ReportingDueInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $this->director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reportItems(?User $user = null): Collection
    {
        return collect(app(ApprovalInbox::class)->items($user ?? $this->director, 2026))->where('group', 'report');
    }

    public function test_spending_missing_for_the_month_that_ended_is_listed_and_clears_when_reported(): void
    {
        $unitIds = Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds();
        $activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereIn('directorate_id', $unitIds)->whereHas('lineItems.adjustedBudget')->firstOrFail();
        BudgetExecution::whereIn('line_item_id', $activity->lineItems()->pluck('id'))->where('month', 9)->delete();

        $before = $this->reportItems()->first(fn ($i) => str_contains($i['text'], 'no executed amount reported for September'));
        $this->assertNotNull($before);

        // report every approved activity of the unit for September: the item goes away
        Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereIn('directorate_id', $unitIds)->whereHas('lineItems.adjustedBudget')->get()->each(function ($a) {
            $line = $a->lineItems()->whereHas('adjustedBudget')->first();
            BudgetExecution::updateOrCreate(['line_item_id' => $line->id, 'month' => 9], ['executed' => 1, 'status' => 'Draft']);
        });
        $this->assertNull($this->reportItems()->first(fn ($i) => str_contains($i['text'], 'no executed amount reported')));
    }

    public function test_a_window_about_to_close_is_listed_and_a_distant_one_is_not(): void
    {
        ReportingWindow::create(['fiscal_year' => 2026, 'period_type' => 'month', 'period' => 9, 'opens_on' => '2026-10-01', 'closes_on' => '2026-10-17']);
        ReportingWindow::create(['fiscal_year' => 2026, 'period_type' => 'month', 'period' => 10, 'opens_on' => '2026-11-01', 'closes_on' => '2026-11-30']);

        $texts = $this->reportItems()->pluck('text')->implode(' | ');

        $this->assertStringContainsString('Reporting for September closes on 17/10/2026.', $texts);
        $this->assertStringNotContainsString('30/11/2026', $texts);
    }

    public function test_those_who_do_not_report_see_nothing_of_it(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');

        $this->assertTrue($this->reportItems($viewer)->isEmpty());
    }

    public function test_the_waiting_for_you_page_leaves_reporting_due_to_my_work(): void
    {
        // the page shows the running year, and only the month that has just ended can be missing
        $unitIds = Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds();
        BudgetExecution::whereIn('line_item_id', LineItem::whereIn('activity_id', Activity::whereIn('directorate_id', $unitIds)->select('id'))->select('id'))->where('month', 9)->delete();

        $this->assertTrue($this->reportItems($this->director)->isNotEmpty(), 'the item still exists for the dashboard and My work');
        $this->actingAs($this->director)->withSession(['fiscal_year' => 2026])->get(route('approvals.index'))->assertOk()->assertDontSee('Reporting due');
    }
}
