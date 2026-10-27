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

    protected $fillable = ['fiscal_year', 'budget_decision_id', 'closes_on', 'instructions', 'allows_new_activities', 'opened_by', 'closed_at', 'closed_by', 'closing_note'];

    protected $casts = ['closes_on' => 'date', 'closed_at' => 'datetime', 'allows_new_activities' => 'boolean'];

    /** @var array<int, array<int, int>> year => directorates that may add new activities now (memo for the request) */
    private static array $takingNew = [];

    protected static function booted(): void
    {
        static::saved(fn () => self::$takingNew = []);
        static::deleted(fn () => self::$takingNew = []);
    }

    public static function forgetMemo(): void
    {
        self::$takingNew = [];
    }

    /**
     * May this directorate (a top-level unit) add new activities to the plan of the year now, although its plan is
     * finalized or the calendar is closed? Only while a round that allows it is open and the directorate has not
     * confirmed its adjustment. One query per year and request.
     */
    public static function takesNewActivities(int $year, int $rootId): bool
    {
        if (! \App\Support\PlanOptions::current()->budgetDecisions) {
            return false;
        }
        if (! isset(self::$takingNew[$year]) || app()->runningUnitTests()) {
            self::$takingNew[$year] = AdjustmentRoundUnit::query()
                ->join('adjustment_rounds as r', 'r.id', '=', 'adjustment_round_units.adjustment_round_id')
                ->where('r.fiscal_year', $year)->whereNull('r.closed_at')->where('r.allows_new_activities', true)
                ->whereNull('adjustment_round_units.confirmed_at')
                ->pluck('adjustment_round_units.directorate_id')->map(fn ($id) => (int) $id)->all();
        }

        return in_array($rootId, self::$takingNew[$year], true);
    }

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
