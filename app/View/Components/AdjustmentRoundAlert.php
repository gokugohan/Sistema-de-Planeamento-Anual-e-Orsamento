<?php

namespace App\View\Components;

use App\Models\AdjustmentRound;
use App\Services\PlanningWindow;
use App\Support\FiscalYear;
use App\Support\PlanOptions;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * One line while an adjustment round is open: for those who see every plan, how many directorates have confirmed;
 * for a directorate in the round, its target and whether it has confirmed. Two small queries at most.
 */
class AdjustmentRoundAlert extends Component
{
    public ?string $text = null;

    public bool $urgent = false;

    public function __construct(?int $year = null)
    {
        $user = auth()->user();
        if (! $user || ! PlanOptions::current()->budgetDecisions || ! ($round = AdjustmentRound::openFor($year ?? FiscalYear::current()))) {
            return;
        }
        $date = $round->closes_on->format('d/m/Y');

        if ($user->isAdministrator() || $user->can('view_all_paa')) {
            $all = $round->units()->count();
            $done = $round->units()->whereNotNull('confirmed_at')->count();
            $this->text = __('adjustment round open until :date: :n of :m directorates have confirmed their adjustment.', ['date' => $date, 'n' => $done, 'm' => $all]);
            $this->urgent = $round->closes_on->isPast() && $done < $all;

            return;
        }
        if (! $user->directorate_id) {
            return;
        }
        $mine = $round->units()->where('directorate_id', app(PlanningWindow::class)->rootOf((int) $user->directorate_id))->first();
        if (! $mine || $mine->confirmed_at) {
            return;
        }
        $this->text = (float) $mine->target > 0
            ? __('adjustment round open until :date: your directorate is asked to reduce $ :amount. Choose the activities to revise.', ['date' => $date, 'amount' => number_format((float) $mine->target, 0)])
            : __('adjustment round open until :date: your directorate may revise its activities.', ['date' => $date]);
        $this->urgent = $round->closes_on->isPast();
    }

    public function render(): View
    {
        return view('components.adjustment-round-alert');
    }
}
