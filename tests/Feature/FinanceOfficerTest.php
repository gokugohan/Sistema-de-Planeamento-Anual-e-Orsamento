<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\IndicatorProgress;
use App\Models\User;
use App\Services\ApprovalInbox;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The Finance Officer verifies the spending the units report, and nothing else. */
#[Group('office')]
class FinanceOfficerTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);

        $director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')->whereHas('indicators')
            ->whereIn('directorate_id', Directorate::find($director->directorate_id)->getSelfAndDescendantIds())->firstOrFail();

        $this->finance = User::factory()->create(['directorate_id' => $director->directorate_id]);
        $this->finance->assignRole('Finance');

        $line = $this->activity->lineItems()->whereHas('adjustedBudget')->firstOrFail();
        BudgetExecution::updateOrCreate(['line_item_id' => $line->id, 'month' => 10], [
            'committed' => 100, 'paid' => 50, 'status' => 'Submitted', 'submitted_by' => $director->id,
        ]);
        $this->line = $line;
    }

    private $line;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_role_holds_the_verify_spending_permission_only_for_money(): void
    {
        $this->assertTrue($this->finance->can('verify_spending'));
        $this->assertFalse($this->finance->can('approve_progress_report'));
        $this->assertFalse($this->finance->can('update_indicator_progress'));
    }

    public function test_finance_verifies_spending_but_the_unit_figures_stay_with_the_unit(): void
    {
        Volt::actingAs($this->finance)->test('pages.monitoring.execution-spending', ['activityId' => $this->activity->id, 'month' => 10])->call('verifyAll');

        $this->assertSame('Verified', BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->value('status'));
        $this->assertSame($this->finance->id, BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->value('verified_by'));
    }

    public function test_finance_cannot_verify_indicator_progress(): void
    {
        $indicator = $this->activity->indicators()->firstOrFail();
        IndicatorProgress::updateOrCreate(['indicator_id' => $indicator->id, 'quarter' => 4], ['achieved_value' => 1, 'status' => 'Submitted']);

        Volt::actingAs($this->finance)->test('pages.monitoring.execution-progress', ['activityId' => $this->activity->id, 'quarter' => 4])
            ->call('verifyProgress', $indicator->id)->assertForbidden();
    }

    public function test_the_inbox_lists_spending_to_verify_and_the_approvals_page_opens(): void
    {
        $items = collect(app(ApprovalInbox::class)->items($this->finance, 2026));
        $this->assertTrue($items->contains(fn ($i) => str_contains($i['text'], 'spending figure')));
        $this->assertFalse($items->contains(fn ($i) => str_contains($i['text'], 'progress report')));

        $this->actingAs($this->finance)->get(route('approvals.index'))->assertOk();
    }
}
