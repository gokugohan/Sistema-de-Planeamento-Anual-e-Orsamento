<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Services\SampleRegistry;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Database\Seeders\Samples\MtcSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sample must never plan or distribute over a year that already has a real fiscal envelope. It used to
 * try, fail on the closed envelope, and leave units and accounts behind without their plans.
 */
class SampleOverRealDataTest extends TestCase
{
    use RefreshDatabase;

    private function realClosedEnvelope(int $year): FiscalEnvelope
    {
        return FiscalEnvelope::create([
            'fiscal_year' => $year, 'reference_number' => "REAL-{$year}", 'source_authority' => 'Parlamento Nacional',
            'total_amount' => 21000000, 'status' => FiscalEnvelope::STATUS_CLOSED,
            'start_date' => "{$year}-01-01", 'due_date' => "{$year}-12-31", 'is_test_data' => false,
        ]);
    }

    public function test_the_ministry_sample_skips_a_year_with_a_real_envelope_and_still_loads_the_rest(): void
    {
        $real = $this->realClosedEnvelope(2025);

        $this->seed(MtcSampleDataSeeder::class); // used to throw when it tried to distribute over the closed envelope

        $this->assertSame(0, Activity::where('fiscal_year', 2025)->count(), 'nothing is planned in a real year');
        $this->assertGreaterThan(0, Activity::where('fiscal_year', 2026)->count());
        $this->assertGreaterThan(0, Directorate::count());
        $real->refresh();
        $this->assertSame(FiscalEnvelope::STATUS_CLOSED, $real->status);
        $this->assertSame('REAL-2025', $real->reference_number);
        $this->assertSame(21000000.0, (float) $real->total_amount);

        $skipped = implode(' ', app(SampleRegistry::class)->skipped);
        $this->assertStringContainsString('Fiscal year 2025', $skipped);
    }

    public function test_the_office_sample_skips_a_year_with_a_real_envelope_too(): void
    {
        $this->realClosedEnvelope(2026);

        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();

        $this->assertSame(0, Activity::where('fiscal_year', 2026)->count());
        $this->assertGreaterThan(0, Activity::where('fiscal_year', 2027)->count());
    }
}
