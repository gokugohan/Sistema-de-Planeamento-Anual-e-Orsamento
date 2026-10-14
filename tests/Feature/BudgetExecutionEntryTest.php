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
use Tests\TestCase;

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
        // start from a clean quarter 4 for this line
        BudgetExecution::where('line_item_id', $this->line->id)->where('quarter', 4)->delete();
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
        return Volt::actingAs($user)->test('pages.monitoring.budget-execution')
            ->set('activityId', $this->activity->id)->set('quarter', 4);
    }

    private function fill($page, float $committed, float $paid)
    {
        return $page->set("form.{$this->line->id}.committed", $committed)->set("form.{$this->line->id}.paid", $paid);
    }

    public function test_the_unit_reports_and_someone_else_verifies_and_only_then_it_counts(): void
    {
        $report = app(ExecutionReport::class);
        $before = $report->summary($report->load(2026))['paid'];

        $this->fill($this->page($this->user('director.udig@goku.tl')), 1000, 800)->call('save', true)->assertHasNoErrors();
        $entry = BudgetExecution::where('line_item_id', $this->line->id)->where('quarter', 4)->firstOrFail();
        $this->assertSame(BudgetExecution::SUBMITTED, $entry->status);
        $this->assertSame($before, $report->summary($report->load(2026))['paid'], 'a submitted figure does not count yet');

        // the person who reported it cannot verify it
        $this->page($this->user('director.udig@goku.tl'))->call('verify', $this->line->id);
        $this->assertSame(BudgetExecution::SUBMITTED, $entry->fresh()->status);

        $this->page($this->user('chefe.gdsc@goku.tl'))->call('verify', $this->line->id);
        $this->assertSame(BudgetExecution::VERIFIED, $entry->fresh()->status);
        $this->assertSame($this->user('chefe.gdsc@goku.tl')->id, $entry->fresh()->verified_by);
        $this->assertEqualsWithDelta($before + 800, $report->summary($report->load(2026))['paid'], 0.01);
    }

    public function test_a_verified_quarter_cannot_be_changed_from_the_page(): void
    {
        $this->fill($this->page($this->user('director.udig@goku.tl')), 1000, 800)->call('save', true);
        $this->page($this->user('chefe.gdsc@goku.tl'))->call('verify', $this->line->id);

        $this->fill($this->page($this->user('director.udig@goku.tl')), 1, 1)->call('save', false);

        $entry = BudgetExecution::where('line_item_id', $this->line->id)->where('quarter', 4)->first();
        $this->assertEquals(1000, $entry->committed);
        $this->assertSame(BudgetExecution::VERIFIED, $entry->status);
    }

    public function test_a_returned_entry_can_be_corrected_and_sent_again(): void
    {
        $this->fill($this->page($this->user('director.udig@goku.tl')), 500, 500)->call('save', true);
        $this->page($this->user('chefe.gdsc@goku.tl'))->call('verify', $this->line->id, false);
        $this->assertSame(BudgetExecution::REJECTED, BudgetExecution::where('line_item_id', $this->line->id)->where('quarter', 4)->value('status'));

        $this->fill($this->page($this->user('director.udig@goku.tl')), 600, 500)->call('save', true);
        $this->assertSame(BudgetExecution::SUBMITTED, BudgetExecution::where('line_item_id', $this->line->id)->where('quarter', 4)->value('status'));
    }

    public function test_amounts_are_checked_against_the_approved_budget_and_against_each_other(): void
    {
        $approved = (float) $this->line->adjustedBudget->approved_amount;

        $this->fill($this->page($this->user('director.udig@goku.tl')), $approved * 5, 0)->call('save')
            ->assertHasErrors(["form.{$this->line->id}.committed"]);

        // paid is capped by everything committed so far, including the other quarters
        $slack = (float) $this->line->executions()->where('quarter', '!=', 4)->sum('committed') - (float) $this->line->executions()->where('quarter', '!=', 4)->sum('paid');
        $this->fill($this->page($this->user('director.udig@goku.tl')), 100, $slack + 100 + 50)->call('save')
            ->assertHasErrors(["form.{$this->line->id}.paid"]);

        $this->fill($this->page($this->user('director.udig@goku.tl')), -5, 0)->call('save')
            ->assertHasErrors(["form.{$this->line->id}.committed"]);

        $this->assertNull(BudgetExecution::where('line_item_id', $this->line->id)->where('quarter', 4)->first(), 'nothing invalid was saved');
    }

    public function test_someone_without_the_permission_or_outside_the_unit_cannot_report(): void
    {
        $this->fill($this->page($this->user('planner.udig@goku.tl')), 10, 10)->call('save')->assertForbidden();

        $other = User::factory()->create(['directorate_id' => Directorate::where('code', 'USC')->value('id')]);
        $other->assignRole('Director');
        $this->fill($this->page($other), 10, 10)->call('save')->assertForbidden();

        $this->assertNull(BudgetExecution::where('line_item_id', $this->line->id)->where('quarter', 4)->first());
    }

    public function test_a_quarter_that_has_not_started_cannot_be_reported(): void
    {
        Carbon::setTestNow('2026-05-10 10:00:00'); // second quarter

        $page = Volt::actingAs($this->user('director.udig@goku.tl'))->test('pages.monitoring.budget-execution')
            ->set('activityId', $this->activity->id)->set('quarter', 4);

        $page->assertSet('quarter', 2);
    }
}
