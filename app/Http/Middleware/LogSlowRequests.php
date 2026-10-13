<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every request slower than config('paa.slow_request_ms') with its route, number of queries
 * and time spent in SQL, so slow pages are found from real use instead of guessed. Livewire updates
 * (POST /livewire/update) are logged too, with the component names they carry. Nothing is logged for
 * fast requests, and nothing about the request body or the user beyond the id.
 */
class LogSlowRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $threshold = (int) config('paa.slow_request_ms', 0);
        if ($threshold <= 0) {
            return $next($request);
        }

        $started = microtime(true);
        $queries = 0;
        $sqlMs = 0.0;
        DB::listen(function ($query) use (&$queries, &$sqlMs) {
            $queries++;
            $sqlMs += $query->time;
        });

        $response = $next($request);

        $ms = (microtime(true) - $started) * 1000;
        if ($ms >= $threshold) {
            Log::channel(config('paa.slow_request_channel'))->warning('Slow request', [
                'ms' => round($ms),
                'sql_ms' => round($sqlMs),
                'queries' => $queries,
                'method' => $request->method(),
                'route' => $request->route()?->getName() ?? $request->path(),
                'components' => $this->components($request),
                'status' => $response->getStatusCode(),
                'user_id' => $request->user()?->id,
            ]);
        }

        return $response;
    }

    /** Names of the Livewire components an update request touches (the payload is never logged). */
    private function components(Request $request): ?array
    {
        if (! $request->is('livewire/update')) {
            return null;
        }

        return collect($request->input('components', []))
            ->map(fn ($c) => json_decode($c['snapshot'] ?? '{}', true)['memo']['name'] ?? null)
            ->filter()->values()->all() ?: null;
    }
}
