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
 * A ministry runs the whole cycle (ceiling, CRO, State Budget law, envelope). A directorate or gabinete
 * does not control the CRO or the law: its cycle is what it does and what the ministry gives it.
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

    public function test_the_office_dashboard_shows_only_the_steps_it_controls(): void
    {
        $this->signedIn();

        $this->get('/')->assertOk()
            ->assertSee('Ceiling from the ministry')
            ->assertSee('Sent to the ministry')
            ->assertSee('Allocation received')
            ->assertDontSee('CRO decision')
            ->assertDontSee('Budget law');
    }

    public function test_the_ministry_dashboard_keeps_the_cro_and_the_budget_law(): void
    {
        $this->signedIn();
        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');

        $this->get('/')->assertOk()
            ->assertSee('CRO decision')
            ->assertSee('Budget law')
            ->assertDontSee('Allocation received');
    }

    public function test_the_office_cycle_page_has_no_cro_decision(): void
    {
        $this->signedIn();

        $this->get('/planning/budget-cycle')->assertOk()
            ->assertSee('Allocation from the ministry')
            ->assertSee('Ceiling from the ministry')
            ->assertDontSee('CRO decision')
            ->assertDontSee('State Budget law and envelope');
    }

    public function test_the_ministry_cycle_page_keeps_every_step(): void
    {
        $this->signedIn();
        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');

        $this->get('/planning/budget-cycle')->assertOk()
            ->assertSee('CRO decision')
            ->assertSee('State Budget law and envelope')
            ->assertDontSee('Allocation from the ministry');
    }
}
