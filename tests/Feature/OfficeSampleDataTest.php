<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\Directorate;
use App\Models\LineItem;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SampleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcBudgetSeeder;
use Database\Seeders\Samples\MtcCostCentres;
use Database\Seeders\Samples\MtcDirectorateSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('office')]
class OfficeSampleDataTest extends TestCase
{
    use RefreshDatabase;

    private function loadOffice(?string $office = null): void
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run($office);
    }

    public function test_the_default_office_is_the_gabinete_with_its_two_units(): void
    {
        $this->loadOffice();

        $this->assertEqualsCanonicalizing(['GDSC', 'UDIG', 'USC'], Directorate::pluck('code')->all());
        $this->assertNull(Directorate::where('code', 'GDSC')->value('parent_id'));
        $this->assertSame(Directorate::where('code', 'GDSC')->value('id'), Directorate::where('code', 'UDIG')->value('parent_id'));

        // Nothing of the rest of the ministry is created.
        $this->assertNull(User::where('email', 'ministro@gdsc.tl')->first());
        $this->assertNotNull(User::where('email', 'chefe.gdsc@gdsc.tl')->first());
        $this->assertSame(7, User::count()); // a Planner and a Director for each of the three units (the head of the office is the Director of the top unit), and a Finance officer for the office
    }

    public function test_the_gabinete_gets_only_the_plan_of_the_budget_books(): void
    {
        $this->loadOffice();

        // The books give each of the three units one activity (2026 and 2027); the Gabinete did not exist in 2025.
        $this->assertEqualsCanonicalizing(['510014M', '510014N', '510014O'], Activity::where('fiscal_year', 2026)->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['510014M', '510014N', '510014O'], Activity::where('fiscal_year', 2027)->pluck('code')->all());
        $this->assertSame(0, Activity::where('fiscal_year', 2025)->count());

        // the amounts printed in the books, nothing added
        $total = fn (int $year) => (float) LineItem::whereIn('activity_id', Activity::where('fiscal_year', $year)->pluck('id'))->sum('estimated_amount');
        $this->assertEqualsWithDelta(713702, $total(2026), 0.01);
        $this->assertEqualsWithDelta(762646, $total(2027), 0.01);

        $this->assertSame(3, Activity::where('fiscal_year', 2026)->where('status', Activity::STATUS_APPROVED)->count());
        $this->assertSame(0, Activity::where('fiscal_year', 2026)->where('version', '!=', 1)->count(), 'every approved activity is version 1');
        $this->assertGreaterThan(0, AdjustedBudget::count());
    }

    public function test_an_office_loaded_alone_has_the_same_amounts_as_in_the_whole_ministry(): void
    {
        $sum = fn (array $entities, ?array $units) => collect($entities)->flatMap(fn ($e, $entity) => collect($e['activities'])
            ->filter(fn ($a) => $units === null || in_array(MtcCostCentres::ownerUnit((string) $entity, $a['div']), $units, true)))
            ->sum(fn ($a) => array_sum($a['amounts']));

        foreach (array_keys(MtcDirectorateSeeder::offices()) as $office) {
            $units = MtcDirectorateSeeder::subtree($office);
            foreach (MtcBudgetSeeder::YEARS as $year) {
                $inMinistry = $sum(MtcBudgetSeeder::data($year)['entities'], $units);
                $alone = $sum(MtcBudgetSeeder::data($year, $units)['entities'], null);
                $this->assertEqualsWithDelta($inMinistry, $alone, 0.01, "{$office} {$year}: loaded alone it must have the amounts it has in the whole ministry");
            }
        }
    }

    public function test_the_institution_shown_is_the_office(): void
    {
        $this->loadOffice();

        $this->assertSame('GDSC', SystemSetting::getLocalized('org_abbr', '', 'en'));
    }

    public function test_another_office_loads_only_its_own_tree_and_plans(): void
    {
        $this->loadOffice('DGT');

        $codes = Directorate::pluck('code');
        $this->assertTrue($codes->contains('DGT'));
        $this->assertTrue($codes->contains('DNTT'));
        $this->assertTrue($codes->contains('DMTC-AIL'));
        $this->assertFalse($codes->contains('GDSC'));
        $this->assertFalse($codes->contains('DGC'));
        $this->assertNull(Directorate::where('code', 'DGT')->value('parent_id'));

        $outside = Activity::withoutGlobalScopes()->whereNotIn('directorate_id', Directorate::pluck('id'))->count();
        $this->assertSame(0, $outside);
        $this->assertGreaterThan(0, Activity::count());
        $this->assertFalse(Activity::where('description', 'like', 'Atividade%')->exists());
    }

    public function test_an_unknown_office_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->loadOffice('NOPE');
    }

    public function test_loading_twice_does_not_duplicate(): void
    {
        $this->loadOffice();
        $first = Activity::count();
        $this->loadOffice();

        $this->assertSame($first, Activity::count());
        $this->assertSame(3, Directorate::count());
    }

    public function test_purge_removes_the_office_and_restores_the_neutral_branding(): void
    {
        $this->loadOffice();
        $this->assertTrue(app(SampleRegistry::class)->hasSamples());

        app(SampleRegistry::class)->purge('mtc');

        $this->assertSame(0, Directorate::count());
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, User::count());
        $this->assertSame('ORG', SystemSetting::getLocalized('org_abbr', '', 'en'));
    }

    public function test_the_page_names_the_budget_books_the_figures_come_from(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Volt::test('pages.management.sample-data')
            ->assertSee(__('Where the figures come from'))
            ->set('scope', 'office')
            ->assertSee(__('Activities, codes, indicators, starting points, targets and amounts are as printed; nothing is made up. A unit or office loaded alone has the same amounts as in the whole ministry.'));
    }

    public function test_the_page_loads_the_chosen_office(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Volt::test('pages.management.sample-data')
            ->assertSet('scope', 'entity') // a public entity is offered first; an MTC office is chosen on purpose
            ->set('scope', 'office')
            ->assertSet('office', 'GDSC')
            ->set('office', 'GPPC')
            ->call('load');

        $this->assertTrue(Directorate::where('code', 'GPPC')->exists());
        $this->assertFalse(Directorate::where('code', 'GDSC')->exists());
    }

    public function test_the_buttons_open_a_modal_instead_of_the_browser_confirm(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Volt::test('pages.management.sample-data')
            ->assertSeeHtml("open-modal', 'confirm-load'")
            ->assertSeeHtml('role="alertdialog"')
            ->assertDontSeeHtml('wire:confirm');
    }
}
