<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\LineItem;
use App\Models\User;
use App\Services\ExecutionReport;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('office')]
class BudgetExecutionEntryTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    private LineItem $line;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow('2026-10-14 10:00:00'); // the last quarter of 2026
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();

        $unit = Directorate::where('code', 'UDIG')->value('id');
        $this->activity = Activity::where('fiscal_year', 2026)->where('directorate_id', $unit)->where('status', Activity::STATUS_APPROVED)->firstOrFail();
        $this->line = $this->activity->lineItems()->whereHas('adjustedBudget')->firstOrFail();
        // start from a clean October for this line
        BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function page(User $user)
    {
        return Volt::actingAs($user)->test('pages.monitoring.execution-spending')
            ->set('activityId', $this->activity->id)->set('month', 10);
    }

    private function fill($page, float $executed)
    {
        return $page->set("form.{$this->line->id}.executed", $executed);
    }

    public function test_the_unit_reports_and_someone_else_verifies_and_only_then_it_counts(): void
    {
        $report = app(ExecutionReport::class);
        $before = $report->summary($report->load(2026))['executed'];

        $this->fill($this->page($this->user('director.udig@gdsc.tl')), 800)->call('save', true)->assertHasNoErrors();
        $entry = BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->firstOrFail();
        $this->assertSame(BudgetExecution::SUBMITTED, $entry->status);
        $this->assertSame($before, $report->summary($report->load(2026))['executed'], 'a submitted figure does not count yet');

        // the person who reported it cannot verify it
        $this->page($this->user('director.udig@gdsc.tl'))->call('verify', $this->line->id);
        $this->assertSame(BudgetExecution::SUBMITTED, $entry->fresh()->status);

        $this->page($this->user('chefe.gdsc@gdsc.tl'))->call('verify', $this->line->id);
        $this->assertSame(BudgetExecution::VERIFIED, $entry->fresh()->status);
        $this->assertSame($this->user('chefe.gdsc@gdsc.tl')->id, $entry->fresh()->verified_by);
        $this->assertEqualsWithDelta($before + 800, $report->summary($report->load(2026))['executed'], 0.01);
    }

    public function test_a_verified_quarter_cannot_be_changed_from_the_page(): void
    {
        $this->fill($this->page($this->user('director.udig@gdsc.tl')), 800)->call('save', true);
        $this->page($this->user('chefe.gdsc@gdsc.tl'))->call('verify', $this->line->id);

        $this->fill($this->page($this->user('director.udig@gdsc.tl')), 1)->call('save', false);

        $entry = BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->first();
        $this->assertEquals(800, $entry->executed);
        $this->assertSame(BudgetExecution::VERIFIED, $entry->status);
    }

    public function test_a_returned_entry_can_be_corrected_and_sent_again(): void
    {
        $this->fill($this->page($this->user('director.udig@gdsc.tl')), 500)->call('save', true);
        $this->page($this->user('chefe.gdsc@gdsc.tl'))->call('verify', $this->line->id, false);
        $this->assertSame(BudgetExecution::REJECTED, BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->value('status'));

        $this->fill($this->page($this->user('director.udig@gdsc.tl')), 600)->call('save', true);
        $this->assertSame(BudgetExecution::SUBMITTED, BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->value('status'));
    }

    public function test_amounts_are_checked_against_the_approved_budget_and_against_each_other(): void
    {
        $approved = (float) $this->line->adjustedBudget->approved_amount;

        // the executed amount is capped by the approved amount, counting what the other months already executed
        $this->fill($this->page($this->user('director.udig@gdsc.tl')), $approved * 5)->call('save')
            ->assertHasErrors(["form.{$this->line->id}.executed"]);

        $others = (float) $this->line->executions()->where('month', '!=', 10)->sum('executed');
        $this->fill($this->page($this->user('director.udig@gdsc.tl')), $approved - $others + 1)->call('save')
            ->assertHasErrors(["form.{$this->line->id}.executed"]);

        $this->fill($this->page($this->user('director.udig@gdsc.tl')), -5)->call('save')
            ->assertHasErrors(["form.{$this->line->id}.executed"]);

        $this->assertNull(BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->first(), 'nothing invalid was saved');
    }

    public function test_someone_without_the_permission_or_outside_the_unit_cannot_report(): void
    {
        $this->fill($this->page($this->user('tecnico.udig@gdsc.tl')), 10)->call('save')->assertForbidden();

        $other = User::factory()->create(['directorate_id' => Directorate::where('code', 'USC')->value('id')]);
        $other->assignRole('Director');
        $this->fill($this->page($other), 10)->call('save')->assertForbidden();

        $this->assertNull(BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->first());
    }

    public function test_the_page_shows_what_is_left_of_the_approved_amount_as_it_is_typed(): void
    {
        $approved = (float) $this->line->adjustedBudget->approved_amount;
        $others = (float) $this->line->executions()->where('month', '!=', 10)->sum('executed');

        $this->fill($this->page($this->user('director.udig@gdsc.tl')), 100)
            ->assertSee('Left after this month')
            ->assertSee(number_format($approved - $others - 100, 2));
    }

    public function test_nothing_executed_reports_zero_and_copy_last_month_fills_the_empty_lines(): void
    {
        $director = $this->user('director.udig@gdsc.tl');
        BudgetExecution::where('line_item_id', $this->line->id)->where('month', 9)->delete();
        BudgetExecution::create(['line_item_id' => $this->line->id, 'month' => 9, 'executed' => 250, 'status' => BudgetExecution::VERIFIED]);

        $copied = $this->page($director)->call('copyPreviousMonth');
        $copied->assertSet("form.{$this->line->id}.executed", 250.0);

        $this->page($director)->call('markNothingSpent')->assertSet("form.{$this->line->id}.executed", 0)->call('save', true)->assertHasNoErrors();
        $entry = BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->firstOrFail();
        $this->assertEquals(0, $entry->executed, 'nothing was executed, and that is reported');
        $this->assertSame(BudgetExecution::SUBMITTED, $entry->status);
    }

    public function test_a_month_that_has_not_started_cannot_be_reported(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00'); // May

        $page = Volt::actingAs($this->user('director.udig@gdsc.tl'))->test('pages.monitoring.execution-spending')
            ->set('activityId', $this->activity->id)->set('month', 10);

        $page->assertSet('month', 5);
    }
}
