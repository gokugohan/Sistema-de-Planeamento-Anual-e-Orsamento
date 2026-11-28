<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One kind of error the application met (the same exception at the same place), with how often and when. */
class ErrorReport extends Model
{
    protected $fillable = [
        'fingerprint', 'class', 'message', 'file', 'line', 'url', 'method', 'user_id', 'app_version', 'trace',
        'occurrences', 'first_seen_at', 'last_seen_at', 'notified_at', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'notified_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
