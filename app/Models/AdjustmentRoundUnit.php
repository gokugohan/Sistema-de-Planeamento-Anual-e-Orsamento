<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A directorate in an adjustment round: how much it is asked to reduce, its plan when the round opened, its confirmation. */
class AdjustmentRoundUnit extends Model
{
    use Auditable;

    protected $fillable = ['adjustment_round_id', 'directorate_id', 'target', 'plan_at_start', 'confirmed_at', 'confirmed_by', 'remark'];

    protected $casts = ['target' => 'decimal:2', 'plan_at_start' => 'decimal:2', 'confirmed_at' => 'datetime'];

    protected static function booted(): void
    {
        // confirming ends the directorate's new activities for the round
        static::saved(fn () => AdjustmentRound::forgetMemo());
    }

    public function round()
    {
        return $this->belongsTo(AdjustmentRound::class, 'adjustment_round_id');
    }

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
