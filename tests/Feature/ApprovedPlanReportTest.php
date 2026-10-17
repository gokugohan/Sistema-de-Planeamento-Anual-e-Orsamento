<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\EconomicClassification;
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
            'description' => "Atividade {$status} ".uniqid(), 'quarter' => 2, 'priority' => 'Alta',
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
        $this->actingAs($this->user('Minister'));

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

    public function test_the_final_report_is_complete_or_summary_and_names_no_committee(): void
    {
        $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 1000]]);
        $this->actingAs($this->user('Minister'));
        app()->setLocale('en');

        $complete = $this->get(route('reports.approved-plan', ['year' => 2027]))->assertOk()->getContent();
        $summary = $this->get(route('reports.approved-plan', ['year' => 2027, 'mode' => 'summary']))->assertOk()->getContent();

        $this->assertStringContainsString('Complete report', $complete);
        $this->assertStringContainsString('Plan of approved activities', $complete);
        $this->assertStringContainsString('1. Plan of approved activities', $complete);
        $this->assertStringContainsString('2. Summary by economic category', $complete);

        $this->assertStringNotContainsString('Plan of approved activities', $summary, 'the summary has no plan table');
        $this->assertStringContainsString('1. Summary by economic category', $summary);
        $this->assertStringContainsString('3. Summary by organic unit', $summary);
        $this->assertStringNotContainsString('CRO', $complete.$summary);
        $this->assertStringNotContainsString('Budget Review Committee', $complete.$summary);
    }

    public function test_the_pdf_follows_the_mode_and_the_audit_says_which(): void
    {
        $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 1000]]);
        $this->actingAs($this->user('Minister'));

        $response = $this->get(route('reports.approved-plan.pdf', ['year' => 2027, 'mode' => 'summary']))->assertOk();

        $this->assertStringContainsString('-summary.pdf', $response->headers->get('content-disposition'));
        $this->assertSame('summary', AuditLog::where('action', 'report.approved_plan_pdf')->latest('id')->first()->metadata['mode']);
    }

    public function test_users_without_report_permission_are_refused(): void
    {
        $this->actingAs(User::factory()->create()); // no role

        $this->get(route('reports.approved-plan', ['year' => 2027]))->assertForbidden();
        $this->get(route('reports.approved-plan.pdf', ['year' => 2027]))->assertForbidden();
    }

    /** Autonomous bodies stay inside the ministry's package (one total) but are shown as their own section. */
    public function test_autonomous_bodies_are_in_the_total_and_shown_as_a_separate_section(): void
    {
        app()->setLocale('en');
        $this->activity(Activity::STATUS_APPROVED, [[$this->goods, 1000]]);

        $body = Directorate::create(['code' => 'APORTIL', 'name' => 'Administração dos Portos', 'type' => 'Independent', 'administration' => 'indirect', 'legal_form' => 'I.P.', 'active' => true]);
        $a = Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $body->id,
            'description' => 'Conservação portuária', 'quarter' => 2, 'priority' => 'Alta',
            'planned_start' => '2027-04-01', 'planned_end' => '2027-06-30', 'status' => Activity::STATUS_APPROVED,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => $this->capital->id, 'estimated_amount' => 4000]);

        $report = app(ApprovedPlanReport::class)->build(2027);
        $this->assertEqualsWithDelta(5000.0, $report['totals']['total'], 0.001, 'the autonomous body is part of the package');
        $this->assertEqualsWithDelta(4000.0, $report['autonomous']['total'], 0.001);
        $this->assertSame(1, $report['autonomous']['count']);

        $this->actingAs($this->user('Minister'))->get(route('reports.approved-plan', ['year' => 2027]))
            ->assertOk()
            ->assertSee('Transfers to autonomous bodies')
            ->assertSee('Ministry services')
            ->assertSee('APORTIL');
    }
}
