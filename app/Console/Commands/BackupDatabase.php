<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupDatabase extends Command
{
    protected $signature = 'paa:backup {--prune-only : Only delete backups past the retention period}';

    protected $description = 'Create a verified, compressed database backup and delete old ones';

    public function handle(DatabaseBackup $backup): int
    {
        if (! config('backup.enabled')) {
            $this->warn('Backups are disabled (BACKUP_ENABLED=false).');

            return self::SUCCESS;
        }

        if ($this->option('prune-only')) {
            $this->info('Removed '.$backup->prune().' old backup(s).');

            return self::SUCCESS;
        }

        try {
            $r = $backup->run();
        } catch (\Throwable $e) {
            Log::error('Database backup failed: '.$e->getMessage());
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        AuditLog::create([
            'action' => 'backup.created',
            'description' => 'Database backup created: '.basename($r['path']),
            'metadata' => ['bytes' => $r['bytes'], 'pruned' => $r['pruned']],
        ]);

        $this->info(sprintf('Backup written: %s (%s KB). Removed %d old backup(s).', $r['path'], number_format($r['bytes'] / 1024, 1), $r['pruned']));
        if ($r['copy'] ?? null) {
            $this->info('Copied to: '.$r['copy']);
        }

        return self::SUCCESS;
    }
}
