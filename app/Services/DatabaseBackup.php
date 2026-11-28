<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\InputStream;
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

    /**
     * @param  Closure|null  $dumper  receives a "write chunk" callback and writes the dump through it
     * @param  Closure|null  $loader  receives (database, "read chunks" generator) and loads the dump into that database
     */
    public function __construct(private ?Closure $dumper = null, private ?Closure $loader = null) {}

    /** @return Collection<int, array{name: string, bytes: int, time: int}> the backups on this server, newest first */
    public function list(): Collection
    {
        return collect(glob(rtrim(config('backup.path'), '\/').DIRECTORY_SEPARATOR.config('backup.prefix').'*.sql.gz') ?: [])
            ->map(fn ($f) => ['name' => basename($f), 'bytes' => (int) filesize($f), 'time' => (int) filemtime($f)])
            ->sortByDesc('name')->values();
    }

    /** The full path of a backup named in a request: only a backup file of this folder, never a path elsewhere. */
    public function file(string $name): string
    {
        $prefix = preg_quote(config('backup.prefix'), '/');
        abort_unless(preg_match('/^'.$prefix.'\d{8}_\d{6}\.sql\.gz$/', $name) === 1, 404);
        $path = rtrim(config('backup.path'), '\/').DIRECTORY_SEPARATOR.$name;
        abort_unless(is_file($path), 404);

        return $path;
    }

    /**
     * Prove a backup can be restored: load it into a temporary database beside the live one, count the rows of every
     * table in both, then drop the temporary database. The live database is never written.
     *
     * @return array{tables: int, rows: int, live_rows: int, missing: array<int, string>, seconds: float}
     */
    public function restoreCheck(string $file): array
    {
        $live = (string) config('database.connections.'.config('database.default').'.database');
        $temp = substr($live, 0, 40).'_restore_check';
        $started = microtime(true);
        $chunks = function () use ($file) {
            $gz = gzopen($file, 'rb');
            if ($gz === false) {
                throw new RuntimeException('The backup cannot be read.');
            }
            try {
                while (! gzeof($gz)) {
                    yield (string) gzread($gz, 1 << 20);
                }
            } finally {
                gzclose($gz);
            }
        };

        DB::statement("DROP DATABASE IF EXISTS `{$temp}`");
        DB::statement("CREATE DATABASE `{$temp}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            ($this->loader ?? fn (string $db, iterable $read) => $this->mysqlLoad($db, $read))($temp, $chunks());

            $count = fn (string $db) => collect(DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = ?', [$db, 'BASE TABLE']))
                ->mapWithKeys(fn ($r) => [$r->t => (int) DB::selectOne("SELECT COUNT(*) AS n FROM `{$db}`.`{$r->t}`")->n]);
            $restored = $count($temp);
            $current = $count($live);
        } finally {
            DB::statement("DROP DATABASE IF EXISTS `{$temp}`");
        }

        if ($restored->isEmpty()) {
            throw new RuntimeException('The backup restored no tables.');
        }

        return [
            'tables' => $restored->count(), 'rows' => $restored->sum(), 'live_rows' => $current->sum(),
            // tables the live database has and the backup does not: a backup taken before a migration, or a broken one
            'missing' => $current->keys()->diff($restored->keys())->values()->all(),
            'seconds' => round(microtime(true) - $started, 1),
        ];
    }

    /** The client as configured, or its MariaDB name where only that is installed (newer MariaDB clients drop the mysql* names). */
    private static function binary(string $configured, string $mariadb): string
    {
        if (str_contains($configured, '/') || str_contains($configured, '\\')) {
            return $configured; // a full path was given: use it as it is
        }
        $finder = new ExecutableFinder;

        return $finder->find($configured) ? $configured : ($finder->find($mariadb) ?? $configured);
    }

    private function mysqlLoad(string $database, iterable $read): void
    {
        $c = config('database.connections.'.config('database.default'));
        $input = new InputStream;
        $process = new Process([
            self::binary(config('backup.mysql'), 'mariadb'), '--default-character-set=utf8mb4',
            '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], $database,
        ], null, ['MYSQL_PWD' => (string) ($c['password'] ?? '')]);
        $process->setInput($input);
        $process->setTimeout(config('backup.timeout'));
        $process->start();
        foreach ($read as $chunk) {
            $input->write($chunk);
        }
        $input->close();
        $process->wait();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The backup could not be loaded: '.trim($process->getErrorOutput() ?: 'exit code '.$process->getExitCode()));
        }
    }

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
            self::binary(config('backup.mysqldump'), 'mariadb-dump'),
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
