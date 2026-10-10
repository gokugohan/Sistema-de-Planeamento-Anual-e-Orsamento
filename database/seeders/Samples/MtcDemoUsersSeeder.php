<?php

namespace Database\Seeders\Samples;

use App\Models\Directorate;
use App\Models\User;
use App\Services\SampleRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts for the sample dataset: one Minister, and a Planner and a
 * Director for every organic unit. All use the password "password" and the
 *
 * @goku.tl domain, so they must never exist in production: purge them from
 * Management > Sample Data before going live.
 */
class MtcDemoUsersSeeder extends Seeder
{
    public function run(SampleRegistry $registry): void
    {
        $hash = Hash::make('password'); // hashed once; the same demo password for every account

        $minister = $registry->upsert('mtc', User::class, ['email' => 'ministro@goku.tl'], [
            'name' => 'Ministro dos Transportes e Comunicações',
            'password' => $hash,
        ]);
        $this->assignRole($registry, $minister, 'Minister');

        foreach (Directorate::all() as $dir) {
            if (! $registry->isRegistered($dir)) {
                continue; // only sample units get demo accounts
            }
            if (preg_match('/^(DMTC|AACTL|APORTIL)-/', $dir->code)) {
                continue; // municipal directorates and divisions of the autonomous bodies have no demo accounts
            }
            $code = strtolower($dir->code);

            foreach (['planner' => ['Planning', 'Planner'], 'director' => ['Director', 'Director']] as $prefix => [$role, $label]) {
                $user = $registry->upsert('mtc', User::class, ['email' => "{$prefix}.{$code}@goku.tl"], [
                    'name' => "{$label} {$dir->code}",
                    'password' => $hash,
                    'directorate_id' => $dir->id,
                ]);
                $this->assignRole($registry, $user, $role);
            }
        }
    }

    /** Roles are only set on accounts that belong to the sample; a real user is never re-roled. */
    private function assignRole(SampleRegistry $registry, User $user, string $role): void
    {
        if ($registry->isRegistered($user)) {
            $user->syncRoles($role);
        }
    }
}
