<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\UnitPlanning;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Whether new activities can still be planned for a fiscal year, directorate by directorate.
 *
 * Planning ends with the final approval of the plan of a directorate: the head of the top-level unit (or an
 * administrator) finalizes it once nothing in that directorate, and the units below it, is waiting for a
 * decision. Only they can reopen it, and they must say why; both steps are in the audit trail. A directorate
 * that has finalized takes no new activities while the others go on planning.
 *
 * The year counts as ended when every directorate that planned has finalized (so an institution with one
 * directorate behaves as before).
 *
 * Finalizing stops new activities only: an approved activity can still be changed through a revision, which
 * makes a new version and goes through the approval chain again (see ActivityVersions).
 */
class PlanningWindow
{
    /** @var array<int, string> statuses of an activity that is waiting for somebody's decision */
    private const PENDING = [
        Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL,
    ];

    /** @var array<int, int|null>|null unit id => parent id, kept for the process and dropped whenever a unit changes */
    private static ?array $parents = null;

    /** @var array<int, Collection> year => the finalized directorates, dropped whenever one changes */
    private static array $closedRows = [];

    public static function forgetTree(): void
    {
        self::$parents = null;
        self::$closedRows = [];
    }


    // ── the tree

    private function parents(): array
    {
        return self::$parents ??= Directorate::pluck('parent_id', 'id')->all();
    }

    /** The top-level unit a unit belongs to (itself when it has no parent). */
    public function rootOf(int $unitId): int
    {
        $parents = $this->parents();
        for ($n = 0; $n < 20 && ($parents[$unitId] ?? null); $n++) {
            $unitId = (int) $parents[$unitId];
        }

        return $unitId;
    }

    /** The top-level units that have activities in the year. @return Collection<int, int> */
    public function plannedRoots(int $year): Collection
    {
        return Activity::where('fiscal_year', $year)->distinct()->pluck('directorate_id')->map(fn ($id) => $this->rootOf((int) $id))->unique()->values();
    }

    /** @return Collection<int, UnitPlanning> the finalized directorates of the year, by top-level unit id */
    private function closed(int $year): Collection
    {
        return self::$closedRows[$year] ??= UnitPlanning::where('fiscal_year', $year)->get()->keyBy('directorate_id');
    }

    // ── the verdict

    /** 'Finalized' when every directorate that planned has finalized its plan, null while planning is open. */
    public function endedBy(int $year): ?string
    {
        $roots = $this->plannedRoots($year);
        $closed = $this->closed($year);

        return $roots->isNotEmpty() && $roots->every(fn ($id) => $closed->has($id)) ? 'Finalized' : null;
    }

    /** The light version of state() for places that only need how far the directorates are. @return array{ended: ?string, units: array<int, array{closed: bool}>} */
    public function progress(int $year): array
    {
        $closed = $this->closed($year);
        $roots = $this->plannedRoots($year);

        return [
            'ended' => $roots->isNotEmpty() && $roots->every(fn ($id) => $closed->has($id)) ? 'Finalized' : null,
            'units' => $roots->map(fn ($id) => ['closed' => $closed->has($id)])->all(),
        ];
    }

    /** The same, for the plan of one unit (through its directorate). */
    public function unitEndedBy(int $year, int $unitId): ?string
    {
        return $this->closed($year)->has($this->rootOf($unitId)) ? 'Finalized' : null;
    }

    public function isOpenFor(int $year, int $unitId): bool
    {
        return $this->unitEndedBy($year, $unitId) === null;
    }

    /** The first year from this one on whose plan is still open for this unit: where its new activities go. */
    public function nextOpenYearFor(int $unitId): int
    {
        $current = (int) date('Y');
        foreach ([$current, $current + 1, $current + 2] as $year) {
            if ($this->isOpenFor($year, $unitId)) {
                return $year;
            }
        }

        return $current + 3;
    }

    /** The first year from this one on whose plan is still open for everyone (two queries for the three years). */
    public function nextOpenYear(): int
    {
        $current = (int) date('Y');
        foreach ([$current, $current + 1, $current + 2] as $year) {
            if ($this->endedBy($year) === null) {
                return $year;
            }
        }

        return $current + 3;
    }

    public function isOpen(int $year): bool
    {
        return $this->endedBy($year) === null;
    }

    // ── who

    /**
     * Who can finalize or reopen the plan: an administrator, or the head of a top-level unit for their own
     * directorate. With a unit given, that unit must be in a directorate the user heads; without one, heading
     * any directorate counts. (The year argument is kept for callers that pass it.)
     */
    public function canDecide(User $user, ?int $year = null, ?int $unitId = null): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }
        $seats = $user->seats()->filter(fn ($d) => ! $d->parent_id);
        if ($seats->isEmpty()) {
            return false;
        }

        return $unitId === null || $seats->contains(fn ($d) => $d->id === $this->rootOf($unitId));
    }

    /** The top-level units this user decides for in the year (every planned one for an administrator). @return Collection<int, int> */
    public function decidesFor(User $user, int $year): Collection
    {
        if ($user->isAdministrator()) {
            return $this->plannedRoots($year);
        }

        return $this->plannedRoots($year)->filter(fn ($id) => $this->canDecide($user, $year, $id))->values();
    }

    // ── the state

    /** Activities of the year (of the given directorates, with everything below them) that wait for a decision. */
    public function pending(int $year, ?array $rootIds = null): int
    {
        return Activity::where('fiscal_year', $year)->whereIn('status', self::PENDING)
            ->when($rootIds !== null, fn ($q) => $q->whereIn('directorate_id', $this->subtreeOf($rootIds)))->count();
    }

    /** @return array<int, int> the units in these directorates, the directorates included */
    private function subtreeOf(array $rootIds): array
    {
        return collect($this->parents())->keys()->filter(fn ($id) => in_array($this->rootOf((int) $id), $rootIds, true))->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Where planning stands for the year: the verdict for the whole year, and for each directorate that planned
     * whether it has finalized, who did and when, and what still waits.
     *
     * @return array{ended: ?string, by: ?string, at: ?CarbonInterface, remark: ?string, pending: int, units: array<int, array<string, mixed>>}
     */
    public function state(int $year): array
    {
        // one grouped query: the activities of the year by unit and status, folded into their directorates
        $perRoot = [];
        foreach (Activity::where('fiscal_year', $year)->groupBy('directorate_id', 'status')->selectRaw('directorate_id, status, count(*) as n')->get() as $row) {
            $root = $this->rootOf((int) $row->directorate_id);
            $perRoot[$root]['pending'] = ($perRoot[$root]['pending'] ?? 0) + (in_array($row->status, self::PENDING, true) ? (int) $row->n : 0);
            $perRoot[$root]['approved'] = ($perRoot[$root]['approved'] ?? 0) + ($row->status === Activity::STATUS_APPROVED ? (int) $row->n : 0);
        }
        $roots = collect(array_keys($perRoot));
        $closed = $this->closed($year);
        $names = User::whereIn('id', $closed->pluck('planning_by')->filter())->pluck('name', 'id');
        $dirs = Directorate::whereIn('id', $roots)->get(['id', 'code', 'name'])->keyBy('id');

        $ended = $roots->isNotEmpty() && $roots->every(fn ($id) => $closed->has($id)) ? 'Finalized' : null;

        $units = $roots->map(function ($id) use ($closed, $names, $dirs, $perRoot) {
            $row = $closed->get($id);

            return [
                'id' => $id, 'code' => $dirs[$id]->code ?? '', 'name' => $dirs[$id]->name ?? '',
                'closed' => $row !== null, 'by' => $row?->planning_by ? ($names[$row->planning_by] ?? null) : null,
                'at' => $row?->planning_at, 'remark' => $row?->planning_remark,
                'pending' => $perRoot[$id]['pending'], 'approved' => $perRoot[$id]['approved'],
            ];
        })->sortBy('code')->values()->all();

        $last = $closed->sortByDesc('planning_at')->first();

        return [
            'ended' => $ended,
            'by' => $last?->planning_by ? ($names[$last->planning_by] ?? null) : null,
            'at' => $last?->planning_at,
            'remark' => $last?->planning_remark,
            'pending' => array_sum(array_column($perRoot, 'pending')),
            'units' => $units,
        ];
    }

    // ── the acts

    /**
     * The final approval of the plan of a directorate: its planning ends. With no unit given, the user finalizes
     * every directorate they decide for that is still open (an administrator: all of them).
     */
    public function finalize(User $user, int $year, ?string $remark = null, ?int $unitId = null): void
    {
        $roots = $this->targets($user, $year, $unitId);

        $open = $roots->reject(fn ($id) => UnitPlanning::where(['fiscal_year' => $year, 'directorate_id' => $id])->exists());
        if ($open->isEmpty()) {
            throw new DomainException(__('The plan for :year is already finalized.', ['year' => $year]));
        }
        if ($pending = $this->pending($year, $open->all())) {
            throw new DomainException(trans_choice(
                ':n activity is still waiting for a decision. Decide on it before finalizing the plan.|:n activities are still waiting for a decision. Decide on them before finalizing the plan.',
                $pending, ['n' => $pending]
            ));
        }
        foreach ($open as $id) {
            if (! Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->whereIn('directorate_id', $this->subtreeOf([$id]))->exists()) {
                throw new DomainException(__('There is no approved activity to finalize.'));
            }
        }

        $remark = $remark !== null && trim($remark) !== '' ? trim($remark) : null;
        foreach ($open as $id) {
            $row = new UnitPlanning(['fiscal_year' => $year, 'directorate_id' => $id, 'planning_by' => $user->id, 'planning_at' => now(), 'planning_remark' => $remark]);
            $row->auditMetadata = array_filter(['by' => $user->id, 'remark' => $remark, 'planning' => 'finalized']);
            $row->save();
        }
    }

    /** Open planning again, for the head of the directorate or an administrator, with a remark. */
    public function reopen(User $user, int $year, string $remark, ?int $unitId = null): void
    {
        $roots = $this->targets($user, $year, $unitId);

        $closed = UnitPlanning::where('fiscal_year', $year)->whereIn('directorate_id', $roots)->get();
        if ($closed->isEmpty()) {
            throw new DomainException(__('Planning for :year is already open.', ['year' => $year]));
        }
        if (mb_strlen(trim($remark)) < 3) {
            throw new DomainException(__('Say why planning is being reopened.'));
        }
        foreach ($closed as $row) {
            $row->auditMetadata = ['by' => $user->id, 'remark' => trim($remark), 'planning' => 'reopened'];
            $row->delete();
        }
    }

    /** The directorates an act applies to: the one asked for, or every one the user decides for. @return Collection<int, int> */
    private function targets(User $user, int $year, ?int $unitId): Collection
    {
        if ($unitId !== null) {
            abort_unless($this->canDecide($user, $year, $unitId), 403);

            return collect([$this->rootOf($unitId)]);
        }
        $mine = $this->decidesFor($user, $year);
        if ($mine->isEmpty()) {
            abort_unless($this->canDecide($user, $year), 403);
            throw new DomainException(__('There is no approved activity to finalize.'));
        }

        return $mine;
    }
}
