<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcBrandingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The institution's logo: set in Settings (or by the sample branding), shown in the menu, and only a safe address is accepted. */
class OrganizationLogoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('Admin');

        return $u;
    }

    public function test_the_menu_shows_the_abbreviation_until_a_logo_is_set(): void
    {
        SystemSetting::set('org_abbr', ['en' => 'ABC', 'pt' => 'ABC', 'tet' => 'ABC'], 'json');
        $user = User::factory()->create();

        Volt::actingAs($user)->test('layout.sidebar')->assertSee('ABC')->assertDontSeeHtml('mtc-logo.png');

        SystemSetting::set('organization_logo', 'images/sample/mtc-logo.png', 'string');
        Volt::actingAs($user)->test('layout.sidebar')->assertSeeHtml('images/sample/mtc-logo.png');
    }

    public function test_the_sample_branding_brings_its_logo_and_the_purge_takes_it_away(): void
    {
        (new MtcBrandingSeeder)->run();
        $this->assertSame(MtcBrandingSeeder::LOGO, SystemSetting::get('organization_logo'));
        $this->assertFileExists(public_path(MtcBrandingSeeder::LOGO));

        MtcBrandingSeeder::resetIfUnchanged();
        $this->assertEmpty(SystemSetting::get('organization_logo'));
    }

    public function test_an_administrator_sets_the_logo_and_only_a_safe_address_is_accepted(): void
    {
        $page = Volt::actingAs($this->admin())->test('pages.management.settings');

        $page->set('organizationLogo', 'javascript:alert(1)')->call('saveSettings')->assertHasErrors('organizationLogo');
        $page->set('organizationLogo', '../../etc/passwd')->call('saveSettings')->assertHasErrors('organizationLogo');

        $page->set('organizationLogo', 'images/logo.png')->call('saveSettings')->assertHasNoErrors('organizationLogo');
        $this->assertSame('images/logo.png', SystemSetting::get('organization_logo'));

        $page->set('organizationLogo', 'https://example.gov.tl/logo.webp')->call('saveSettings')->assertHasNoErrors('organizationLogo');
    }
}
