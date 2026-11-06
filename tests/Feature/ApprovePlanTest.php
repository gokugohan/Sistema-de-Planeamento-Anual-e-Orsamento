<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Services\PlanningWindow;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The simplified end of the cycle: the head of a directorate approves the plan in one act (every certified activity
 * approved, the plan finalized), including what waits for the head's own validation, and only once it is ready.
 */
class ApprovePlanTest extends TestCase
{
    use RefreshDatabase;

    private PlanningWindow $window;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        app()->setLocale('en');
        app(MtcSampleDataSeeder::class)->setContainer(app())->run('halfway');
        $this->window = app(PlanningWindow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The first directorate (by code) whose 2027 activities are all at this stage, and its head. @return array{0: Directorate, 1: User} */
    private function directorateAt(string $status): array
    {
        $root = Activity::where('fiscal_year', 2027)->get()->groupBy(fn ($a) => $this->window->rootOf((int) $a->directorate_id))
            ->filter(fn ($g) => $g->pluck('status')->unique()->all() === [$status])->keys()
            ->map(fn ($id) => Directorate::find($id))->sortBy('code')->first();
        $this->assertNotNull($root, "no directorate at {$status}");
        $head = User::role('Director')->where('directorate_id', $root->id)->firstOrFail();

        return [$root, $head];
    }

    public function test_the_head_approves_the_plan_in_one_act_and_it_is_finalized(): void
    {
        [$root, $head] = $this->directorateAt(Activity::STATUS_VALIDATED);
        // one activity of a unit right under the directorate still waits for the head's validation: approving covers it
        $child = Directorate::where('parent_id', $root->id)->pluck('id');
        $waiting = Activity::where('fiscal_year', 2027)->whereIn('directorate_id', $child)->first();
        if ($waiting) {
            $waiting->update(['status' => Activity::STATUS_REVIEWED]);
        }
        $total = Activity::where('fiscal_year', 2027)->whereIn('directorate_id', $this->window->subtree($root->id))->count();

        $state = $this->window->planReadiness(2027, $root->id);
        $this->assertTrue($state['ready']);
        $this->assertSame($total, $state['certified']);

        $result = $this->window->approvePlan($head, 2027, $root->id, 'Plan approved at the board meeting');
        $this->assertTrue($result['finalized']);
        $this->assertSame($total, $result['approved']);
        $this->assertSame(0, Activity::where('fiscal_year', 2027)->whereIn('directorate_id', $this->window->subtree($root->id))
            ->where('status', '!=', Activity::STATUS_APPROVED)->count());
        $this->assertSame('Plan approved at the board meeting', UnitPlanning::where(['fiscal_year' => 2027, 'directorate_id' => $root->id])->value('planning_remark'));
    }

    public function test_a_plan_still_with_the_units_is_not_ready_and_cannot_be_approved(): void
    {
        [$root, $head] = $this->directorateAt(Activity::STATUS_DRAFT);

        $state = $this->window->planReadiness(2027, $root->id);
        $this->assertFalse($state['ready']);
        $this->assertGreaterThan(0, $state['with_units']);

        $this->expectException(\DomainException::class);
        $this->window->approvePlan($head, 2027, $root->id);
    }

    public function test_waiting_for_you_offers_the_plan_to_its_head_and_approves_it(): void
    {
        [$root, $head] = $this->directorateAt(Activity::STATUS_VALIDATED);
        \App\Support\FiscalYear::pin(2027);
        $this->actingAs($head);

        $this->get(route('approvals.index'))->assertOk()->assertSee('Approve the plan')->assertSee($root->code);
        Volt::test('pages.planning.approve-plan', ['year' => 2027])->call('approve', $root->id)->assertDispatched('toast');
        $this->assertTrue(UnitPlanning::where(['fiscal_year' => 2027, 'directorate_id' => $root->id])->exists());
    }
}
