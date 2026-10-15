<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\BudgetExecution;
use App\Models\User;
use App\Services\ActivityExecutionSummary;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** An approved activity's page shows how it is doing: verified spending by month, indicators, the latest words. */
class ActivityExecutionTabTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')->whereHas('indicators')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_summary_counts_verified_figures_only_and_says_how_many_wait(): void
    {
        $line = $this->activity->lineItems()->whereHas('adjustedBudget')->firstOrFail();
        BudgetExecution::where('line_item_id', $line->id)->delete();
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 1, 'committed' => 200, 'paid' => 100, 'status' => 'Verified']);
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 2, 'committed' => 50, 'paid' => 40, 'status' => 'Verified']);
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 3, 'committed' => 999, 'paid' => 999, 'status' => 'Submitted']);

        $s = app(ActivityExecutionSummary::class)->for($this->activity);

        $other = BudgetExecution::whereIn('line_item_id', $this->activity->lineItems()->where('id', '!=', $line->id)->pluck('id'))->where('status', 'Verified')->sum('paid');
        $this->assertEqualsWithDelta(140 + $other, $s['paid'], 0.01);
        $this->assertGreaterThanOrEqual(140, $s['months'][2]['cumulative']);
        $this->assertEqualsWithDelta($s['months'][2]['cumulative'], $s['months'][3]['cumulative'] - $s['months'][3]['paid'], 0.01);
        $this->assertGreaterThanOrEqual(1, $s['waiting']);
        $this->assertCount($this->activity->indicators()->count(), $s['indicators']);
    }

    public function test_the_page_shows_the_execution_tab_with_the_latest_verified_report(): void
    {
        ActivityReport::create(['activity_id' => $this->activity->id, 'period_type' => 'quarter', 'period' => 3, 'results' => 'ROADS REPAIRED', 'status' => 'Verified']);
        ActivityReport::create(['activity_id' => $this->activity->id, 'period_type' => 'month', 'period' => 9, 'results' => 'STILL A DRAFT', 'status' => 'Draft']);
        $user = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();

        Volt::actingAs($user)->test('pages.activities.show', ['activity' => $this->activity])
            ->assertSee('Execution')->assertSee('Month by month')->assertSee('Paid / approved')->assertSee('ROADS REPAIRED')->assertDontSee('STILL A DRAFT');
    }

    public function test_a_draft_activity_has_no_execution_tab(): void
    {
        $draft = Activity::where('fiscal_year', 2026)->firstOrFail();
        $draft->forceFill(['status' => Activity::STATUS_DRAFT])->saveQuietly();
        $user = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();

        Volt::actingAs($user)->test('pages.activities.show', ['activity' => $draft])->assertDontSee('Paid / approved');
    }
}
