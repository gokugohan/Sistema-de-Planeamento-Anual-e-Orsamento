<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ErrorReport;
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\ErrorReports;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/**
 * The administrator keeps the backups off the server by downloading them, proves one restores, and follows up the
 * errors the application met, which are kept on the server and announced to the administrators.
 */
class BackupsAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->setLocale('en');
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paa-backups-'.uniqid();
        config(['backup.path' => $this->dir, 'backup.enabled' => true, 'backup.copy_to' => null]);
        // a dump without a MySQL client: the same completion marker mysqldump writes
        app()->instance(DatabaseBackup::class, new DatabaseBackup(
            fn (callable $write) => $write("CREATE TABLE t (id int);\n".DatabaseBackup::COMPLETE_MARKER." on 2026-11-28\n"),
            fn (string $db, iterable $read) => DB::statement("CREATE TABLE `{$db}`.`restored` (id int)"),
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $this->actingAs($u);

        return $u;
    }

    public function test_the_administrator_backs_up_downloads_and_is_told_to_keep_a_copy_off_the_server(): void
    {
        $this->as('Admin');
        $this->get(route('management.backups'))->assertOk()->assertSee('None yet')->assertSee('Never');

        $page = Volt::test('pages.management.backups')->call('backupNow');
        $name = app(DatabaseBackup::class)->list()->sole()['name'];
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.created']);
        $page->assertSee($name)->assertSee('No backup has been downloaded yet.');

        $this->get(route('management.backups.download', $name))->assertOk()->assertDownload($name);
        $this->assertSame($name, AuditLog::where('action', 'backup.downloaded')->sole()->metadata['file']);
        Volt::test('pages.management.backups')->assertDontSee('No backup has been downloaded yet.');

        // a week later the newest copy should be taken away again
        Carbon::setTestNow(now()->addDays(8));
        Volt::test('pages.management.backups')->assertSee('The last copy was taken away more than 7 days ago.');
    }

    public function test_only_a_backup_file_of_the_folder_can_be_downloaded(): void
    {
        $this->as('Admin');
        $this->get(route('management.backups.download', 'paa_20261128_020000.sql.gz'))->assertNotFound();
        $this->get('/management/backups/..%2F..%2F.env/download')->assertNotFound();
        $this->get(route('management.backups.download', 'other.sql.gz'))->assertNotFound();
    }

    public function test_a_restore_test_loads_the_backup_into_a_temporary_database_and_drops_it(): void
    {
        $this->as('Admin');
        app(DatabaseBackup::class)->run();
        $name = app(DatabaseBackup::class)->list()->sole()['name'];

        Volt::test('pages.management.backups')->call('restoreCheck', $name)->assertSet('check.tables', 1)->assertSet('check.ok', false);
        $temp = config('database.connections.mysql.database').'_restore_check';
        $this->assertSame([], DB::select('SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?', [$temp]), 'the temporary database is dropped');
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restore_checked']);
    }

    public function test_only_the_administrator_opens_backups_and_errors(): void
    {
        $this->as('Director');
        $this->get(route('management.backups'))->assertForbidden();
        $this->get(route('management.errors'))->assertForbidden();
    }

    public function test_errors_are_grouped_announced_once_and_come_back_after_being_resolved(): void
    {
        config(['errors.record_in_tests' => true]);
        $admin = $this->as('Admin');
        $boom = fn () => new RuntimeException('Boom in the plan');

        foreach ([1, 2] as $_) {
            ErrorReports::record($boom()); // the same line: one kind of error
        }
        $r = ErrorReport::sole();
        $this->assertSame(2, $r->occurrences);
        $this->assertSame('tests/Feature/BackupsAndErrorsTest.php', $r->file);
        $this->assertSame(1, $admin->notifications()->count(), 'told once, not on every occurrence');

        $this->get(route('management.errors'))->assertOk()->assertSee('RuntimeException')->assertSee('Boom in the plan');
        Volt::test('pages.management.errors')->call('resolve', $r->id);
        $this->assertNotNull($r->fresh()->resolved_at);

        // back within the hour: listed again, not announced again
        ErrorReports::record($boom());
        $this->assertNull($r->fresh()->resolved_at);
        $this->assertSame(1, $admin->notifications()->count());

        // back later: announced as coming back
        Volt::test('pages.management.errors')->call('resolve', $r->id);
        Carbon::setTestNow(now()->addHours(2));
        ErrorReports::record($boom());
        $this->assertSame(2, $admin->notifications()->count());
        $this->assertSame('An error came back', $admin->notifications()->latest()->first()->data['title']);
    }
}
