<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SampleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

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
        $this->assertNull(User::where('email', 'ministro@goku.tl')->first());
        $this->assertNotNull(User::where('email', 'chefe.gdsc@goku.tl')->first());
        $this->assertSame(7, User::count()); // the head, and a Planner and a Director for each of the three units
    }

    public function test_the_gabinete_gets_a_real_and_an_illustrative_plan_for_2026_and_2027(): void
    {
        $this->loadOffice();

        // The budget books give each of the three units one activity; the illustrative plan adds ten.
        $this->assertSame(13, Activity::where('fiscal_year', 2026)->count());
        $this->assertSame(13, Activity::where('fiscal_year', 2027)->count());
        $this->assertSame(0, Activity::where('fiscal_year', 2025)->count());

        // 2026 is fully approved and distributed over its envelope; 2027 sits at every step of the workflow.
        $this->assertSame(13, Activity::where('fiscal_year', 2026)->where('status', Activity::STATUS_APPROVED)->count());
        $statuses2027 = Activity::where('fiscal_year', 2027)->pluck('status')->unique()->all();
        foreach ([Activity::STATUS_DRAFT, Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL, Activity::STATUS_APPROVED] as $status) {
            $this->assertContains($status, $statuses2027);
        }

        $envelope = FiscalEnvelope::where('fiscal_year', 2026)->firstOrFail();
        $this->assertSame(FiscalEnvelope::STATUS_APPROVED, $envelope->status);
        $this->assertGreaterThan(0, (float) $envelope->total_amount);
        $this->assertGreaterThan(0, AdjustedBudget::count());
        $this->assertNull(FiscalEnvelope::where('fiscal_year', 2027)->first(), 'the 2027 bill is still in Parliament');
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
        $this->assertSame(0, Activity::where('code', 'like', 'DEMO-%')->count(), 'the illustrative plan belongs to the Gabinete only');
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
        $this->assertSame(0, FiscalEnvelope::count());
        $this->assertSame(0, User::count());
        $this->assertSame('ORG', SystemSetting::getLocalized('org_abbr', '', 'en'));
    }

    public function test_the_page_loads_the_chosen_office(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Volt::test('pages.management.sample-data')
            ->assertSet('scope', 'office')
            ->assertSet('office', 'GDSC')
            ->set('office', 'GPPC')
            ->call('load');

        $this->assertTrue(Directorate::where('code', 'GPPC')->exists());
        $this->assertFalse(Directorate::where('code', 'GDSC')->exists());
    }
}
