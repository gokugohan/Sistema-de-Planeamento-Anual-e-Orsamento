<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BudgetDistribution;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\LineItem;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Support\ApprovalRules;
use App\Support\FiscalYear;
use App\Support\Modules;
use Carbon\Carbon;

/**
 * What is waiting for one person, gathered in one place: activities to decide, execution figures to verify,
 * the plan to finalize, budget steps, and their own drafts and returned work. Only counts and a link each,
 * from grouped queries, so it is cheap enough for the dashboard as well as the Approvals page.
 *
 * Each item: group (decide | verify | plan | budget | late | mine), count, text, url, tone.
 */
class ApprovalInbox
{
    /** @return array<int, array{group: string, count: int, text: string, url: string, tone: string}> */
    public function items(User $user, ?int $year = null): array
    {
        // Two plans are alive at once: the one being prepared (its decisions) and the one being carried out (its reports,
        // and revisions of its approved activities). Without a year asked for, the inbox covers both.
        if ($year !== null || FiscalYear::pinned() !== null) {
            $planYear = $reportYear = $year ?? FiscalYear::current();
        } else {
            [$planYear, $reportYear] = [FiscalYear::planning(), FiscalYear::execution()];
        }
        $years = array_values(array_unique([$planYear, $reportYear]));
        $year = $planYear;
        $items = [];
        $add = function (string $group, int $count, string $text, string $url, string $tone, ?string $key = null) use (&$items) {
            if ($count > 0) {
                $items[] = compact('group', 'count', 'text', 'url', 'tone', 'key');
            }
        };

        $approver = $user->isAdministrator();
        $seesAll = $approver || $user->can('view_all_paa');
        $scope = null; // null = every unit
        if (! $seesAll) {
            $unit = $user->directorate_id ? Directorate::find($user->directorate_id) : null;
            $scope = $unit ? $unit->getSelfAndDescendantIds() : [0];
        }
        $activities = fn () => Activity::query()->whereIn('fiscal_year', $years)->when($scope !== null, fn ($q) => $q->whereIn('directorate_id', $scope));
        // what is reported on: the plan being carried out
        $reported = fn () => Activity::query()->where('fiscal_year', $reportYear)->when($scope !== null, fn ($q) => $q->whereIn('directorate_id', $scope));

        // ── activities to decide: first to be certified, then to be given the final approval; each opens its own queue
        if ($approver || $user->isDirector()) {
            $review = $activities()->where(fn ($q) => $this->toCertify($q, $scope === null ? null : $user))->count();
            $add('decide', $review, trans_choice(':n activity prepared, waiting to be certified|:n activities prepared, waiting to be certified', $review, ['n' => $review]), route('planning.paa-approval', ['queue' => 'review']), 'amber', 'review');
            $approval = $activities()->whereIn('status', [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL])->count();
            $add('decide', $approval, trans_choice(':n activity certified, waiting for the final approval|:n activities certified, waiting for the final approval', $approval, ['n' => $approval]), route('planning.paa-approval', ['queue' => 'approval']), 'indigo', 'approval');
        }

        // ── finance: submitted activities whose budget is waiting for confirmation (where the institution asks for it)
        if (ApprovalRules::current()->budgetConfirmation && ($user->isAdministrator() || $user->can('confirm_budget'))) {
            $policy = app(ActivityPolicy::class);
            $n = Activity::whereIn('fiscal_year', $years)->where('status', Activity::STATUS_SUBMITTED)->whereNull('budget_confirmed_at')
                ->limit(300)->get(['id', 'directorate_id'])->filter(fn ($a) => $policy->inScope($user, $a->directorate_id))->count();
            $add('decide', $n, trans_choice(':n activity waiting for its budget to be confirmed|:n activities waiting for their budget to be confirmed', $n, ['n' => $n]), route('approvals.budget'), 'amber', 'budget');
        }

        // ── named in an approval list: what is waiting at the step they were named for
        if (! $approver && ! $user->isDirector()) {
            $unitIds = app(ApproverLists::class)->unitIdsOf($user);
            if ($unitIds) {
                $workflow = app(ActivityWorkflow::class);
                $waiting = Activity::whereIn('fiscal_year', $years)->whereIn('directorate_id', $unitIds)
                    ->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL])
                    ->with('directorate.parent')->limit(300)->get()
                    ->filter(fn (Activity $a) => $workflow->next($user, $a) !== null || $workflow->canApprove($user, $a))->count();
                $add('decide', $waiting, trans_choice(':n activity waiting for your decision|:n activities waiting for your decision', $waiting, ['n' => $waiting]), route('activities.index'), 'indigo', 'listed');
            }
        }

        // ── execution figures to verify (never your own reports)
        $execution = Modules::on(Modules::EXECUTION); // the execution reporting module: without it nothing is reported
        $mayVerifyProgress = $execution && ($user->can('approve_progress_report') || $user->isDirector());
        if ($mayVerifyProgress || ($execution && $user->can('verify_spending'))) {
            $activityIds = $reported()->select('id');
            $money = BudgetExecution::where('status', BudgetExecution::SUBMITTED)->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id))
                ->whereIn('line_item_id', LineItem::whereIn('activity_id', $activityIds)->select('id'))->count();
            $add('verify', $money, trans_choice(':n executed amount to verify|:n executed amounts to verify', $money, ['n' => $money]), route('monitoring.report'), 'indigo', 'money');
        }
        if ($mayVerifyProgress) {
            $progress = IndicatorProgress::where('status', 'Submitted')->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id))
                ->whereIn('indicator_id', Indicator::whereIn('activity_id', $reported()->select('id'))->select('id'))->count();
            $add('verify', $progress, trans_choice(':n progress report to verify|:n progress reports to verify', $progress, ['n' => $progress]),
                $user->can('approve_progress_report') ? route('monitoring.approval') : route('monitoring.report'), 'indigo', 'progress');
        }

        // ── the plan: each directorate whose plan is ready for its head's approval (approve the plan: approve and finalize)
        $window = app(PlanningWindow::class);
        $readiness = $window->planReadinessOf($year, $window->decidesFor($user, $year)->all());
        $readyIds = array_keys(array_filter($readiness, fn ($r) => $r['ready']));
        $ready = $readyIds ? Directorate::whereIn('id', $readyIds)->orderBy('code')->pluck('code')->all() : [];
        if ($ready) {
            // one line for all of them, not one per directorate
            $add('plan', count($ready), count($ready) === 1
                ? __('The plan of :unit for :year is ready for your approval.', ['unit' => $ready[0], 'year' => $year])
                : __(':n directorates have their plan for :year ready for your approval: :units.', ['n' => count($ready), 'year' => $year, 'units' => implode(', ', $ready)]),
                route('approvals.index').'#plan', 'emerald');
        }

        // ── the distribution of the budget among the units (an optional rule): waiting for its approver, or returned to
        //    the budget unit that prepares it; an administrator is asked only when nobody holds the approver's role
        if (BudgetDistributions::on()) {
            $distributions = app(BudgetDistributions::class);
            $open = $distributions->openFor($year);
            $url = route('planning.budget-decisions', ['year' => $year, 'tab' => 'distribution']);
            if ($open?->status === BudgetDistribution::SUBMITTED && $distributions->canApprove($user, $open)
                && ($distributions->isApprover($user) || $distributions->approvers()->isEmpty())) {
                $add('plan', 1, __('The distribution of the budget of :year among the units is waiting for your approval.', ['year' => $year]), $url, 'emerald');
            }
            if ($open?->status === BudgetDistribution::DRAFT && $open->return_note && $distributions->canPrepare($user)) {
                $add('mine', 1, __('The distribution of the budget of :year was returned: see why and send it again.', ['year' => $year]), $url, 'rose');
            }
        }

        // ── reporting due: the units' monthly duty, in the running year
        if ($execution && $reportYear === (int) now()->year && $user->can('update_indicator_progress')) {
            $month = (int) now()->month;
            $last = $month - 1; // the month that has just ended is the one to have reported
            if ($last >= 1) {
                $due = $reported()->where('version', '>', 0)->whereHas('lineItems.adjustedBudget')
                    ->whereDoesntHave('lineItems.executions', fn ($q) => $q->where('month', $last))->count();
                $name = Carbon::create($reportYear, $last, 1)->translatedFormat('F');
                $add('report', $due, trans_choice(':n approved activity has no executed amount reported for :month|:n approved activities have no executed amount reported for :month', $due, ['n' => $due, 'month' => $name]), route('monitoring.report'), 'amber');
            }
            foreach (ReportingWindow::where('fiscal_year', $reportYear)->where('period_type', 'month')->whereIn('period', array_filter([$month, $last]))
                ->whereDate('closes_on', '>=', now()->toDateString())->whereDate('closes_on', '<=', now()->addDays(7)->toDateString())->get() as $w) {
                $add('report', 1, __('Reporting for :month closes on :d.', ['month' => Carbon::create($reportYear, $w->period, 1)->translatedFormat('F'), 'd' => $w->closes_on->format('d/m/Y')]), route('monitoring.report'), 'rose');
            }
        }

        // ── directors: the quarter that has just ended is theirs to compile
        if ($execution && $reportYear === (int) now()->year && ($user->isAdministrator() || $user->isDirector())) {
            $ended = (int) ceil(now()->month / 3) - 1;
            if ($ended >= 1) {
                $todo = app(QuarterCompilation::class)->activities($user, $reportYear)
                    ->whereDoesntHave('reports', fn ($q) => $q->where('period_type', 'quarter')->where('period', $ended)->whereNotNull('compiled_at'))->count();
                $add('report', $todo, trans_choice(':n approved activity has no compiled text for Q:q|:n approved activities have no compiled text for Q:q', $todo, ['n' => $todo, 'q' => $ended]), route('reports.compile'), 'indigo');
            }
        }

        // ── the administrator: units that are late on the calendar and need more time
        if ($approver) {
            $late = collect($years)->sum(fn ($y) => count(app(ReportingWindows::class)->overdue($y)));
            $add('late', $late, trans_choice(':n late item on the calendar: give the unit more time|:n late items on the calendar: give the units more time', $late, ['n' => $late]), route('management.reporting-windows'), 'rose');
        }

        // ── your own work
        if ($user->can('create_activities') || $user->can('submit_paa')) {
            $drafts = $activities()->where('status', Activity::STATUS_DRAFT)->count();
            $add('mine', $drafts, trans_choice(':n draft activity not yet submitted|:n draft activities not yet submitted', $drafts, ['n' => $drafts]), route('activities.index', ['tab' => 'draft']), 'amber');
            $returned = $activities()->where('status', Activity::STATUS_REJECTED)->count();
            $add('mine', $returned, trans_choice(':n activity returned for correction|:n activities returned for correction', $returned, ['n' => $returned]), route('activities.index', ['tab' => 'draft']), 'rose');
        }

        return $items;
    }

    /**
     * What is behind one item of the inbox (its key): the activities waiting, unit by unit, with the first few of each,
     * so that the page says what is waiting without opening it. Only for the Approvals page (one query an item); the
     * dashboard keeps the counts.
     *
     * @return array<int, array{unit: Directorate, parent: ?Directorate, count: int, activities: array<int, Activity>}>
     */
    public function details(User $user, string $key, ?int $year = null, int $perUnit = 3): array
    {
        if ($year !== null || FiscalYear::pinned() !== null) {
            $planYear = $reportYear = $year ?? FiscalYear::current();
        } else {
            [$planYear, $reportYear] = [FiscalYear::planning(), FiscalYear::execution()];
        }
        $years = array_values(array_unique([$planYear, $reportYear]));
        $scope = null;
        if (! $user->isAdministrator() && ! $user->can('view_all_paa')) {
            $unit = $user->directorate_id ? Directorate::find($user->directorate_id) : null;
            $scope = $unit ? $unit->getSelfAndDescendantIds() : [0];
        }
        $notMine = fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id);

        $query = match ($key) {
            'review' => Activity::whereIn('fiscal_year', $years)->where(fn ($q) => $this->toCertify($q, $scope === null ? null : $user)),
            'approval' => Activity::whereIn('fiscal_year', $years)->whereIn('status', [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL]),
            'budget' => Activity::whereIn('fiscal_year', $years)->where('status', Activity::STATUS_SUBMITTED)->whereNull('budget_confirmed_at'),
            'listed' => Activity::whereIn('fiscal_year', $years)->whereIn('directorate_id', app(ApproverLists::class)->unitIdsOf($user) ?: [0])
                ->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL]),
            'money' => Activity::where('fiscal_year', $reportYear)->whereHas('lineItems.executions', fn ($q) => $q->where('status', BudgetExecution::SUBMITTED)->where($notMine)),
            'progress' => Activity::where('fiscal_year', $reportYear)->whereHas('indicators.progress', fn ($q) => $q->where('status', 'Submitted')->where($notMine)),
            default => null,
        };
        if (! $query) {
            return [];
        }
        $activities = $query->when($scope !== null && $key !== 'listed', fn ($q) => $q->whereIn('directorate_id', $scope))
            ->with('directorate:id,code,name,parent_id', 'directorate.parent:id,code,name')
            ->orderBy('directorate_id')->orderBy('code')->limit(300)->get(['id', 'code', 'description', 'directorate_id', 'status', 'fiscal_year']);
        if ($key === 'budget') {
            $policy = app(ActivityPolicy::class);
            $activities = $activities->filter(fn ($a) => $policy->inScope($user, $a->directorate_id));
        }

        return $activities->groupBy('directorate_id')
            ->map(fn ($group) => [
                'unit' => $group->first()->directorate,
                'parent' => $group->first()->directorate?->parent,
                'count' => $group->count(),
                'activities' => $group->take($perUnit)->all(),
            ])
            ->sortByDesc('count')->values()->all();
    }

    /**
     * The activities waiting to be certified, and for a head, only those whose certification is theirs: prepared
     * activities of the units they head, and activities their units below have certified. A head further up waits
     * for the levels below (see ActivityWorkflow::next). $user null: every one (administrators, wide viewers).
     */
    private function toCertify($query, ?User $user)
    {
        if (! $user) {
            return $query->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED]);
        }
        $seats = $user->seats()->pluck('id')->all() ?: [0];
        $below = Directorate::whereIn('parent_id', $seats)->pluck('id')->all() ?: [0];
        $under = array_values(array_diff($user->directedUnitIds(), $seats)) ?: [0];
        $heads = User::role('Director')->select('id');

        return $query->where(fn ($q) => $q->where('status', Activity::STATUS_SUBMITTED)->whereIn('directorate_id', $seats))
            ->orWhere(fn ($q) => $q->where('status', Activity::STATUS_REVIEWED)->whereIn('directorate_id', $below))
            // prepared by the head of a unit below: someone above certifies it in their place
            ->orWhere(fn ($q) => $q->where('status', Activity::STATUS_SUBMITTED)->whereIn('directorate_id', $under)
                ->whereExists(fn ($e) => $e->from('users')->whereColumn('users.id', 'activities.submitted_by')
                    ->whereColumn('users.directorate_id', 'activities.directorate_id')->whereIn('users.id', $heads)));
    }
}
