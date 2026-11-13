<?php

namespace App\Models;

use App\Support\AppVersion;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'action', 'user_id', 'auditable_type', 'auditable_id', 'description',
        'metadata', 'old_values', 'new_values', 'ip_address', 'user_agent', 'app_version',
    ];

    protected static function booted(): void
    {
        // which build of the application made the change
        static::creating(fn (AuditLog $log) => $log->app_version ??= AppVersion::build());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }
}
