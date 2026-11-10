<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\ResultIndicator;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SampleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\OgeCatalog;
use Database\Seeders\Samples\OgeEntitySampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Any entity of the State Budget 2027 can be loaded as the sample, with its own units, and purged again. */
class OgeEntitySampleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('Admin');
        $this->actingAs($u);

        return $u;
    }

    public function test_the_catalog_holds_every_entity_of_the_book_and_agrees_with_its_totals(): void
    {
        $index = OgeCatalog::index();
        $this->assertCount(109, $index);
        $this->assertSame(['ministry', 'secretariat', 'body', 'municipal', 'fund'], array_keys(OgeCatalog::grouped()));

        // the ministry sample's own file (read separately) and the catalog read the same plan
        $mtc = OgeCatalog::entity('025');
        $this->assertEqualsWithDelta(14620942, collect($mtc['activities'])->sum(fn ($a) => array_sum($a['amounts'])), 0.5);

        foreach (array_keys($index) as $code) {
            $data = OgeCatalog::entity($code);
            $codes = collect($data['units'])->pluck('code');
            $this->assertSame($codes->count(), $codes->unique()->count(), "unit codes are unique in {$code}");
            foreach ($data['activities'] as $a) {
                $this->assertNotSame([], $a['amounts'], "{$code} {$a['code']} has an amount");
                $this->assertTrue($codes->contains(OgeCatalog::ownerUnit($code, $a['div'])), "{$code} {$a['code']} has an owner");
            }
            foreach ($data['units'] as $u) {
                $this->assertTrue($u['parent'] === null || $codes->contains($u['parent']));
            }
        }
    }

    public function test_an_entity_loads_with_its_units_plan_and_name_and_purges_keeping_its_units(): void
    {
        $this->admin();
        app(OgeEntitySampleSeeder::class)->setContainer(app())->run('017', 'draft', [2027]);

        $data = OgeCatalog::entity('017');
        $this->assertSame(count($data['units']), Directorate::count());
        $dg = Directorate::where('code', 'SADGCSP')->first();
        $this->assertSame('General Directorate', $dg->type);
        $this->assertSame($dg->id, Directorate::where('code', 'DNN')->value('parent_id'), 'a national directorate sits under its general directorate');
        $this->assertNull(Directorate::where('code', 'GMS')->value('parent_id'));

        $this->assertSame(count($data['activities']), Activity::where('fiscal_year', 2027)->count());
        $this->assertSame(Activity::count(), Activity::where('status', Activity::STATUS_DRAFT)->count());
        $this->assertEqualsWithDelta(81063097, (float) LineItem::sum('estimated_amount'), 0.5);
        $this->assertEqualsCanonicalizing(['510', '528', '529'], Program::where('fiscal_year', 2027)->pluck('oge_code')->all());
        $this->assertGreaterThan(0, ResultIndicator::count());
        $this->assertSame(['en' => 'MS', 'pt' => 'MS', 'tet' => 'MS'], SystemSetting::get('org_abbr'));
        $this->assertTrue(User::where('email', 'ministro@gdsc.tl')->first()->hasRole('Minister'));
        $this->assertTrue(User::where('email', 'director.dnn@gdsc.tl')->first()->hasRole('Director'));

        // purge, keeping the units as the institution's structure
        app(SampleRegistry::class)->purge('mtc', true);
        $this->assertSame(0, Activity::count());
        $this->assertSame(count($data['units']), Directorate::count());
        $this->assertSame(0, User::where('email', 'like', '%@gdsc.tl')->count());
        $this->assertSame(['en' => 'MS', 'pt' => 'MS', 'tet' => 'MS'], SystemSetting::get('org_abbr'), 'the name stays with the units');
    }

    public function test_purging_without_keeping_units_removes_everything_and_the_name(): void
    {
        $this->admin();
        app(OgeEntitySampleSeeder::class)->setContainer(app())->run('062', 'approved', [2026, 2027]);
        $this->assertSame(2, Activity::query()->distinct()->count('fiscal_year'), 'every year takes the 2027 plan');
        $this->assertNull(User::where('email', 'ministro@gdsc.tl')->first(), 'only a ministry has a minister');

        app(SampleRegistry::class)->purge('mtc');
        $this->assertSame(0, Activity::count());
        $this->assertSame(0, Directorate::count());
        $this->assertSame(['en' => 'ORG', 'pt' => 'ORG', 'tet' => 'ORG'], SystemSetting::get('org_abbr'));
    }

    public function test_the_page_loads_the_chosen_entity(): void
    {
        $this->admin();
        app()->setLocale('en');
        Volt::test('pages.management.sample-data')
            ->assertSet('scope', 'entity')->assertSee('Ministério da Saúde') // a public entity is the first choice
            ->assertSee('Ministério da Saúde becomes the institution shown')   // what it contains follows the choice
            ->assertSee('510 Funcionamento e desenvolvimento institucional')
            ->assertDontSee('Decreto-Lei 75/2023')->assertSee('Municipal and regional authorities')
            ->assertSee('27 units · 85 activities · $ 81,063,097 planned for 2027')
            ->set('entity', '062')->set('fromYear', 2027)->set('toYear', 2027)
            ->call('load')->assertHasNoErrors();

        $this->assertSame(count(OgeCatalog::entity('062')['activities']), Activity::count());

        Volt::test('pages.management.sample-data')->set('entity', '999')->set('scope', 'entity')->call('load')->assertStatus(422);
    }
}
