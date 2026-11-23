<?php

namespace App\Services;

use App\Models\AdjustmentRound;
use App\Models\BudgetDecision;
use App\Models\BudgetDistribution;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FundingSource;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Support\PlanOptions;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The decisions on the institution's budget for a year (an optional rule, PlanOptions::budgetDecisions): what the
 * budget review committee, the State Budget law or a supplementary budget gave, by economic category, compared with
 * the plan. A decision is a reference: it limits nothing and changes no activity. When the plan has to follow it, the
 * units revise their activities through the usual revision. A year can have several decisions; the latest one counts.
 */
class BudgetDecisions
{
    public function __construct(private PlanBreakdown $breakdown) {}

    /** An administrator records a decision; where the budget is distributed, the budget unit that prepares the distribution too. */
    public function canRecord(User $user): bool
    {
        $options = PlanOptions::current();

        return $options->budgetDecisions && ($user->isAdministrator() || ($options->budgetDistribution && app(BudgetDistributions::class)->canPrepare($user)));
    }

    /**
     * The plan figures a decision is compared with: every budget line, or, where the budget is distributed, only the
     * lines paid from the institution's own budget title (a partner's money is not part of the State Budget decision).
     */
    private function planFigures(): PlanBreakdown
    {
        return PlanOptions::current()->budgetDistribution ? $this->breakdown->forSources(FundingSource::inTitle()) : $this->breakdown;
    }

    /**
     * The plan of the year by economic category, as a decision is compared with it: what is approved and what is still
     * being prepared. Shown beside the amounts while a decision is recorded.
     *
     * @return array<int, array{in_force: float, preparing: float}>
     */
    public function planByClass(int $year): array
    {
        return $this->planFigures()->classTotals($year);
    }

    /**
     * Record a decision with its amount per economic category, and keep the plan in force at that moment
     * (its amounts by category, and a baseline when something is approved).
     *
     * @param  array<int|string, mixed>  $amounts  economic classification id => amount
     */
    public function record(User $user, int $year, string $stage, string $decidedOn, array $amounts, ?string $reference = null, ?string $note = null, mixed $committed = null, mixed $newPolicySpace = null, ?string $plansDueOn = null): BudgetDecision
    {
        abort_unless($this->canRecord($user), 403);
        if (! array_key_exists($stage, BudgetDecision::STAGES)) {
            throw new DomainException(__('Choose where the decision comes from.'));
        }
        // one envelope a year, decided in stages: a decision never goes back to a stage already passed
        if (! in_array($stage, $this->nextStages($year), true)) {
            throw new DomainException(__('The budget of :year is already past this stage. Record the next stage, a supplementary budget once the law is approved, or another decision.', ['year' => $year]));
        }
        $latest = $this->latest($year);
        if ($latest && $decidedOn < $latest->decided_on->toDateString()) {
            throw new DomainException(__('A decision cannot be dated before the latest one (:date).', ['date' => $latest->decided_on->format('d/m/Y')]));
        }
        if ($plansDueOn !== null && $plansDueOn !== '' && strtotime($plansDueOn) === false) {
            throw new DomainException(__('Give a valid date for the corrected plans.'));
        }
        $classes = EconomicClassification::pluck('id')->all();
        $clean = [];
        foreach ($amounts as $id => $amount) {
            if (! in_array((int) $id, $classes, true) || $amount === null || $amount === '') {
                continue;
            }
            if (! is_numeric($amount) || (float) $amount < 0) {
                throw new DomainException(__('An amount must be a number of zero or more.'));
            }
            $clean[(string) (int) $id] = round((float) $amount, 2);
        }
        if ($clean === [] || array_sum($clean) <= 0) {
            throw new DomainException(__('Give the amount decided for at least one category.'));
        }

        foreach ([$committed, $newPolicySpace] as $extra) {
            if ($extra !== null && $extra !== '' && (! is_numeric($extra) || (float) $extra < 0)) {
                throw new DomainException(__('An amount must be a number of zero or more.'));
            }
        }
        $plan = array_map(fn ($c) => round($c['in_force'], 2), $this->planFigures()->classTotals($year));
        $reference = filled($reference) ? mb_substr(trim($reference), 0, 255) : null;

        return DB::transaction(function () use ($user, $year, $stage, $decidedOn, $clean, $plan, $reference, $note, $committed, $newPolicySpace, $plansDueOn) {
            $baseline = null;
            if (array_sum($plan) > 0) {
                // the plan as it stood before this stage: as submitted (before the ceiling), as corrected and sent with the
                // proposal (before the law), as in force (before a supplementary budget)
                $name = match ($stage) {
                    'ceiling' => __('PAA as submitted, before the ceiling'),
                    'law' => __('PAA before the State Budget law'),
                    default => __('PAA before the supplementary budget'),
                };
                $baseline = app(PlanBaselines::class)->capture($user, $year, $name.($reference ? ' ('.$reference.')' : ''));
            }

            return BudgetDecision::create([
                'fiscal_year' => $year,
                'stage' => $stage,
                'decided_on' => $decidedOn,
                'reference' => $reference,
                'note' => filled($note) ? mb_substr(trim($note), 0, 2000) : null,
                'amounts' => $clean,
                'total' => array_sum($clean),
                // what the budget circular gives besides the total (Decreto-Lei n.º 42/2025, art. 19.2 f and g)
                'committed_amount' => $committed !== null && $committed !== '' ? round((float) $committed, 2) : null,
                'new_policy_space' => $newPolicySpace !== null && $newPolicySpace !== '' ? round((float) $newPolicySpace, 2) : null,
                // by when the plans are to be corrected to it (the budget circular gives the calendar, art. 19.2 i)
                'plans_due_on' => filled($plansDueOn) ? $plansDueOn : null,
                'plan_amounts' => $plan,
                'plan_total' => array_sum($plan),
                'plan_baseline_id' => $baseline?->id,
                'recorded_by' => $user->id,
            ]);
        });
    }

    /** @return Collection<int, BudgetDecision> the decisions of the year, the latest first */
    public function history(int $year): Collection
    {
        return BudgetDecision::with('recorder:id,name')->where('fiscal_year', $year)->orderByDesc('decided_on')->orderByDesc('id')->get();
    }

    /**
     * The stages a new decision of the year may be, the expected one first: the ceiling or the law before the law is
     * recorded (the ceiling only while nothing is recorded), then only supplementary budgets.
     *
     * @return array<int, string>
     */
    public function nextStages(int $year): array
    {
        $reached = 0;
        foreach (BudgetDecision::where('fiscal_year', $year)->distinct()->pluck('stage') as $stage) {
            $reached = max($reached, BudgetDecision::RANK[$stage] ?? 0);
        }

        return match (true) {
            $reached >= BudgetDecision::RANK['law'] => ['supplementary'],
            $reached >= BudgetDecision::RANK['ceiling'] => ['law'],
            default => ['ceiling', 'law'],
        };
    }

    /** The final envelope of the year: the latest decision once the State Budget law is recorded; null before the law. */
    public function finalEnvelope(int $year): ?BudgetDecision
    {
        $latest = $this->latest($year);

        return $latest?->isFinal() ? $latest : null;
    }

    /**
     * The legal dates of the budget of a year, prepared the year before: the annual plans in May and June (Decreto-Lei
     * n.º 42/2025, art. 15.7), the budget circular with the ceiling by 20 July (art. 19.1), the State Budget law between the
     * Government's proposal (by 1 October, Lei n.º 3/2025, art. 30.1) and the start of the year, when it takes effect
     * (art. 35), and a supplementary budget during the year itself.
     *
     * @return array<string, array{0: string, 1: string}> stage (and 'plan') => [opens on, closes on]
     */
    public static function legalDates(int $year): array
    {
        $y = $year - 1;

        return [
            'plan' => ["$y-05-01", "$y-06-30"],
            'ceiling' => ["$y-07-01", "$y-07-20"],
            'law' => ["$y-10-01", "$y-12-31"],
            'supplementary' => ["$year-01-01", "$year-12-31"],
        ];
    }

    /** The row of a stage in the 'budget' dates of the calendar. */
    public static function calendarPeriod(string $stage): int
    {
        return BudgetDecision::PERIODS[$stage];
    }

    /**
     * The calendar of the year's budget, step by step, with the planning steps of the calendar where they fall: each step
     * with its dates (when the administrator gave them), what was recorded for it, and where it stands today.
     *   recorded       a decision of the stage was recorded (late: after its closing date)
     *   open           its dates are running now
     *   upcoming       its dates have not started
     *   late           the State Budget law, its dates passed, not recorded yet
     *   not_recorded   a provisional stage whose dates passed without a decision being recorded (no harm: it is optional)
     *   skipped        a provisional stage passed over: a later stage is recorded
     *   if_needed      a supplementary budget, which comes only when the Government proposes one
     *   undated        no dates in the calendar
     *   closed         a planning step whose dates have passed
     *
     * @return array{rows: array<int, array<string, mixed>>, final: ?BudgetDecision, latest: ?BudgetDecision, law_due: ?Carbon}
     */
    public function calendar(int $year): array
    {
        $windows = ReportingWindow::where('fiscal_year', $year)->whereIn('period_type', ['budget', ReportingWindows::PLAN, ReportingWindows::APPROVAL])->get()
            ->keyBy(fn ($w) => $w->period_type.'_'.$w->period);
        $decisions = BudgetDecision::where('fiscal_year', $year)->orderBy('decided_on')->orderBy('id')->get();
        $today = now()->toDateString();
        $reached = $decisions->map(fn ($d) => BudgetDecision::RANK[$d->stage] ?? 0)->max() ?? 0;
        // a decision of a stage no longer offered shows where it belongs: provisional with the ceiling, final with the
        // supplementary budgets
        $rowOf = fn (BudgetDecision $d) => in_array($d->stage, BudgetDecision::ORDER, true) ? $d->stage : ($d->isFinal() ? 'supplementary' : 'ceiling');

        $dated = function ($w) use ($today): string {
            return match (true) {
                $w === null => 'undated',
                $today < $w->opens_on->toDateString() => 'upcoming',
                $today <= $w->closes_on->toDateString() => 'open',
                default => 'closed',
            };
        };
        $planStep = fn (string $type, string $label) => ['kind' => 'plan', 'key' => $type, 'label' => $label, 'final' => false,
            'opens' => $windows[$type.'_1']->opens_on ?? null, 'closes' => $windows[$type.'_1']->closes_on ?? null,
            'status' => $dated($windows[$type.'_1'] ?? null), 'decisions' => collect()];

        $before = $year - 1;
        $plan = $planStep(ReportingWindows::PLAN, __('The units prepare and submit their annual plans'));
        $plan['note'] = __('By law in May and June :year (Decreto-Lei n.º 42/2025, art. 15.7).', ['year' => $before]);
        $rows = [$plan];
        $lawDue = null;
        $notes = [
            'ceiling' => __('Budget circular by 20 July :year.', ['year' => $before]),
            'law' => __('Proposal to Parliament by 1 October :before; in force from 1 January :year.', ['before' => $before, 'year' => $year]),
            'supplementary' => __('Only if the Government proposes one and Parliament approves it.'),
        ];
        foreach (BudgetDecision::ORDER as $stage) {
            if ($stage === 'law') {
                // the plans are corrected to the ceiling and approved before the proposal (Decreto-Lei n.º 42/2025, art. 20.3)
                $rows[] = $planStep(ReportingWindows::APPROVAL, __('The plans are corrected to the ceiling and approved'));
            }
            $w = $windows['budget_'.self::calendarPeriod($stage)] ?? null;
            $recorded = $decisions->filter(fn ($d) => $rowOf($d) === $stage)->values();
            $state = $dated($w);
            $status = match (true) {
                $recorded->isNotEmpty() => 'recorded',
                $stage === 'supplementary' => $state === 'upcoming' ? 'upcoming' : 'if_needed',
                BudgetDecision::RANK[$stage] < $reached => 'skipped',
                $state === 'closed' => $stage === 'law' ? 'late' : 'not_recorded',
                default => $state,
            };
            if ($status === 'late') {
                $lawDue = $w->closes_on;
            }
            $rows[] = [
                'kind' => 'budget', 'key' => $stage, 'label' => __(BudgetDecision::STAGES[$stage]), 'final' => in_array($stage, BudgetDecision::FINAL, true),
                'opens' => $w?->opens_on, 'closes' => $w?->closes_on, 'status' => $status, 'decisions' => $recorded, 'note' => $notes[$stage],
                'late' => $w && ($own = $recorded->firstWhere('stage', $stage)) && $own->decided_on->gt($w->closes_on),
            ];
        }

        return [
            'rows' => $rows, 'final' => $this->finalEnvelope($year), 'latest' => $decisions->last(), 'law_due' => $lawDue,
            // no law on 1 January: the law of the year before is extended, spent at one twelfth a month (Lei n.º 3/2025, art. 35.3 and 46)
            'extended' => $reached < BudgetDecision::RANK['law'] && now()->toDateString() >= "$year-01-01",
        ];
    }

    /** The dates the calendar gives a stage, if any. */
    public function window(int $year, string $stage): ?ReportingWindow
    {
        return ReportingWindow::where('fiscal_year', $year)->where('period_type', 'budget')->where('period', self::calendarPeriod($stage))->first();
    }

    /**
     * What the latest decision changed from the one before: the total and each category that moved. Null with fewer than
     * two decisions.
     *
     * @return array{decision: BudgetDecision, previous: BudgetDecision, difference: float, rows: array<int, array{code: string, name: string, before: float, now: float, difference: float}>}|null
     */
    public function change(int $year): ?array
    {
        $two = BudgetDecision::where('fiscal_year', $year)->orderByDesc('decided_on')->orderByDesc('id')->take(2)->get();
        if ($two->count() < 2) {
            return null;
        }
        [$now, $before] = [$two[0], $two[1]];
        $rows = [];
        foreach (EconomicClassification::orderBy('code')->get(['id', 'code', 'name']) as $c) {
            $a = $before->amountFor($c->id);
            $b = $now->amountFor($c->id);
            if (abs($b - $a) >= 0.5) {
                $rows[] = ['code' => $c->code, 'name' => $c->name, 'before' => $a, 'now' => $b, 'difference' => $b - $a];
            }
        }

        return ['decision' => $now, 'previous' => $before, 'difference' => (float) $now->total - (float) $before->total, 'rows' => $rows];
    }

    /**
     * Where the latest decision, or the shares of it, are below what has already been spent: a cut that cannot be made,
     * since an amount executed is not undone. By category for the institution, and by unit where the decision is
     * distributed (the distribution of the latest decision being prepared, or in force).
     *
     * @return array<int, array{unit: ?string, code: string, name: string, limit: float, executed: float}>
     */
    public function belowSpent(int $year): array
    {
        if (! $decision = $this->latest($year)) {
            return [];
        }
        $executed = $this->planFigures()->rootClassExecuted($year);
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name']);
        $items = [];
        foreach ($classes as $c) {
            $spent = array_sum(array_map(fn ($byClass) => $byClass[$c->id] ?? 0.0, $executed));
            if ($spent > $decision->amountFor($c->id) + 0.5) {
                $items[] = ['unit' => null, 'code' => $c->code, 'name' => $c->name, 'limit' => $decision->amountFor($c->id), 'executed' => $spent];
            }
        }
        if (BudgetDistributions::on()) {
            $service = app(BudgetDistributions::class);
            $open = $service->openFor($year);
            $distribution = $open && (int) $open->budget_decision_id === (int) $decision->id ? $open : ($service->isCurrent($year) ? $service->inForce($year) : null);
            if ($distribution) {
                $matrix = $distribution->matrix();
                $shared = $service->sharedClassIds();
                foreach ($service->units() as $u) {
                    foreach ($classes as $c) {
                        $spent = (float) ($executed[$u->id][$c->id] ?? 0);
                        $share = (float) ($matrix[$u->id][$c->id] ?? 0);
                        if (in_array((int) $c->id, $shared, true) && $spent > $share + 0.5) {
                            $items[] = ['unit' => $u->code, 'code' => $c->code, 'name' => $c->name, 'limit' => $share, 'executed' => $spent];
                        }
                    }
                }
            }
        }

        return $items;
    }

    /**
     * What the institution does after the latest decision, step by step, and where each step stands: share the new
     * amount among the units (where the budget is distributed), make sure no cut goes below what was spent, and bring
     * the plan to the new amount in an adjustment round. Each step has a status: done, doing, waiting (for approval),
     * todo, problem or none (not needed).
     *
     * @return array{decision: BudgetDecision, change: ?array, increase: bool, steps: array<string, array<string, mixed>>}|null
     */
    public function nextSteps(int $year): ?array
    {
        if (! $decision = $this->latest($year)) {
            return null;
        }
        $change = $this->change($year);
        $steps = [];
        $distributing = BudgetDistributions::on();
        if ($distributing) {
            $service = app(BudgetDistributions::class);
            $open = $service->openFor($year);
            $steps['distribute'] = ['status' => match (true) {
                $service->isCurrent($year) => 'done',
                $open && (int) $open->budget_decision_id === (int) $decision->id && $open->status === BudgetDistribution::SUBMITTED => 'waiting',
                $open !== null => 'doing',
                default => 'todo',
            }];
        }
        $below = $this->belowSpent($year);
        $steps['spent'] = ['status' => $below ? 'problem' : 'done', 'items' => $below];

        $gap = $this->gap($year);
        $over = array_sum(array_map(fn ($r) => max(0.0, $r['difference']), $gap['rows'] ?? []));
        $overruns = $distributing ? count(app(BudgetDistributions::class)->overruns($year)) : 0;
        $increase = $change !== null && $change['difference'] > 0.5;
        $round = AdjustmentRound::where('fiscal_year', $year)->where('budget_decision_id', $decision->id)->orderByDesc('id')->first();
        $steps['adjust'] = [
            'status' => match (true) {
                $round && $round->closed_at !== null => 'done',
                $round !== null => 'doing',
                $over < 0.5 && $overruns === 0 && ! $increase => 'none',
                default => 'todo',
            },
            'over' => $over,
            'units_over' => $overruns,
        ];

        return ['decision' => $decision, 'change' => $change, 'increase' => $increase, 'steps' => $steps];
    }

    public function latest(int $year): ?BudgetDecision
    {
        return BudgetDecision::where('fiscal_year', $year)->orderByDesc('decided_on')->orderByDesc('id')->first();
    }

    /**
     * The plan against the latest decision, by economic category: what was decided, what is in force, the difference
     * (positive: the plan is above the decision) and what is still being prepared. Null when nothing is decided.
     *
     * @return array{decision: BudgetDecision, rows: array<int, array>, decided: float, in_force: float, preparing: float, difference: float}|null
     */
    public function gap(int $year): ?array
    {
        if (! $decision = $this->latest($year)) {
            return null;
        }
        $plan = $this->planFigures()->classTotals($year);
        $rows = [];
        foreach (EconomicClassification::orderBy('code')->get(['id', 'code', 'name']) as $c) {
            $decided = (float) ($decision->amounts[(string) $c->id] ?? 0);
            $inForce = $plan[$c->id]['in_force'] ?? 0.0;
            $preparing = $plan[$c->id]['preparing'] ?? 0.0;
            if ($decided == 0.0 && $inForce == 0.0 && $preparing == 0.0) {
                continue;
            }
            $rows[] = ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'decided' => $decided, 'in_force' => $inForce, 'preparing' => $preparing, 'difference' => $inForce - $decided];
        }
        $sum = fn ($k) => array_sum(array_column($rows, $k));

        return ['decision' => $decision, 'rows' => $rows, 'decided' => $sum('decided'), 'in_force' => $sum('in_force'), 'preparing' => $sum('preparing'), 'difference' => $sum('in_force') - $sum('decided')];
    }

    /**
     * The plan in force of each top-level unit and its share of the plan, so the planning office sees where the
     * difference sits. No share of the decision is given to a unit: the decision is not distributed.
     *
     * @return array<int, array{code: string, name: string, in_force: float, share: float}>
     */
    public function byUnit(int $year): array
    {
        $totals = $this->breakdown->unitTotals($year);
        $roots = Directorate::whereNull('parent_id')->orderBy('code')->get(['id', 'code', 'name']);
        $all = $roots->sum(fn ($d) => $totals[$d->id]['approved'] ?? 0);
        $rows = [];
        foreach ($roots as $d) {
            $inForce = (float) ($totals[$d->id]['approved'] ?? 0);
            if ($inForce > 0) {
                $rows[] = ['code' => (string) $d->code, 'name' => $d->name, 'in_force' => $inForce, 'share' => $all > 0 ? $inForce / $all : 0.0];
            }
        }

        return $rows;
    }
}
