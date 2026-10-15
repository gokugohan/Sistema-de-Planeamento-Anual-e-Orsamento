<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\User;
use App\Services\ActivityCsvImporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ActivityCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private string $header = 'ref;directorate_code;program_code;description;priority;quarter;planned_start;planned_end;procurement_required;manager_name;indicator_name;indicator_type;indicator_unit;indicator_target;class_code;amount';

    protected function setUp(): void
    {
        parent::setUp();

        Directorate::create(['code' => 'DNTT', 'name' => 'Direção Nacional dos Transportes Terrestres', 'type' => 'National Directorate', 'active' => true]);
        Program::create(['fiscal_year' => 2027, 'oge_code' => '002', 'name' => 'Transportes Terrestres']);
        EconomicClassification::create(['code' => '10', 'name' => 'Salários e Vencimentos']);
        EconomicClassification::create(['code' => '20', 'name' => 'Bens e Serviços']);
    }

    private function csv(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'paa');
        file_put_contents($path, implode("\n", array_merge([$this->header], $lines)));

        return $path;
    }

    public function test_valid_file_is_parsed_into_activities_with_children(): void
    {
        $path = $this->csv([
            'A1;DNTT;002;Revisão do quadro regulamentar;Alta;2;2027-04-01;2027-06-30;sim;Ana;Diplomas;Output;diplomas;3;20;15000,50',
            'A1;;;;;;;;;;;;;;10;5000',
        ]);

        $parsed = (new ActivityCsvImporter)->parse($path, 2027);

        $this->assertSame([], $parsed['errors']);
        $this->assertCount(1, $parsed['activities']);
        $a = $parsed['activities'][0];
        $this->assertSame('Alta', $a['priority']);
        $this->assertTrue($a['procurement_required']);
        $this->assertCount(1, $a['indicators']);
        $this->assertSame([15000.5, 5000.0], array_column($a['line_items'], 'estimated_amount'));
    }

    public function test_import_creates_draft_records_in_one_transaction(): void
    {
        $parsed = (new ActivityCsvImporter)->parse($this->csv([
            'A1;DNTT;002;Revisão do quadro regulamentar;Média;1;2027-01-01;2027-03-31;não;;Diplomas;Outcome;diplomas;3;20;1000',
        ]), 2027);

        $counts = (new ActivityCsvImporter)->import($parsed['activities'], 2027);

        $this->assertSame(1, $counts['activities']);
        $activity = Activity::firstOrFail();
        $this->assertSame(Activity::STATUS_DRAFT, $activity->status);
        $this->assertSame(2027, (int) $activity->fiscal_year);
        $this->assertSame(1, $activity->indicators()->count());
        $this->assertEquals(1000, $activity->lineItems()->sum('estimated_amount'));
    }

    public function test_every_problem_is_reported_with_its_row_number(): void
    {
        $parsed = (new ActivityCsvImporter)->parse($this->csv([
            'A1;NOPE;999;abc;Urgente;7;2027-13-01;2027-06-30;;;;;;;;',
            'A2;DNTT;002;Atividade válida com dados;Alta;1;2026-01-01;2026-03-31;;;;;;;99;-5',
        ]), 2027);

        $messages = implode("\n", array_column($parsed['errors'], 'message'));
        $this->assertStringContainsString('Unknown or inactive directorate_code', $messages);
        $this->assertStringContainsString('Unknown program_code', $messages);
        $this->assertStringContainsString('description must have', $messages);
        $this->assertStringContainsString('priority', $messages);
        $this->assertStringContainsString('quarter must be', $messages);
        $this->assertStringContainsString('valid dates', $messages);
        $this->assertStringContainsString('within the selected fiscal year', $messages);
        $this->assertStringContainsString('unknown class_code', $messages);
        $this->assertContains(2, array_column($parsed['errors'], 'row'));
        $this->assertContains(3, array_column($parsed['errors'], 'row'));
    }

    public function test_reimporting_the_same_file_is_rejected_as_duplicate(): void
    {
        $lines = ['A1;DNTT;002;Revisão do quadro regulamentar;Alta;2;2027-04-01;2027-06-30;;;;;;;20;100'];
        $importer = new ActivityCsvImporter;
        $importer->import($importer->parse($this->csv($lines), 2027)['activities'], 2027);

        $second = $importer->parse($this->csv($lines), 2027);

        $this->assertStringContainsString('already exists', $second['errors'][0]['message']);
    }

    public function test_missing_columns_and_latin1_encoding_are_handled(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'paa');
        file_put_contents($bad, "ref;description\nA1;x");
        $this->assertStringContainsString('Missing required column', (new ActivityCsvImporter)->parse($bad, 2027)['errors'][0]['message']);

        $latin1 = tempnam(sys_get_temp_dir(), 'paa');
        file_put_contents($latin1, mb_convert_encoding($this->header."\nA1;DNTT;002;Revisão técnica;Alta;1;2027-01-01;2027-03-31;;;;;;;20;10", 'Windows-1252', 'UTF-8'));
        $parsed = (new ActivityCsvImporter)->parse($latin1, 2027);
        $this->assertSame([], $parsed['errors']);
        $this->assertSame('Revisão técnica', $parsed['activities'][0]['description']);
    }

    public function test_the_downloadable_template_is_itself_valid(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paa');
        file_put_contents($path, ActivityCsvImporter::template());

        $this->assertSame([], (new ActivityCsvImporter)->parse($path, 2027)['errors']);
    }

    public function test_only_admins_can_use_the_import_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $minister = User::factory()->create();
        $minister->assignRole('Minister');
        $this->actingAs($minister)->get(route('management.import'))->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->get(route('management.import'))->assertOk();
        $this->get(route('management.import.template'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_page_validates_then_imports_an_uploaded_file(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $file = UploadedFile::fake()->createWithContent('plan.csv', $this->header."\nA1;DNTT;002;Revisão do quadro regulamentar;Alta;2;2027-04-01;2027-06-30;;;;;;;20;250");

        Volt::test('pages.management.import-activities')
            ->set('fiscalYear', 2027)
            ->set('file', $file)
            ->call('check')
            ->assertSet('preview.error_count', 0)
            ->assertSet('preview.activities', 1)
            ->call('import')
            ->assertSet('result.activities', 1);

        $this->assertSame(1, Activity::count());
    }
}
