<?php

namespace Tests\Feature;

use App\Models\Directorate;
use App\Models\User;
use App\Services\SampleRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The office sample loaded onto an office the administrator already entered: every unit of the office gets its demo
 * accounts, so the demo can be followed in each of them, and purging the sample removes them again.
 */
#[Group('office')]
class OfficeSampleAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_office_sample_gives_demo_accounts_to_the_real_units_of_the_office_and_purge_removes_them(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $gdsc = Directorate::create(['code' => 'GDSC', 'name' => 'Gabinete de Digitalização e Segurança Cibernética', 'is_active' => true]);
        $udig = Directorate::create(['code' => 'UDIG', 'name' => 'Unidade para o Digital', 'parent_id' => $gdsc->id, 'is_active' => true]);
        $usc = Directorate::create(['code' => 'USC', 'name' => 'Unidade de Segurança Cibernética', 'parent_id' => $gdsc->id, 'is_active' => true]);
        $real = User::factory()->create(['email' => 'real.head@example.tl', 'directorate_id' => $udig->id]);
        $real->assignRole('Director');

        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();

        $registry = app(SampleRegistry::class);
        $this->assertFalse($registry->isRegistered($usc->fresh()), 'the real unit stays real');
        foreach ([
            'chefe.gdsc@gdsc.tl' => ['GDSC', 'Director'],
            'tecnico.gdsc@gdsc.tl' => ['GDSC', 'Planning'],
            'finance.gdsc@gdsc.tl' => ['GDSC', 'Finance'],
            'tecnico.udig@gdsc.tl' => ['UDIG', 'Planning'],
            'director.udig@gdsc.tl' => ['UDIG', 'Director'],
            'tecnico.usc@gdsc.tl' => ['USC', 'Planning'],
            'director.usc@gdsc.tl' => ['USC', 'Director'],
        ] as $email => [$unit, $role]) {
            $user = User::where('email', $email)->first();
            $this->assertNotNull($user, "{$email} was not created");
            $this->assertSame($unit, $user->directorate?->code, $email);
            $this->assertTrue($user->hasRole($role), "{$email} is not {$role}");
            $this->assertTrue($registry->isRegistered($user), "{$email} is not marked as sample data");
        }
        $this->assertNull(User::where('email', 'director.gdsc@gdsc.tl')->first(), 'the chefe is the head of the office');
        $this->assertSame(['Director'], $real->fresh()->getRoleNames()->all(), 'a real account is never re-roled');

        $registry->purge('mtc', keepUnits: true);

        $this->assertSame(0, User::where('email', 'like', '%@gdsc.tl')->count(), 'purge removes the demo accounts');
        $this->assertNotNull($real->fresh());
        $this->assertNotNull($usc->fresh());
    }
}
