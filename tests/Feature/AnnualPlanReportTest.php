<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AnnualPlanReport;
use App\Services\AnnualPlanWorkbook;
use App\Support\Branding;
use App\Support\StructureCache;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_unit_staff_get_the_report_of_their_own_unit_only(): void
    {
        $technician = User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail();
        $this->actingAs($technician);

        $this->get(route('reports.annual-plan.pdf', ['year' => 2027]))->assertOk()->assertDownload('PAA-2027-UDIG.pdf');
        $other = Directorate::where('code', 'USC')->firstOrFail();
        $this->get(route('reports.annual-plan.pdf', ['year' => 2027, 'unit' => $other->id]))->assertForbidden();
    }

    public function test_the_reporting_page_downloads_the_plan_as_excel_with_planned_and_approved(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('reports.paa', ['year' => 2027]))->assertOk()->getContent();
        $this->assertStringContainsString(route('reports.annual-plan.xlsx', ['year' => 2027]), $html);
        $this->assertStringNotContainsString('exportToCSV', $html, 'the browser CSV export is gone');

        $response = $this->get(route('reports.annual-plan.xlsx', ['year' => 2027]));
        $response->assertOk()->assertDownload('PAA-2027-plan.xlsx');
        $file = tempnam(sys_get_temp_dir(), 'paa').'.xlsx';
        file_put_contents($file, $response->streamedContent());
        $book = IOFactory::load($file);
        @unlink($file);

        $this->assertSame(['Planned and approved, by unit', 'Detailed Programmatic Breakdown'], $book->getSheetNames());
        $report = app(AnnualPlanReport::class)->build(2027, $this->admin());
        $units = collect($book->getSheet(0)->toArray(null, true, false, false));
        $total = $units->first(fn ($r) => ($r[0] ?? null) === 'Consolidated Institutional Totals');
        $this->assertEqualsWithDelta($report['totals']['planned'], $total[1], 0.01, 'planned: every activity, whatever its stage');
        $this->assertEqualsWithDelta($report['totals']['approved'], $total[2], 0.01);
        $this->assertGreaterThan(0, $report['totals']['planned']);
        $this->assertSame(1, AuditLog::where('action', 'report.annual_plan_xlsx')->count());
    }

    public function test_the_workbooks_carry_the_institution_logo_and_never_a_file_outside_public(): void
    {
        $report = app(AnnualPlanReport::class)->build(2027, $this->admin());

        SystemSetting::set('organization_logo', 'logo.png', 'string');
        $book = app(AnnualPlanWorkbook::class)->build($report);
        $this->assertCount(1, $book->getSheet(0)->getDrawingCollection(), 'the logo heads the sheet');

        SystemSetting::set('organization_logo', '../.env', 'string');
        StructureCache::bump();
        $this->assertNull(Branding::logoPath());
        $this->assertCount(0, app(AnnualPlanWorkbook::class)->build($report)->getSheet(0)->getDrawingCollection());
    }

    public function test_the_activity_list_exports_excel_not_csv(): void
    {
        $this->actingAs($this->admin());
        Volt::test('pages.activities.list-activities')
            ->assertSee('Export Excel')->assertDontSee('Export CSV')
            ->call('export')->assertFileDownloaded();
    }
}
