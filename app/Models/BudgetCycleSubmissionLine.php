<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A frozen budget line exactly as it was submitted to the CRO. */
class BudgetCycleSubmissionLine extends Model
{
    protected $fillable = [
        'budget_cycle_id', 'activity_id', 'line_item_id', 'activity_description',
        'directorate_code', 'program_code', 'class_code', 'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function cycle()
    {
        return $this->belongsTo(BudgetCycle::class, 'budget_cycle_id');
    }
}
