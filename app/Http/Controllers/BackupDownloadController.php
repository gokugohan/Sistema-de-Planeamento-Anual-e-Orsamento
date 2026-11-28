<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\DatabaseBackup;

/**
 * An administrator downloads a backup to keep it off the server: a backup on the same server as the application is
 * lost with it. Every download is recorded, so the Backups page can say when a copy was last taken away.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(string $name, DatabaseBackup $backups)
    {
        abort_unless(auth()->user()?->isAdministrator(), 403);
        $path = $backups->file($name);

        AuditLog::create([
            'action' => 'backup.downloaded',
            'description' => 'Database backup downloaded: '.$name,
            'metadata' => ['file' => $name, 'bytes' => filesize($path)],
        ]);

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }
}
