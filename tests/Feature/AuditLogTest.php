<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_model_changes_are_recorded_with_old_and_new_values(): void
    {
        $admin = $this->userWithRole('Admin');
        $this->actingAs($admin);

        $program = Program::create(['name' => 'Original', 'oge_code' => '900']);
        $program->update(['name' => 'Renamed']);

        $update = AuditLog::where('action', 'program.updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $update->user_id);
        $this->assertSame($program->id, $update->auditable_id);
        $this->assertSame(['name' => 'Original'], $update->old_values);
        $this->assertSame(['name' => 'Renamed'], $update->new_values);
        $this->assertDatabaseHas('audit_logs', ['action' => 'program.created', 'auditable_id' => $program->id]);
    }

    public function test_passwords_never_reach_the_audit_log(): void
    {
        $this->actingAs($this->userWithRole('Admin'));
        User::factory()->create(['password' => 'secret-value']);

        $this->assertFalse(
            AuditLog::where('action', 'user.created')->get()
                ->contains(fn ($l) => array_key_exists('password', $l->new_values ?? []))
        );
    }

    public function test_admin_can_open_the_audit_page_and_filter_it(): void
    {
        $admin = $this->userWithRole('Admin');
        $this->actingAs($admin);
        Program::create(['name' => 'Alpha', 'oge_code' => '901']);

        $this->get(route('management.audit'))->assertOk()->assertSee('program.created');

        Volt::test('pages.management.audit-log')
            ->set('action', 'program.created')
            ->assertSee('program.created')
            ->set('action', 'program.deleted')
            ->assertDontSee('Program #');
    }

    public function test_non_admins_cannot_see_the_audit_log(): void
    {
        $this->actingAs($this->userWithRole('Minister'));

        $this->get(route('management.audit'))->assertForbidden();
    }
}
