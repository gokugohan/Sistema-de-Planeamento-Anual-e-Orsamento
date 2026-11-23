<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * The distribution of one budget decision among the institution's top-level units, by economic category (an optional
 * rule, PlanOptions::budgetDistribution). The budget unit prepares it, the head of the institution approves it by an
 * act (a despacho) whose reference is recorded; the approved distribution of the latest decision is the one in force,
 * an earlier one is kept as superseded. Its status is written only by App\Services\BudgetDistributions.
 */
class BudgetDistribution extends Model
{
    use Auditable;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const SUPERSEDED = 'superseded';

    public const STATUS_WORDS = [
        self::DRAFT => 'Draft',
        self::SUBMITTED => 'Waiting for approval',
        self::APPROVED => 'In force',
        self::SUPERSEDED => 'Superseded',
    ];

    protected $fillable = [
        'budget_decision_id', 'fiscal_year', 'version', 'status', 'act_reference', 'act_date', 'note', 'return_note',
        'prepared_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'on_behalf',
    ];

    protected $casts = ['act_date' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'on_behalf' => 'boolean'];

    public function decision()
    {
        return $this->belongsTo(BudgetDecision::class, 'budget_decision_id');
    }

    public function lines()
    {
        return $this->hasMany(BudgetDistributionLine::class);
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SUBMITTED], true);
    }

    public function statusWord(): string
    {
        return __(self::STATUS_WORDS[$this->status] ?? 'Draft');
    }

    /** @return array<int, array<int, float>> directorate id (0 = kept by the institution) => economic classification id => amount */
    public function matrix(): array
    {
        $m = [];
        foreach ($this->lines as $l) {
            $m[(int) $l->directorate_id][(int) $l->economic_classification_id] = (float) $l->amount;
        }

        return $m;
    }
}
