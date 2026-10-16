<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Services\ApprovalInbox;
use App\Support\ApprovalRules;
use App\Support\Institution;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The resource plan: where an institution asks for it, a finance officer confirms the budget before the activity is certified. */
class BudgetConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private ActivityWorkflow $workflow;

    private Activity $activity;

    private Directorate $unit;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->workflow = app(ActivityWorkflow::class);

        $this->unit = Directorate::where('code', 'UDIG')->firstOrFail();
        $this->activity = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => $this->unit->id,
            'description' => 'Budget test activity', 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_SUBMITTED,
        ]);
        $this->activity->lineItems()->create(['code' => 'B-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 1000]);
        $this->activity->indicators()->create(['name' => 'Outputs', 'type' => 'Output', 'unit' => 'units', 'annual_target' => 5]);
    }

    private function switchOn(): void
    {
        (new ApprovalRules(true, true))->save();
        \App\Models\SystemSetting::flushCache();
    }

    private function finance(): User
    {
        $u = User::factory()->create(['directorate_id' => $this->unit->id]);
        $u->assignRole('Finance');

        return $u;
    }

    private function director(): User
    {
        return User::where('email', 'director.udig@goku.tl')->firstOrFail();
    }

    public function test_switched_off_nothing_changes(): void
    {
        $this->assertTrue($this->workflow->budgetConfirmed($this->activity));
        $this->assertNotNull($this->workflow->next($this->director(), $this->activity->fresh()));
        $this->assertFalse($this->workflow->canConfirmBudget($this->finance(), $this->activity->fresh()));
    }

    public function test_switched_on_certification_waits_for_finance(): void
    {
        $this->switchOn();

        $this->assertFalse($this->workflow->budgetConfirmed($this->activity->fresh()));
        $this->assertNull($this->workflow->next($this->director(), $this->activity->fresh()), 'the director cannot certify an unconfirmed budget');
        $this->assertSame('The finance officer to confirm the budget', $this->workflow->awaiting($this->activity->fresh()));

        $this->workflow->confirmBudget($this->finance(), $this->activity->fresh(), 'Matches the unit\'s costing');

        $a = $this->activity->fresh();
        $this->assertNotNull($a->budget_confirmed_at);
        $this->assertSame('Matches the unit\'s costing', $a->budget_justification);
        $this->assertNotNull($this->workflow->next($this->director(), $a), 'now it is the director\'s turn');
    }

    public function test_changing_the_lines_takes_the_confirmation_away(): void
    {
        $this->switchOn();
        $this->workflow->confirmBudget($this->finance(), $this->activity->fresh());
        $this->assertNotNull($this->activity->fresh()->budget_confirmed_at);

        $this->activity->lineItems()->first()->update(['estimated_amount' => 1500]);

        $this->assertNull($this->activity->fresh()->budget_confirmed_at);
        $this->assertNull($this->activity->fresh()->budget_confirmed_by);
    }

    public function test_only_finance_of_the_unit_confirms_and_only_while_it_waits(): void
    {
        $this->switchOn();
        $outsider = User::factory()->create(['directorate_id' => Directorate::where('id', '!=', $this->unit->id)->where('id', '!=', $this->unit->parent_id)->firstOrFail()->id]);
        $outsider->assignRole('Finance');

        $this->assertFalse($this->workflow->canConfirmBudget($this->director(), $this->activity->fresh()), 'a director is not finance');
        $this->assertFalse($this->workflow->canConfirmBudget($outsider, $this->activity->fresh()), 'finance of another unit');
        $this->assertTrue($this->workflow->canConfirmBudget($this->finance(), $this->activity->fresh()));

        $this->activity->forceFill(['status' => Activity::STATUS_DRAFT])->saveQuietly();
        $this->assertFalse($this->workflow->canConfirmBudget($this->finance(), $this->activity->fresh()));
    }

    public function test_the_review_page_confirms_and_returns_with_a_reason(): void
    {
        $this->switchOn();
        $finance = $this->finance();
        $second = Activity::create(['fiscal_year' => 2027, 'program_id' => $this->activity->program_id, 'directorate_id' => $this->unit->id, 'description' => 'Second', 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31', 'status' => Activity::STATUS_SUBMITTED]);
        $second->lineItems()->create(['code' => 'B-2', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 50]);
        session(['fiscal_year' => 2027]);

        $page = Volt::actingAs($finance)->test('pages.approvals.budget-review')->assertSee('Budget test activity');
        $page->set("justification.{$this->activity->id}", 'Costing checked')->call('confirm', $this->activity->id);
        $this->assertNotNull($this->activity->fresh()->budget_confirmed_at);

        $page->call('sendBack', $second->id); // no reason: nothing happens
        $this->assertSame(Activity::STATUS_SUBMITTED, $second->fresh()->status);
        $page->set("reason.{$second->id}", 'Amounts do not match the quotation')->call('sendBack', $second->id);
        $this->assertSame(Activity::STATUS_REJECTED, $second->fresh()->status);
    }

    public function test_the_page_and_the_inbox_are_for_finance_and_say_when_it_is_off(): void
    {
        $finance = $this->finance();
        session(['fiscal_year' => 2027]);

        $this->actingAs($this->director())->get(route('approvals.budget'))->assertForbidden();
        $this->actingAs($finance)->get(route('approvals.budget'))->assertOk()->assertSee('not switched on');

        $this->switchOn();
        $items = collect(app(ApprovalInbox::class)->items($finance->fresh(), 2027));
        $this->assertTrue($items->contains(fn ($i) => str_contains($i['text'], 'budget to be confirmed') || str_contains($i['text'], 'their budget to be confirmed')));
    }

    public function test_the_activity_page_says_whether_the_budget_is_confirmed(): void
    {
        $this->switchOn();
        $director = $this->director();

        Volt::actingAs($director)->test('pages.activities.show', ['activity' => $this->activity])->assertSee('not confirmed yet');

        $this->workflow->confirmBudget($this->finance(), $this->activity->fresh(), 'Checked');
        Volt::actingAs($director)->test('pages.activities.show', ['activity' => $this->activity->fresh()])->assertSee('confirmed by')->assertSee('Checked');
    }
}
