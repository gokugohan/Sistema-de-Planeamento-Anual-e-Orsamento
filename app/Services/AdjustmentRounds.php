<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\AdjustmentRound;
use App\Models\AdjustmentRoundActivity;
use App\Models\AdjustmentRoundUnit;
use App\Models\Directorate;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\PlanOptions;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Adjustment rounds (part of the optional rule PlanOptions::budgetDecisions). After a budget decision, an administrator
 * opens a round with a deadline and a target reduction for each directorate. Each directorate chooses which of its
 * approved activities to revise for the round; every revision goes through the usual approval chain, and the plan
 * changes only when a revision is approved. The head of the directorate confirms when its adjustment is complete,
 * and the administrator closes the round. Nothing is reduced automatically and nothing is blocked.
 *
 * A round may also let its directorates add new activities until it closes (a supplementary budget with new money):
 * their plan is then open again for new activities, outside the calendar, as if it had not been finalized.
 *
 * The target is the amount a directorate is asked to reduce its plan by (zero: no reduction asked). "Reduced" is what
 * revisions took off its existing activities, "added" what new activities of the round put in; the target is reached
 * when the plan as a whole has gone down by it. "Proposed" is what revisions still in the chain would take off.
 */
class AdjustmentRounds
{
    public function __construct(private PlanBreakdown $breakdown, private PlanningWindow $window) {}

    // ── who may do what

    public function canOpen(User $user): bool
    {
        return PlanOptions::current()->budgetDecisions && $user->isAdministrator();
    }

    /** The head of the directorate (or an administrator) confirms its adjustment. */
    public function canConfirm(User $user, AdjustmentRoundUnit $unit): bool
    {
        return $unit->round->isOpen() && $unit->confirmed_at === null && $this->window->canDecide($user, null, $unit->directorate_id);
    }

    /** The directorates of the round this user sees: all of them for those who see every plan, otherwise their own. @return array<int, int> */
    public function visibleUnitIds(User $user, AdjustmentRound $round): array
    {
        $ids = $round->units()->pluck('directorate_id')->map(fn ($id) => (int) $id)->all();
        if ($user->isAdministrator() || $user->can('view_all_paa')) {
            return $ids;
        }
        $own = array_unique(array_filter([
            $user->directorate_id ? $this->window->rootOf((int) $user->directorate_id) : null,
            ...$user->seats()->map(fn (Directorate $d) => $this->window->rootOf($d->id))->all(),
        ]));

        return array_values(array_intersect($ids, $own));
    }

    // ── opening

    /**
     * A target for each directorate: the difference between the plan and the latest decision, shared out in proportion
     * to each directorate's approved plan, in whole dollars. Zero for everyone when the plan is not above the decision.
     *
     * @return array<int, float> directorate id => amount to reduce
     */
    public function suggestedTargets(int $year): array
    {
        $gap = app(BudgetDecisions::class)->gap($year);
        $over = max(0.0, (float) ($gap['difference'] ?? 0));
        $plans = $this->planByDirectorate($year);
        $total = array_sum($plans);

        return array_map(fn ($plan) => $total > 0 ? round($over * $plan / $total) : 0.0, $plans);
    }

    /** @param  array<int|string, mixed>  $targets  directorate id => amount to reduce */
    public function open(User $user, int $year, string $closesOn, ?string $instructions, array $targets, bool $allowsNewActivities = false): AdjustmentRound
    {
        abort_unless($this->canOpen($user), 403);
        if (AdjustmentRound::openFor($year)) {
            throw new DomainException(__('An adjustment round is already open for :year. Close it before opening another.', ['year' => $year]));
        }
        if (! $decision = app(BudgetDecisions::class)->latest($year)) {
            throw new DomainException(__('Record the budget decision first: the round adjusts the plan to it.'));
        }
        $plans = $this->planByDirectorate($year);
        if ($plans === []) {
            throw new DomainException(__('There is no approved activity to adjust for :year.', ['year' => $year]));
        }
        foreach ($targets as $amount) {
            if ($amount !== null && $amount !== '' && (! is_numeric($amount) || (float) $amount < 0)) {
                throw new DomainException(__('A target must be an amount of zero or more.'));
            }
        }

        $round = DB::transaction(function () use ($user, $year, $closesOn, $instructions, $targets, $decision, $plans, $allowsNewActivities) {
            $round = AdjustmentRound::create([
                'fiscal_year' => $year, 'budget_decision_id' => $decision->id, 'closes_on' => $closesOn,
                'instructions' => filled($instructions) ? mb_substr(trim($instructions), 0, 4000) : null,
                'allows_new_activities' => $allowsNewActivities, 'opened_by' => $user->id,
            ]);
            foreach ($plans as $directorateId => $plan) {
                $round->units()->create(['directorate_id' => $directorateId, 'target' => round((float) ($targets[$directorateId] ?? 0), 2), 'plan_at_start' => $plan]);
            }

            return $round;
        });

        $this->tellTheDirectorates($round->load('units', 'decision'));

        return $round;
    }

    // ── revising an activity for the round

    /**
     * May this user revise this activity for the round? The round is open, the activity's directorate is in it and has
     * not confirmed, the activity is approved (not already being revised), and the user may start a revision of it.
     */
    public function canRevise(User $user, AdjustmentRound $round, Activity $activity): bool
    {
        $unit = $this->unitOf($round, $activity);

        return $round->isOpen() && $unit && $unit->confirmed_at === null
            && (int) $activity->fiscal_year === (int) $round->fiscal_year
            && app(ActivityWorkflow::class)->canReopen($user, $activity);
    }

    /**
     * Revise an activity for the round: it goes back to Draft with the round as the reason (and the user's own words),
     * and through the whole approval chain again. The amount in force until then is kept with the round.
     */
    public function revise(User $user, AdjustmentRound $round, Activity $activity, ?string $note = null): AdjustmentRoundActivity
    {
        abort_unless($this->canRevise($user, $round, $activity), 403);

        return DB::transaction(function () use ($user, $round, $activity, $note) {
            $before = (float) ActivityVersion::where('activity_id', $activity->id)->orderByDesc('version')->value('total');
            app(ActivityWorkflow::class)->reopen($user, $activity, $this->reasonFor($round).(filled($note) ? ': '.trim($note) : ''));

            // revised again in the same round (after an earlier revision of the round was approved, or a new activity of
            // the round revised after its approval): one row, as it was first recorded
            return $round->activities()->firstOrCreate(['activity_id' => $activity->id], ['total_before' => $before, 'added_by' => $user->id]);
        });
    }

    /** The reason written on a revision started for the round, e.g. "Adjustment to the State Budget law (Law 1/2027)". */
    public function reasonFor(AdjustmentRound $round): string
    {
        $d = $round->decision;
        $what = $d ? $d->stageLabel().($d->reference ? ' ('.$d->reference.')' : '') : __('the budget decision');

        return __('Adjustment to the :decision', ['decision' => $what]);
    }

    // ── confirming and closing

    public function confirm(User $user, AdjustmentRoundUnit $unit, ?string $remark = null): void
    {
        abort_unless($this->canConfirm($user, $unit), 403);
        $row = $this->progress($unit->round)[$unit->directorate_id] ?? null;
        if ($row && $row['in_revision'] > 0) {
            throw new DomainException(trans_choice(
                ':n revision of the round is still in the approval chain. Confirm once it is approved or returned.|:n revisions of the round are still in the approval chain. Confirm once they are approved or returned.',
                $row['in_revision'], ['n' => $row['in_revision']]
            ));
        }
        $remark = trim((string) $remark);
        if ($row && $row['reduced'] + 0.5 < (float) $unit->target && mb_strlen($remark) < 3) {
            throw new DomainException(__('The target is not reached: say why in the remark.'));
        }

        $unit->update(['confirmed_at' => now(), 'confirmed_by' => $user->id, 'remark' => $remark !== '' ? mb_substr($remark, 0, 2000) : null]);
    }

    public function close(User $user, AdjustmentRound $round, ?string $note = null): void
    {
        abort_unless($this->canOpen($user) && $round->isOpen(), 403);
        $round->update(['closed_at' => now(), 'closed_by' => $user->id, 'closing_note' => filled($note) ? mb_substr(trim($note), 0, 2000) : null]);
    }

    // ── where the round stands

    /**
     * Each directorate of the round: its target, its approved plan when the round opened and now, how much it has
     * reduced, what its revisions still in the chain propose, and its state. Two grouped queries for the whole round.
     *
     * @return array<int, array{unit: AdjustmentRoundUnit, target: float, plan_at_start: float, plan_now: float, reduced: float, proposed: float, remaining: float, revised: int, in_revision: int, state: string}>
     */
    public function progress(AdjustmentRound $round): array
    {
        $units = $round->units()->with(['directorate:id,code,name', 'confirmer:id,name'])->get();
        $now = $this->breakdown->unitTotals($round->fiscal_year);

        $empty = ['revised' => 0, 'in_revision' => 0, 'proposed' => 0.0, 'added' => 0.0, 'adding' => 0.0, 'new' => 0];
        $byRoot = [];
        foreach ($this->activitiesOf($round)->get() as $r) {
            $root = $this->window->rootOf((int) $r->directorate_id);
            $byRoot[$root] ??= $empty;
            $waiting = ! in_array($r->status, [Activity::STATUS_APPROVED, Activity::STATUS_REJECTED], true);
            if ($r->is_new) {
                // a new activity: once approved it is in the plan ("added"); until then it is on its way
                if ($r->status === Activity::STATUS_REJECTED) {
                    continue;
                }
                $byRoot[$root]['new']++;
                $byRoot[$root]['added'] += (float) $r->in_force;
                $byRoot[$root]['adding'] += $waiting ? (float) $r->draft : 0.0;
            } else {
                // a revision: what its draft takes off the amount in force, while it is in the chain
                $byRoot[$root]['revised']++;
                $byRoot[$root]['proposed'] += $waiting ? (float) $r->in_force - (float) $r->draft : 0.0;
            }
            $byRoot[$root]['in_revision'] += $waiting ? 1 : 0;
        }

        $result = [];
        foreach ($units as $u) {
            $start = (float) $u->plan_at_start;
            $planNow = (float) ($now[$u->directorate_id]['approved'] ?? 0);
            $mine = $byRoot[$u->directorate_id] ?? $empty;
            $reduced = $start - ($planNow - $mine['added']); // what came off the activities it already had
            $result[$u->directorate_id] = [
                'unit' => $u,
                'target' => (float) $u->target,
                'plan_at_start' => $start,
                'plan_now' => $planNow,
                'reduced' => $reduced,
                'added' => $mine['added'],
                'adding' => $mine['adding'],
                'proposed' => $mine['proposed'],
                'remaining' => max(0.0, (float) $u->target - ($start - $planNow)),
                'revised' => $mine['revised'],
                'new' => $mine['new'],
                'in_revision' => $mine['in_revision'],
                'state' => match (true) {
                    $u->confirmed_at !== null => 'confirmed',
                    $mine['in_revision'] > 0 => 'revising',
                    $mine['revised'] > 0 || $mine['new'] > 0 || abs($start - $planNow) >= 0.5 => 'ready',
                    default => 'not_started',
                },
            ];
        }
        uasort($result, fn ($a, $b) => strcmp((string) $a['unit']->directorate?->code, (string) $b['unit']->directorate?->code));

        return $result;
    }

    /**
     * The activities of the round, one row each: its directorate, status, whether it is new, its amount in force and
     * its draft amount (one grouped query).
     */
    public function activitiesOf(AdjustmentRound $round)
    {
        return DB::table('adjustment_round_activities as ra')
            ->join('activities as a', 'a.id', '=', 'ra.activity_id')
            ->leftJoin('line_items as li', 'li.activity_id', '=', 'a.id')
            ->leftJoin('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')
            ->where('ra.adjustment_round_id', $round->id)
            ->groupBy('a.id', 'a.code', 'a.description', 'a.directorate_id', 'a.status', 'ra.is_new')
            ->selectRaw('a.id, a.code, a.description, a.directorate_id, a.status, ra.is_new, COALESCE(SUM(ab.approved_amount), 0) AS in_force, SUM(CASE WHEN li.deleted_at IS NULL THEN li.estimated_amount ELSE 0 END) AS draft');
    }

    /** The new activities of the round (see activitiesOf), the rejected ones left out. */
    public function newActivities(AdjustmentRound $round)
    {
        return $this->activitiesOf($round)->where('ra.is_new', true)->where('a.status', '!=', Activity::STATUS_REJECTED);
    }

    /**
     * Keep a new activity with the round when it exists because of it: its directorate had finalized its plan and the
     * round reopened it for new activities. Activities planned while the directorate's plan was open anyway are ordinary
     * planning, not part of the round. Called when an activity is created.
     */
    public function recordNew(Activity $activity): void
    {
        if ($activity->is_test_data || ! $activity->directorate_id) {
            return;
        }
        $root = $this->window->rootOf((int) $activity->directorate_id);
        if ($this->window->unitEndedBy((int) $activity->fiscal_year, $root) === null || ! AdjustmentRound::takesNewActivities((int) $activity->fiscal_year, $root)) {
            return;
        }
        if ($round = AdjustmentRound::openFor((int) $activity->fiscal_year)) {
            $round->activities()->create(['activity_id' => $activity->id, 'is_new' => true, 'total_before' => 0, 'added_by' => auth()->id()]);
        }
    }

    // ── helpers

    /** The approved plan of each top-level unit that has one. @return array<int, float> */
    private function planByDirectorate(int $year): array
    {
        $totals = $this->breakdown->unitTotals($year);
        $plans = [];
        foreach (Directorate::whereNull('parent_id')->orderBy('code')->pluck('id') as $id) {
            if (($totals[$id]['approved'] ?? 0) > 0) {
                $plans[(int) $id] = round((float) $totals[$id]['approved'], 2);
            }
        }

        return $plans;
    }

    private function unitOf(AdjustmentRound $round, Activity $activity): ?AdjustmentRoundUnit
    {
        return $round->units()->where('directorate_id', $this->window->rootOf((int) $activity->directorate_id))->first();
    }

    /** The directors and planners of each directorate of the round learn its target and deadline. */
    private function tellTheDirectorates(AdjustmentRound $round): void
    {
        $what = $round->decision?->stageLabel() ?? __('the budget decision');
        foreach ($round->units as $unit) {
            $users = User::role(['Director', 'Planning'])->whereIn('directorate_id', $this->window->subtree($unit->directorate_id))->get();
            $message = (float) $unit->target > 0
                ? __('The plan of :year is being adjusted to :decision. Your directorate is asked to reduce $ :amount by :date: choose the activities to revise.', ['year' => $round->fiscal_year, 'decision' => $what, 'amount' => number_format((float) $unit->target, 0), 'date' => $round->closes_on->format('d/m/Y')])
                : __('The plan of :year is being adjusted to :decision, by :date. Your directorate is not asked to reduce, but may revise its activities.', ['year' => $round->fiscal_year, 'decision' => $what, 'date' => $round->closes_on->format('d/m/Y')]);
            if ($round->allows_new_activities) {
                $message .= ' '.__('New activities may be added until the round closes.');
            }
            $users->each->notify(new SystemNotification(__('Adjustment round open'), $message, route('planning.adjustment', [], false)));
        }
    }
}
