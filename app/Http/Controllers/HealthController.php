<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * GET /health: a monitoring endpoint (JSON, no secrets).
 *
 * status "ok"        everything works
 *        "degraded"  works, but needs attention (failed jobs, low disk space, stale backup)
 *        "down"      the database (or storage) is unavailable → HTTP 503
 *
 * Distinct from Laravel's /up, which only says the framework booted.
 */
class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'queue' => $this->queue(),
            'disk' => $this->disk(),
            'backup' => $this->backup(),
        ];

        $critical = ['database', 'storage'];
        $down = collect($critical)->contains(fn ($c) => $checks[$c]['status'] === 'fail');
        $warn = collect($checks)->contains(fn ($c) => $c['status'] === 'warn' || $c['status'] === 'fail');

        $status = $down ? 'down' : ($warn ? 'degraded' : 'ok');

        return response()->json([
            'status' => $status,
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $down ? 503 : 200, ['Cache-Control' => 'no-store']);
    }

    private function database(): array
    {
        try {
            $t = microtime(true);
            DB::select('select 1');

            return ['status' => 'ok', 'ms' => (int) round((microtime(true) - $t) * 1000)];
        } catch (\Throwable) {
            return ['status' => 'fail'];
        }
    }

    private function cache(): array
    {
        try {
            $key = 'health:'.bin2hex(random_bytes(4));
            Cache::put($key, '1', 10);
            $ok = Cache::get($key) === '1';
            Cache::forget($key);

            return ['status' => $ok ? 'ok' : 'warn'];
        } catch (\Throwable) {
            return ['status' => 'warn'];
        }
    }

    private function storage(): array
    {
        try {
            $path = 'health-'.bin2hex(random_bytes(4)).'.tmp';
            Storage::disk('local')->put($path, 'ok');
            $ok = Storage::disk('local')->get($path) === 'ok';
            Storage::disk('local')->delete($path);

            return ['status' => $ok ? 'ok' : 'fail'];
        } catch (\Throwable) {
            return ['status' => 'fail'];
        }
    }

    /** Pending and failed jobs: notifications are queued, so a stuck queue silently stops them. */
    private function queue(): array
    {
        try {
            $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;
            $stuck = $oldest !== null && (time() - (int) $oldest) > 900; // waiting > 15 min: no worker running

            return ['status' => ($failed > 0 || $stuck) ? 'warn' : 'ok', 'pending' => $pending, 'failed' => $failed, 'stuck' => $stuck];
        } catch (\Throwable) {
            return ['status' => 'warn'];
        }
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if (! $free || ! $total) {
            return ['status' => 'warn'];
        }
        $pct = round($free / $total * 100, 1);

        return ['status' => $pct < 10 ? 'warn' : 'ok', 'free_percent' => $pct];
    }

    /** The newest backup file should be younger than the configured limit (default 36 h). */
    private function backup(): array
    {
        if (! config('backup.enabled')) {
            return ['status' => 'ok', 'enabled' => false];
        }

        $dir = config('backup.path');
        $files = is_dir($dir) ? glob($dir.DIRECTORY_SEPARATOR.config('backup.prefix').'*.sql.gz') : [];
        if (! $files) {
            return ['status' => 'warn', 'enabled' => true, 'last' => null];
        }
        $age = (time() - max(array_map('filemtime', $files))) / 3600;

        return ['status' => $age > (float) config('backup.max_age_hours') ? 'warn' : 'ok', 'enabled' => true, 'age_hours' => round($age, 1)];
    }
}
