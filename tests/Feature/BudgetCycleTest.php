<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetCycle;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\BudgetCycleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BudgetCycleTest extends TestCase
{
    use RefreshDatabase;

    private EconomicClassification $goods;

    private Directorate $dir;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->goods = EconomicClassification::create(['code' => '20', 'name' => 'Bens e Serviços']);
        $this->dir = Directorate::create(['code' => 'DNTT', 'name' => 'DNTT', 'type' => 'National Directorate', 'active' => true]);
        $this->program = Program::create(['oge_code' => '002', 'name' => 'Transportes Terrestres']);
    }

    private function approved(float $amount, string $status = Activity::STATUS_APPROVED): Activity
    {
        $a = Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $this->dir->id,
            'description' => 'Atividade '.uniqid(), 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => '2027-01-01', 'planned_end' => '2027-03-31', 'status' => $status,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => $this->goods->id, 'estimated_amount' => $amount]);

        return $a;
    }

    private function as(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    public function test_submission_freezes_the_approved_plan(): void
    {
        $this->as('Admin');
        $a = $this->approved(1000);
        $this->approved(5000, Activity::STATUS_DRAFT);

        $cycle = app(BudgetCycleService::class)->recordSubmission(2027);

        $this->assertEquals(1000, $cycle->submitted_total);
        $this->assertSame(1, $cycle->submitted_activities);
        $this->assertStringStartsWith('PAA-2027-', $cycle->submission_reference);
        $this->assertSame(1, $cycle->submissionLines()->count());

        // Editing the activity afterwards does not change what was submitted…
        $a->lineItems()->first()->update(['estimated_amount' => 9999]);
        $this->assertEquals(1000, $cycle->fresh()->submissionLines()->sum('amount'));

        // …but the summary flags that the plan has moved on.
        $this->assertTrue(app(BudgetCycleService::class)->summary(2027)['plan_changed_since_submission']);
    }

    public function test_cannot_submit_an_empty_plan(): void
    {
        $this->as('Admin');
        $this->approved(1000, Activity::STATUS_SUBMITTED);

        $this->expectException(DomainException::class);
        app(BudgetCycleService::class)->recordSubmission(2027);
    }

    public function test_cro_decision_requires_submission_and_locks_it(): void
    {
        $this->as('Admin');
        $service = app(BudgetCycleService::class);
        $this->approved(1000);

        try {
            $service->recordCroDecision(2027, 800, '2026-08-20', null, null);
            $this->fail('CRO decision accepted before submission');
        } catch (DomainException) {
        }

        $service->recordSubmission(2027);
        $service->recordCroDecision(2027, 800, '2026-08-20', 'Ata CRO 12/2026', 'Corte de 20%');

        $this->expectException(DomainException::class);
        $service->recordSubmission(2027); // cannot replace after the CRO decided
    }

    public function test_summary_compares_every_stage(): void
    {
        $this->as('Admin');
        $service = app(BudgetCycleService::class);
        $this->approved(1200);

        $service->recordCeiling(2027, 1000, 'Resolução CM', '2026-07-08');
        $service->recordSubmission(2027);
        $service->recordCroDecision(2027, 1100, '2026-08-20', null, null);
        $envelope = FiscalEnvelope::create(['fiscal_year' => 2027, 'total_amount' => 1050, 'status' => FiscalEnvelope::STATUS_DRAFT, 'reference_number' => 'OGE/2027']);
        FiscalEnvelopeCategoryAllocation::create(['fiscal_envelope_id' => $envelope->id, 'economic_classification_id' => $this->goods->id, 'allocated_amount' => 1050]);

        $s = $service->summary(2027)['stages'];

        $this->assertEquals(200, $s['submitted_vs_ceiling']);
        $this->assertEquals(-100, $s['cro_vs_submitted']);
        $this->assertEquals(-50, $s['final_vs_cro']);
        $this->assertEquals(['20' => 1200.0], $service->summary(2027)['submitted_by_class']);
        $this->assertEquals(['20' => 1050.0], $service->summary(2027)['final_by_class']);
    }

    public function test_cro_decision_is_frozen_once_the_envelope_is_approved(): void
    {
        $this->as('Admin');
        $service = app(BudgetCycleService::class);
        $this->approved(1000);
        $service->recordSubmission(2027);
        FiscalEnvelope::create(['fiscal_year' => 2027, 'total_amount' => 900, 'status' => FiscalEnvelope::STATUS_APPROVED, 'reference_number' => 'OGE/2027']);

        $this->expectException(DomainException::class);
        $service->recordCroDecision(2027, 900, '2026-08-20', null, null);
    }

    public function test_viewing_a_year_does_not_create_records(): void
    {
        $this->as('Minister');
        $this->get(route('planning.budget-cycle'))->assertOk()->assertSee(__('Budget Cycle'));

        $this->assertSame(0, BudgetCycle::count());
    }

    public function test_only_admin_or_minister_can_record_stages(): void
    {
        $this->approved(1000);

        $this->as('Viewer');
        Volt::test('pages.planning.budget-cycle')->set('year', 2027)->call('recordSubmission')->assertForbidden();
        $this->assertSame(0, BudgetCycle::count());

        $this->as('Minister');
        Volt::test('pages.planning.budget-cycle')->set('year', 2027)->call('recordSubmission');
        $this->assertTrue(BudgetCycle::where('fiscal_year', 2027)->first()->isSubmitted());

        $this->as('Planning'); // no view_budget permission
        $this->get(route('planning.budget-cycle'))->assertForbidden();
    }

    public function test_fit_gap_shows_how_far_the_plan_is_from_the_cro_amount(): void
    {
        $this->as('Admin');
        $service = app(BudgetCycleService::class);
        $this->approved(1000);
        $this->assertNull($service->summary(2027)['fit_gap']); // no CRO decision yet

        $service->recordSubmission(2027);
        $service->recordCroDecision(2027, 700, '2026-08-20', null, null);
        $this->assertEquals(300, $service->summary(2027)['fit_gap']); // plan exceeds CRO by 300

        Activity::first()->lineItems()->first()->update(['estimated_amount' => 650]);
        $this->assertEquals(-50, $service->summary(2027)['fit_gap']); // now 50 under it
    }
}
