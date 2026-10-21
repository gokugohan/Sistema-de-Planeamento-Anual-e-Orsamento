<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Services\CalendarAlerts;
use App\Services\PlanningWindow;
use App\Services\PlanStatus;
use App\Services\ReportingWindows;
use App\Support\PlanOptions;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** The calendar of the plan: dates for every step, more time for one late unit, alerts, and the order of the year. */
#[Group('office')]
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $admin;

    private Activity $approved;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);

        $this->director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->approved = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')
            ->whereIn('directorate_id', Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds())->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function window(string $type, int $period, string $from, string $to, int $year = 2026): void
    {
        ReportingWindow::create(['fiscal_year' => $year, 'period_type' => $type, 'period' => $period, 'opens_on' => $from, 'closes_on' => $to]);
    }

    private function submitted2027(): Activity
    {
        $activity = Activity::where('fiscal_year', 2027)->whereHas('directorate', fn ($q) => $q->where('code', 'UDIG'))->firstOrFail();
        $activity->forceFill(['status' => Activity::STATUS_SUBMITTED, 'version' => 0, 'reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null])->save();

        return $activity->fresh();
    }

    public function test_a_late_unit_is_listed_and_reopening_it_opens_the_period_for_that_unit_only(): void
    {
        $this->window('month', 9, '2026-09-01', '2026-09-30');
        BudgetExecution::whereIn('line_item_id', $this->approved->lineItems()->pluck('id'))->where('month', 9)->delete();
        $windows = app(ReportingWindows::class);
        $unit = (int) $this->approved->directorate_id;

        $this->assertFalse($windows->allows($this->director, 2026, 'month', 9, $unit));
        $late = collect($windows->overdue(2026))->firstWhere('unit_id', $unit);
        $this->assertNotNull($late, 'the unit with nothing reported for September is late');
        $this->assertSame('month', $late['type']);

        $stranger = User::factory()->create(['directorate_id' => Directorate::where('code', 'USC')->value('id')]);
        $stranger->assignRole('Director');

        $windows->reopen($this->admin, 2026, 'month', 9, $unit, '2026-10-20', 'Power cut in the office');

        $this->assertTrue($windows->allows($this->director, 2026, 'month', 9, $unit));
        $this->assertFalse($windows->allows($stranger, 2026, 'month', 9, (int) $stranger->directorate_id), 'another unit stays closed');
        $this->assertNull(collect((new ReportingWindows)->overdue(2026))->firstWhere('unit_id', $unit), 'a reopened unit is no longer late');
        $this->assertSame(1, $this->director->notifications()->count(), 'the unit is told');

        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->assertFalse((new ReportingWindows)->allows($this->director, 2026, 'month', 9, $unit), 'the extra time ends on its date');
    }

    public function test_only_an_administrator_reopens_a_period(): void
    {
        $this->window('month', 9, '2026-09-01', '2026-09-30');

        $this->expectException(HttpException::class);
        app(ReportingWindows::class)->reopen($this->director, 2026, 'month', 9, (int) $this->director->directorate_id, '2026-10-20', null);
    }

    public function test_the_calendar_page_reopens_a_late_unit_in_one_step(): void
    {
        $this->window('month', 9, '2026-09-01', '2026-09-30');
        BudgetExecution::whereIn('line_item_id', $this->approved->lineItems()->pluck('id'))->where('month', 9)->delete();
        $unit = (int) $this->approved->directorate_id;
        $row = collect(app(ReportingWindows::class)->overdue(2026))->search(fn ($r) => $r['unit_id'] === $unit);

        Volt::actingAs($this->admin)->test('pages.management.reporting-windows')
            ->assertSee('Late')->assertSee('Reopen for this unit')
            ->set("until.$row", '2026-10-25')->set("why.$row", 'Late data')->call('reopen', $row)->assertHasNoErrors();

        $this->assertTrue(app(ReportingWindows::class)->allows($this->director, 2026, 'month', 9, $unit));
    }

    public function test_deciding_on_the_plan_can_have_its_own_dates(): void
    {
        (new PlanOptions(completeBeforeApproval: false))->save(); // this test is about the dates, not the order of the year
        $activity = $this->submitted2027();
        $workflow = app(ActivityWorkflow::class);
        $this->assertNotNull($workflow->next($this->director, $activity), 'no dates: open');

        $this->window('approval', 1, '2026-08-01', '2026-09-30', 2027);

        $this->assertNull($workflow->next($this->director, $activity), 'closed for the unit');
        $this->assertNotNull($workflow->next($this->admin, $activity), 'an administrator still can');

        app(ReportingWindows::class)->reopen($this->admin, 2027, 'approval', 1, (int) $activity->directorate_id, '2026-10-30', null);
        $this->assertNotNull((new ActivityWorkflow)->next($this->director, $activity), 'reopened for that unit');
    }

    public function test_the_plan_must_be_complete_before_approval_where_the_institution_asks_for_it(): void
    {
        $activity = $this->submitted2027();
        Activity::where('fiscal_year', 2027)->whereKeyNot($activity->id)->where('directorate_id', $activity->directorate_id)->firstOrFail()
            ->forceFill(['status' => Activity::STATUS_DRAFT, 'version' => 0])->save();
        $workflow = app(ActivityWorkflow::class);

        $this->assertNull($workflow->next($this->director, $activity), 'on by default: a draft of the directorate holds the decisions back');

        (new PlanOptions(completeBeforeApproval: false))->save();
        $this->assertNotNull($workflow->next($this->director, $activity), 'the institution can switch it off');
        (new PlanOptions)->save();
        $this->assertStringContainsString('not submitted yet', (string) app(PlanStatus::class)->holdBack($activity));

        $this->expectException(\DomainException::class);
        app(PlanningWindow::class)->finalize($this->admin, 2027);
    }

    public function test_dates_that_concern_a_person_are_shown_to_them(): void
    {
        $this->window('plan', 1, '2026-07-01', '2026-10-20', 2026);
        $this->window('quarter', 4, '2026-10-24', '2026-11-10', 2026);

        $texts = collect(app(CalendarAlerts::class)->for($this->director, 2026))->pluck('text')->implode(' | ');

        $this->assertStringContainsString('closes on 20/10/2026', $texts);
        $this->assertStringContainsString('opens on 24/10/2026', $texts);

        $this->actingAs($this->director)->followingRedirects()->get(route('dashboard'))->assertSee('closes on 20/10/2026');
    }

    public function test_a_late_unit_can_ask_the_administrators_once_a_day(): void
    {
        $this->window('month', 9, '2026-09-01', '2026-09-30');

        $page = fn () => Volt::actingAs($this->director)->test('pages.calendar.ask-reopen', ['year' => 2026, 'type' => 'month', 'period' => 9])->call('ask');
        $page();
        $page();

        $this->assertSame(1, $this->admin->notifications()->count(), 'one request per person and period a day');
    }

    public function test_a_report_warns_when_the_plan_is_not_fully_approved(): void
    {
        $this->submitted2027();
        $warning = app(PlanStatus::class)->reportWarning($this->admin, 2027);

        $this->assertStringContainsString('not fully approved', (string) $warning);

        $this->actingAs($this->admin)->get(route('reports.implementation-plan', ['year' => 2027]))->assertOk()->assertSee('Draft state');
    }
}
