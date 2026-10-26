<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * After a budget decision, the directorates revise the activities they choose so that the plan follows it. A round is
 * open until an administrator closes it; its deadline is a date to work to, not a lock.
 */
class AdjustmentRound extends Model
{
    use Auditable;

    protected $fillable = ['fiscal_year', 'budget_decision_id', 'closes_on', 'instructions', 'opened_by', 'closed_at', 'closed_by', 'closing_note'];

    protected $casts = ['closes_on' => 'date', 'closed_at' => 'datetime'];

    public function decision()
    {
        return $this->belongsTo(BudgetDecision::class, 'budget_decision_id');
    }

    public function units()
    {
        return $this->hasMany(AdjustmentRoundUnit::class);
    }

    public function activities()
    {
        return $this->hasMany(AdjustmentRoundActivity::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** The open round of a year, if there is one. */
    public static function openFor(int $year): ?self
    {
        return self::where('fiscal_year', $year)->whereNull('closed_at')->latest('id')->first();
    }
}
