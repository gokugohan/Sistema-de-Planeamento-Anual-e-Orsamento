<?php

namespace App\View\Components;

use App\Services\BudgetDistributions;
use App\Services\PlanningWindow;
use App\Support\FiscalYear;
use App\Support\PlanOptions;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * A directorate's share of the budget against its plan, category by category (an optional rule): on the unit's page,
 * and on the dashboard of people who belong to one directorate. Nothing when the budget is not distributed.
 */
class BudgetShare extends Component
{
    /** @var array{rows: array, share: float, planned: float, room: float, over: float}|null */
    public ?array $position = null;

    public ?string $unitCode = null;

    public int $forYear;

    public string $mode = PlanOptions::CEILING_WARN;

    public function __construct(?int $year = null, ?int $unit = null)
    {
        $this->forYear = $year ?? FiscalYear::current();
        $user = auth()->user();
        $unit ??= $user?->directorate_id;
        if (! $user || ! $unit || ! BudgetDistributions::on()) {
            return;
        }
        $root = app(PlanningWindow::class)->rootOf((int) $unit);
        $service = app(BudgetDistributions::class);
        $this->position = $service->position($this->forYear, $root);
        $this->unitCode = $service->units()->firstWhere('id', $root)?->code;
        $this->mode = PlanOptions::current()->ceilingMode;
    }

    public function render(): View
    {
        return view('components.budget-share');
    }
}
