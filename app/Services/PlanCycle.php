<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\ReportingWindow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Where the annual action plan of one fiscal year stands: the window to submit it, the approval workflow, the
 * end of planning, the budget it receives, and the monthly and quarterly reporting with their windows. Counts
 * are limited to what the user may see. Everything is a handful of grouped queries; the lite form (for the
 * dashboard) leaves out the month-by-month reporting and the budget figures it does not show.
 */
class PlanCycle
{
    public function __construct(private ReportingWindows $windows, private PlanningWindow $planning) {}

    /** @return array<string, mixed> */
    public function build(User $user, int $year, bool $lite = false): array
    {
        $scope = $this->scopeFor($user);
        $activities = fn () => Activity::query()->where('fiscal_year', $year)->when($scope !== null, fn ($q) => $q->whereIn('directorate_id', $scope ?: [0]));

        $byStatus = $activities()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $total = array_sum($byStatus);
        $approved = $byStatus[Activity::STATUS_APPROVED] ?? 0;
        $pending = ($byStatus[Activity::STATUS_SUBMITTED] ?? 0) + ($byStatus[Activity::STATUS_REVIEWED] ?? 0)
            + ($byStatus[Activity::STATUS_VALIDATED] ?? 0) + ($byStatus[Activity::STATUS_WAITING_APPROVAL] ?? 0);
        $drafts = ($byStatus[Activity::STATUS_DRAFT] ?? 0) + ($byStatus[Activity::STATUS_REJECTED] ?? 0);

        $planWindow = $this->windowState($year, ReportingWindows::PLAN, 1);
        $state = $this->planning->state($year);

        $envelope = FiscalEnvelope::where('fiscal_year', $year)->first(['status', 'total_amount']);
        $received = $envelope && in_array($envelope->status, [FiscalEnvelope::STATUS_APPROVED, FiscalEnvelope::STATUS_CLOSED], true);
        $approvedPlan = (float) AdjustedBudget::query()->whereHas('lineItem.activity', fn ($q) => $q->where('fiscal_year', $year)->when($scope !== null, fn ($w) => $w->whereIn('directorate_id', $scope ?: [0])))->sum('approved_amount');
        $planned = $lite ? 0.0 : (float) DB::table('line_items as li')->join('activities as a', 'a.id', '=', 'li.activity_id')
            ->where('a.fiscal_year', $year)->where('a.status', Activity::STATUS_APPROVED)->when($scope !== null, fn ($q) => $q->whereIn('a.directorate_id', $scope ?: [0]))->sum('li.estimated_amount');

        $reporting = $lite ? null : $this->reporting($year, $scope);

        $steps = $this->steps([
            'planWindow' => $planWindow, 'ended' => $state['ended'], 'pending' => $pending, 'drafts' => $drafts, 'total' => $total, 'approved' => $approved,
            'envelopeStatus' => $envelope?->status, 'distributed' => $approvedPlan > 0,
        ]);

        return [
            'year' => $year,
            'steps' => $steps,
            'plan_window' => $planWindow,
            'approval' => ['by_status' => $byStatus, 'total' => $total, 'approved' => $approved, 'pending' => $pending, 'drafts' => $drafts],
            'planning' => $state,
            'budget' => ['envelope' => $envelope?->total_amount !== null ? (float) $envelope->total_amount : null, 'received' => $received, 'approved_plan' => $approvedPlan, 'planned' => $planned],
            'reporting' => $reporting,
        ];
    }

    /**
     * The steps of the plan and which one is current, from a few facts: planWindow (as windowState gives it), ended,
     * pending, drafts, total, approved, envelopeStatus, distributed.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, array{key: string, label: string, state: string, detail: string}>
     */
    public function steps(array $f): array
    {
        $received = in_array($f['envelopeStatus'], [FiscalEnvelope::STATUS_APPROVED, FiscalEnvelope::STATUS_CLOSED], true);
        $stepDone = [
            'submission' => $f['ended'] !== null || $f['planWindow']['state'] === 'closed',
            'approval' => $f['total'] > 0 && $f['pending'] === 0 && $f['drafts'] === 0,
            'finalized' => $f['ended'] !== null,
            'budget' => $received,
            'distribution' => $received && $f['distributed'],
            'reporting' => $f['envelopeStatus'] === FiscalEnvelope::STATUS_CLOSED, // runs from the end of planning until the year is closed
        ];
        $steps = [];
        $currentFound = false;
        $details = [
            'submission' => match ($f['planWindow']['state']) {
                'none' => __('No dates set: planning is open until it is finalized.'),
                'upcoming' => __('Opens on :d', ['d' => $f['planWindow']['opens_on']->format('d/m/Y')]),
                'open' => __('Open until :d', ['d' => $f['planWindow']['closes_on']->format('d/m/Y')]),
                default => __('Closed on :d', ['d' => $f['planWindow']['closes_on']->format('d/m/Y')]),
            },
            'approval' => $f['total'] === 0 ? __('No activity yet') : __(':approved of :total activities approved', ['approved' => $f['approved'], 'total' => $f['total']]),
            'finalized' => $f['ended'] ? __('Planning has ended') : (($u = $f['planning']['units'] ?? []) && count($u) > 1 ? __(':n of :total directorates finalized', ['n' => count(array_filter($u, fn ($x) => $x['closed'])), 'total' => count($u)]) : ($f['pending'] > 0 ? trans_choice(':n activity still waiting for a decision|:n activities still waiting for a decision', $f['pending'], ['n' => $f['pending']]) : __('Ready to be finalized once everything is decided'))),
            'budget' => $received ? __('Received') : ($f['envelopeStatus'] ? __($f['envelopeStatus']) : __('Not registered yet')),
            'distribution' => ($received && $f['distributed']) ? __('Distributed over the approved plan') : __('Not distributed yet'),
            'reporting' => $f['envelopeStatus'] === FiscalEnvelope::STATUS_CLOSED ? __('The year is closed') : __('Monthly spending and quarterly indicators'),
        ];
        $labels = [
            'submission' => __('Plan submission'),
            'approval' => __('Approval'),
            'finalized' => __('Plan finalized'),
            'budget' => __('Budget received'),
            'distribution' => __('Distribution'),
            'reporting' => __('Reporting'),
        ];
        foreach ($labels as $key => $label) {
            $done = $stepDone[$key];
            $current = ! $done && ! $currentFound;
            $currentFound = $currentFound || $current;
            $steps[] = ['key' => $key, 'label' => $label, 'state' => $done ? 'done' : ($current ? 'current' : 'todo'), 'detail' => $details[$key]];
        }

        return $steps;
    }

    /** The unit ids the user's numbers cover, or null for every unit. @return array<int, int>|null */
    public function scopeFor(User $user): ?array
    {
        if ($user->isAdministrator() || $user->can('view_all_paa')) {
            return null;
        }
        $ids = $user->directorate_id ? (Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? []) : [];

        return array_values(array_unique(array_merge($ids, $user->directedUnitIds(), app(ApproverLists::class)->unitIdsOf($user))));
    }

    /** @return array{state: string, opens_on: ?\Carbon\CarbonInterface, closes_on: ?\Carbon\CarbonInterface, note: ?string} state: none | upcoming | open | closed */
    public function windowState(int $year, string $type, int $period): array
    {
        $w = ReportingWindow::where(['fiscal_year' => $year, 'period_type' => $type, 'period' => $period])->first();
        if (! $w) {
            return ['state' => 'none', 'opens_on' => null, 'closes_on' => null, 'note' => null];
        }
        $today = now()->startOfDay();

        return [
            'state' => $today->lt($w->opens_on) ? 'upcoming' : ($today->gt($w->closes_on) ? 'closed' : 'open'),
            'opens_on' => $w->opens_on, 'closes_on' => $w->closes_on, 'note' => $w->note,
        ];
    }

    /**
     * Month by month and quarter by quarter: the window and how much has been reported. A month counts the
     * approved activities with a budget that have a verified figure, and those with one still waiting.
     */
    private function reporting(int $year, ?array $scope): array
    {
        $windows = ReportingWindow::where('fiscal_year', $year)->whereIn('period_type', ['month', 'quarter'])->get()->groupBy('period_type');
        $today = now()->startOfDay();
        $stateOf = function ($w) use ($today) {
            return ! $w ? ['state' => 'none', 'opens_on' => null, 'closes_on' => null, 'note' => null] : [
                'state' => $today->lt($w->opens_on) ? 'upcoming' : ($today->gt($w->closes_on) ? 'closed' : 'open'),
                'opens_on' => $w->opens_on, 'closes_on' => $w->closes_on, 'note' => $w->note,
            ];
        };

        $withBudget = DB::table('activities as a')->join('line_items as li', 'li.activity_id', '=', 'a.id')->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')
            ->where('a.fiscal_year', $year)->where('a.status', Activity::STATUS_APPROVED)->when($scope !== null, fn ($q) => $q->whereIn('a.directorate_id', $scope ?: [0]));
        $due = (int) (clone $withBudget)->distinct()->count('a.id');

        $verified = (clone $withBudget)->join('budget_executions as be', 'be.line_item_id', '=', 'li.id')->where('be.status', BudgetExecution::VERIFIED)
            ->groupBy('be.month')->selectRaw('be.month, COUNT(DISTINCT a.id) AS n')->pluck('n', 'month');
        $waiting = (clone $withBudget)->join('budget_executions as be', 'be.line_item_id', '=', 'li.id')->where('be.status', BudgetExecution::SUBMITTED)
            ->groupBy('be.month')->selectRaw('be.month, COUNT(DISTINCT a.id) AS n')->pluck('n', 'month');

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['window' => $stateOf($windows->get('month', collect())->firstWhere('period', $m)), 'due' => $due, 'verified' => (int) ($verified[$m] ?? 0), 'waiting' => (int) ($waiting[$m] ?? 0)];
        }

        $quarters = [];
        for ($q = 1; $q <= 4; $q++) {
            $quarters[$q] = ['window' => $stateOf($windows->get('quarter', collect())->firstWhere('period', $q))];
        }

        return ['months' => $months, 'quarters' => $quarters, 'activities_due' => $due];
    }
}
