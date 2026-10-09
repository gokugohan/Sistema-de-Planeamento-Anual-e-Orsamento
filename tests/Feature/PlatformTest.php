<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DatabaseBackup;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DatabaseSeeder::class);

        $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paa_bk_'.uniqid();
        config(['backup.path' => $this->backupDir, 'backup.keep_days' => 30, 'backup.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->backupDir);
        parent::tearDown();
    }

    private function admin(): User
    {
        $u = User::where('email', 'admin@example.org')->first();
        $this->actingAs($u);

        return $u;
    }

    private function goodDump(): \Closure
    {
        return function (callable $write) {
            $write("-- MySQL dump\nCREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n");
            $write(DatabaseBackup::COMPLETE_MARKER." on 2027-01-01\n");
        };
    }

    // ── security headers ──

    public function test_responses_carry_the_security_headers(): void
    {
        $r = $this->get('/login');

        $r->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', $r->headers->get('Permissions-Policy'));
        $this->assertNull($r->headers->get('Strict-Transport-Security'), 'HSTS must not be sent over plain HTTP');
    }

    public function test_signed_in_pages_are_not_cached_by_the_browser(): void
    {
        $this->admin();

        $cache = $this->get(route('dashboard'))->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cache);
    }

    // ── passwords ──

    public function test_production_requires_a_strong_password_other_environments_do_not(): void
    {
        $rule = fn (string $p) => validator(['p' => $p], ['p' => Password::default()])->passes();

        $this->assertTrue($rule('abcd1234'), 'local/test keeps the light default');

        $this->app['env'] = 'production';
        $this->assertFalse($rule('abcd1234'));
        $this->assertFalse($rule('Abcdefgh1234'), 'needs a symbol');
        $this->assertFalse($rule('Short1!a'), 'needs 12 characters');
        $this->assertTrue($rule('Correct-Horse-9'));
    }

    public function test_an_administrator_creates_users_with_a_random_temporary_password_never_a_default(): void
    {
        $this->admin();

        $page = Volt::test('pages.management.users.user-management')
            ->set('name', 'New Planner')->set('email', 'new.planner@institution.example')->set('selectedRole', 'Planning')
            ->call('saveUser')
            ->assertSet('issuedFor', 'new.planner@institution.example');

        $temporary = $page->get('issuedPassword');
        $user = User::where('email', 'new.planner@institution.example')->first();

        $this->assertNotSame('password', $temporary);
        $this->assertGreaterThanOrEqual(14, strlen($temporary));
        $this->assertTrue(Hash::check($temporary, $user->password));
        $this->assertFalse(Hash::check('password', $user->password), 'the old default password must not work');
        $this->assertTrue($user->must_change_password);

        $page->call('dismissIssuedPassword')->assertSet('issuedPassword', null);
    }

    public function test_resetting_a_password_issues_a_new_temporary_one_and_forces_a_change(): void
    {
        $this->admin();
        $target = User::factory()->create(['must_change_password' => false]);
        $old = $target->password;

        $page = Volt::test('pages.management.users.user-management')->call('resetPassword', $target->id);

        $target->refresh();
        $this->assertNotSame($old, $target->password);
        $this->assertTrue($target->must_change_password);
        $this->assertTrue(Hash::check($page->get('issuedPassword'), $target->password));
    }

    public function test_a_user_with_a_temporary_password_can_only_change_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('Viewer');
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect(route('profile'));
        $this->get(route('reports.paa'))->assertRedirect(route('profile'));
        $this->get(route('profile'))->assertOk();

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'A-new-secret-123')->set('password_confirmation', 'A-new-secret-123')
            ->call('updatePassword')->assertHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get(route('dashboard'))->assertOk();
    }

    // ── health ──

    public function test_health_reports_status_without_secrets(): void
    {
        config(['backup.enabled' => false]);

        $r = $this->getJson('/health')->assertOk()->assertJsonStructure(['status', 'time', 'checks' => ['database', 'cache', 'storage', 'queue', 'disk', 'backup']]);

        $this->assertSame('ok', $r->json('checks.database.status'));
        $this->assertStringNotContainsString(config('database.connections.mysql.database'), $r->getContent());
        $this->assertStringNotContainsString((string) config('app.key'), $r->getContent());
    }

    public function test_health_is_degraded_until_a_recent_backup_exists(): void
    {
        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.backup.status', 'warn');

        (new DatabaseBackup($this->goodDump()))->run();

        $this->getJson('/health')->assertOk()->assertJsonPath('checks.backup.status', 'ok');
    }

    // ── backups ──

    public function test_a_backup_is_compressed_verified_and_readable(): void
    {
        $r = (new DatabaseBackup($this->goodDump()))->run();

        $this->assertFileExists($r['path']);
        $this->assertStringEndsWith('.sql.gz', $r['path']);
        $this->assertStringContainsString('CREATE TABLE t', implode('', gzfile($r['path'])));
        $this->assertSame([], glob($this->backupDir.DIRECTORY_SEPARATOR.'*.partial'), 'no partial file may be left behind');
    }

    public function test_an_incomplete_dump_is_rejected_and_discarded(): void
    {
        $truncated = fn (callable $write) => $write("-- MySQL dump\nCREATE TABLE t (id int);\n"); // no completion marker

        try {
            (new DatabaseBackup($truncated))->run();
            $this->fail('a truncated dump was accepted as a backup');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('verification', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir.DIRECTORY_SEPARATOR.'*'), 'a bad dump must leave nothing behind');
    }

    public function test_a_crashing_dumper_leaves_no_file(): void
    {
        try {
            (new DatabaseBackup(function () {
                throw new RuntimeException('mysqldump failed: access denied');
            }))->run();
            $this->fail('expected a failure');
        } catch (RuntimeException) {
        }

        $this->assertSame([], glob($this->backupDir.DIRECTORY_SEPARATOR.'*'));
    }

    public function test_old_backups_are_pruned_and_recent_ones_kept(): void
    {
        @mkdir($this->backupDir, 0775, true);
        $old = $this->backupDir.DIRECTORY_SEPARATOR.'paa_20200101_000000.sql.gz';
        $recent = $this->backupDir.DIRECTORY_SEPARATOR.'paa_20991231_000000.sql.gz';
        $unrelated = $this->backupDir.DIRECTORY_SEPARATOR.'notes.txt';
        foreach ([$old, $recent, $unrelated] as $f) {
            file_put_contents($f, 'x');
        }
        touch($old, time() - 40 * 86400);
        touch($unrelated, time() - 400 * 86400);

        $removed = (new DatabaseBackup($this->goodDump()))->prune();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($unrelated, 'only our own backup files may be deleted');
    }

    public function test_the_backup_command_fails_cleanly_when_mysqldump_is_missing(): void
    {
        config(['backup.mysqldump' => 'definitely-not-a-real-binary-xyz']);

        $this->artisan('paa:backup')->assertFailed();

        $this->assertSame([], glob($this->backupDir.DIRECTORY_SEPARATOR.'*.sql.gz'));
    }
}
