<?php

namespace App\Services;

use App\Models\ApprovalDelegation;
use App\Models\BudgetDecision;
use App\Models\BudgetDistribution;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FundingSource;
use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\PlanOptions;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The distribution of the institution's budget among its top-level units (an optional rule,
 * PlanOptions::budgetDistribution).
 *
 * The State Budget sets the total of each budget title, that is of the institution (Decreto-Lei n.º 42/2025,
 * art. 18-19: the Jornada Orçamental, then the budget circular by 20 July); how that total is shared among the
 * institution's own directorates is the institution's decision. Here the budget unit (in a ministry, the finance
 * and administration directorate) prepares a distribution of the latest budget decision by unit and economic
 * category, and submits it; the head of the institution (or, by delegation, the head of the budget unit) approves
 * it with the reference of the act. Whatever is not given to a unit stays with the institution, salaries included
 * where they are managed centrally. The approved distribution of the year is the one in force; each unit's plan is
 * compared with its share, and the head who approves the plan is warned (or stopped, if the institution chose so)
 * when the plan is above it. Nothing is ever deleted: a later approval supersedes the earlier one.
 *
 * Bound as a scoped service: what it reads is kept for the rest of the request.
 */
class BudgetDistributions
{
    /** @var array<int, ?BudgetDistribution> */
    private array $inForce = [];

    /** @var array<int, array> */
    private array $plans = [];

    private ?Collection $units = null;

    public function __construct(private PlanBreakdown $breakdown, private PlanningWindow $window, private BudgetDecisions $decisions) {}

    public static function on(): bool
    {
        return PlanOptions::current()->budgetDistribution;
    }

    // ── the structure

    /**
     * The units a distribution shares the budget among: the active top-level units of the direct administration.
     * Public bodies of the indirect administration (institutes, public enterprises) have a budget title of their own.
     *
     * @return Collection<int, Directorate>
     */
    public function units(): Collection
    {
        return $this->units ??= Directorate::whereNull('parent_id')->where('active', true)
            ->where(fn ($q) => $q->whereNull('administration')->orWhere('administration', '!=', 'indirect'))
            ->orderBy('code')->get(['id', 'code', 'name', 'type']);
    }

    /** @return Collection<int, Directorate> the top-level units with a budget title of their own, not part of a distribution */
    public function ownTitleUnits(): Collection
    {
        return Directorate::whereNull('parent_id')->where('active', true)->where('administration', 'indirect')->orderBy('code')->get(['id', 'code', 'name']);
    }

    /** @return Collection<int, EconomicClassification> every economic category, in code order */
    public function classes(): Collection
    {
        return EconomicClassification::orderBy('code')->get(['id', 'code', 'name']);
    }

    /** The categories shared among the units: all of them, but salaries and wages where they stay with the institution. @return array<int, int> */
    public function sharedClassIds(): array
    {
        $central = PlanOptions::SALARIES_CENTRAL ? $this->salaryClassId() : null;

        return $this->classes()->pluck('id')->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $central)->values()->all();
    }

    public function salaryClassId(): ?int
    {
        $id = EconomicClassification::where('code', '10')->value('id');

        return $id ? (int) $id : null;
    }

    private Directorate|false|null $budgetUnit = false;

    public function budgetUnit(): ?Directorate
    {
        if ($this->budgetUnit === false) {
            $id = PlanOptions::current()->budgetUnitId;
            $this->budgetUnit = $id ? Directorate::whereNull('parent_id')->find($id, ['id', 'code', 'name']) : null;
        }

        return $this->budgetUnit;
    }

    // ── who may do what

    /** The budget unit's officers, technical officers and head prepare and submit a distribution; an administrator may too. */
    public function canPrepare(User $user): bool
    {
        if (! self::on()) {
            return false;
        }
        if ($user->isAdministrator()) {
            return true;
        }
        $unit = $this->budgetUnit();

        return $unit && $user->directorate_id && in_array((int) $user->directorate_id, $this->window->subtree($unit->id), true)
            && $user->hasAnyRole(['Planning', 'Finance', 'Director']);
    }

    /** Is this user the one who approves a distribution (the head of the institution, or of the budget unit by delegation)? */
    public function isApprover(User $user): bool
    {
        if (PlanOptions::current()->distributionApprover === PlanOptions::APPROVER_BUDGET_UNIT_HEAD) {
            $unit = $this->budgetUnit();

            return $unit !== null && $user->seats()->contains(fn (Directorate $d) => $d->id === $unit->id);
        }

        return $user->isMinister();
    }

    /** @return Collection<int, User> the people who approve a distribution */
    public function approvers(): Collection
    {
        if (PlanOptions::current()->distributionApprover === PlanOptions::APPROVER_BUDGET_UNIT_HEAD) {
            $unit = $this->budgetUnit();
            if (! $unit) {
                return collect();
            }
            $interim = ApprovalDelegation::active()->where('directorate_id', $unit->id)->pluck('user_id');

            return User::where(fn ($q) => $q->whereIn('id', $interim)->orWhere(fn ($q) => $q->where('directorate_id', $unit->id)->whereHas('roles', fn ($r) => $r->where('name', 'Director'))))->get();
        }

        return User::whereHas('roles', fn ($r) => $r->where('name', 'Minister'))->get();
    }

    /**
     * The approver approves or returns a submitted distribution; an administrator may act for them (it is recorded as on
     * their behalf). Never the person who submitted it: the one who prepares is not the one who approves.
     */
    public function canApprove(User $user, BudgetDistribution $distribution): bool
    {
        return self::on() && $distribution->status === BudgetDistribution::SUBMITTED
            && (int) $distribution->submitted_by !== (int) $user->id
            && ($this->isApprover($user) || $user->isAdministrator());
    }

    /** Who sees the whole distribution (everyone else sees their own unit's share on their pages). */
    public function canSeeAll(User $user): bool
    {
        return self::on() && ($user->isAdministrator() || $user->can('view_all_paa') || $this->canPrepare($user) || $this->isApprover($user));
    }

    // ── the year

    /** The distribution in force: the latest approved one of the year. */
    public function inForce(int $year): ?BudgetDistribution
    {
        if (! array_key_exists($year, $this->inForce)) {
            $this->inForce[$year] = BudgetDistribution::with('lines', 'decision')->where('fiscal_year', $year)
                ->where('status', BudgetDistribution::APPROVED)->orderByDesc('version')->first();
        }

        return $this->inForce[$year];
    }

    /** The distribution being prepared or waiting for approval, if any (one at a time per year). */
    public function openFor(int $year): ?BudgetDistribution
    {
        return BudgetDistribution::with('lines', 'decision')->where('fiscal_year', $year)
            ->whereIn('status', [BudgetDistribution::DRAFT, BudgetDistribution::SUBMITTED])->orderByDesc('version')->first();
    }

    /** @return Collection<int, BudgetDistribution> every distribution of the year, the latest first */
    public function history(int $year): Collection
    {
        return BudgetDistribution::with('decision', 'preparer:id,name', 'submitter:id,name', 'approver:id,name')
            ->withSum(['lines as given' => fn ($q) => $q->whereNotNull('directorate_id')], 'amount')
            ->where('fiscal_year', $year)->orderByDesc('version')->get();
    }

    /** Does the distribution in force share the latest decision (or an earlier one, so that the plan follows an old figure)? */
    public function isCurrent(int $year): bool
    {
        $inForce = $this->inForce($year);
        $latest = $this->decisions->latest($year);

        return $inForce && $latest && (int) $inForce->budget_decision_id === (int) $latest->id;
    }

    /**
     * The plan of each top-level unit by category, counting only the money of the institution's own budget title.
     *
     * @return array<int, array<int, array{in_force: float, preparing: float}>>
     */
    public function plan(int $year): array
    {
        return $this->plans[$year] ??= $this->breakdown->forSources(FundingSource::inTitle())->rootClassTotals($year);
    }

    /**
     * Where a unit stands against its share, category by category (only the categories that are shared): the share,
     * the plan in force, what is still being prepared, and the room left (negative: above the share). Null without a
     * distribution in force or when the unit is not part of it.
     *
     * @return array{rows: array<int, array>, share: float, planned: float, room: float, over: float, distribution: BudgetDistribution}|null
     */
    public function position(int $year, int $rootId): ?array
    {
        $distribution = $this->inForce($year);
        if (! $distribution || ! $this->units()->contains('id', $rootId)) {
            return null;
        }
        $matrix = $distribution->matrix();
        $plan = $this->plan($year)[$rootId] ?? [];
        $rows = [];
        foreach ($this->classes() as $c) {
            if (! in_array((int) $c->id, $this->sharedClassIds(), true)) {
                continue;
            }
            $share = (float) ($matrix[$rootId][$c->id] ?? 0);
            $inForce = (float) ($plan[$c->id]['in_force'] ?? 0);
            $preparing = (float) ($plan[$c->id]['preparing'] ?? 0);
            if ($share == 0.0 && $inForce == 0.0 && $preparing == 0.0) {
                continue;
            }
            $rows[] = ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name, 'share' => $share, 'in_force' => $inForce,
                'preparing' => $preparing, 'room' => $share - $inForce - $preparing];
        }
        $sum = fn ($k) => array_sum(array_column($rows, $k));

        return [
            'rows' => $rows, 'share' => $sum('share'), 'planned' => $sum('in_force') + $sum('preparing'), 'room' => $sum('room'),
            // a category above its share is not made good by room in another: the share is given by category
            'over' => array_sum(array_map(fn ($r) => max(0.0, -$r['room']), $rows)),
            'distribution' => $distribution,
        ];
    }

    /**
     * The categories in which the plan of each unit is above its share (rounding under a dollar ignored).
     *
     * @param  array<int, int>|null  $rootIds  null for every unit of the distribution
     * @return array<int, array<int, array{code: string, name: string, over: float}>> unit id => over by category
     */
    public function overruns(int $year, ?array $rootIds = null): array
    {
        if (! self::on() || ! $this->inForce($year)) {
            return [];
        }
        $result = [];
        foreach ($rootIds ?? $this->units()->pluck('id')->all() as $id) {
            $over = array_values(array_filter(array_map(
                fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'over' => -$r['room']],
                $this->position($year, (int) $id)['rows'] ?? []
            ), fn ($r) => $r['over'] >= 1));
            if ($over) {
                $result[(int) $id] = $over;
            }
        }

        return $result;
    }

    /** "20 Bens e Serviços $ 1,200; 40 Capital Menor $ 300": what is above the share, in words. */
    public static function describe(array $over): string
    {
        return implode('; ', array_map(fn ($r) => $r['code'].' '.$r['name'].' $ '.number_format($r['over'], 0), $over));
    }

    // ── preparing a distribution

    public const SUGGESTIONS = [
        'plan' => 'In proportion to the plan now',
        'last_year' => 'In proportion to last year\'s approved plan',
        'in_force' => 'As in the distribution in force',
        'same_percent' => 'Same percentages as the distribution in force',
    ];

    /**
     * A starting point for the shares of the latest decision, which the budget unit then changes as it decides:
     *   plan          each unit gets what its plan has in each category, scaled down when the plan is above the decision;
     *   last_year     the decision shared in proportion to each unit's approved plan of the year before;
     *   in_force      the shares of the distribution in force, scaled down where the new decision is lower;
     *   same_percent  each unit keeps the percentage of each category it has in the distribution in force, applied
     *                 to the new decision (what a new ceiling, law or supplementary budget usually calls for).
     * In whole dollars; the reserve takes what is left.
     *
     * @return array<int, array<int, float>> unit id => classification id => amount
     */
    public function suggest(int $year, string $method): array
    {
        if (! $decision = $this->decisions->latest($year)) {
            return [];
        }
        if ($method === 'same_percent') {
            $inForce = $this->inForce($year);

            return $inForce ? $this->fromPercents($decision, $this->toPercents($inForce->decision, $inForce->matrix(), 6), true) : [];
        }
        $units = $this->units()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $weights = match ($method) {
            'last_year' => array_map(fn ($byClass) => array_map(fn ($c) => $c['in_force'], $byClass), $this->breakdown->forSources(FundingSource::inTitle())->rootClassTotals($year - 1)),
            'in_force' => $this->inForce($year)?->matrix() ?? [],
            default => array_map(fn ($byClass) => array_map(fn ($c) => $c['in_force'] + $c['preparing'], $byClass), $this->plan($year)),
        };
        $result = [];
        foreach ($this->sharedClassIds() as $classId) {
            $decided = $decision->amountFor($classId);
            $w = [];
            foreach ($units as $u) {
                $w[$u] = max(0.0, (float) ($weights[$u][$classId] ?? 0));
            }
            $total = array_sum($w);
            if ($total <= 0 || $decided <= 0) {
                continue;
            }
            // the whole decision is shared out in proportion to last year; otherwise only scaled down when above it
            $factor = $method === 'last_year' || $total > $decided ? $decided / $total : 1.0;
            $amounts = array_map(fn ($v) => floor($v * $factor), $w);
            foreach ($amounts as $u => $amount) {
                if ($amount > 0) {
                    $result[$u][$classId] = (float) $amount;
                }
            }
        }

        return $result;
    }

    /**
     * Each unit's shares as a percentage of what the decision has in the category (only the shared categories with
     * money in the decision).
     *
     * @param  array<int|string, array<int|string, mixed>>  $amounts  unit id => classification id => amount
     * @return array<int, array<int, float>> unit id => classification id => percentage
     */
    public function toPercents(?BudgetDecision $decision, array $amounts, int $decimals = 2): array
    {
        $result = [];
        foreach ($this->units()->pluck('id') as $unit) {
            foreach ($this->sharedClassIds() as $class) {
                $decided = (float) ($decision?->amountFor($class) ?? 0);
                $amount = (float) ($amounts[$unit][$class] ?? 0);
                if ($decided > 0 && $amount > 0) {
                    $result[(int) $unit][$class] = round(100 * $amount / $decided, $decimals);
                }
            }
        }

        return $result;
    }

    /**
     * The amounts that percentages of each category of the decision come to. When the percentages of a category add up
     * to 100 % or less, rounding never takes the shares above the decision: any cent too many comes off the largest.
     *
     * @param  array<int|string, array<int|string, mixed>>  $percents  unit id => classification id => percentage
     * @return array<int, array<int, float>> unit id => classification id => amount
     */
    public function fromPercents(BudgetDecision $decision, array $percents, bool $wholeDollars = false): array
    {
        $unitIds = $this->units()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $result = [];
        foreach ($this->sharedClassIds() as $class) {
            $decided = (float) $decision->amountFor($class);
            if ($decided <= 0) {
                continue;
            }
            $byUnit = [];
            $pctSum = 0.0;
            foreach ($unitIds as $unit) {
                $p = $percents[$unit][$class] ?? null;
                if ($p === null || $p === '' || ! is_numeric($p) || (float) $p <= 0) {
                    continue;
                }
                $pctSum += (float) $p;
                $amount = $decided * (float) $p / 100;
                $byUnit[$unit] = $wholeDollars ? floor($amount + 1e-6) : round($amount, 2);
            }
            $excess = round(array_sum($byUnit) - $decided, 2);
            if ($byUnit && $excess > 0 && $pctSum <= 100.0001) {
                $largest = array_search(max($byUnit), $byUnit, true);
                $byUnit[$largest] = round($byUnit[$largest] - $excess, 2);
            }
            foreach ($byUnit as $unit => $amount) {
                if ($amount > 0) {
                    $result[$unit][$class] = (float) $amount;
                }
            }
        }

        return $result;
    }

    /**
     * Save the shares being prepared for the latest decision (a new draft, or the draft already open). Amounts are per
     * unit and category; the reserve of each category is what the decision leaves after the units' shares (it may be
     * negative while the draft is being worked on, but such a draft cannot be submitted).
     *
     * @param  array<int|string, array<int|string, mixed>>  $amounts  unit id => classification id => amount
     */
    public function save(User $user, int $year, array $amounts, ?string $note = null): BudgetDistribution
    {
        abort_unless($this->canPrepare($user), 403);
        if (! $decision = $this->decisions->latest($year)) {
            throw new DomainException(__('Record the budget decision first: the distribution shares it.'));
        }
        $open = $this->openFor($year);
        if ($open && $open->status === BudgetDistribution::SUBMITTED) {
            throw new DomainException(__('The distribution is waiting for approval: it can be changed once it is returned.'));
        }
        $unitIds = $this->units()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $classIds = $this->sharedClassIds();
        $clean = [];
        foreach ($amounts as $unit => $byClass) {
            if (! in_array((int) $unit, $unitIds, true) || ! is_array($byClass)) {
                continue;
            }
            foreach ($byClass as $class => $amount) {
                if (! in_array((int) $class, $classIds, true) || $amount === null || $amount === '') {
                    continue;
                }
                if (! is_numeric($amount) || (float) $amount < 0) {
                    throw new DomainException(__('An amount must be a number of zero or more.'));
                }
                if ((float) $amount > 0) {
                    $clean[(int) $unit][(int) $class] = round((float) $amount, 2);
                }
            }
        }

        return DB::transaction(function () use ($user, $year, $decision, $open, $clean, $note) {
            $distribution = $open ?? new BudgetDistribution([
                'fiscal_year' => $year,
                'version' => (int) BudgetDistribution::where('fiscal_year', $year)->lockForUpdate()->max('version') + 1,
                'status' => BudgetDistribution::DRAFT,
                'prepared_by' => $user->id,
            ]);
            $distribution->fill([
                // a draft always shares the latest decision: a decision recorded meanwhile is taken up on the next save
                'budget_decision_id' => $decision->id,
                'note' => filled($note) ? mb_substr(trim($note), 0, 2000) : null,
            ]);
            $distribution->save();
            $distribution->lines()->delete();
            $lines = [];
            foreach ($clean as $unit => $byClass) {
                foreach ($byClass as $class => $amount) {
                    $lines[] = ['directorate_id' => $unit, 'economic_classification_id' => $class, 'amount' => $amount];
                }
            }
            foreach ($this->classes() as $c) {
                $given = array_sum(array_map(fn ($byClass) => $byClass[$c->id] ?? 0, $clean));
                $reserve = round($decision->amountFor((int) $c->id) - $given, 2);
                if ($reserve != 0.0) {
                    $lines[] = ['directorate_id' => null, 'economic_classification_id' => $c->id, 'amount' => $reserve];
                }
            }
            $distribution->lines()->createMany($lines);

            return $distribution->load('lines', 'decision');
        });
    }

    /** Why a distribution cannot be submitted or approved yet (empty: it can). @return array<int, string> */
    public function problems(BudgetDistribution $distribution): array
    {
        $problems = [];
        $latest = $this->decisions->latest((int) $distribution->fiscal_year);
        if (! $latest || (int) $latest->id !== (int) $distribution->budget_decision_id) {
            $problems[] = __('A newer budget decision was recorded: save the distribution again to share it.');
        }
        $short = $distribution->lines->whereNull('directorate_id')->filter(fn ($l) => (float) $l->amount < -0.005);
        foreach ($short as $l) {
            $c = $this->classes()->firstWhere('id', $l->economic_classification_id);
            $problems[] = __('The shares of :category are $ :amount above the decision.', ['category' => $c ? $c->code.' '.$c->name : '?', 'amount' => number_format(-(float) $l->amount, 0)]);
        }
        if ($distribution->lines->whereNotNull('directorate_id')->isEmpty()) {
            $problems[] = __('Give a share to at least one unit.');
        }

        return $problems;
    }

    public function submit(User $user, BudgetDistribution $distribution): void
    {
        abort_unless($this->canPrepare($user) && $distribution->status === BudgetDistribution::DRAFT, 403);
        $distribution->loadMissing('lines');
        if ($problems = $this->problems($distribution)) {
            throw new DomainException(implode(' ', $problems));
        }
        $distribution->update(['status' => BudgetDistribution::SUBMITTED, 'submitted_by' => $user->id, 'submitted_at' => now(), 'return_note' => null]);

        $approvers = $this->approvers()->reject(fn (User $u) => $u->id === $user->id);
        if ($approvers->isEmpty()) {
            $approvers = User::whereHas('roles', fn ($r) => $r->where('name', 'Admin'))->where('id', '!=', $user->id)->get();
        }
        $approvers->each->notify(new SystemNotification(
            __('Budget distribution to approve'),
            __('The distribution of the budget of :year among the units (:stage) is waiting for your approval.', ['year' => $distribution->fiscal_year, 'stage' => $distribution->decision?->stageLabel()]),
            route('planning.budget-decisions', ['year' => $distribution->fiscal_year, 'tab' => 'distribution'], false),
        ));
    }

    /** The approver returns a submitted distribution to the budget unit, with the reason. */
    public function sendBack(User $user, BudgetDistribution $distribution, string $reason): void
    {
        abort_unless($this->canApprove($user, $distribution), 403);
        if (mb_strlen(trim($reason)) < 3) {
            throw new DomainException(__('Say why the distribution is returned.'));
        }
        $distribution->auditMetadata = ['by' => $user->id, 'returned' => trim($reason)];
        $distribution->update(['status' => BudgetDistribution::DRAFT, 'return_note' => mb_substr(trim($reason), 0, 2000)]);
        $distribution->submitter?->notify(new SystemNotification(
            __('Budget distribution returned'),
            __('The distribution of the budget of :year was returned: :reason', ['year' => $distribution->fiscal_year, 'reason' => trim($reason)]),
            route('planning.budget-decisions', ['year' => $distribution->fiscal_year, 'tab' => 'distribution'], false),
        ));
    }

    /**
     * Approve a submitted distribution with the reference and date of the act (the despacho). It comes into force, the
     * one in force until then is kept as superseded, and the heads and technical officers of each unit learn their share.
     */
    public function approve(User $user, BudgetDistribution $distribution, string $actReference, string $actDate): void
    {
        abort_unless($this->canApprove($user, $distribution), 403);
        if (mb_strlen(trim($actReference)) < 3) {
            throw new DomainException(__('Give the reference of the act that approves the distribution (for example the number of the despacho).'));
        }
        $distribution->loadMissing('lines');
        if ($problems = $this->problems($distribution)) {
            throw new DomainException(implode(' ', $problems));
        }

        DB::transaction(function () use ($user, $distribution, $actReference, $actDate) {
            BudgetDistribution::where('fiscal_year', $distribution->fiscal_year)->where('status', BudgetDistribution::APPROVED)
                ->get()->each(fn (BudgetDistribution $old) => $old->update(['status' => BudgetDistribution::SUPERSEDED]));
            $distribution->auditMetadata = ['by' => $user->id, 'act' => trim($actReference)];
            $distribution->update([
                'status' => BudgetDistribution::APPROVED, 'act_reference' => mb_substr(trim($actReference), 0, 255), 'act_date' => $actDate,
                'approved_by' => $user->id, 'approved_at' => now(), 'on_behalf' => ! $this->isApprover($user),
            ]);
        });
        unset($this->inForce[(int) $distribution->fiscal_year]);

        $this->tellTheUnits($distribution->fresh(['lines', 'decision']));
    }

    /**
     * What the printed distribution shows (the annex of the act that approves it): every unit that has a share, as the
     * structure stood, and every category, the ones kept by the institution included.
     *
     * @return array<string, mixed>
     */
    public function printable(BudgetDistribution $distribution): array
    {
        $distribution->loadMissing('lines', 'decision', 'preparer:id,name', 'submitter:id,name', 'approver:id,name');
        $matrix = $distribution->matrix();
        $unitIds = array_values(array_filter(array_keys($matrix)));

        return [
            'distribution' => $distribution,
            'matrix' => $matrix,
            'units' => Directorate::whereIn('id', $unitIds)->orderBy('code')->get(['id', 'code', 'name']),
            'classes' => $this->classes(),
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
        ];
    }

    /** The heads and technical officers of each unit with a share learn it. */
    private function tellTheUnits(BudgetDistribution $distribution): void
    {
        $byUnit = $distribution->lines->whereNotNull('directorate_id')->groupBy('directorate_id');
        foreach ($byUnit as $unitId => $lines) {
            $users = User::role(['Director', 'Planning'])->whereIn('directorate_id', $this->window->subtree((int) $unitId))->get();
            $users->each->notify(new SystemNotification(
                __('Budget distributed'),
                __('The budget of :year was distributed among the units (:stage). Your directorate\'s share is $ :amount: plan within it.', [
                    'year' => $distribution->fiscal_year, 'stage' => $distribution->decision?->stageLabel(), 'amount' => number_format((float) $lines->sum('amount'), 0),
                ]),
                route('dashboard', ['year' => $distribution->fiscal_year], false),
            ));
        }
    }
}
