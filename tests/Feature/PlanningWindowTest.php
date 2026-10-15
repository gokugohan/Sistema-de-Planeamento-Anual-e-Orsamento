<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityWorkflow;
use App\Services\PlanningWindow;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Planning ends with the final approval of the plan; only the final approver or an administrator reopens it,
 * with a remark; and once the budget envelope is received nothing but its distribution changes.
 */
class PlanningWindowTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2028;

    private Directorate $unit;

    private Program $program;

    private PlanningWindow $window;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app()->setLocale('en');
        $this->unit = Directorate::create(['name' => 'Unit', 'code' => 'UNT', 'type' => 'General Directorate', 'active' => true]);
        $this->program = Program::create(['fiscal_year' => self::YEAR, 'oge_code' => 'P1', 'name' => 'P']);
        $this->window = app(PlanningWindow::class);
    }

    private function user(string $role, ?Directorate $unit = null): User
    {
        $u = User::factory()->create(['directorate_id' => $unit?->id]);
        $u->assignRole($role);

        return $u;
    }

    private function activity(string $status): Activity
    {
        $a = Activity::create([
            'fiscal_year' => self::YEAR, 'program_id' => $this->program->id, 'directorate_id' => $this->unit->id, 'description' => 'Activity '.uniqid(),
            'priority' => 'Alta', 'planned_start' => self::YEAR.'-02-01', 'planned_end' => self::YEAR.'-06-30', 'quarter' => 1, 'status' => $status, 'is_test_data' => false,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => 100, 'is_test_data' => false]);

        return $a;
    }

    private function envelope(string $status): FiscalEnvelope
    {
        return FiscalEnvelope::create([
            'fiscal_year' => self::YEAR, 'reference_number' => 'OGE', 'source_authority' => 'Parlamento', 'total_amount' => 1000,
            'status' => $status, 'start_date' => self::YEAR.'-01-01', 'due_date' => self::YEAR.'-12-31', 'is_test_data' => false,
        ]);
    }

    public function test_the_final_approver_finalizes_the_plan_and_planning_ends(): void
    {
        $this->activity(Activity::STATUS_APPROVED);
        $minister = $this->user('Minister');

        $this->assertTrue($this->window->isOpen(self::YEAR));
        $this->window->finalize($minister, self::YEAR, 'All units decided.');

        $this->assertSame('Finalized', $this->window->endedBy(self::YEAR));
        $this->assertFalse(FiscalEnvelope::planningOpen(self::YEAR));
        $state = $this->window->state(self::YEAR);
        $this->assertSame($minister->name, $state['by']);
        $this->assertSame('All units decided.', $state['remark']);
    }

    public function test_only_the_final_approver_or_an_administrator_can_decide(): void
    {
        $this->activity(Activity::STATUS_APPROVED);

        foreach (['Planning', 'Viewer'] as $role) {
            $this->assertFalse($this->window->canDecide($this->user($role, $this->unit)), $role);
        }
        $this->assertTrue($this->window->canDecide($this->user('Minister')));
        $this->assertTrue($this->window->canDecide($this->user('Admin')));

        $planner = $this->user('Planning', $this->unit);
        try {
            $this->window->finalize($planner, self::YEAR);
            $this->fail('a planner cannot finalize the plan');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertTrue($this->window->isOpen(self::YEAR));
    }

    public function test_it_cannot_be_finalized_while_something_waits_for_a_decision_or_with_nothing_approved(): void
    {
        $minister = $this->user('Minister');

        try {
            $this->window->finalize($minister, self::YEAR);
            $this->fail('nothing approved');
        } catch (DomainException $e) {
            $this->assertStringContainsString('no approved activity', $e->getMessage());
        }

        $this->activity(Activity::STATUS_APPROVED);
        $this->activity(Activity::STATUS_VALIDATED);
        try {
            $this->window->finalize($minister, self::YEAR);
            $this->fail('one is still waiting');
        } catch (DomainException $e) {
            $this->assertStringContainsString('1 activity is still waiting', $e->getMessage());
        }
        $this->assertTrue($this->window->isOpen(self::YEAR));
    }

    public function test_reopening_needs_a_remark_the_right_person_and_is_recorded(): void
    {
        $this->activity(Activity::STATUS_APPROVED);
        $minister = $this->user('Minister');
        $this->window->finalize($minister, self::YEAR);

        try {
            $this->window->reopen($minister, self::YEAR, '  ');
            $this->fail('a remark is required');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Say why', $e->getMessage());
        }
        $this->assertSame('Finalized', $this->window->endedBy(self::YEAR));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        try {
            $this->window->reopen($this->user('Planning', $this->unit), self::YEAR, 'I would like to add one');
        } finally {
            $this->window->reopen($this->user('Admin'), self::YEAR, 'A new programme was agreed with the ministry.');
            $this->assertTrue($this->window->isOpen(self::YEAR));
            $log = AuditLog::where('action', 'budgetcycle.updated')->latest('id')->first();
            $this->assertSame('A new programme was agreed with the ministry.', $log->metadata['remark']);
            $this->assertSame('reopened', $log->metadata['planning']);
        }
    }

    public function test_once_the_envelope_is_received_planning_cannot_be_reopened_and_only_distribution_remains(): void
    {
        $approved = $this->activity(Activity::STATUS_APPROVED);
        $minister = $this->user('Minister');
        $this->window->finalize($minister, self::YEAR);
        $this->assertTrue(app(ActivityWorkflow::class)->canReopen($minister, $approved->fresh()), 'before the envelope an approved activity can still be reopened');

        $this->envelope(FiscalEnvelope::STATUS_APPROVED);

        $this->assertSame('Approved', $this->window->endedBy(self::YEAR));
        $this->assertTrue($this->window->envelopeReceived(self::YEAR));
        $this->assertFalse(app(ActivityWorkflow::class)->canReopen($minister, $approved->fresh()), 'after the envelope only its distribution changes');
        $this->expectException(DomainException::class);
        $this->window->reopen($this->user('Admin'), self::YEAR, 'Even an administrator cannot reopen it now');
    }

    public function test_an_envelope_received_without_finalizing_ends_planning_too(): void
    {
        $this->activity(Activity::STATUS_APPROVED);
        $this->envelope(FiscalEnvelope::STATUS_APPROVED);

        $this->assertSame('Approved', $this->window->endedBy(self::YEAR));
        $this->expectException(DomainException::class);
        $this->window->finalize($this->user('Minister'), self::YEAR);
    }

    public function test_the_planning_card_finalizes_and_reopens_for_the_final_approver(): void
    {
        $this->activity(Activity::STATUS_APPROVED);
        $this->actingAs($this->user('Minister'));

        Volt::test('pages.planning.planning-window', ['year' => self::YEAR])
            ->assertSee('Planning for '.self::YEAR.' is open')->assertSee('Finalize plan')
            ->set('remark', 'Done')->call('finalize')->assertDispatched('planning-changed')
            ->assertSee('has ended')->assertSee('Reopen planning')
            ->set('remark', 'A late change was agreed')->call('reopen')->assertDispatched('planning-changed')
            ->assertSee('is open');

        $this->assertTrue($this->window->isOpen(self::YEAR));
    }

    public function test_a_planner_sees_the_state_but_no_buttons(): void
    {
        $this->activity(Activity::STATUS_APPROVED);
        $this->window->finalize($this->user('Minister'), self::YEAR);
        $this->actingAs($this->user('Planning', $this->unit));

        Volt::test('pages.planning.planning-window', ['year' => self::YEAR])
            ->assertSee('has ended')->assertSee('Only the final approver or an administrator can reopen planning')->assertDontSee('Reopen planning');
    }

    public function test_at_an_office_the_head_gives_the_final_approval_of_the_plan(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $head = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $unitDirector = User::where('email', 'director.udig@goku.tl')->firstOrFail();
        // everything of 2027 decided, so the plan can be finalized
        DB::table('activities')->where('fiscal_year', 2027)->update(['status' => Activity::STATUS_APPROVED]);

        $this->assertTrue($this->window->canDecide($head));
        $this->assertFalse($this->window->canDecide($unitDirector), 'the director of a unit does not give the final approval');

        $this->window->finalize($head, 2027, 'Plan of the office is final.');
        $this->assertSame('Finalized', $this->window->endedBy(2027));
    }
}
