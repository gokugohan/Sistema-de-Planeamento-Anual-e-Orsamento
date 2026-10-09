<?php

return [
    /*
    | Nightly database backups (php artisan paa:backup, scheduled at 02:00).
    | Files are gzip-compressed mysqldump output in storage/app/backups.
    | Copy them off the server too: a backup on the same disk is not a backup.
    */
    'enabled' => env('BACKUP_ENABLED', true),

    'path' => env('BACKUP_PATH', storage_path('app/backups')),
    'prefix' => 'paa_',

    // Backups older than this are deleted after each successful run.
    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),

    // /health reports "degraded" when the newest backup is older than this.
    'max_age_hours' => (float) env('BACKUP_MAX_AGE_HOURS', 36),

    // Full path to mysqldump if it is not on PATH (e.g. C:\laragon\bin\mysql\...\bin\mysqldump.exe).
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 900),
];
