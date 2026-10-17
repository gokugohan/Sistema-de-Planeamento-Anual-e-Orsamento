<?php

namespace App\Services;

use App\Models\Activity;
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
use Carbon\Carbon;

/**
 * What is waiting for one person, gathered in one place: activities to decide, execution figures to verify,
 * the plan to finalize, budget steps, and their own drafts and returned work. Only counts and a link each,
 * from grouped queries, so it is cheap enough for the dashboard as well as the Approvals page.
 *
 * Each item: group (decide | verify | plan | budget | mine), count, text, url, tone.
 */
class ApprovalInbox
{
    /** @return array<int, array{group: string, count: int, text: string, url: string, tone: string}> */
    public function items(User $user, ?int $year = null): array
    {
        $year ??= FiscalYear::current();
        $items = [];
        $add = function (string $group, int $count, string $text, string $url, string $tone) use (&$items) {
            if ($count > 0) {
                $items[] = compact('group', 'count', 'text', 'url', 'tone');
            }
        };

        $approver = $user->isAdministrator();
        $seesAll = $approver || $user->can('view_all_paa');
        $scope = null; // null = every unit
        if (! $seesAll) {
            $unit = $user->directorate_id ? Directorate::find($user->directorate_id) : null;
            $scope = $unit ? $unit->getSelfAndDescendantIds() : [0];
        }
        $activities = fn () => Activity::query()->where('fiscal_year', $year)->when($scope !== null, fn ($q) => $q->whereIn('directorate_id', $scope));

        // ── activities to decide
        if ($approver) {
            $review = $activities()->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED])->count();
            $add('decide', $review, trans_choice(':n activity waiting for technical review|:n activities waiting for technical review', $review, ['n' => $review]), route('planning.paa-approval'), 'amber');
            $approval = $activities()->whereIn('status', [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL])->count();
            $add('decide', $approval, trans_choice(':n activity waiting for approval|:n activities waiting for approval', $approval, ['n' => $approval]), route('planning.paa-approval'), 'indigo');
        } elseif ($user->isDirector()) {
            $own = $activities()->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL])->count();
            $add('decide', $own, trans_choice(':n activity in your units waiting for a decision|:n activities in your units waiting for a decision', $own, ['n' => $own]), route('activities.index', ['tab' => 'review']), 'amber');
        }

        // ── finance: submitted activities whose budget is waiting for confirmation (where the institution asks for it)
        if (ApprovalRules::current()->budgetConfirmation && ($user->isAdministrator() || $user->can('confirm_budget'))) {
            $policy = app(ActivityPolicy::class);
            $n = Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_SUBMITTED)->whereNull('budget_confirmed_at')
                ->limit(300)->get(['id', 'directorate_id'])->filter(fn ($a) => $policy->inScope($user, $a->directorate_id))->count();
            $add('decide', $n, trans_choice(':n activity waiting for its budget to be confirmed|:n activities waiting for their budget to be confirmed', $n, ['n' => $n]), route('approvals.budget'), 'amber');
        }

        // ── named in an approval list: what is waiting at the step they were named for
        if (! $approver && ! $user->isDirector()) {
            $unitIds = app(ApproverLists::class)->unitIdsOf($user);
            if ($unitIds) {
                $workflow = app(ActivityWorkflow::class);
                $waiting = Activity::where('fiscal_year', $year)->whereIn('directorate_id', $unitIds)
                    ->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL])
                    ->with('directorate.parent')->limit(300)->get()
                    ->filter(fn (Activity $a) => $workflow->next($user, $a) !== null || $workflow->canApprove($user, $a))->count();
                $add('decide', $waiting, trans_choice(':n activity waiting for your decision|:n activities waiting for your decision', $waiting, ['n' => $waiting]), route('activities.index'), 'indigo');
            }
        }

        // ── execution figures to verify (never your own reports)
        $mayVerifyProgress = $user->can('approve_progress_report') || $user->isDirector();
        if ($mayVerifyProgress || $user->can('verify_spending')) {
            $activityIds = $activities()->select('id');
            $money = BudgetExecution::where('status', BudgetExecution::SUBMITTED)->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id))
                ->whereIn('line_item_id', LineItem::whereIn('activity_id', $activityIds)->select('id'))->count();
            $add('verify', $money, trans_choice(':n spending figure to verify|:n spending figures to verify', $money, ['n' => $money]), route('monitoring.report'), 'indigo');
        }
        if ($mayVerifyProgress) {
            $progress = IndicatorProgress::where('status', 'Submitted')->where(fn ($q) => $q->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id))
                ->whereIn('indicator_id', Indicator::whereIn('activity_id', $activities()->select('id'))->select('id'))->count();
            $add('verify', $progress, trans_choice(':n progress report to verify|:n progress reports to verify', $progress, ['n' => $progress]),
                $user->can('approve_progress_report') ? route('monitoring.approval') : route('monitoring.report'), 'indigo');
        }

        // ── the plan: each directorate that is ready to be finalized, for whoever gives its final approval
        $window = app(PlanningWindow::class);
        $units = $window->state($year)['units'];
        foreach ($window->decidesFor($user, $year) as $rootId) {
            $u = collect($units)->firstWhere('id', $rootId);
            if ($u && ! $u['closed'] && $u['pending'] === 0 && $u['approved'] > 0) {
                $add('plan', 1, __('Every activity of :unit for :year has been decided: the plan is ready to be finalized.', ['unit' => $u['code'], 'year' => $year]), route('activities.index'), 'emerald');
            }
        }

        // ── reporting due: the units' monthly duty, in the running year
        if ($year === (int) now()->year && $user->can('update_indicator_progress')) {
            $month = (int) now()->month;
            $last = $month - 1; // the month that has just ended is the one to have reported
            if ($last >= 1) {
                $due = $activities()->where('version', '>', 0)->whereHas('lineItems.adjustedBudget')
                    ->whereDoesntHave('lineItems.executions', fn ($q) => $q->where('month', $last))->count();
                $name = Carbon::create($year, $last, 1)->translatedFormat('F');
                $add('report', $due, trans_choice(':n approved activity has no spending reported for :month|:n approved activities have no spending reported for :month', $due, ['n' => $due, 'month' => $name]), route('monitoring.report'), 'amber');
            }
            foreach (ReportingWindow::where('fiscal_year', $year)->where('period_type', 'month')->whereIn('period', array_filter([$month, $last]))
                ->whereDate('closes_on', '>=', now()->toDateString())->whereDate('closes_on', '<=', now()->addDays(7)->toDateString())->get() as $w) {
                $add('report', 1, __('Reporting for :month closes on :d.', ['month' => Carbon::create($year, $w->period, 1)->translatedFormat('F'), 'd' => $w->closes_on->format('d/m/Y')]), route('monitoring.report'), 'rose');
            }
        }

        // ── directors: the quarter that has just ended is theirs to compile
        if ($year === (int) now()->year && ($user->isAdministrator() || $user->isDirector())) {
            $ended = (int) ceil(now()->month / 3) - 1;
            if ($ended >= 1) {
                $todo = app(QuarterCompilation::class)->activities($user, $year)
                    ->whereDoesntHave('reports', fn ($q) => $q->where('period_type', 'quarter')->where('period', $ended)->whereNotNull('compiled_at'))->count();
                $add('report', $todo, trans_choice(':n approved activity has no compiled text for Q:q|:n approved activities have no compiled text for Q:q', $todo, ['n' => $todo, 'q' => $ended]), route('reports.compile'), 'indigo');
            }
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
}
