<?php

namespace App\Console\Commands;

use App\Services\SampleRegistry;
use Illuminate\Console\Command;

class PurgeSampleData extends Command
{
    protected $signature = 'paa:purge-samples {--force : Do not ask for confirmation}';

    protected $description = 'Remove everything the sample dataset created (demo users, sample units, programmes and activities). Real data is never touched.';

    public function handle(SampleRegistry $registry): int
    {
        $counts = $registry->counts();

        if (array_sum($counts) === 0) {
            $this->info('No sample data is registered; nothing to purge.');

            return self::SUCCESS;
        }

        $this->table(['Kind', 'Registered sample records'], collect($counts)->map(fn ($n, $k) => [$k, $n])->values()->all());

        if (!$this->option('force') && !$this->confirm('Delete these sample records? Anything real data still depends on is kept.')) {
            return self::FAILURE;
        }

        $report = $registry->purge('mtc');

        $this->info('Deleted: ' . collect($report['deleted'])->map(fn ($n, $k) => "$n $k")->implode(', ') . '.');
        foreach ($report['kept'] as $line) {
            $this->warn("Kept: {$line}");
        }

        return self::SUCCESS;
    }
}
