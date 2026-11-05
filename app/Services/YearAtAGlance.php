<?php

namespace App\Services;

use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Support\FiscalYear;
use Carbon\Carbon;

/**
 * The year in four plain sentences, for whoever opens the dashboard or My work: where planning stands, where the
 * approval stands, whether the directorates have finalized, and what reporting is due. Each phase says whether it is
 * done, going on now, or still to come, and, when it is the person's own business, offers the one button that goes there.
 * The facts come from the plan cycle and the inbox, so the words and the numbers cannot disagree with the pages they link to.
 */
class YearAtAGlance
{
    public function __construct(private PlanCycle $cycle, private ApprovalInbox $inbox, private PlanningWindow $planning) {}

    /**
     * @return array{year: int, phases: array<int, array{key: string, label: string, state: string, text: string, cta: ?array{0: string, 1: string}}>, now: array<int, string>}
     */
    public function for(User $user, int $year, ?array $items = null): array
    {
        $c = $this->cycle->build($user, $year, true); // the lighter build: the reporting month is asked for below
        $items = collect($items ?? $this->inbox->items($user, $year));
        $policy = app(ActivityPolicy::class);
        $wide = $user->isAdministrator() || $user->can('view_all_paa');

        $w = $c['plan_window'];
        $a = $c['approval'];
        // the directorates the person can see: all of them for those who see every plan, their own otherwise
        $units = collect($c['planning']['units'])->filter(fn ($u) => $wide || $policy->inScope($user, $u['id']))->values();
        $closedUnits = $units->where('closed', true)->count();
        // ready for the approval of the plan (approve and finalize in one act): the same reading as Waiting for you
        $readiness = $this->planning->planReadinessOf($year, $units->reject(fn ($u) => $u['closed'])->pluck('id')->all());
        $ready = collect($readiness)->where('ready', true)->count();
        $ended = $units->isNotEmpty() && $closedUnits === $units->count();
        $yearOpen = PlanningWindow::yearAllowed($year);

        // ── planning
        $planningText = match (true) {
            ! $yearOpen => __('Closed: new activities go into the plan of :current or :next. Approved activities can still be revised.', ['current' => now()->year, 'next' => now()->year + 1]),
            $ended => __('Planning has ended: every directorate has finalized its plan. Approved activities can still be revised.'),
            $w['state'] === 'upcoming' => __('Not open yet: it opens on :d.', ['d' => $w['opens_on']->format('d/m/Y')]),
            $w['state'] === 'open' => __('Open until :d. Units add and change their activities now.', ['d' => $w['closes_on']->format('d/m/Y')]),
            $w['state'] === 'closed' => __('Closed on :d. Only an administrator adds or submits activities now; approved activities can still be revised.', ['d' => $w['closes_on']->format('d/m/Y')]),
            default => __('Open: units add and change their activities until their directorate finalizes its plan.'),
        };
        $planningCta = $yearOpen && ! $ended && $w['state'] !== 'closed' && $w['state'] !== 'upcoming' && $user->directorate_id && $user->can('create_activities') && FiscalYear::planningOpen($year, $user->directorate_id)
            ? [__('Add an activity'), route('activities.create')] : null;

        // ── approval
        $approvalText = $a['total'] === 0
            ? __('No activity yet.')
            : trans_choice(':approved of :n activity is approved.|:approved of :n activities are approved.', $a['total'], ['approved' => $a['approved'], 'n' => $a['total']])
                .($a['pending'] > 0 ? ' '.trans_choice(':n is waiting for a decision.|:n are waiting for a decision.', $a['pending'], ['n' => $a['pending']]) : '')
                .($a['drafts'] > 0 ? ' '.trans_choice(':n is still a draft or returned.|:n are still drafts or returned.', $a['drafts'], ['n' => $a['drafts']]) : '');
        $decide = (int) $items->where('group', 'decide')->sum('count');
        $approvalCta = $decide > 0 ? [trans_choice(':n waiting for you|:n waiting for you', $decide, ['n' => $decide]), route('approvals.index')] : null;

        // ── finalize
        $finalizeText = match (true) {
            $units->isEmpty() => __('Nothing to finalize yet.'),
            $units->count() === 1 => $closedUnits === 1 ? __('Your directorate has finalized its plan: no new activities.') : ($ready === 1
                ? __('Everything is certified: the head can approve the plan, which also ends its planning.')
                : __('The head approves the plan, and so finalizes it, once everything in it is certified.')),
            default => __(':n of :total directorates have finalized their plan.', ['n' => $closedUnits, 'total' => $units->count()])
                .($ready > 0 ? ' '.trans_choice(':n is ready for the approval of its plan.|:n are ready for the approval of their plan.', $ready, ['n' => $ready]) : ''),
        };
        $mayFinalize = $items->where('group', 'plan')->isNotEmpty();
        $finalizeCta = $mayFinalize ? [__('Approve the plan'), route('approvals.index').'#plan'] : null;

        // ── reporting
        $today = Carbon::now();
        $isCurrent = $year === (int) $today->year;
        $reportingText = __('The executed amount is reported every month and indicators every quarter, in Report execution; a figure counts once someone else has verified it.');
        $reportingCta = null;
        // reporting needs an approved plan: without one there is nothing to report, whatever the date
        $past = $year < (int) $today->year;
        $reportingState = $past ? 'done' : ($a['approved'] === 0 ? 'soon' : ($isCurrent ? 'now' : 'soon'));
        if ($a['approved'] === 0 && ! $past) {
            $reportingText = __('Starts once activities are approved: the executed amount is then reported every month and indicators every quarter.');
        } elseif ($isCurrent && $today->month > 1) {
            $last = $today->month - 1;
            $m = $this->cycle->month($user, $year, $last);
            $name = Carbon::create($year, $last, 1)->translatedFormat('F');
            $reportingText = $m['due'] > 0
                ? __(':name: :done of :due approved activities have a verified executed amount.', ['name' => $name, 'done' => $m['verified'], 'due' => $m['due']]).' '.__('Indicators are reported every quarter.')
                : $reportingText;
            if ($m['window']['state'] === 'open' && $m['window']['closes_on']) {
                $reportingText .= ' '.__('Reporting for :name closes on :d.', ['name' => $name, 'd' => $m['window']['closes_on']->format('d/m/Y')]);
            }
        }
        if ($isCurrent && $a['approved'] > 0) {
            $verify = (int) $items->where('group', 'verify')->sum('count');
            $due = (int) $items->where('group', 'report')->sum('count');
            if ($user->can('update_indicator_progress') && ($due > 0 || $verify === 0)) {
                $reportingCta = [__('Report execution'), route('monitoring.report')];
            } elseif ($verify > 0) {
                $reportingCta = [trans_choice(':n to verify|:n to verify', $verify, ['n' => $verify]), route('approvals.index')];
            }
        }

        $phases = [
            ['key' => 'planning', 'label' => __('Planning'), 'state' => ! $yearOpen || $ended || $w['state'] === 'closed' ? 'done' : 'now', 'text' => $planningText, 'cta' => $planningCta],
            ['key' => 'approval', 'label' => __('Approval'), 'state' => $a['total'] > 0 && $a['pending'] === 0 && $a['drafts'] === 0 ? 'done' : ($a['total'] > 0 ? 'now' : 'soon'), 'text' => $approvalText, 'cta' => $approvalCta],
            ['key' => 'finalize', 'label' => __('Finalizing'), 'state' => $ended ? 'done' : ($ready > 0 ? 'now' : 'soon'), 'text' => $finalizeText, 'cta' => $finalizeCta],
            ['key' => 'reporting', 'label' => __('Reporting'), 'state' => $reportingState, 'text' => $reportingText, 'cta' => $reportingCta],
        ];

        return ['year' => $year, 'phases' => $phases, 'now' => array_values(array_map(fn ($p) => $p['label'], array_filter($phases, fn ($p) => $p['state'] === 'now')))];
    }
}
