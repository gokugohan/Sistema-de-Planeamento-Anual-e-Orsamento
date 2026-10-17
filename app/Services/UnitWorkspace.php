<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * What a unit's workspace shows: where its plan stands, what waits for the person looking at it, how the month's
 * reporting is going, and (for a unit with units below it) how each of them is doing. The same data serves a
 * planner's "My work", a head of directorate's view of the whole directorate, and an administrator or the Minister
 * looking at any unit's plan.
 *
 * Everything comes from grouped queries over the unit and its descendants; nothing is typed in by hand.
 */
class UnitWorkspace
{
    public const LIGHT_NONE = 'none';     // no activity yet

    public const LIGHT_OK = 'ok';         // everything approved, nothing waiting

    public const LIGHT_WORK = 'work';     // drafts or activities waiting for a decision

    public const LIGHT_ALERT = 'alert';   // something was returned for correction

    public function __construct(private ActivityWorkflow $workflow, private PlanningWindow $planning) {}

    /** The units a user may open as a workspace, with their descendants; null means every unit. @return array<int, int>|null */
    public function visibleUnitIds(User $user): ?array
    {
        if ($user->isAdministrator() || $user->can('view_all_paa')) {
            return null;
        }
        $own = $user->directorate_id ? (Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? []) : [];

        return array_values(array_unique(array_merge($own, $user->directedUnitIds(), app(ApproverLists::class)->unitIdsOf($user))));
    }

    public function canOpen(User $user, Directorate $unit): bool
    {
        $ids = $this->visibleUnitIds($user);

        return $ids === null || in_array($unit->id, $ids, true);
    }

    /** The unit a user's own work starts from: their own unit, else the first unit they direct or are named for. */
    public function homeUnit(User $user): ?Directorate
    {
        if ($user->directorate_id && ($d = Directorate::find($user->directorate_id))) {
            return $d;
        }

        if ($seat = $user->seats()->first()) {
            return $seat;
        }
        $named = app(ApproverLists::class)->unitIdsOf($user);

        return $named ? Directorate::find($named[0]) : null;
    }

    /** The units a person can switch between in the header of their workspace: their own, the ones they direct, those they are named for. @return Collection<int, Directorate> */
    public function switchableUnits(User $user): Collection
    {
        $ids = collect([$user->directorate_id])->merge($user->seats()->pluck('id'))->merge(app(ApproverLists::class)->unitIdsOf($user))->filter()->unique();

        return Directorate::whereIn('id', $ids)->orderBy('code')->get(['id', 'code', 'name', 'parent_id']);
    }

    /** @return array<int, int|null> unit id => parent id */
    private function parents(): array
    {
        return Directorate::pluck('parent_id', 'id')->all();
    }

    /** @return array<int, array<int, int>> unit id => its direct children */
    private function childrenMap(array $parents): array
    {
        $map = [];
        foreach ($parents as $id => $parent) {
            if ($parent) {
                $map[(int) $parent][] = (int) $id;
            }
        }

        return $map;
    }

    /** @return array<int, int> the unit and every unit below it */
    private function subtree(int $id, array $children): array
    {
        $out = [$id];
        foreach ($children[$id] ?? [] as $c) {
            $out = array_merge($out, $this->subtree($c, $children));
        }

        return $out;
    }

    /** The light of a unit from its counts. */
    public static function light(array $c): string
    {
        if (($c['total'] ?? 0) === 0) {
            return self::LIGHT_NONE;
        }
        if (($c['returned'] ?? 0) > 0) {
            return self::LIGHT_ALERT;
        }
        if (($c['draft'] ?? 0) + ($c['review'] ?? 0) + ($c['revising'] ?? 0) > 0) {
            return self::LIGHT_WORK;
        }

        return self::LIGHT_OK;
    }

    /**
     * The tree of units the user may open, each with the counts of its own subtree and a light. One grouped query
     * for the counts, the rest in memory.
     *
     * @return array<int, array{id: int, code: string, name: string, depth: int, parent_id: ?int, counts: array<string, int>, light: string, has_children: bool}>
     */
    public function tree(User $user, int $year, ?int $rootId = null): array
    {
        $units = Directorate::orderBy('code')->get(['id', 'code', 'name', 'parent_id'])->keyBy('id');
        $parents = $units->pluck('parent_id', 'id')->all();
        $children = $this->childrenMap($parents);
        $visible = $this->visibleUnitIds($user);

        // own counts per unit
        $own = [];
        foreach (Activity::where('fiscal_year', $year)->groupBy('directorate_id', 'status', DB::raw('version > 0'))
            ->selectRaw('directorate_id, status, (version > 0) as vers, COUNT(*) as n')->get() as $r) {
            $c = &$own[(int) $r->directorate_id];
            $c = $c ?? $this->emptyCounts();
            $c['total'] += (int) $r->n;
            match (true) {
                $r->status === Activity::STATUS_APPROVED => $c['approved'] += (int) $r->n,
                $r->status === Activity::STATUS_REJECTED => $c['returned'] += (int) $r->n,
                $r->status === Activity::STATUS_DRAFT => $c['draft'] += (int) $r->n,
                default => $c['review'] += (int) $r->n,
            };
            if ($r->status !== Activity::STATUS_APPROVED && (int) $r->vers === 1) {
                $c['revising'] += (int) $r->n;
            }
            unset($c);
        }

        $rows = [];
        $walk = function (int $id, int $depth) use (&$walk, &$rows, $units, $children, $own, $visible) {
            $ids = $this->subtree($id, $children);
            $counts = $this->emptyCounts();
            foreach ($ids as $u) {
                foreach ($own[$u] ?? [] as $k => $v) {
                    $counts[$k] += $v;
                }
            }
            if ($visible === null || in_array($id, $visible, true)) {
                $unit = $units[$id];
                $rows[] = ['id' => $id, 'code' => $unit->code, 'name' => $unit->name, 'depth' => $depth, 'parent_id' => $unit->parent_id ? (int) $unit->parent_id : null,
                    'counts' => $counts, 'light' => self::light($counts), 'has_children' => ! empty($children[$id])];
            }
            foreach ($children[$id] ?? [] as $c) {
                $walk($c, $depth + 1);
            }
        };
        if ($rootId !== null) {
            $walk($rootId, 0);
        } else {
            foreach ($units as $id => $u) {
                if (! $u->parent_id) {
                    $walk((int) $id, 0);
                }
            }
        }

        // a user whose own unit is deep in the tree sees it from there: lift the depth of what they may open
        if ($rows) {
            $min = min(array_column($rows, 'depth'));
            $rows = array_map(fn ($r) => ['depth' => $r['depth'] - $min] + $r, $rows);
        }

        return $rows;
    }

    private function emptyCounts(): array
    {
        return ['total' => 0, 'draft' => 0, 'review' => 0, 'approved' => 0, 'returned' => 0, 'revising' => 0];
    }

    /**
     * The workspace of one unit for the year. $tree: the unit and everything below it; otherwise only the unit.
     *
     * @return array<string, mixed>
     */
    public function for(User $user, Directorate $unit, int $year, bool $tree = true): array
    {
        $parents = $this->parents();
        $children = $this->childrenMap($parents);
        $ids = $tree ? $this->subtree($unit->id, $children) : [$unit->id];
        $activities = fn () => Activity::query()->where('fiscal_year', $year)->whereIn('directorate_id', $ids);

        // ── where the plan stands
        $by = $activities()->selectRaw('status, (version > 0) as vers, COUNT(*) as n')->groupBy('status', DB::raw('version > 0'))->get();
        $counts = $this->emptyCounts();
        foreach ($by as $r) {
            $counts['total'] += (int) $r->n;
            match ($r->status) {
                Activity::STATUS_APPROVED => $counts['approved'] += (int) $r->n,
                Activity::STATUS_REJECTED => $counts['returned'] += (int) $r->n,
                Activity::STATUS_DRAFT => $counts['draft'] += (int) $r->n,
                default => $counts['review'] += (int) $r->n,
            };
            if ($r->status !== Activity::STATUS_APPROVED && (int) $r->vers === 1) {
                $counts['revising'] += (int) $r->n;
            }
        }
        $statusCounts = $activities()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        // ── what waits for this person
        $mayDecide = $this->decisions($user, $activities, $year);

        // ── money: planned by every activity, the amounts of the approved versions in force, what has been paid (verified)
        $lineIds = fn () => DB::table('line_items as li')->join('activities as a', 'a.id', '=', 'li.activity_id')->where('a.fiscal_year', $year)->whereIn('a.directorate_id', $ids);
        $planned = (float) $lineIds()->sum('li.estimated_amount');
        $inForce = (float) $lineIds()->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')->sum('ab.approved_amount');
        $paid = (float) $lineIds()->join('budget_executions as be', 'be.line_item_id', '=', 'li.id')->where('be.status', BudgetExecution::VERIFIED)->sum('be.paid');

        // ── the month's reporting (the running year only)
        $reporting = $this->reporting($activities, $year);

        // ── people and places
        $unitsBelow = [];
        if ($tree && ! empty($children[$unit->id])) {
            $unitsBelow = array_values(array_filter($this->tree($user, $year, $unit->id), fn ($r) => $r['id'] !== $unit->id));
        }

        return [
            'unit' => $unit,
            'year' => $year,
            'counts' => $counts,
            'statuses' => $statusCounts,
            'light' => self::light($counts),
            'decide' => $mayDecide,
            'money' => ['planned' => $planned, 'in_force' => $inForce, 'paid' => $paid],
            'reporting' => $reporting,
            'units' => $unitsBelow,
            'planning' => $this->planningOf($unit, $year),
            'recent' => $this->recent($activities),
            'windows' => $this->windows($year),
            'path' => $this->path($unit, $parents),
        ];
    }

    /** What the person looking at the workspace has to decide, confirm or verify: activities that are their turn, budgets, spending and progress. @return array<string, int> */
    private function decisions(User $user, \Closure $activities, int $year): array
    {
        $toCertify = 0;
        $toApprove = 0;
        foreach ($activities()->whereIn('status', [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL])
            ->with('directorate:id,code,parent_id')->limit(400)->get() as $a) {
            if ($this->workflow->canApprove($user, $a)) {
                $toApprove++;
            } elseif ($this->workflow->next($user, $a) !== null) {
                $toCertify++;
            }
        }

        return ['certify' => $toCertify, 'approve' => $toApprove] + $this->verifications($user, $activities, $year);
    }

    /**
     * What a finance officer, a director or an administrator has to confirm or verify in the unit: the budget of submitted
     * activities (where the institution asks for it), the spending that units reported, and indicator progress. Never the
     * person's own reports.
     *
     * @return array{confirm: int, spending: int, progress: int}
     */
    private function verifications(User $user, \Closure $activities, int $year): array
    {
        $out = ['confirm' => 0, 'spending' => 0, 'progress' => 0];
        $admin = $user->isAdministrator();

        if (\App\Support\ApprovalRules::current()->budgetConfirmation && ($admin || $user->can('confirm_budget'))) {
            $out['confirm'] = $activities()->where('status', Activity::STATUS_SUBMITTED)->whereNull('budget_confirmed_at')->count();
        }
        if ($admin || $user->can('verify_spending') || $user->isDirector()) {
            $out['spending'] = (int) DB::table('budget_executions as be')->join('line_items as li', 'li.id', '=', 'be.line_item_id')
                ->whereIn('li.activity_id', $activities()->select('id'))->where('be.status', BudgetExecution::SUBMITTED)
                ->where(fn ($q) => $q->whereNull('be.submitted_by')->orWhere('be.submitted_by', '!=', $user->id))->count();
        }
        if ($admin || $user->can('approve_progress_report') || $user->isDirector()) {
            $out['progress'] = (int) DB::table('indicator_progress as ip')->join('indicators as i', 'i.id', '=', 'ip.indicator_id')
                ->whereIn('i.activity_id', $activities()->select('id'))->where('ip.status', 'Submitted')
                ->where(fn ($q) => $q->whereNull('ip.submitted_by')->orWhere('ip.submitted_by', '!=', $user->id))->count();
        }

        return $out;
    }

    /** The plan of a top-level unit: whether it has been finalized, and whether it is ready to be. @return array{root: ?array, closed: bool, ready: bool, pending: int} */
    private function planningOf(Directorate $unit, int $year): array
    {
        $rootId = $this->planning->rootOf($unit->id);
        if ($rootId !== $unit->id) {
            return ['root' => null, 'closed' => $this->planning->unitEndedBy($year, $unit->id) !== null, 'ready' => false, 'pending' => 0];
        }
        $row = collect($this->planning->state($year)['units'])->firstWhere('id', $rootId);

        return ['root' => $row, 'closed' => (bool) ($row['closed'] ?? false), 'ready' => $row && ! $row['closed'] && $row['pending'] === 0 && $row['approved'] > 0, 'pending' => (int) ($row['pending'] ?? 0)];
    }

    /** How much of the month's spending has been reported: the approved activities with an approved version, and those with a figure. @return array<string, mixed>|null */
    private function reporting(\Closure $activities, int $year): ?array
    {
        if ($year !== (int) now()->year) {
            return null;
        }
        $month = (int) now()->month;
        $due = $activities()->where('version', '>', 0)->whereHas('lineItems')->pluck('id');
        if ($due->isEmpty()) {
            return null;
        }
        $done = DB::table('budget_executions as be')->join('line_items as li', 'li.id', '=', 'be.line_item_id')
            ->whereIn('li.activity_id', $due)->where('be.month', $month)->distinct()->count('li.activity_id');
        $window = ReportingWindow::where(['fiscal_year' => $year, 'period_type' => 'month', 'period' => $month])->first();

        return ['month' => $month, 'total' => $due->count(), 'done' => $done, 'closes_on' => $window?->closes_on];
    }

    /** The last steps taken on the unit's activities: who did what, when. @return array<int, array{at: \Carbon\CarbonInterface, by: ?string, text: string, activity_id: int}> */
    private function recent(\Closure $activities): array
    {
        $ids = $activities()->pluck('id', 'id');
        if ($ids->isEmpty()) {
            return [];
        }
        $logs = AuditLog::where('auditable_type', (new Activity)->getMorphClass())->whereIn('auditable_id', $ids->keys())
            ->whereIn('action', ['activity.created', 'activity.updated'])->orderByDesc('id')->limit(40)->get();
        $names = User::whereIn('id', $logs->map(fn ($l) => $l->metadata['by'] ?? $l->user_id)->filter()->unique())->pluck('name', 'id');
        $codes = Activity::whereIn('id', $logs->pluck('auditable_id'))->pluck('code', 'id');

        $out = [];
        foreach ($logs as $l) {
            $to = ($l->new_values ?? [])['status'] ?? null;
            if ($l->action === 'activity.updated' && $to === null) {
                continue; // an edit of the content, not a step of the plan
            }
            $label = $l->action === 'activity.created' ? __('created') : match ($to) {
                Activity::STATUS_SUBMITTED => __('prepared'), Activity::STATUS_REVIEWED => __('verified'), Activity::STATUS_VALIDATED => __('certified'),
                Activity::STATUS_APPROVED => __('approved'), Activity::STATUS_REJECTED => __('returned'), Activity::STATUS_DRAFT => __('revision started'),
                default => __('updated'),
            };
            $out[] = ['at' => $l->created_at, 'by' => $names[$l->metadata['by'] ?? $l->user_id] ?? null, 'text' => $label, 'activity_id' => (int) $l->auditable_id, 'code' => $codes[$l->auditable_id] ?? ''];
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    /** The windows that matter in the next weeks: the plan submission and the months about to close. @return array<int, array{label: string, on: \Carbon\CarbonInterface}> */
    private function windows(int $year): array
    {
        $out = [];
        $today = now()->startOfDay();
        foreach (ReportingWindow::where('fiscal_year', $year)->whereDate('closes_on', '>=', $today)->whereDate('closes_on', '<=', $today->copy()->addDays(45))->orderBy('closes_on')->get() as $w) {
            $label = match ($w->period_type) {
                'plan' => __('Plan submission closes'),
                'month' => __('Report for :m closes', ['m' => \Carbon\Carbon::create($year, (int) $w->period, 1)->translatedFormat('F')]),
                default => __('Report for Q:q closes', ['q' => $w->period]),
            };
            $out[] = ['label' => $label, 'on' => $w->closes_on];
        }

        return $out;
    }

    /** The unit's place in the tree, from the top: Ministry › DGT › DNTT. @return array<int, array{id: int, code: string, name: string}> */
    private function path(Directorate $unit, array $parents): array
    {
        $chain = [];
        $id = $unit->id;
        for ($n = 0; $n < 20 && $id; $n++) {
            array_unshift($chain, $id);
            $id = $parents[$id] ?? null;
        }
        $all = Directorate::whereIn('id', $chain)->get(['id', 'code', 'name'])->keyBy('id');

        return array_map(fn ($i) => ['id' => $i, 'code' => $all[$i]->code, 'name' => $all[$i]->name], $chain);
    }
}
