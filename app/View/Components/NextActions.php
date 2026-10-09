<?php

namespace App\View\Components;

use App\Models\Activity;
use App\Models\BudgetCycle;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\IndicatorProgress;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * "Needs your attention": the handful of things the signed-in user can act on
 * right now, each with a count and a direct link. Only counts, no heavy queries.
 */
class NextActions extends Component
{
    /** @var array<int, array{count: int, text: string, url: string, tone: string}> */
    public array $items = [];

    public function __construct()
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $approver = $user->hasAnyRole(['Admin', 'Minister']);
        $seesAll = $approver || $user->can('view_all_paa');

        $scope = null; // null = every unit
        if (!$seesAll) {
            $unit = $user->directorate_id ? Directorate::find($user->directorate_id) : null;
            $scope = $unit ? $unit->getSelfAndDescendantIds() : [0];
        }
        $activities = fn () => Activity::query()->when($scope !== null, fn ($q) => $q->whereIn('directorate_id', $scope));

        if ($user->can('create_activities') || $user->can('submit_paa')) {
            $drafts = $activities()->where('status', Activity::STATUS_DRAFT)->count();
            $this->add($drafts, trans_choice(':n draft activity not yet submitted|:n draft activities not yet submitted', $drafts, ['n' => $drafts]), route('activities.index'), 'amber');
        }

        if ($approver) {
            $review = $activities()->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED])->count();
            $this->add($review, trans_choice(':n activity waiting for technical review|:n activities waiting for technical review', $review, ['n' => $review]), route('planning.paa-approval'), 'indigo');

            $approval = $activities()->whereIn('status', [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL])->count();
            $this->add($approval, trans_choice(':n activity waiting for approval|:n activities waiting for approval', $approval, ['n' => $approval]), route('planning.paa-approval'), 'indigo');

            $progress = IndicatorProgress::where('status', 'Submitted')->count();
            $this->add($progress, trans_choice(':n progress report to verify|:n progress reports to verify', $progress, ['n' => $progress]), route('monitoring.approval'), 'indigo');
        }

        if ($user->hasAnyRole(['Admin', 'Minister'])) {
            $pending = FiscalEnvelope::where('status', FiscalEnvelope::STATUS_PENDING)->count();
            $this->add($pending, trans_choice(':n fiscal envelope waiting for approval|:n fiscal envelopes waiting for approval', $pending, ['n' => $pending]), route('budget.envelope.current'), 'rose');
        }

        if ($approver && $user->can('view_budget')) {
            $year = (int) date('Y') + 1;
            $approved = Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->count();
            $submitted = BudgetCycle::where('fiscal_year', $year)->whereNotNull('submitted_at')->exists();
            if ($approved > 0 && !$submitted) {
                $this->add($approved, __('approved activities for :year have not been recorded as submitted to the CRO', ['year' => $year]), route('planning.budget-cycle'), 'amber');
            }
        }
    }

    private function add(int $count, string $text, string $url, string $tone): void
    {
        if ($count > 0) {
            $this->items[] = compact('count', 'text', 'url', 'tone');
        }
    }

    public function render(): View
    {
        return view('components.next-actions');
    }
}
