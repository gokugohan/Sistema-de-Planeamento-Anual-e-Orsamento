<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemSetting extends Model
{
    use \App\Models\Concerns\Auditable;
    use HasFactory;

    protected $fillable = ['key', 'value', 'type', 'description'];

    private const CACHE_KEY = 'system_settings.all';

    /** Per-request memo of decoded values */
    private static array $cache = [];

    /** Per-request memo of raw rows (key => [value, type]) */
    private static ?array $rows = null;

    /** Flush all caches (call after any write) */
    public static function flushCache(): void
    {
        self::$cache = [];
        self::$rows = null;
        \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY);
    }

    /** Load every setting with a single query, shared across requests. */
    private static function rows(): array
    {
        return self::$rows ??= \Illuminate\Support\Facades\Cache::remember(
            self::CACHE_KEY,
            3600,
            fn () => self::query()->get(['key', 'value', 'type'])
                ->mapWithKeys(fn ($r) => [$r->key => ['value' => $r->value, 'type' => $r->type]])
                ->all()
        );
    }

    public static function get($key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            $value = self::$cache[$key];
            return $value === null ? $default : $value;
        }

        $row = self::rows()[$key] ?? null;

        if ($row === null) {
            self::$cache[$key] = null;
            return $default;
        }

        // If it looks like a boolean (1, 0, true, false as strings), treat it as such
        if ($row['type'] === 'boolean' || in_array(strtolower((string) $row['value']), ['1', '0', 'true', 'false', 'on', 'off', 'yes', 'no'])) {
            return self::$cache[$key] = filter_var($row['value'], FILTER_VALIDATE_BOOLEAN);
        }

        return self::$cache[$key] = match ($row['type']) {
            'integer' => (int) $row['value'],
            'json' => json_decode($row['value'], true),
            default => $row['value'],
        };
    }

    /**
     * Get a localized string from a JSON setting whose value is {"en":…,"pt":…,"tet":…}.
     * Falls back to English, then to $default.
     *
     * Example key: 'app_brand_name'  →  stored value: {"en":"Ministry","pt":"Ministério","tet":"Ministériu"}
     */
    public static function getLocalized(string $key, $default = '', ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $data = static::get($key);

        if (is_array($data)) {
            return (string) ($data[$locale] ?? $data['en'] ?? $default);
        }

        return (string) ($default ?? '');
    }

    public static function set($key, $value, $type = 'string', $description = null)
    {
        $val = match ($type) {
            'json' => json_encode($value),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
        };

        $result = self::updateOrCreate(
            ['key' => $key],
            ['value' => $val, 'type' => $type, 'description' => $description]
        );

        self::flushCache();

        return $result;
    }
}
