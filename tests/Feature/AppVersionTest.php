<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AppVersion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_next_release_follows_major_minor_patch(): void
    {
        $this->assertSame('1.0.1', AppVersion::next('1.0.0', 'patch'));
        $this->assertSame('1.1.0', AppVersion::next('1.0.3', 'minor'));
        $this->assertSame('2.0.0', AppVersion::next('1.4.2', 'major'));
        $this->assertSame('3.1.4', AppVersion::next('1.0.0', '3.1.4'));

        $this->expectException(\InvalidArgumentException::class);
        AppVersion::next('1.0.0', 'banana');
    }

    public function test_the_build_names_the_changes_since_the_release(): void
    {
        $this->assertSame('1.4.0', AppVersion::compose('1.4.0', 0, 'e54f2f1'));
        $this->assertSame('1.4.0+3.e54f2f1', AppVersion::compose('1.4.0', 3, 'e54f2f1'));
        $this->assertSame('1.4.0+e54f2f1', AppVersion::compose('1.4.0', null, 'e54f2f1'));
        $this->assertSame('1.4.0', AppVersion::compose('1.4.0', null, null));
    }

    public function test_the_version_comes_from_the_version_file(): void
    {
        $info = AppVersion::info();

        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), $info['release']);
        $this->assertStringStartsWith($info['release'], $info['build']);
    }

    public function test_every_audit_row_keeps_the_build_that_wrote_it(): void
    {
        $log = AuditLog::create(['action' => 'test.done', 'description' => 'test']);

        $this->assertSame(AppVersion::build(), $log->fresh()->app_version);
    }

    public function test_the_login_page_shows_the_release_but_not_the_commit(): void
    {
        $info = AppVersion::info();
        $page = $this->get(route('login'))->assertOk()->assertSee(__('Version').' '.$info['release'], false);

        if ($info['commit']) {
            $page->assertDontSee($info['commit']);
        }
    }

    public function test_the_version_shows_in_the_sidebar_and_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)->get(route('management.settings'))
            ->assertOk()
            ->assertSee('id="version"', false)
            ->assertSee(AppVersion::build());
    }
}
