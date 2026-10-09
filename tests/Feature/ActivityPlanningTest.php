<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityExporter;
use App\Services\ActivityRollForward;
use App\Services\ActivityWorkflow;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ActivityPlanningTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $dg;

    private Directorate $dn;

    private Directorate $other;

    private Program $program;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);

        $this->year = FiscalEnvelope::getEffectiveYear();
        $this->dg = Directorate::create(['name' => 'General Directorate', 'code' => 'DGX', 'type' => 'General Directorate']);
        $this->dn = Directorate::create(['name' => 'National Directorate', 'code' => 'DNX', 'type' => 'National Directorate', 'parent_id' => $this->dg->id]);
        $this->other = Directorate::create(['name' => 'Other Directorate', 'code' => 'OTH', 'type' => 'Independent']);
        $this->program = Program::create(['name' => 'Programme', 'oge_code' => 'P-001']);
    }

    private function as(string $role, ?Directorate $unit = null): User
    {
        $u = User::factory()->create(['directorate_id' => $unit?->id]);
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    private function activity(Directorate $unit, string $status = Activity::STATUS_DRAFT, bool $complete = true): Activity
    {
        $a = Activity::create([
            'fiscal_year' => $this->year, 'program_id' => $this->program->id, 'directorate_id' => $unit->id,
            'description' => 'Activity '.uniqid(), 'priority' => 'Alta', 'planned_start' => "{$this->year}-02-01", 'planned_end' => "{$this->year}-06-30",
            'quarter' => 1, 'status' => $status, 'is_test_data' => false,
        ]);
        if ($complete) {
            LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => 1000, 'is_test_data' => false]);
            Indicator::create(['activity_id' => $a->id, 'name' => 'Reports', 'type' => 'Output', 'unit' => 'reports', 'annual_target' => 4]);
        }

        return $a;
    }

    public function test_a_unit_cannot_see_or_change_another_units_activity(): void
    {
        $foreign = $this->activity($this->other);
        $this->as('Planning', $this->dn);

        $this->get(route('activities.show', $foreign))->assertForbidden();
        $this->get(route('activities.edit', $foreign))->assertForbidden();
    }

    public function test_show_page_actions_are_authorised_on_the_server(): void
    {
        $mine = $this->activity($this->dn);
        $this->as('Viewer', $this->dn);

        $this->get(route('activities.show', $mine))->assertOk();
        Volt::test('pages.activities.show', ['activity' => $mine])->call('deleteLineItem', $mine->lineItems()->first()->id)->assertForbidden();
        Volt::test('pages.activities.show', ['activity' => $mine])->call('approve')->assertForbidden();
        $this->assertSame(1, $mine->lineItems()->count());
    }

    public function test_approved_activities_cannot_be_edited_by_planners(): void
    {
        $approved = $this->activity($this->dn, Activity::STATUS_APPROVED);
        $this->as('Planning', $this->dn);

        $this->get(route('activities.edit', $approved))->assertForbidden();
    }

    public function test_the_chain_runs_from_planner_to_minister(): void
    {
        $a = $this->activity($this->dn);
        $wf = app(ActivityWorkflow::class);

        $planner = $this->as('Planning', $this->dn);
        $this->assertSame(Activity::STATUS_SUBMITTED, $wf->advance($planner, $a));
        $this->assertNull($wf->next($planner, $a->fresh()), 'the planner cannot review their own submission');

        $dnDirector = $this->as('Director', $this->dn);
        $this->assertSame(Activity::STATUS_REVIEWED, $wf->advance($dnDirector, $a->fresh()));
        $this->assertNull($wf->next($dnDirector, $a->fresh()), 'the unit director cannot also validate');

        $dgDirector = $this->as('Director', $this->dg);
        $this->assertSame(Activity::STATUS_VALIDATED, $wf->advance($dgDirector, $a->fresh()));
        $this->assertSame(Activity::STATUS_WAITING_APPROVAL, $wf->advance($dgDirector, $a->fresh()));

        $minister = $this->as('Minister');
        $wf->approve($minister, $a->fresh());
        $this->assertSame(Activity::STATUS_APPROVED, $a->fresh()->status);
    }

    public function test_an_incomplete_activity_cannot_be_submitted(): void
    {
        $a = $this->activity($this->dn, complete: false);
        $planner = $this->as('Planning', $this->dn);

        $this->expectException(\DomainException::class);
        app(ActivityWorkflow::class)->advance($planner, $a);
    }

    public function test_the_form_creates_a_complete_draft_with_a_code(): void
    {
        $this->as('Planning', $this->dn);
        $class = EconomicClassification::first();

        Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $this->program->id)
            ->set('description', 'Inspect regional ports')
            ->set('objective', 'Safer ports')
            ->set('location', 'Dili')
            ->set('quarters', ['2', '3'])
            ->set('lines', [['class_id' => (string) $class->id, 'amount' => '2500.50']])
            ->set('indicators', [['name' => 'Inspections', 'type' => 'Output', 'unit' => 'ports', 'target' => '6']])
            ->call('save')
            ->assertHasNoErrors();

        $a = Activity::where('description', 'Inspect regional ports')->firstOrFail();
        $this->assertSame('DNX-'.substr((string) $this->year, -2).'-001', $a->code);
        $this->assertSame(Activity::STATUS_DRAFT, $a->status);
        $this->assertSame([2, 3], $a->execution_quarters);
        $this->assertSame('custom', $a->period_type);
        $this->assertSame('2500.50', $a->lineItems()->first()->estimated_amount);
        $this->assertSame(1, $a->indicators()->count());
    }

    public function test_the_form_validates_and_stays_in_the_users_unit(): void
    {
        $this->as('Planning', $this->dn);
        $class = EconomicClassification::first();

        Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $this->program->id)
            ->set('description', 'Short')
            ->set('planned_start', ($this->year - 1).'-12-01')
            ->set('lines', [['class_id' => (string) $class->id, 'amount' => '10'], ['class_id' => (string) $class->id, 'amount' => '5']])
            ->set('indicators', [['name' => '', 'type' => 'Output', 'unit' => '', 'target' => '']])
            ->call('save')
            ->assertHasErrors(['planned_start', 'lines.0.class_id', 'indicators.0.name']);

        Volt::test('pages.activities.activity-form')
            ->set('directorate_id', (string) $this->other->id)
            ->set('program_id', (string) $this->program->id)
            ->set('description', 'Sneaky activity')
            ->set('lines', [['class_id' => (string) $class->id, 'amount' => '10']])
            ->set('indicators', [['name' => 'N', 'type' => 'Output', 'unit' => 'u', 'target' => '1']])
            ->call('save')
            ->assertForbidden();
        $this->assertDatabaseMissing('activities', ['description' => 'Sneaky activity']);
    }

    public function test_the_list_only_shows_the_units_own_activities(): void
    {
        $mine = $this->activity($this->dn);
        $foreign = $this->activity($this->other);
        $this->as('Planning', $this->dn);

        $this->get(route('activities.index'))->assertOk()->assertSee($mine->description)->assertDontSee($foreign->description);
    }

    public function test_bulk_move_on_skips_what_is_not_the_users_turn(): void
    {
        $ready = $this->activity($this->dn);
        $notReady = $this->activity($this->dn, complete: false);
        $this->as('Planning', $this->dn);

        Volt::test('pages.activities.list-activities')->set('selected', [(string) $ready->id, (string) $notReady->id])->call('advanceSelected');

        $this->assertSame(Activity::STATUS_SUBMITTED, $ready->fresh()->status);
        $this->assertSame(Activity::STATUS_DRAFT, $notReady->fresh()->status);
    }

    public function test_roll_forward_copies_once_as_drafts(): void
    {
        $src = $this->activity($this->dn, Activity::STATUS_APPROVED);
        Activity::where('id', $src->id)->update(['fiscal_year' => 2026]);

        $svc = app(ActivityRollForward::class);
        $this->assertSame(['copied' => 1, 'skipped' => 0], $svc->copy(2026, 2027));
        $this->assertSame(['copied' => 0, 'skipped' => 1], $svc->copy(2026, 2027));

        $copy = Activity::where('fiscal_year', 2027)->firstOrFail();
        $this->assertSame(Activity::STATUS_DRAFT, $copy->status);
        $this->assertSame('2027-02-01', $copy->planned_start->format('Y-m-d'));
        $this->assertSame('DNX-27-001', $copy->code);
        $this->assertSame(1, $copy->lineItems()->count());
        $this->assertSame(1, $copy->indicators()->count());
        $this->assertNull($copy->approved_at);
    }

    public function test_export_has_one_amount_column_per_category_and_neutralises_formulas(): void
    {
        $a = $this->activity($this->dn);
        $a->update(['description' => '=HYPERLINK("http://x")']);

        $out = fopen('php://memory', 'w+');
        app(ActivityExporter::class)->write($out, Activity::query()->where('fiscal_year', $this->year));
        rewind($out);
        $csv = stream_get_contents($out);

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('10 Salários', $csv);
        $this->assertStringContainsString('1000.00', $csv);
    }
}
