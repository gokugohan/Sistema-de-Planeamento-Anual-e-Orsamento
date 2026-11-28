<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\ResultIndicator;
use App\Models\Subprogram;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use App\Services\ApprovedPlanReport;
use App\Support\FiscalYear;
use App\Support\PlanOptions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * The results chain of the State Budget's annual plans (Anexo K): a programme's outcome, the subprogramme's output
 * that serves it, and the activities that deliver the output. Also the budget's division code beside each unit and
 * the men and women in an indicator's baseline and target, printed in the final plan report as the books print them.
 */
class ResultsChainTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    private Directorate $unit;

    private Program $program;

    private Subprogram $sub;

    private ResultIndicator $outcome;

    private ResultIndicator $output;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);

        $this->year = FiscalYear::current();
        $this->unit = Directorate::create(['name' => 'National Directorate of Maritime Transport', 'code' => 'DNTM', 'budget_code' => '0250202', 'type' => 'Independent']);
        $this->program = Program::create(['name' => 'Transport', 'oge_code' => '502']);
        $this->sub = Subprogram::create(['program_id' => $this->program->id, 'code' => '50204', 'name' => 'Maritime transport']);
        $this->outcome = $this->program->resultIndicators()->create(['code' => '502.0.1', 'statement' => 'Maritime transport working for growth', 'name' => 'National and international cargo', 'baseline' => '894', 'target' => '900']);
        $this->output = $this->sub->resultIndicators()->create(['code' => '50204.0.0', 'statement' => 'Maritime facilities working well', 'outcome_id' => $this->outcome->id, 'name' => '% working well', 'baseline' => '100%', 'target' => '100%']);
    }

    private function as(string $role): User
    {
        $u = User::factory()->create(['directorate_id' => $this->unit->id]);
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    private function activity(array $attrs = []): Activity
    {
        $a = Activity::create($attrs + [
            'fiscal_year' => $this->year, 'program_id' => $this->program->id, 'subprogram_id' => $this->sub->id, 'output_id' => $this->output->id,
            'directorate_id' => $this->unit->id, 'code' => '5020401', 'description' => 'Maritime training and certification', 'priority' => 'Média',
            'planned_start' => "{$this->year}-02-01", 'planned_end' => "{$this->year}-06-30", 'quarter' => 1, 'status' => Activity::STATUS_DRAFT, 'is_test_data' => false,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => EconomicClassification::first()->id, 'estimated_amount' => 270969, 'is_test_data' => false]);
        Indicator::create(['activity_id' => $a->id, 'name' => 'People trained and certified', 'type' => 'Output', 'unit' => 'people', 'annual_target' => 20,
            'baseline' => '24', 'baseline_men' => 18, 'baseline_women' => 6, 'target_men' => 12, 'target_women' => 8]);

        return $a;
    }

    public function test_a_subprogramme_with_one_output_names_it_in_the_form_and_the_men_and_women_are_kept(): void
    {
        $this->as('Planning');

        Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $this->program->id)
            ->set('subprogram_id', (string) $this->sub->id)
            ->assertSet('output_id', (string) $this->output->id) // the only output: chosen at once
            ->assertSee('50204.0.0: Maritime facilities working well')
            ->set('description', 'Maritime training and certification')
            ->set('quarters', ['1'])
            ->set('lines', [['class_id' => (string) EconomicClassification::first()->id, 'amount' => '1000']])
            ->set('indicators', [['name' => 'People trained', 'type' => 'Output', 'unit' => 'people', 'target' => '20', 'baseline' => '24', 'baseline_men' => '18', 'baseline_women' => '6', 'target_men' => '12', 'target_women' => '8']])
            ->call('save')->assertHasNoErrors();

        $a = Activity::firstOrFail();
        $this->assertSame($this->output->id, $a->output_id);
        $i = $a->indicators->sole();
        $this->assertSame([18.0, 6.0, 12.0, 8.0], [$i->baseline_men, $i->baseline_women, $i->target_men, $i->target_women]);
        $this->assertSame('M = 18, F = 6', $i->bySex('baseline'));
    }

    public function test_the_output_must_be_one_of_the_subprogrammes(): void
    {
        $this->as('Planning');
        $other = Subprogram::create(['program_id' => $this->program->id, 'code' => '50202', 'name' => 'Land transport']);
        $foreign = $other->resultIndicators()->create(['code' => '50202.0.0', 'name' => 'Fewer accidents']);

        Volt::test('pages.activities.activity-form')
            ->set('program_id', (string) $this->program->id)
            ->set('subprogram_id', (string) $this->sub->id)
            ->set('output_id', (string) $foreign->id)
            ->set('description', 'Maritime training and certification')
            ->set('quarters', ['1'])
            ->set('lines', [['class_id' => (string) EconomicClassification::first()->id, 'amount' => '1000']])
            ->set('indicators', [['name' => 'People trained', 'type' => 'Output', 'unit' => 'people', 'target' => '20']])
            ->call('save')->assertHasErrors(['output_id']);
    }

    public function test_the_institution_may_require_the_output_before_submitting(): void
    {
        $a = $this->activity(['output_id' => null]);
        $workflow = app(ActivityWorkflow::class);
        $this->assertSame([], $workflow->problems($a->fresh()), 'not required by default');

        (new PlanOptions(requiredFields: ['output_id']))->save();
        $this->assertContains('Fill in: Output it serves.', $workflow->problems($a->fresh()));

        $a->update(['output_id' => $this->output->id]);
        $this->assertSame([], $workflow->problems($a->fresh()));

        // a subprogramme without outputs has nothing to choose: it does not hold the activity back
        $bare = Subprogram::create(['program_id' => $this->program->id, 'code' => '50299', 'name' => 'No outputs yet']);
        $a->update(['subprogram_id' => $bare->id, 'output_id' => null]);
        $this->assertSame([], $workflow->problems($a->fresh()));
    }

    public function test_the_final_plan_report_prints_the_chain_as_the_state_budget_does(): void
    {
        $a = $this->activity(['status' => Activity::STATUS_APPROVED]);
        $snapshot = app(ActivityVersions::class)->snapshot($a->fresh(['indicators.actions', 'lineItems']));
        $this->assertSame($this->output->id, $snapshot['activity']['output_id'], 'the approved version keeps the output');
        $this->assertSame(18.0, $snapshot['indicators'][0]['baseline_men']);

        $tree = (new ApprovedPlanReport)->build($this->year)['tree'];
        $this->assertTrue($tree[0]['linked']);
        $outcome = $tree[0]['outcomes'][0];
        $this->assertSame($this->outcome->id, $outcome['outcome']->id);
        $this->assertSame($this->output->id, $outcome['subprograms'][0]['outputs'][0]['output']->id);
        $this->assertEquals(270969, $outcome['total']);

        $this->as('Admin');
        $this->get(route('reports.approved-plan', ['year' => $this->year]))->assertOk()
            ->assertSeeInOrder(['Program', '502', 'Medium-term objective (outcome)', '502.0.1', 'Subprogram', '50204', 'Short-term result (output)', '50204.0.0', '5020401'])
            ->assertSee('0250202')->assertSee('M = 18, F = 6')->assertSee('M = 12, F = 8');

        $response = $this->get(route('reports.approved-plan.xlsx', ['year' => $this->year]))->assertOk();
        $file = tempnam(sys_get_temp_dir(), 'paa').'.xlsx';
        file_put_contents($file, $response->streamedContent());
        $rows = IOFactory::load($file)->getSheetByName('Plan of approved activities')->toArray(null, true, false, false);
        @unlink($file);
        $labels = collect($rows)->pluck(1)->filter()->values();
        $outcomeRow = $labels->search(fn ($l) => str_starts_with((string) $l, 'Medium-term objective (outcome) 502.0.1'));
        $outputRow = $labels->search(fn ($l) => str_starts_with((string) $l, 'Short-term result (output) 50204.0.0'));
        $this->assertNotFalse($outcomeRow);
        $this->assertGreaterThan($outcomeRow, $outputRow);
        $activityRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === '5020401');
        $this->assertSame('0250202 National Directorate of Maritime Transport', $activityRow[4]);
        $this->assertStringContainsString('M = 18, F = 6', $activityRow[6]);
        $outcomeTotal = collect($rows)->first(fn ($r) => str_starts_with((string) ($r[1] ?? ''), 'Medium-term objective (outcome) 502.0.1'));
        $this->assertEquals(270969, end($outcomeTotal), 'the outcome carries the subtotal of the activities serving it');
    }

    public function test_without_links_the_report_lists_the_results_as_before(): void
    {
        $this->activity(['status' => Activity::STATUS_APPROVED, 'output_id' => null]);

        $tree = (new ApprovedPlanReport)->build($this->year)['tree'];
        $this->assertFalse($tree[0]['linked']);

        $this->as('Admin');
        $this->get(route('reports.approved-plan', ['year' => $this->year]))->assertOk()
            ->assertDontSee('Medium-term objective (outcome)')->assertSee('National and international cargo');
    }
}
