<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\BudgetCycleService;
use App\Services\EnvelopeDistributor;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $year;

    private Directorate $dg;

    private Directorate $dn;

    private Program $program;

    private EconomicClassification $goods;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);

        $this->year = (int) date('Y') + 1; // the planning window: next year
        $this->dg = Directorate::create(['code' => 'DGT', 'name' => 'Direção-Geral dos Transportes', 'type' => 'General Directorate', 'active' => true]);
        $this->dn = Directorate::create(['code' => 'DNTT', 'name' => 'Terrestres', 'type' => 'National Directorate', 'parent_id' => $this->dg->id, 'active' => true]);
        $this->program = Program::create(['oge_code' => 'P1', 'name' => 'Transportes']);
        $this->goods = EconomicClassification::where('code', '20')->first();

        $admin = User::where('email', 'admin@example.org')->first();
        $this->actingAs($admin);
    }

    private function activity(string $status, float $amount, ?Directorate $unit = null): Activity
    {
        $a = Activity::create([
            'fiscal_year' => $this->year, 'program_id' => $this->program->id, 'directorate_id' => ($unit ?? $this->dn)->id,
            'description' => "Activity {$status} ".uniqid(), 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => "{$this->year}-01-01", 'planned_end' => "{$this->year}-03-31", 'status' => $status,
        ]);
        LineItem::create(['activity_id' => $a->id, 'economic_classification_id' => $this->goods->id, 'estimated_amount' => $amount]);

        return $a;
    }

    public function test_the_working_year_defaults_to_the_year_being_planned_not_an_empty_calendar_year(): void
    {
        $this->activity(Activity::STATUS_DRAFT, 100);

        $this->assertSame($this->year, FiscalEnvelope::getEffectiveYear());
    }

    public function test_the_year_can_be_switched_and_a_bogus_year_is_ignored(): void
    {
        $this->activity(Activity::STATUS_DRAFT, 100);
        $previous = $this->year - 1;

        $this->get(route('fiscal-year.switch', $previous));
        $this->assertSame($previous, FiscalEnvelope::getEffectiveYear());

        $this->get(route('fiscal-year.switch', 1999));
        $this->assertSame($previous, FiscalEnvelope::getEffectiveYear(), 'an unknown year must not change the context');
    }

    public function test_dashboard_shows_the_cycle_the_money_and_the_work(): void
    {
        $this->activity(Activity::STATUS_APPROVED, 1000);
        $this->activity(Activity::STATUS_APPROVED, 500);
        $this->activity(Activity::STATUS_DRAFT, 9999);

        $svc = app(BudgetCycleService::class);
        $svc->recordCeiling($this->year, 2000, 'CoM', null);
        $svc->recordSubmission($this->year);
        $svc->recordCroDecision($this->year, 1200, "{$this->year}-08-20", null, null);

        $env = FiscalEnvelope::create([
            'fiscal_year' => $this->year, 'total_amount' => 1200, 'status' => FiscalEnvelope::STATUS_APPROVED, 'reference_number' => 'OGE',
            'start_date' => "{$this->year}-01-01", 'due_date' => "{$this->year}-12-31",
            'budget_law_reference' => 'Lei 1', 'promulgation_date' => ($this->year - 1).'-12-20',
        ]);
        FiscalEnvelopeCategoryAllocation::create(['fiscal_envelope_id' => $env->id, 'economic_classification_id' => $this->goods->id, 'allocated_amount' => 1200]);
        app(EnvelopeDistributor::class)->distribute($env);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Budget cycle '.$this->year)
            ->assertSee('$ 1,200')            // envelope
            ->assertSee('$ 1,500')            // approved plan (drafts excluded)
            ->assertSee('2 / 3')              // approved / all activities
            ->assertSee('The approved plan exceeds the CRO decision by $ 300.')
            ->assertSee('Needs your attention');
    }

    public function test_unit_filter_narrows_the_figures_and_can_be_cleared(): void
    {
        $other = Directorate::create(['code' => 'DGC', 'name' => 'Comunicações', 'type' => 'General Directorate', 'active' => true]);
        $this->activity(Activity::STATUS_APPROVED, 1000, $this->dn);   // under DGT
        $this->activity(Activity::STATUS_APPROVED, 400, $other);

        Volt::test('pages.dashboard.summary')
            ->assertSee('$ 1,400')
            ->set('unit', (string) $this->dg->id)
            ->assertSee('$ 1,000')
            ->assertDontSee('$ 1,400')
            ->call('clearFilters')
            ->assertSee('$ 1,400');
    }

    public function test_empty_year_explains_what_to_do(): void
    {
        $this->get(route('fiscal-year.switch', $this->year));

        Volt::test('pages.dashboard.summary')
            ->assertSee('No activities for '.FiscalEnvelope::getEffectiveYear().' yet')
            ->assertSee('Create first activity');
    }

    public function test_budget_allocation_shows_real_distribution_and_no_invented_spending(): void
    {
        $this->activity(Activity::STATUS_APPROVED, 1000);
        $env = FiscalEnvelope::create([
            'fiscal_year' => $this->year, 'total_amount' => 1000, 'status' => FiscalEnvelope::STATUS_APPROVED, 'reference_number' => 'OGE',
            'start_date' => "{$this->year}-01-01", 'due_date' => "{$this->year}-12-31",
        ]);
        FiscalEnvelopeCategoryAllocation::create(['fiscal_envelope_id' => $env->id, 'economic_classification_id' => $this->goods->id, 'allocated_amount' => 1000]);
        app(EnvelopeDistributor::class)->distribute($env);

        $html = $this->get(route('budget.allocation'))->assertOk()->getContent();

        $this->assertStringContainsString('$ 1,000', $html);
        $this->assertStringContainsString('is in Report execution and Performance', $html);
        $this->assertStringNotContainsString('OVER BUDGET', $html);
        $this->assertStringNotContainsString('spent of', $html);
    }

    public function test_no_view_fabricates_figures(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR) || str_contains($file->getPathname(), 'simulation-')) {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (preg_match('/\brand\(|mt_rand\(|\/\/\s*Simulated|simulated usage|Placeholder until/i', $src)) {
                $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'These views contain invented or random numbers: '.implode(', ', $offenders));
    }
}
