<?php

namespace App\Console\Commands;

use App\Models\Activity;
use Database\Seeders\MtcSampleActivitiesSeeder;
use Illuminate\Console\Command;

class ClearSampleActivities extends Command
{
    protected $signature = 'paa:clear-samples {--force : Do not ask for confirmation}';

    protected $description = 'Delete the illustrative sample activities (code SAMPLE-*) with their indicators and line items';

    public function handle(): int
    {
        $query = Activity::withoutGlobalScopes()
            ->where('code', 'like', MtcSampleActivitiesSeeder::CODE_PREFIX . '%')
            ->where('description', 'like', MtcSampleActivitiesSeeder::DESCRIPTION_PREFIX . '%');

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No sample activities found.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Delete {$count} sample activities (and their indicators and line items)?")) {
            return self::FAILURE;
        }

        // Row-by-row so each deletion is audited and locked years are respected.
        $query->get()->each->delete();
        $this->info("Deleted {$count} sample activities.");

        return self::SUCCESS;
    }
}
