<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\LineItem;
use App\Models\User;
use App\Services\ExecutionHistory;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** A corrected figure still shows what was verified before: who verified it, when, and the values. */
#[Group('office')]
class ExecutionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    private LineItem $line;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->activity = Activity::where('fiscal_year', 2026)->where('directorate_id', Directorate::where('code', 'UDIG')->value('id'))->where('status', Activity::STATUS_APPROVED)->firstOrFail();
        $this->line = $this->activity->lineItems()->whereHas('adjustedBudget')->firstOrFail();
        BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(string $email)
    {
        return Volt::actingAs(User::where('email', $email)->firstOrFail())->test('pages.monitoring.execution-spending')
            ->set('activityId', $this->activity->id)->set('month', 10);
    }

    private function report(float $executed): void
    {
        $this->page('director.udig@gdsc.tl')->set("form.{$this->line->id}.executed", $executed)->call('save', true);
    }

    public function test_the_values_verified_before_a_correction_stay_visible(): void
    {
        $this->report(800);
        $this->page('chefe.gdsc@gdsc.tl')->call('verify', $this->line->id);
        $entry = BudgetExecution::where('line_item_id', $this->line->id)->where('month', 10)->firstOrFail();
        $this->assertSame([], app(ExecutionHistory::class)->earlierVerified($entry->fresh()), 'nothing earlier yet');

        $this->page('chefe.gdsc@gdsc.tl')->set('reopenId', $this->line->id)->set('remark', 'Entered twice')->call('reopen');
        $this->report(900);
        $this->page('chefe.gdsc@gdsc.tl')->call('verify', $this->line->id);

        $earlier = app(ExecutionHistory::class)->earlierVerified($entry->fresh());
        $this->assertCount(1, $earlier);
        $this->assertEquals(800, $earlier[0]['executed']);
        $this->assertSame(User::where('email', 'chefe.gdsc@gdsc.tl')->value('name'), $earlier[0]['verified_by']);
        $this->assertEquals(900, $entry->fresh()->executed, 'the current figure is the corrected one');
    }

    public function test_the_page_lists_the_earlier_values(): void
    {
        $this->report(800);
        $this->page('chefe.gdsc@gdsc.tl')->call('verify', $this->line->id);
        $this->page('chefe.gdsc@gdsc.tl')->set('reopenId', $this->line->id)->set('remark', 'Entered twice')->call('reopen');
        $this->report(900);
        $this->page('chefe.gdsc@gdsc.tl')->call('verify', $this->line->id);

        $this->page('chefe.gdsc@gdsc.tl')->assertSee('Earlier verified values')->assertSee('800.00');
    }
}
