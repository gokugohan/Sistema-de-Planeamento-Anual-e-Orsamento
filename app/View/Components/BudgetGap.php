<?php

namespace App\View\Components;

use App\Services\BudgetDecisions;
use App\Support\FiscalYear;
use App\Support\PlanOptions;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * One line on the dashboard when the plan in force differs from the latest budget decision (an optional rule), so
 * that a cut or an increase decided outside the institution is not missed. Shown only to those who see every plan.
 */
class BudgetGap extends Component
{
    /** @var array{difference: float, decided: float, in_force: float, stage: string}|null */
    public ?array $gap = null;

    public int $forYear;

    public function __construct(?int $year = null)
    {
        $this->forYear = $year ?? FiscalYear::current();
        $user = auth()->user();
        if (! $user || ! PlanOptions::current()->budgetDecisions || ! ($user->isAdministrator() || $user->can('view_all_paa'))) {
            return;
        }
        $gap = app(BudgetDecisions::class)->gap($this->forYear);
        // a difference under one dollar is rounding, not a decision to act on
        if ($gap && abs($gap['difference']) >= 1) {
            $this->gap = ['difference' => $gap['difference'], 'decided' => $gap['decided'], 'in_force' => $gap['in_force'], 'stage' => $gap['decision']->stageLabel()];
        }
    }

    public function render(): View
    {
        return view('components.budget-gap');
    }
}
