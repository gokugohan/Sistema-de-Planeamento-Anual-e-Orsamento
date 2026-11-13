<?php

namespace App\Console\Commands;

use App\Support\AppVersion;
use Illuminate\Console\Command;

class ShowVersion extends Command
{
    protected $signature = 'paa:version {--refresh : Work the version out again (after a deployment without git history changes)}';

    protected $description = 'Show the application version: the release and the build of this copy';

    public function handle(): int
    {
        $v = $this->option('refresh') ? AppVersion::refresh() : AppVersion::info();

        $this->table(['', ''], [
            ['Release', $v['release']],
            ['Build', $v['build']],
            ['Commit', $v['commit'] ?? '—'],
            ['Commits since the release', $v['commits_since'] ?? '—'],
            ['Commit date', $v['date'] ?? '—'],
            ['Worked out from', $v['source']],
        ]);

        return self::SUCCESS;
    }
}
