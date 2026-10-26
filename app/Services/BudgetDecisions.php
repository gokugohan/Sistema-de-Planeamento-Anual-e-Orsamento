<?php

namespace App\Services;

use App\Models\BudgetDecision;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\User;
use App\Support\PlanOptions;
use DomainException;
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

    /** Only an administrator records a decision. */
    public function canRecord(User $user): bool
    {
        return PlanOptions::current()->budgetDecisions && $user->isAdministrator();
    }

    /**
     * Record a decision with its amount per economic category, and keep the plan in force at that moment
     * (its amounts by category, and a baseline when something is approved).
     *
     * @param  array<int|string, mixed>  $amounts  economic classification id => amount
     */
    public function record(User $user, int $year, string $stage, string $decidedOn, array $amounts, ?string $reference = null, ?string $note = null): BudgetDecision
    {
        abort_unless($this->canRecord($user), 403);
        if (! array_key_exists($stage, BudgetDecision::STAGES)) {
            throw new DomainException(__('Choose where the decision comes from.'));
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

        $plan = array_map(fn ($c) => round($c['in_force'], 2), $this->breakdown->classTotals($year));
        $reference = filled($reference) ? mb_substr(trim($reference), 0, 255) : null;

        return DB::transaction(function () use ($user, $year, $stage, $decidedOn, $clean, $plan, $reference, $note) {
            $baseline = null;
            if (array_sum($plan) > 0) {
                $baseline = app(PlanBaselines::class)->capture($user, $year, __('Plan when the decision was recorded: :stage', ['stage' => __(BudgetDecision::STAGES[$stage])]).($reference ? ' ('.$reference.')' : ''));
            }

            return BudgetDecision::create([
                'fiscal_year' => $year,
                'stage' => $stage,
                'decided_on' => $decidedOn,
                'reference' => $reference,
                'note' => filled($note) ? mb_substr(trim($note), 0, 2000) : null,
                'amounts' => $clean,
                'total' => array_sum($clean),
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
        $plan = $this->breakdown->classTotals($year);
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
