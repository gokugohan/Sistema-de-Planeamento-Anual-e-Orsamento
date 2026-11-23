<?php

namespace Tests\Feature;

use App\Models\BudgetDecision;
use App\Models\BudgetDistribution;
use App\Services\SampleRegistry;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample's budget decisions come only from the State Budget books: the 2026 law and its supplementary budget, the
 * 2027 proposal; each distribution adds up exactly to its decision, and purging the sample removes them all.
 */
class BudgetDistributionSampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sample_decisions_follow_the_books_and_are_purged_with_the_sample(): void
    {
        Carbon::setTestNow('2026-11-23 10:00:00');
        app(MtcSampleDataSeeder::class)->setContainer(app())->run('approved', [2026, 2027]);

        $this->assertSame(['law', 'supplementary'], BudgetDecision::where('fiscal_year', 2026)->orderBy('decided_on')->pluck('stage')->all());
        $this->assertSame(['proposal'], BudgetDecision::where('fiscal_year', 2027)->pluck('stage')->all());
        $this->assertSame(0, BudgetDecision::where('stage', 'ceiling')->count(), 'no ceiling is published, so none is loaded');
        $this->assertEqualsWithDelta(14620942, (float) BudgetDecision::where('fiscal_year', 2027)->value('total'), 0.5, 'the MTC total of the 2027 proposal');

        foreach (BudgetDistribution::with('lines', 'decision')->get() as $d) {
            foreach ($d->decision->amounts as $class => $amount) {
                $this->assertEqualsWithDelta((float) $amount, (float) $d->lines->where('economic_classification_id', (int) $class)->sum('amount'), 0.01, "distribution {$d->fiscal_year} adds up to its decision");
            }
        }
        $this->assertSame(2, BudgetDistribution::count(), 'the supplementary budget is not distributed: the books do not say where');

        app(SampleRegistry::class)->purge('mtc');
        $this->assertSame(0, BudgetDecision::count());
        $this->assertSame(0, BudgetDistribution::count());
        Carbon::setTestNow();
    }
}
