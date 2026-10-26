<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An activity revised for an adjustment round, with the amount it had in force when its revision started. */
class AdjustmentRoundActivity extends Model
{
    protected $fillable = ['adjustment_round_id', 'activity_id', 'total_before', 'added_by'];

    protected $casts = ['total_before' => 'decimal:2'];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
