<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\UnitWorkspace;
use App\Support\Navigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** My work: the workspace of a unit, the tree of units to find one, and a menu built around what the person does. */
class UnitWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $dg;

    private Directorate $dn;

    private Directorate $other;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);
        $this->dg = Directorate::create(['code' => 'DGT', 'name' => 'Direção-Geral dos Transportes', 'type' => 'General Directorate', 'active' => true]);
        $this->dn = Directorate::create(['code' => 'DNTT', 'name' => 'Terrestres', 'type' => 'National Directorate', 'parent_id' => $this->dg->id, 'active' => true]);
        $this->other = Directorate::create(['code' => 'DGC', 'name' => 'Comunicações', 'type' => 'General Directorate', 'active' => true]);
        $this->program = Program::create(['fiscal_year' => 2027, 'oge_code' => 'P1', 'name' => 'Transportes']);
        session(['fiscal_year' => 2027]);
    }

    private function user(string $role, ?Directorate $unit = null): User
    {
        $u = User::factory()->create(['directorate_id' => $unit?->id]);
        $u->assignRole($role);

        return $u;
    }

    private function activity(Directorate $unit, string $status, int $version = 0): Activity
    {
        return Activity::create(['fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $unit->id, 'description' => 'A '.uniqid(),
            'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-03-31', 'status' => $status, 'version' => $version]);
    }

    public function test_a_unit_user_starts_from_their_own_unit_and_the_dashboard_is_not_theirs(): void
    {
        $planner = $this->user('Planning', $this->dn);
        $this->actingAs($planner);

        $this->get('/')->assertRedirect(route('work.home'));
        $this->get(route('work.home'))->assertRedirect(route('units.show', $this->dn));
        $this->get(route('units.show', $this->dn))->assertOk()->assertSee('Next for you');
    }

    public function test_administrators_and_the_minister_keep_the_dashboard_and_get_the_units_page(): void
    {
        foreach (['Admin', 'Minister'] as $role) {
            $this->actingAs($this->user($role));
            $this->get('/')->assertOk();
            $this->get(route('units.index'))->assertOk()->assertSee('DGT')->assertSee('DNTT')->assertSee('DGC');
        }
    }

    public function test_a_unit_user_cannot_open_the_workspace_of_another_unit(): void
    {
        $this->actingAs($this->user('Planning', $this->dn));

        $this->get(route('units.show', $this->other))->assertForbidden();
        $this->get(route('units.index'))->assertForbidden();
    }

    public function test_a_head_sees_the_units_below_and_may_open_them_but_not_their_neighbours(): void
    {
        $this->activity($this->dn, Activity::STATUS_REJECTED);
        $head = $this->user('Director', $this->dg);
        $this->actingAs($head);

        $this->get(route('units.show', $this->dg))->assertOk()->assertSee('DNTT')->assertSee('Needs correction');
        $this->get(route('units.show', $this->dn))->assertOk();
        $this->get(route('units.show', $this->other))->assertForbidden();
        $this->get(route('units.index'))->assertOk()->assertSee('DNTT')->assertDontSee('DGC');
    }

    public function test_the_workspace_says_what_waits_for_the_person(): void
    {
        $this->activity($this->dn, Activity::STATUS_DRAFT);
        $this->activity($this->dn, Activity::STATUS_DRAFT);
        $this->activity($this->dn, Activity::STATUS_REJECTED);
        $this->activity($this->dn, Activity::STATUS_APPROVED, 1);
        $this->actingAs($this->user('Planning', $this->dn));

        Volt::test('pages.work.unit', ['directorate' => $this->dn])
            ->assertSee('2 drafts to finish')->assertSee('1 activity returned to correct')->assertSee('4 activities');
    }

    public function test_the_lights_follow_the_state_of_the_plan(): void
    {
        $light = fn (array $c) => UnitWorkspace::light($c + ['total' => 1, 'draft' => 0, 'review' => 0, 'returned' => 0, 'revising' => 0]);

        $this->assertSame('none', UnitWorkspace::light(['total' => 0]));
        $this->assertSame('ok', $light([]));
        $this->assertSame('work', $light(['draft' => 1]));
        $this->assertSame('work', $light(['revising' => 1]));
        $this->assertSame('alert', $light(['returned' => 1, 'draft' => 3]));
    }

    public function test_the_menu_is_built_around_the_job(): void
    {
        $unitUser = Navigation::quickLinks($this->user('Planning', $this->dn));
        $wide = Navigation::quickLinks($this->user('Admin'));
        $labels = fn (array $links) => array_column($links, 'label');

        $this->assertContains('My work', $labels($unitUser));
        $this->assertContains('My plan', $labels($unitUser));
        $this->assertNotContains('Dashboard', $labels($unitUser));
        $this->assertNotContains('Units', $labels($unitUser));
        $this->assertContains('Units', $labels($wide));
        $this->assertContains('All activities', $labels($wide));
        $this->assertNotContains('My work', $labels($wide));
    }

    public function test_a_user_without_a_unit_is_told_to_ask_for_one(): void
    {
        $this->actingAs($this->user('Viewer'));

        $this->get(route('work.home'))->assertOk()->assertSee('not linked to a unit');
    }

    public function test_a_finance_officer_sees_what_they_have_to_verify_and_not_their_own_reports(): void
    {
        $finance = $this->user('Finance', $this->dn);
        $planner = $this->user('Planning', $this->dn);
        $a = $this->activity($this->dn, Activity::STATUS_APPROVED, 1);
        $line = LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => 100]);
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 1, 'quarter' => 1, 'committed' => 10, 'paid' => 5, 'status' => 'Submitted', 'submitted_by' => $planner->id]);
        BudgetExecution::create(['line_item_id' => $line->id, 'month' => 2, 'quarter' => 1, 'committed' => 10, 'paid' => 5, 'status' => 'Submitted', 'submitted_by' => $finance->id]);

        $this->actingAs($finance);
        Volt::test('pages.work.unit', ['directorate' => $this->dn])->assertSee('1 spending figure to verify');

        // the person who reported it is not asked to verify it
        $this->actingAs($planner);
        Volt::test('pages.work.unit', ['directorate' => $this->dn])->assertDontSee('spending figure to verify');
    }
}
