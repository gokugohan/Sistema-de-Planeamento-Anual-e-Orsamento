<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One approved version of an activity: a copy of what was approved, kept as it was. */
class ActivityVersion extends Model
{
    protected $fillable = ['activity_id', 'version', 'snapshot', 'total', 'reason', 'approved_by', 'approved_at'];

    protected $casts = ['snapshot' => 'array', 'approved_at' => 'datetime', 'total' => 'decimal:2'];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
