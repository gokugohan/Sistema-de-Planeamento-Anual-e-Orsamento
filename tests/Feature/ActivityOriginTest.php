<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityCsvImporter;
use App\Services\ActivityExporter;
use App\Services\ActivityRollForward;
use App\Services\ActivityVersions;
use App\Services\ApprovedPlanReport;
use App\Support\FiscalYear;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Samples\MtcBudgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Whether an activity continues an existing service (the baseline) or carries out a new policy priority (PPG, named).
 * Since the 2027 budget new policies are funded on top of the baseline and followed apart, so this is kept with each
 * version and summed in the final plan report. Who pays is the funding source of each line, not this.
 */
class ActivityOriginTest extends TestCase
{
    use RefreshDatabase;

    private Directorate $unit;

    private Program $program;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);

        $this->year = FiscalYear::current();
        $this->unit = Directorate::create(['name' => 'National Directorate', 'code' => 'DNX', 'type' => 'Independent']);
        $this->program = Program::create(['name' => 'Programme', 'oge_code' => 'P-001']);
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['directorate_id' => $this->unit->id]);
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    private function activity(array $attrs = [], float $amount = 1000): Activity
    {
        $a = Activity::create($attrs + [
            'fiscal_year' => $this->year, 'program_id' => $this->program->id, 'directorate_id' => $this->unit->id,
            'description' => 'Activity '.uniqid(), 'priority' => 'Média', 'planned_start' => "{$this->year}-02-01", 'planned_end' => "{$this->year}-06-30",
            'quarter' => 1, 'status' => Activity::STATUS_DRAFT, 'is_test_data' => false,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => $amount, 'is_test_data' => false]);
        Indicator::create(['activity_id' => $a->id, 'name' => 'Reports', 'type' => 'Output', 'unit' => 'reports', 'annual_target' => 4]);

        return $a;
    }

    private function form()
    {
        return Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $this->program->id)
            ->set('description', 'Government network bandwidth')
            ->set('quarters', ['1'])
            ->set('lines', [['class_id' => (string) EconomicClassification::first()->id, 'amount' => '1000']])
            ->set('indicators', [['name' => 'Reports', 'type' => 'Output', 'unit' => 'reports', 'target' => '4']]);
    }

    public function test_an_activity_is_an_existing_service_unless_said_otherwise(): void
    {
        $a = $this->activity();

        $this->assertSame(Activity::ORIGIN_BASELINE, $a->fresh()->origin);
        $this->assertNull($a->fresh()->policy_ref);
        $this->assertSame('Existing service (baseline)', $a->originLabel());
    }

    public function test_the_form_names_the_policy_priority_of_a_new_policy(): void
    {
        $this->as('Planning');

        $this->form()->set('origin', Activity::ORIGIN_POLICY)->call('save')->assertHasErrors(['policy_ref']);
        $this->assertSame(0, Activity::count());

        $this->form()->set('origin', Activity::ORIGIN_POLICY)->set('policy_ref', ' PPG 18 ')->call('save')->assertHasNoErrors();
        $a = Activity::firstOrFail();
        $this->assertSame(Activity::ORIGIN_POLICY, $a->origin);
        $this->assertSame('PPG 18', $a->policy_ref);
        $this->assertSame('New policy priority — PPG 18', $a->originLabel());

        // back to an existing service drops the policy it no longer has
        Volt::test('pages.activities.activity-form', ['activity' => $a])
            ->assertSet('origin', Activity::ORIGIN_POLICY)->assertSet('policy_ref', 'PPG 18')
            ->set('origin', Activity::ORIGIN_BASELINE)->call('save')->assertHasNoErrors();
        $this->assertSame(Activity::ORIGIN_BASELINE, $a->fresh()->origin);
        $this->assertNull($a->fresh()->policy_ref);

        // who pays is the funding source of a line, not a value of this field
        $this->form()->set('description', 'Something else')->set('origin', 'infrastructure_fund')->call('save')->assertHasErrors(['origin']);
    }

    public function test_a_version_keeps_the_origin_and_the_report_reads_the_version_in_force(): void
    {
        $approver = $this->as('Admin');
        $policy = $this->activity(['origin' => Activity::ORIGIN_POLICY, 'policy_ref' => 'PPG 18', 'status' => Activity::STATUS_APPROVED], 2215);
        $this->activity(['status' => Activity::STATUS_APPROVED], 5000);
        $this->activity(['origin' => Activity::ORIGIN_POLICY, 'policy_ref' => 'PPG 3', 'status' => Activity::STATUS_APPROVED], 1200);

        $version = app(ActivityVersions::class)->approved($policy->fresh(), $approver);
        $this->assertSame(Activity::ORIGIN_POLICY, $version->snapshot['activity']['origin']);
        $this->assertSame('PPG 18', $version->snapshot['activity']['policy_ref']);

        // a revision that turns it into an existing service is not in force until it is approved again
        $policy->fresh()->forceFill(['origin' => Activity::ORIGIN_BASELINE, 'policy_ref' => null, 'status' => Activity::STATUS_DRAFT])->save();

        $byOrigin = (new ApprovedPlanReport)->build($this->year)['by_origin'];
        $this->assertSame(['Existing service (baseline)', 'New policy priority — PPG 3', 'New policy priority — PPG 18'], $byOrigin->pluck('label')->all(), 'the baseline first, then the policies in their order');
        $this->assertEquals([5000, 1200, 2215], $byOrigin->pluck('total')->all());

        $this->get(route('reports.approved-plan', ['year' => $this->year]))->assertOk()
            ->assertSee('Summary of existing services and new policies')->assertSee('New policy priority — PPG 18');
    }

    public function test_the_report_leaves_the_summary_out_when_everything_is_an_existing_service(): void
    {
        $this->as('Admin');
        $this->activity(['status' => Activity::STATUS_APPROVED]);

        $this->get(route('reports.approved-plan', ['year' => $this->year]))->assertOk()->assertDontSee('Summary of existing services and new policies');
    }

    public function test_the_list_filters_by_origin_once_there_is_more_than_the_baseline(): void
    {
        $base = $this->activity(['description' => 'Run the vehicle inspection service']);
        $this->as('Admin');

        Volt::test('pages.activities.list-activities')->assertDontSee('Existing services and new policies');

        $policy = $this->activity(['description' => 'Digital driving licences', 'origin' => Activity::ORIGIN_POLICY, 'policy_ref' => 'PPG 3']);
        Volt::test('pages.activities.list-activities')->assertSee('Existing services and new policies')->assertSee('PPG 3')
            ->set('origin', Activity::ORIGIN_POLICY)->assertSee($policy->description)->assertDontSee($base->description)
            ->set('origin', Activity::ORIGIN_BASELINE)->assertSee($base->description)->assertDontSee($policy->description);
    }

    public function test_the_export_and_the_import_carry_the_origin(): void
    {
        $this->activity(['origin' => Activity::ORIGIN_POLICY, 'policy_ref' => 'PPG 18']);
        $rows = iterator_to_array((new ActivityExporter)->rows(Activity::query()), false);
        $origin = array_search('Existing service or new policy', $rows[0], true);
        $this->assertSame('New policy priority', $rows[1][$origin]);
        $this->assertSame('PPG 18', $rows[1][$origin + 1]);

        $this->unit->update(['active' => true]);
        $this->program->update(['fiscal_year' => $this->year]);
        $head = 'ref;directorate_code;program_code;description;quarter;planned_start;planned_end;class_code;amount;origin;policy_ref';
        $row = fn ($ref, $origin, $policy) => "{$ref};DNX;P-001;Activity {$ref};1;{$this->year}-02-01;{$this->year}-03-31;10;100;{$origin};{$policy}";
        $path = tempnam(sys_get_temp_dir(), 'paa');

        file_put_contents($path, implode("\n", [$head, $row('A', '', ''), $row('B', 'PPG', 'PPG 3'), $row('C', '', 'PPG 18'), $row('D', 'linha de base', '')]));
        $parsed = (new ActivityCsvImporter)->parse($path, $this->year);
        $this->assertSame([], $parsed['errors']);
        $got = collect($parsed['activities'])->map(fn ($a) => [$a['origin'], $a['policy_ref']])->values()->all();
        $this->assertSame([['baseline', null], ['policy', 'PPG 3'], ['policy', 'PPG 18'], ['baseline', null]], $got);
        (new ActivityCsvImporter)->import($parsed['activities'], $this->year);
        $this->assertSame('PPG 3', Activity::where('description', 'Activity B')->value('policy_ref'));

        // an unknown origin, or a policy without its name, is refused
        file_put_contents($path, implode("\n", [$head, $row('E', 'Fundo das Infraestruturas', ''), $row('F', 'policy', '')]));
        $errors = (new ActivityCsvImporter)->parse($path, $this->year)['errors'];
        $this->assertCount(2, $errors);
        @unlink($path);
    }

    public function test_roll_forward_keeps_the_policy_of_a_multi_year_priority(): void
    {
        $this->activity(['origin' => Activity::ORIGIN_POLICY, 'policy_ref' => 'PPG 18', 'status' => Activity::STATUS_APPROVED, 'fiscal_year' => 2026,
            'planned_start' => '2026-02-01', 'planned_end' => '2026-06-30']);

        app(ActivityRollForward::class)->copy(2026, 2027);

        $copy = Activity::where('fiscal_year', 2027)->firstOrFail();
        $this->assertSame(Activity::ORIGIN_POLICY, $copy->origin);
        $this->assertSame('PPG 18', $copy->policy_ref);
    }

    public function test_the_sample_names_the_policy_priorities_of_the_2027_budget(): void
    {
        $this->assertSame(['origin' => 'policy', 'policy_ref' => 'PPG 3'], MtcBudgetSeeder::originOf(2027, '50202X1'));
        $this->assertSame(['origin' => 'policy', 'policy_ref' => 'PPG 3'], MtcBudgetSeeder::originOf(2027, '50203X3'));
        $this->assertSame(['origin' => 'policy', 'policy_ref' => 'PPG 18'], MtcBudgetSeeder::originOf(2027, 'A0807X4'));
        $this->assertSame(['origin' => 'baseline', 'policy_ref' => null], MtcBudgetSeeder::originOf(2027, '5020201'));
        $this->assertSame(['origin' => 'baseline', 'policy_ref' => null], MtcBudgetSeeder::originOf(2026, '50202X1'));

        // the policy activities of the sample add up to what the budget statement gives MTC (Volume X, Tabelas 9 and 21)
        $data = json_decode(file_get_contents(database_path('seeders/Samples/data/mtc_oge_2027.json')), true);
        $totals = [];
        foreach ($data['entities'] as $entity) {
            foreach ($entity['activities'] as $a) {
                $o = MtcBudgetSeeder::originOf(2027, $a['code']);
                if ($o['policy_ref']) {
                    $totals[$o['policy_ref']] = ($totals[$o['policy_ref']] ?? 0) + array_sum($a['amounts'] ?? []);
                }
            }
        }
        $this->assertEquals(['PPG 3' => 1200000, 'PPG 18' => 2215000], $totals);
    }
}
