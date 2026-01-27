<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{

    protected $fillable = ['action', 'user_id', 'description', 'metadata'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
