<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Branding;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcBrandingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** The institution's logo: uploaded in Settings, shown in the menu and on the sign-in page, and the source of the browser icon. */
class OrganizationLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

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

    public function test_an_administrator_uploads_a_logo_which_becomes_the_menu_logo_and_the_browser_icon(): void
    {
        Volt::actingAs($this->admin())->test('pages.management.settings')
            ->set('logoUpload', UploadedFile::fake()->image('emblem.png', 800, 700))
            ->call('saveLogo')->assertHasNoErrors();

        $this->assertSame(Branding::LOGO, SystemSetting::get('organization_logo'));
        Storage::disk('local')->assertExists([Branding::LOGO, Branding::FAVICON]);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get(Branding::LOGO));
        $this->assertLessThanOrEqual(640, max($w, $h), 'the logo is made smaller');
        $this->assertSame([64, 64], array_slice(getimagesizefromstring(Storage::disk('local')->get(Branding::FAVICON)), 0, 2), 'the icon is a 64 pixel square');

        // the logo and the icon are public: the sign-in page shows them
        $this->get(route('brand.logo'))->assertOk()->assertHeader('content-type', 'image/png');
        $this->get(route('brand.favicon'))->assertOk();
        auth()->logout();
        $this->get(route('login'))->assertSee('rel="icon"', false)->assertSee('brand/favicon', false)->assertSee('brand/logo', false);
    }

    public function test_replacing_the_logo_changes_the_address_so_that_browsers_fetch_the_new_one(): void
    {
        $page = Volt::actingAs($this->admin())->test('pages.management.settings');

        $page->set('logoUpload', UploadedFile::fake()->image('a.png', 300, 300))->call('saveLogo');
        SystemSetting::set('organization_logo_version', '1', 'string');
        $first = Branding::logoUrl();
        $page->set('logoUpload', UploadedFile::fake()->image('b.png', 300, 300))->call('saveLogo');

        $this->assertNotSame($first, Branding::logoUrl());
    }

    public function test_only_pictures_are_accepted(): void
    {
        $page = Volt::actingAs($this->admin())->test('pages.management.settings');

        $page->set('logoUpload', UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'))->assertHasErrors('logoUpload');
        $page->set('logoUpload', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertHasErrors('logoUpload');
        $page->set('logoUpload', UploadedFile::fake()->image('huge.png', 100, 100)->size(5000))->assertHasErrors('logoUpload');

        $this->assertEmpty(SystemSetting::get('organization_logo'));
    }

    public function test_removing_the_logo_goes_back_to_the_abbreviation_and_the_default_icon(): void
    {
        $page = Volt::actingAs($this->admin())->test('pages.management.settings');
        $page->set('logoUpload', UploadedFile::fake()->image('a.png', 300, 300))->call('saveLogo');

        $page->call('removeLogo');

        $this->assertNull(Branding::logoUrl());
        $this->assertStringContainsString('favicon.ico', Branding::faviconUrl());
        Storage::disk('local')->assertMissing(Branding::LOGO);
        $this->get(route('brand.logo'))->assertNotFound();
    }
}
