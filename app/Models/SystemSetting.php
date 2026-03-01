<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'type', 'description'];

    /** In-memory cache for the current request */
    private static array $cache = [];

    /** Flush the cache (call after set()) */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    public static function get($key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            $value = self::$cache[$key];
            return $value === null ? $default : $value;
        }

        $setting = self::where('key', $key)->first();

        if (!$setting) {
            self::$cache[$key] = null;
            return $default;
        }

        // If it looks like a boolean (1, 0, true, false as strings), treat it as such
        if ($setting->type === 'boolean' || in_array(strtolower((string) $setting->value), ['1', '0', 'true', 'false', 'on', 'off', 'yes', 'no'])) {
            $result = filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
            self::$cache[$key] = $result;
            return $result;
        }

        $result = match ($setting->type) {
            'integer' => (int) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };

        self::$cache[$key] = $result;
        return $result;
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

        unset(self::$cache[$key]);

        return $result;
    }
}
