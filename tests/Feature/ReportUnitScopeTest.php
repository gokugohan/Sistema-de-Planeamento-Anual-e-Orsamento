<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The reports of the plan cover the whole institution or one unit; whoever cannot see the whole plan gets their own unit only. */
class ReportUnitScopeTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $health;

    private Directorate $clinic;

    private Directorate $roads;

    private Program $program;

    private EconomicClassification $goods;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->goods = EconomicClassification::create(['code' => '20', 'name' => 'Bens e Serviços']);
        $this->program = Program::create(['oge_code' => '528', 'name' => 'Primary care', 'fiscal_year' => 2027]);
        $this->health = Directorate::create(['code' => 'DGCSP', 'name' => 'Primary care directorate', 'type' => 'General Directorate', 'active' => true]);
        $this->clinic = Directorate::create(['code' => 'DNSMI', 'name' => 'Mother and child health', 'type' => 'National Directorate', 'parent_id' => $this->health->id, 'active' => true]);
        $this->roads = Directorate::create(['code' => 'DNEST', 'name' => 'Roads directorate', 'type' => 'National Directorate', 'active' => true]);

        $this->activity($this->clinic, 'Vaccination campaign', 300000);
        $this->activity($this->roads, 'Road maintenance', 700000);
    }

    private function activity(Directorate $unit, string $description, float $amount): Activity
    {
        $a = Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $unit->id, 'description' => $description,
            'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-02-01', 'planned_end' => '2027-06-30', 'status' => Activity::STATUS_APPROVED,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => $this->goods->id, 'estimated_amount' => $amount]);

        return $a;
    }

    private function user(string $role, ?Directorate $unit = null): User
    {
        $u = User::factory()->create(['directorate_id' => $unit?->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_whoever_sees_the_whole_plan_chooses_the_whole_institution_or_one_unit(): void
    {
        $this->actingAs($this->user('Minister'));

        $this->get(route('reports.approved-plan', ['year' => 2027]))->assertOk()
            ->assertSee('Vaccination campaign')->assertSee('Road maintenance')->assertSee('All units');

        $this->get(route('reports.approved-plan', ['year' => 2027, 'unit' => $this->health->id]))->assertOk()
            ->assertSee('DGCSP — Primary care directorate')
            ->assertSee('Vaccination campaign')->assertDontSee('Road maintenance')
            ->assertSee('30.0% of $ 1,000,000.00 in the whole plan')
            ->assertSee('Approved by (head of the unit)');

        $this->get(route('reports.approved-plan.xlsx', ['year' => 2027, 'unit' => $this->health->id]))->assertDownload('PAA-2027-DGCSP-complete.xlsx');
        $this->get(route('reports.approved-plan.pdf', ['year' => 2027, 'unit' => $this->health->id]))->assertOk();
        $this->assertSame('DGCSP', AuditLog::where('action', 'report.approved_plan_pdf')->latest('id')->first()->metadata['unit']);

        $this->get(route('reports.paa', ['year' => 2027, 'unit' => $this->roads->id]))->assertOk()
            ->assertSee('$ 700,000.00')->assertDontSee('$ 1,000,000.00')->assertDontSee('$ 300,000.00');
        $this->get(route('reports.annual-plan.xlsx', ['year' => 2027, 'unit' => $this->roads->id]))->assertDownload('PAA-2027-DNEST-plan.xlsx');
    }

    public function test_a_unit_head_reports_on_their_own_unit_only(): void
    {
        $this->actingAs($this->user('Director', $this->health));

        // the Reporting menu and page are open to them, narrowed to their unit (and those below it)
        $this->get(route('reports.paa', ['year' => 2027]))->assertOk()
            ->assertSee('Primary care directorate')->assertSee('Mother and child health')
            ->assertDontSee('Roads directorate')->assertDontSee('All units');

        $this->get(route('reports.approved-plan', ['year' => 2027]))->assertOk()
            ->assertSee('Vaccination campaign')->assertDontSee('Road maintenance');
        $this->get(route('reports.annual-plan', ['year' => 2027]))->assertOk()->assertDontSee('Road maintenance');
        $this->get(route('reports.approved-plan.xlsx', ['year' => 2027]))->assertDownload('PAA-2027-DGCSP-complete.xlsx');

        // a unit below theirs is fine; another unit is refused, on the pages, the downloads and the rows loaded on expand
        $this->get(route('reports.approved-plan', ['year' => 2027, 'unit' => $this->clinic->id]))->assertOk();
        foreach (['reports.paa', 'reports.approved-plan', 'reports.approved-plan.pdf', 'reports.approved-plan.xlsx', 'reports.annual-plan', 'reports.annual-plan.pdf', 'reports.annual-plan.xlsx'] as $route) {
            $this->get(route($route, ['year' => 2027, 'unit' => $this->roads->id]))->assertForbidden();
        }
        $rows = ['year' => 2027, 'program' => $this->program->id, 'class' => $this->goods->id, 'parent' => 'x'];
        $this->get(route('reports.paa.activities', $rows + ['unit' => $this->roads->id]))->assertForbidden();
        $this->get(route('reports.paa.activities', $rows))->assertOk()->assertSee('Vaccination campaign')->assertDontSee('Road maintenance');
    }

    public function test_the_implementation_plan_downloads_as_excel(): void
    {
        $this->actingAs($this->user('Director', $this->health));

        $this->get(route('reports.implementation-plan', ['year' => 2027]))->assertOk()->assertSee('Download Excel');
        $this->get(route('reports.implementation-plan.xlsx', ['year' => 2027]))->assertOk()->assertDownload();
        $this->assertSame(1, AuditLog::where('action', 'report.implementation_plan_xlsx')->count());
    }

    public function test_the_implementation_plan_of_a_unit_with_only_drafts_says_so_and_shows_them_on_request(): void
    {
        $roadsDraft = $this->activity($this->roads, 'Bridge study', 50000);
        Activity::whereIn('directorate_id', [$this->roads->id])->update(['status' => Activity::STATUS_DRAFT]);
        $this->actingAs($this->user('Minister'));

        $this->get(route('reports.implementation-plan', ['year' => 2027, 'unit' => $this->roads->id]))->assertOk()
            ->assertSee('No activities to show for this unit and year.')
            ->assertSee('2 activities of this unit are not approved yet')
            ->assertDontSee($roadsDraft->description);

        $this->get(route('reports.implementation-plan', ['year' => 2027, 'unit' => $this->roads->id, 'status' => 'all']))->assertOk()
            ->assertSee('Bridge study')->assertSee('Road maintenance')->assertSee('Draft state');
    }
}
