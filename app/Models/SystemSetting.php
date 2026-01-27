<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'type', 'description'];

    public static function get($key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        if (!$setting)
            return $default;

        // If it looks like a boolean (1, 0, true, false as strings), treat it as such
        if ($setting->type === 'boolean' || in_array(strtolower((string) $setting->value), ['1', '0', 'true', 'false', 'on', 'off', 'yes', 'no'])) {
            return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
        }

        return match ($setting->type) {
            'integer' => (int) $setting->value,
            'json' => json_decode($setting->value, true),
            default => $setting->value,
        };
    }

    public static function set($key, $value, $type = 'string', $description = null)
    {
        $val = match ($type) {
            'json' => json_encode($value),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
        };

        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $val, 'type' => $type, 'description' => $description]
        );
    }
}
