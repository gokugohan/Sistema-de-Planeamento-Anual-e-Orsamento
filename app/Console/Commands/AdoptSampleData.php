<?php

namespace App\Console\Commands;

use App\Services\SampleRegistry;
use Database\Seeders\Samples\MtcDirectorateSeeder;
use Database\Seeders\Samples\MtcProgramSeeder;
use Database\Seeders\Samples\MtcSampleActivitiesSeeder;
use Database\Seeders\Samples\MtcDemoUsersSeeder;
use Illuminate\Console\Command;

/**
 * For databases where the MTC sample was loaded BEFORE sample records were
 * tracked: registers the existing records that are identical to the sample
 * (same code and same name/description), so they can be purged from the app.
 * Records that differ are real data and are left alone.
 */
class AdoptSampleData extends Command
{
    protected $signature = 'paa:adopt-samples {--force : Do not ask for confirmation}';

    protected $description = 'Register sample data loaded before tracking existed, so it can be purged';

    public function handle(SampleRegistry $registry): int
    {
        $this->warn('Only records IDENTICAL to the MTC sample (same code and name/description) are adopted. Review before confirming:');
        $this->line('  units · programmes · demo users (@goku.tl) · [EXEMPLO] activities');

        if (!$this->option('force') && !$this->confirm('Adopt matching records as sample data (they become purgeable)?')) {
            return self::FAILURE;
        }

        $registry->adopt = true;

        foreach ([MtcDirectorateSeeder::class, MtcProgramSeeder::class, MtcDemoUsersSeeder::class, MtcSampleActivitiesSeeder::class] as $seeder) {
            app($seeder)->setCommand($this)->run($registry);
        }

        $counts = $registry->counts();
        $this->info('Registered as sample: ' . collect($counts)->map(fn ($n, $k) => "$n $k")->implode(', ') . '.');

        return self::SUCCESS;
    }
}
