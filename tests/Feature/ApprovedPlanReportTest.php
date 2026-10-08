<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\ApprovedPlanReport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovedPlanReportTest extends TestCase
{
    use RefreshDatabase;

    private EconomicClassification $goods;
    private EconomicClassification $capital;
    private Directorate $dg;
    private Directorate $dn;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->goods = EconomicClassification::create(['code' => '20', 'name' => 'Bens e Serviços']);
        $this->capital = EconomicClassification::create(['code' => '50', 'name' => 'Capital de Desenvolvimento']);
        $this->dg = Directorate::create(['code' => 'DGT', 'name' => 'Direção-Geral dos Transportes', 'type' => 'General Directorate', 'active' => true]);
        $this->dn = Directorate::create(['code' => 'DNTT', 'name' => 'Direção Nacional dos Transportes Terrestres', 'type' => 'National Directorate', 'parent_id' => $this->dg->id, 'active' => true]);
        $this->program = Program::create(['oge_code' => '002', 'name' => 'Transportes Terrestres']);
    }

    private function activity(string $status, array $lines): Activity
    {
        $a = Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $this->dn->id,
            'description' => "Atividade {$status} " . uniqid(), 'quarter' => 2, 'priority' => 'Alta',
            'planned_start' => '2027-04-01', 'planned_end' => '2027-06-30', 'status' => $status,
        ]);
        foreach ($lines as [$class, $amount]) {
            LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => $class->id, 'estimated_amount' => $amount]);
        }

        return $a;
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    public function test_report_contains_only_the_approved_plan_and_counts_the_rest(): void
    {
        $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 1000], [$this->capital, 5000]]);
        $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 500]]);
        $this->activity(Activity::STATUS_DRAFT, [[$this->goods, 99999]]);
        $this->activity(Activity::STATUS_SUBMITTED, [[$this->goods, 88888]]);

        $r = (new ApprovedPlanReport)->build(2027);

        $this->assertSame(2, $r['totals']['count']);
        $this->assertEquals(6500, $r['totals']['total']);
        $this->assertEquals(1500, $r['totals']['by_class'][$this->goods->id]);
        $this->assertEquals(5000, $r['totals']['by_class'][$this->capital->id]);
        $this->assertSame([Activity::STATUS_DRAFT => 1, Activity::STATUS_SUBMITTED => 1], $r['pending']);

        // Grouped under the Direção-Geral, with the national directorate beneath it.
        $this->assertSame('DGT', $r['by_unit'][0]['code']);
        $this->assertSame('DNTT', $r['by_unit'][0]['units'][0]['code']);
        $this->assertEquals(6500, $r['by_program'][0]['total']);
    }

    public function test_fingerprint_changes_when_the_plan_changes(): void
    {
        $a = $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 1000]]);
        $before = (new ApprovedPlanReport)->build(2027)['fingerprint'];
        $this->assertSame($before, (new ApprovedPlanReport)->build(2027)['fingerprint']);

        $a->lineItems()->first()->update(['estimated_amount' => 1200]);

        $this->assertNotSame($before, (new ApprovedPlanReport)->build(2027)['fingerprint']);
    }

    public function test_print_view_and_pdf_download_work_and_pdf_is_audited(): void
    {
        $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 1000]]);
        $this->actingAs($this->user('Viewer'));

        $this->get(route('reports.approved-plan', ['year' => 2027]))
            ->assertOk()
            ->assertSee('DNTT')
            ->assertSee('1,000.00');

        $pdf = $this->get(route('reports.approved-plan.pdf', ['year' => 2027]));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.approved_plan_pdf']);
        $this->assertSame(2027, AuditLog::where('action', 'report.approved_plan_pdf')->first()->metadata['fiscal_year']);
    }

    public function test_users_without_report_permission_are_refused(): void
    {
        $this->actingAs(User::factory()->create()); // no role

        $this->get(route('reports.approved-plan', ['year' => 2027]))->assertForbidden();
        $this->get(route('reports.approved-plan.pdf', ['year' => 2027]))->assertForbidden();
    }

    public function test_planned_amount_on_the_envelope_counts_only_approved_activities(): void
    {
        $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 1000]]);
        $this->activity(Activity::STATUS_DRAFT, [[$this->goods, 4000]]);
        $this->activity(Activity::STATUS_REJECTED, [[$this->goods, 2000]]);

        $this->assertEquals(1000, FiscalEnvelopeCategoryAllocation::calculatePlannedAmount(2027, $this->goods->id));

        $envelope = FiscalEnvelope::create(['fiscal_year' => 2027, 'total_amount' => 1500, 'status' => FiscalEnvelope::STATUS_DRAFT, 'reference_number' => 'X']);
        $alloc = FiscalEnvelopeCategoryAllocation::create([
            'fiscal_envelope_id' => $envelope->id, 'economic_classification_id' => $this->goods->id,
            'allocated_amount' => 1500, 'planned_amount' => 999999, // stale value must be replaced
        ]);
        $alloc->calculateVariance();

        $this->assertEquals(1000, $alloc->fresh()->planned_amount);
        $this->assertEquals(500, $alloc->fresh()->variance_amount);
    }
}
