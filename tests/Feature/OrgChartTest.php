<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\FiscalYear;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The organization chart: the institution, its units with their kind and head, the autonomous bodies apart. */
class OrgChartTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $dg;

    private Directorate $dn;

    private Directorate $other;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(RolesAndPermissionsSeeder::class);
        SystemSetting::set('org_name', ['en' => 'Ministry of Health', 'pt' => 'Ministério da Saúde', 'tet' => 'Ministériu Saúde'], 'json');
        $this->dg = Directorate::create(['code' => 'DGSH', 'name' => 'Hospital services', 'type' => 'General Directorate', 'active' => true]);
        $this->dn = Directorate::create(['code' => 'DNFM', 'name' => 'Pharmacy and medicines', 'type' => 'National Directorate', 'parent_id' => $this->dg->id, 'active' => true]);
        $this->other = Directorate::create(['code' => 'GJ', 'name' => 'Legal office', 'type' => 'Cabinet', 'active' => true]);
        Directorate::create(['code' => 'INFPM', 'name' => 'Pharmacy institute', 'type' => 'Independent', 'administration' => Directorate::INDIRECT, 'legal_form' => 'I.P.', 'active' => true]);
        $this->user('Director', $this->dn, 'Ana Pharmacist');
    }

    private function user(string $role, ?Directorate $unit = null, ?string $name = null): User
    {
        $u = User::factory()->create(['directorate_id' => $unit?->id] + ($name ? ['name' => $name] : []));
        $u->assignRole($role);

        return $u;
    }

    public function test_the_chart_shows_the_institution_its_units_and_their_heads(): void
    {
        $this->actingAs($this->user('Admin'));

        $this->get(route('units.index'))->assertOk()->assertSee(route('units.chart', ['year' => FiscalYear::current()]), false);
        $this->get(route('units.chart'))->assertOk()
            ->assertSeeInOrder(['Ministry of Health', 'Hospital services', 'Pharmacy and medicines'])
            ->assertSee('National Director:')->assertSee('Ana Pharmacist')
            ->assertSee('Legal office')
            ->assertSeeInOrder(['Autonomous bodies', 'Pharmacy institute'])->assertSee('Autonomous body (I.P.)')
            ->assertSee(route('units.show', $this->dn), false);
    }

    public function test_a_head_sees_their_own_branch_and_others_are_refused(): void
    {
        $this->actingAs($this->user('Director', $this->dg));
        $this->get(route('units.chart'))->assertOk()
            ->assertSee('Hospital services')->assertSee('Pharmacy and medicines')
            ->assertDontSee('Legal office')->assertDontSee('Pharmacy institute');

        $this->actingAs($this->user('Planning', $this->dn));
        $this->get(route('units.chart'))->assertForbidden();
    }

    public function test_the_plan_of_the_year_can_be_shown_on_the_boxes(): void
    {
        $this->actingAs($this->user('Admin'));
        $year = (int) now()->year;
        FiscalYear::pin($year);
        $program = Program::create(['oge_code' => '529', 'name' => 'Hospitals', 'fiscal_year' => $year]);
        Activity::create(['fiscal_year' => $year, 'program_id' => $program->id, 'directorate_id' => $this->dn->id, 'description' => 'Buy medicines',
            'quarter' => 1, 'priority' => 'Alta', 'planned_start' => "{$year}-02-01", 'planned_end' => "{$year}-06-30", 'status' => Activity::STATUS_DRAFT]);

        // the component speaks the user's language: compare with the same words
        $one = trans_choice(':n activity|:n activities', 1, ['n' => 1]);
        Volt::test('pages.work.unit-chart')->assertDontSee($one)
            ->set('withPlan', true)->assertSee($one)->assertSee(__('Work in progress'));
    }

    public function test_a_unit_named_chart_does_not_take_the_address(): void
    {
        $this->actingAs($this->user('Admin'));
        $this->get('/units/chart')->assertOk()->assertSee('Organization chart');
        $this->get(route('units.show', $this->dg))->assertOk();
    }
}
