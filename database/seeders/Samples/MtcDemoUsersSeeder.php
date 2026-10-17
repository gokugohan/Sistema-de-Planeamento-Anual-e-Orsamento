<?php

namespace Database\Seeders\Samples;

use App\Models\Directorate;
use App\Models\User;
use App\Services\SampleRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts for the sample dataset: one Minister, a Technical Officer and a
 * Director for every organic unit, and a Finance officer for every directorate. All use the password "password" and the
 *
 * @gdsc.tl domain, so they must never exist in production: purge them from
 * Management > Sample Data before going live.
 */
class MtcDemoUsersSeeder extends Seeder
{
    /**
     * @param  ?string  $office  code of the single office being loaded. There is no Minister then: the
     *                           head of the office (a Director, "Chefe") gives the final approval.
     */
    public function run(SampleRegistry $registry, ?string $office = null): void
    {
        $hash = Hash::make('password'); // hashed once; the same demo password for every account

        if ($office === null) {
            $head = $registry->upsert('mtc', User::class, ['email' => 'ministro@gdsc.tl'], [
                'name' => 'Ministro dos Transportes e Comunicações',
                'password' => $hash,
            ]);
        } else {
            $unit = Directorate::where('code', $office)->firstOrFail();
            $head = $registry->upsert('mtc', User::class, ['email' => 'chefe.'.strtolower($office).'@gdsc.tl'], [
                'name' => "Chefe {$office}",
                'password' => $hash,
                'directorate_id' => $unit->id,
            ]);
        }
        $this->assignRole($registry, $head, $office === null ? 'Minister' : 'Director');

        foreach (Directorate::all() as $dir) {
            if (! $registry->isRegistered($dir)) {
                continue; // only sample units get demo accounts
            }
            $code = strtolower($dir->code);

            foreach (['tecnico' => ['Planning', 'Technical Officer'], 'director' => ['Director', 'Director']] as $prefix => [$role, $label]) {
                if ($prefix === 'director' && $office !== null && $dir->code === $office) {
                    continue; // the head of the office is the account created above
                }
                $user = $registry->upsert('mtc', User::class, ['email' => "{$prefix}.{$code}@gdsc.tl"], [
                    'name' => "{$label} {$dir->code}",
                    'password' => $hash,
                    'directorate_id' => $dir->id,
                ]);
                $this->assignRole($registry, $user, $role);
            }

            // a finance officer for every directorate (top-level unit): confirms budgets and verifies the spending reported below it
            if (! $dir->parent_id) {
                $user = $registry->upsert('mtc', User::class, ['email' => "finance.{$code}@gdsc.tl"], [
                    'name' => "Finance {$dir->code}",
                    'password' => $hash,
                    'directorate_id' => $dir->id,
                ]);
                $this->assignRole($registry, $user, 'Finance');
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
