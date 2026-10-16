<?php

namespace Tests\Feature;

use App\Models\FiscalEnvelope;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Institution;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ministry still needs the budget law behind its envelope; an office takes the ministry's allocation as the
 * basis. The cycle shown on the dashboard is the cycle of the annual plan for both.
 */
class CycleByLevelTest extends TestCase
{
    use RefreshDatabase;

    private function signedIn(): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run(); // an office: level "subordinate"
        $admin = User::where('email', 'chefe.gdsc@goku.tl')->firstOrFail();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->withSession(['locale' => 'en']);
    }

    public function test_an_office_envelope_needs_no_budget_law(): void
    {
        $envelope = new FiscalEnvelope(['fiscal_year' => 2030, 'total_amount' => 1000]);

        SystemSetting::set('institution_level', Institution::SUBORDINATE, 'string');
        $this->assertTrue($envelope->hasLegalBasis(), 'the ministry\'s allocation is the basis');

        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');
        $this->assertFalse($envelope->hasLegalBasis(), 'a ministry still needs the law and its promulgation');

        $envelope->budget_law_reference = 'Lei n.º 8/2030';
        $envelope->promulgation_date = '2030-01-10';
        $this->assertTrue($envelope->hasLegalBasis());
    }

    public function test_every_level_sees_the_cycle_of_the_annual_plan_and_no_committee(): void
    {
        $this->signedIn();

        foreach ([Institution::SUBORDINATE, Institution::MINISTRY] as $level) {
            SystemSetting::set('institution_level', $level, 'string');
            $html = $this->get('/')->assertOk()->assertSee('Plan cycle')->assertSee('Plan submission')->assertSee('Distribution')->getContent();
            $this->assertStringNotContainsString('CRO', $html);
            $this->assertStringNotContainsString('Ceiling from the ministry', $html);
        }
    }
}
