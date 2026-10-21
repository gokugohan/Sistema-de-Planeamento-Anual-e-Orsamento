<?php

namespace App\Services;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Compressed, verified MySQL backups with retention.
 *
 * The dump is streamed through gzip into "<name>.partial" and only renamed to its
 * final name after the file has been read back and the dump's completion marker
 * found, so a failed or truncated run can never look like a good backup.
 *
 * The dumper is injectable (a callable that receives a "write chunk" callback) so
 * the logic is testable without a MySQL client.
 */
class DatabaseBackup
{
    public const COMPLETE_MARKER = '-- Dump completed';

    public function __construct(private ?Closure $dumper = null) {}

    /** @return array{path: string, bytes: int, pruned: int, copy: ?string} */
    public function run(): array
    {
        $dir = config('backup.path');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create the backup directory: {$dir}");
        }

        $final = $dir.DIRECTORY_SEPARATOR.config('backup.prefix').date('Ymd_His').'.sql.gz';
        $partial = $final.'.partial';

        $gz = gzopen($partial, 'wb9');
        if ($gz === false) {
            throw new RuntimeException('Cannot write to the backup directory.');
        }

        try {
            ($this->dumper ?? fn (callable $write) => $this->mysqldump($write))(fn (string $chunk) => gzwrite($gz, $chunk));
            gzclose($gz);
            $gz = null; // closed: the failure path below must not close it twice
            $this->verify($partial);
        } catch (\Throwable $e) {
            if ($gz) {
                gzclose($gz);
            }
            @unlink($partial);

            throw $e;
        }

        rename($partial, $final);

        return ['path' => $final, 'bytes' => filesize($final), 'pruned' => $this->prune(), 'copy' => $this->copyAway($final)];
    }

    /** Copy the verified backup to the second place, if one is configured, and check the copy is whole. */
    private function copyAway(string $file): ?string
    {
        $dir = config('backup.copy_to');
        if (blank($dir)) {
            return null;
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create the backup copy directory: {$dir}");
        }
        $target = rtrim($dir, '\/').DIRECTORY_SEPARATOR.basename($file);
        if (! @copy($file, $target) || filesize($target) !== filesize($file)) {
            @unlink($target);

            throw new RuntimeException("The backup could not be copied to {$dir}; the backup on this server is kept.");
        }
        $this->pruneIn($dir);

        return $target;
    }

    /** Delete backups older than the retention period; returns how many were removed. */
    public function prune(): int
    {
        return $this->pruneIn(config('backup.path'));
    }

    private function pruneIn(string $dir): int
    {
        $limit = time() - config('backup.keep_days') * 86400;
        $removed = 0;
        foreach (glob(rtrim($dir, '\/').DIRECTORY_SEPARATOR.config('backup.prefix').'*.sql.gz') ?: [] as $file) {
            if (filemtime($file) < $limit && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** Read the whole gzip stream back and confirm mysqldump finished. */
    private function verify(string $file): void
    {
        $gz = gzopen($file, 'rb');
        $tail = '';
        $bytes = 0;
        while ($gz && ! gzeof($gz)) {
            $chunk = gzread($gz, 1 << 20);
            if ($chunk === false) {
                break;
            }
            $bytes += strlen($chunk);
            $tail = substr($tail.$chunk, -512);
        }
        $gz && gzclose($gz);

        if ($bytes === 0 || ! str_contains($tail, self::COMPLETE_MARKER)) {
            throw new RuntimeException('The backup failed verification (empty or incomplete dump); it was discarded.');
        }
    }

    private function mysqldump(callable $write): void
    {
        $c = config('database.connections.'.config('database.default'));
        if (($c['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('Backups are implemented for MySQL only.');
        }

        $process = new Process([
            config('backup.mysqldump'),
            '--single-transaction', '--routines', '--triggers', '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'],
            $c['database'],
        ], null, ['MYSQL_PWD' => (string) ($c['password'] ?? '')]); // password via environment, never on the command line

        $process->setTimeout(config('backup.timeout'));
        $stderr = '';
        $process->run(function (string $type, string $buffer) use ($write, &$stderr) {
            $type === Process::OUT ? $write($buffer) : $stderr .= $buffer;
        });

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim(preg_replace('/\s+/', ' ', $stderr)));
        }
    }
}
