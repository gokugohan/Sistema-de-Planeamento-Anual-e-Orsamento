<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An activity of an adjustment round: one revised for it, with the amount it had in force when its revision started,
 * or a new one (is_new) created while the round let its directorate add activities.
 */
class AdjustmentRoundActivity extends Model
{
    protected $fillable = ['adjustment_round_id', 'activity_id', 'is_new', 'total_before', 'added_by'];

    protected $casts = ['total_before' => 'decimal:2', 'is_new' => 'boolean'];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
