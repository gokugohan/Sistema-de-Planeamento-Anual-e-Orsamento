<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BudgetCycle;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\LineItem;
use App\Models\User;

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
        $year ??= FiscalEnvelope::getEffectiveYear();
        $items = [];
        $add = function (string $group, int $count, string $text, string $url, string $tone) use (&$items) {
            if ($count > 0) {
                $items[] = compact('group', 'count', 'text', 'url', 'tone');
            }
        };

        $approver = $user->hasAnyRole(['Admin', 'Minister']);
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

        // ── execution figures to verify (never your own reports)
        $mayVerifyProgress = $user->can('approve_progress_report') || $user->hasRole('Director');
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

        // ── the plan: ready to be finalized by whoever gives the final approval
        $window = app(PlanningWindow::class);
        if ($window->canDecide($user) && $window->isOpen($year)) {
            $pending = $window->pending($year);
            $approved = Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->count();
            if ($pending === 0 && $approved > 0) {
                $add('plan', 1, __('Every activity for :year has been decided: the plan is ready to be finalized.', ['year' => $year]), route('activities.index'), 'emerald');
            }
        }

        // ── budget steps for those who run them
        if ($user->hasAnyRole(['Admin', 'Minister'])) {
            $pending = FiscalEnvelope::where('status', FiscalEnvelope::STATUS_PENDING)->count();
            $add('budget', $pending, trans_choice(':n fiscal envelope waiting for approval|:n fiscal envelopes waiting for approval', $pending, ['n' => $pending]), route('budget.envelope.current'), 'indigo');
        }
        if ($approver && $user->can('view_budget')) {
            $next = (int) date('Y') + 1;
            $approved = Activity::where('fiscal_year', $next)->where('status', Activity::STATUS_APPROVED)->count();
            $submitted = BudgetCycle::where('fiscal_year', $next)->whereNotNull('submitted_at')->exists();
            if ($approved > 0 && ! $submitted) {
                $add('budget', $approved, \App\Support\Institution::label('approved activities for :year have not been recorded as submitted to the CRO', ['year' => $next]), route('planning.budget-cycle'), 'amber');
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
