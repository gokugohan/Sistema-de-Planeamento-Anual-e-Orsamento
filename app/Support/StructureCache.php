<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Things that are read on many pages and change rarely: the unit tree, the roles, who belongs to a unit. Each is kept in
 * the cache under a key that carries a version; the version changes whenever a unit, a user, a role, an approver list or an
 * interim appointment is saved or deleted (see AppServiceProvider), so nobody ever reads a stale list.
 */
final class StructureCache
{
    private const VERSION_KEY = 'structure_version';

    public static function version(): string
    {
        return (string) Cache::get(self::VERSION_KEY, '1');
    }

    public static function bump(): void
    {
        Cache::forever(self::VERSION_KEY, (string) hrtime(true));
    }

    /** @template T @param  Closure(): T  $callback @return T */
    public static function remember(string $key, int $seconds, Closure $callback): mixed
    {
        return Cache::remember($key.':'.self::version(), $seconds, $callback);
    }
}
