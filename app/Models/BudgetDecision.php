<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * What was decided about the institution's budget for a year, by economic category (an optional rule). It is a
 * reference: it limits nothing and changes no activity. plan_amounts keeps the plan in force when it was recorded.
 */
class BudgetDecision extends Model
{
    use Auditable;

    /**
     * Where a decision comes from, in the order of the year (Decreto-Lei n.º 42/2025): the total of the institution's
     * budget title in the budget circular (art. 18-19), the Policy Review Committee (art. 20), the Council of Ministers,
     * the Government's proposal to Parliament, the State Budget law and a supplementary budget.
     */
    public const STAGES = [
        'ceiling' => 'Budget ceiling (circular)',
        'committee' => 'Budget review committee',
        'council' => 'Council of Ministers',
        'proposal' => 'Government proposal to Parliament',
        'law' => 'State Budget law',
        'supplementary' => 'Supplementary budget',
        'other' => 'Other decision',
    ];

    /**
     * The stages recorded, in the order of the year: the ceiling of the budget circular (provisional: the plans are
     * corrected to it), the State Budget law (the fiscal envelope) and the supplementary budgets that amend it. The other
     * stages of STAGES (committee, Council of Ministers, proposal, other) are kept only to show decisions recorded before.
     */
    public const ORDER = ['ceiling', 'law', 'supplementary'];

    /** How far in the year each stage is: a decision never goes back to an earlier rank. */
    public const RANK = ['ceiling' => 1, 'committee' => 1, 'council' => 1, 'proposal' => 1, 'law' => 2, 'supplementary' => 3];

    /** The row of each stage in the 'budget' dates of the calendar (fixed, so that dates saved earlier keep their stage). */
    public const PERIODS = ['ceiling' => 1, 'committee' => 2, 'council' => 3, 'proposal' => 4, 'law' => 5, 'supplementary' => 6];

    /** What each stage is, in one line, with its legal date, shown where a decision is recorded. */
    public const HINTS = [
        'ceiling' => 'The total for the institution in the budget circular of the Ministry of Finance, by 20 July (Decreto-Lei n.º 42/2025, art. 19). Provisional: the plans are corrected to it.',
        'law' => 'The amount in the State Budget law approved by Parliament, on the proposal the Government presents by 1 October: the fiscal envelope, in force from 1 January.',
        'supplementary' => 'A law of Parliament that amends the State Budget law during the year (orçamento retificativo, Lei n.º 3/2025, art. 36).',
    ];

    /**
     * The stages that make the final envelope: the State Budget law approved by Parliament, and the supplementary budgets
     * that amend it. The stages before the law are provisional: they guide the planning until the law sets the amount.
     */
    public const FINAL = ['law', 'supplementary'];

    /** @var array<int, string|false> year => date of its State Budget law, false without one (memo for the request) */
    private static array $lawDates = [];

    /** @var array<int, int> decision id => its number among the supplementary budgets of the year (memo for the request) */
    private static array $ordinals = [];

    protected $fillable = ['fiscal_year', 'stage', 'decided_on', 'reference', 'note', 'amounts', 'total', 'committed_amount', 'new_policy_space', 'plans_due_on', 'plan_amounts', 'plan_total', 'plan_baseline_id', 'recorded_by'];

    protected $casts = ['decided_on' => 'date', 'plans_due_on' => 'date', 'amounts' => 'array', 'plan_amounts' => 'array', 'total' => 'decimal:2', 'plan_total' => 'decimal:2', 'committed_amount' => 'decimal:2', 'new_policy_space' => 'decimal:2'];

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetMemo());
        static::deleted(fn () => self::forgetMemo());
    }

    public function distributions()
    {
        return $this->hasMany(BudgetDistribution::class);
    }

    /** The amount decided for one economic category. */
    public function amountFor(int $classId): float
    {
        return (float) ($this->amounts[(string) $classId] ?? 0);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function baseline()
    {
        return $this->belongsTo(PlanBaseline::class, 'plan_baseline_id');
    }

    /**
     * Is this decision part of the final envelope? The law and the supplementary budgets are; another decision is when it
     * comes after the law (a cut by the Ministry of Finance, for example), and provisional before it.
     */
    public function isFinal(): bool
    {
        if (in_array($this->stage, self::FINAL, true)) {
            return true;
        }
        if ($this->stage !== 'other') {
            return false;
        }
        $law = self::$lawDates[$this->fiscal_year] ??= (self::where('fiscal_year', $this->fiscal_year)->where('stage', 'law')->min('decided_on') ?? false);

        return $law !== false && $this->decided_on->toDateString() >= substr((string) $law, 0, 10);
    }

    public static function forgetMemo(): void
    {
        self::$lawDates = [];
        self::$ordinals = [];
    }

    /** The stage in words; from the second supplementary budget of a year on, with its number ("Supplementary budget 2"). */
    public function stageLabel(): string
    {
        $label = __(self::STAGES[$this->stage] ?? 'Other decision');
        if ($this->stage !== 'supplementary' || ! $this->exists) {
            return $label;
        }
        $n = self::$ordinals[$this->id] ??= self::where('fiscal_year', $this->fiscal_year)->where('stage', 'supplementary')
            ->where(fn ($q) => $q->where('decided_on', '<', $this->decided_on)->orWhere(fn ($q) => $q->where('decided_on', $this->decided_on)->where('id', '<=', $this->id)))
            ->count();

        return $n > 1 ? __(':stage :n', ['stage' => $label, 'n' => $n]) : $label;
    }
}
