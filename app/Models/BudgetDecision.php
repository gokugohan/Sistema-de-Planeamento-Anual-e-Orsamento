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

    /** Where a decision comes from, in the order of the year. */
    public const STAGES = [
        'committee' => 'Budget review committee',
        'council' => 'Council of Ministers',
        'law' => 'State Budget law',
        'supplementary' => 'Supplementary budget',
        'other' => 'Other decision',
    ];

    protected $fillable = ['fiscal_year', 'stage', 'decided_on', 'reference', 'note', 'amounts', 'total', 'plan_amounts', 'plan_total', 'plan_baseline_id', 'recorded_by'];

    protected $casts = ['decided_on' => 'date', 'amounts' => 'array', 'plan_amounts' => 'array', 'total' => 'decimal:2', 'plan_total' => 'decimal:2'];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function baseline()
    {
        return $this->belongsTo(PlanBaseline::class, 'plan_baseline_id');
    }

    public function stageLabel(): string
    {
        return __(self::STAGES[$this->stage] ?? 'Other decision');
    }
}
