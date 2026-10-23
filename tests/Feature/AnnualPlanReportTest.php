<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The Print Report button of the Reporting page gives a PDF document of the annual plan, not the browser's print of the page. */
#[Group('office')]
class AnnualPlanReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Admin');

        return $u;
    }

    public function test_the_print_button_downloads_the_pdf_instead_of_printing_the_page(): void
    {
        $html = $this->actingAs($this->admin())->get(route('reports.paa', ['year' => 2027]))->assertOk()->getContent();

        $this->assertStringContainsString(route('reports.annual-plan.pdf', ['year' => 2027]), $html);
        $this->assertStringNotContainsString("withFullReport('print')", $html);
    }

    public function test_the_report_has_its_header_the_units_the_programmes_and_a_reference(): void
    {
        $this->actingAs($this->admin())->get(route('reports.annual-plan', ['year' => 2027]))->assertOk()
            ->assertSee('Annual Activity Plan Report (PAA)')->assertSee('PAA-2027')
            ->assertSee('Planned and approved, by unit')->assertSee('Detailed Programmatic Breakdown')->assertSee('Consolidated Institutional Totals');
    }

    public function test_the_pdf_is_a_real_pdf_and_the_download_is_recorded(): void
    {
        $response = $this->actingAs($this->admin())->get(route('reports.annual-plan.pdf', ['year' => 2027]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(1, AuditLog::where('action', 'report.annual_plan_pdf')->count());
    }

    public function test_a_report_of_a_plan_not_fully_approved_says_so(): void
    {
        Activity::where('fiscal_year', 2027)->orderBy('id')->firstOrFail()->forceFill(['status' => Activity::STATUS_SUBMITTED, 'version' => 0])->save();

        $this->actingAs($this->admin())->get(route('reports.annual-plan', ['year' => 2027]))->assertSee('Draft state')->assertSee('not fully approved');
    }

    public function test_the_institutions_logo_is_on_the_printed_reports_and_the_pdf_still_renders(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('reports.annual-plan', ['year' => 2027]))->assertDontSee('data:image/png;base64', false);

        SystemSetting::set('organization_logo', 'images/sample/mtc-logo.png', 'string');

        foreach (['reports.annual-plan', 'reports.approved-plan', 'reports.implementation-plan'] as $route) {
            $this->actingAs($admin)->get(route($route, ['year' => 2027]))->assertOk()->assertSee('data:image/png;base64', false);
        }
        $this->actingAs($admin)->get(route('reports.annual-plan.pdf', ['year' => 2027]))->assertOk();
    }

    public function test_only_those_who_see_every_plan_get_it(): void
    {
        $viewer = User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail();

        $this->actingAs($viewer)->get(route('reports.annual-plan.pdf', ['year' => 2027]))->assertForbidden();
    }
}
