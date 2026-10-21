<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\UnitPlanning;
use App\Models\User;
use App\Support\PlanOptions;

/**
 * How complete and how approved the plan of a year is, directorate by directorate (the top-level units): activities not
 * yet submitted, activities waiting for a decision, activities approved, and whether the plan has been finalized.
 * The order of the year is: the plan is complete (everything submitted), then it is fully approved, then it is finalized.
 * Used to hold back approval and finalizing where the institution asks for that, and to warn before a report is printed.
 */
class PlanStatus
{
    private const DRAFTS = [Activity::STATUS_DRAFT, Activity::STATUS_REJECTED];

    private const PENDING = [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL];

    public function __construct(private PlanningWindow $planning) {}

    /**
     * @param  array<int, int>|null  $unitIds  limit to activities of these units
     * @return array<int, array{id: int, name: string, drafts: int, pending: int, approved: int, finalized: bool, complete: bool, fully_approved: bool}> by top-level unit
     */
    public function perRoot(int $year, ?array $unitIds = null): array
    {
        $rows = Activity::where('fiscal_year', $year)
            ->when($unitIds !== null, fn ($q) => $q->whereIn('directorate_id', $unitIds ?: [0]))
            ->groupBy('directorate_id', 'status')->selectRaw('directorate_id, status, SUM(version = 0) AS first_n, COUNT(*) AS n')->get();

        $out = [];
        foreach ($rows as $r) {
            $root = $this->planning->rootOf((int) $r->directorate_id);
            $o = &$out[$root];
            $o ??= ['id' => $root, 'name' => '', 'drafts' => 0, 'pending' => 0, 'approved' => 0, 'finalized' => false, 'complete' => true, 'fully_approved' => false];
            if (in_array($r->status, self::DRAFTS, true)) {
                $o['drafts'] += (int) $r->first_n; // a returned revision is not an incomplete plan
            } elseif (in_array($r->status, self::PENDING, true)) {
                $o['pending'] += (int) $r->n;
            } elseif ($r->status === Activity::STATUS_APPROVED) {
                $o['approved'] += (int) $r->n;
            }
            unset($o);
        }

        $names = Directorate::whereIn('id', array_keys($out))->pluck('name', 'id');
        $finalized = UnitPlanning::where('fiscal_year', $year)->pluck('directorate_id')->map(fn ($id) => (int) $id)->all();
        foreach ($out as $id => &$o) {
            $o['name'] = (string) ($names[$id] ?? '—');
            $o['finalized'] = in_array($id, $finalized, true);
            $o['complete'] = $o['drafts'] === 0;
            $o['fully_approved'] = $o['drafts'] === 0 && $o['pending'] === 0 && $o['approved'] > 0;
        }

        return $out;
    }

    /** Activities of the directorate of this unit that are not yet submitted (the plan is complete when there are none). */
    public function notSubmitted(int $year, int $unitId): int
    {
        return Activity::where('fiscal_year', $year)->where('version', 0)->whereIn('status', self::DRAFTS)
            ->whereIn('directorate_id', $this->planning->subtree($unitId))->count();
    }

    /** Why this activity cannot be decided on yet (null when it can): the plan of its directorate is not complete, where that is required. */
    public function holdBack(Activity $activity): ?string
    {
        if (! PlanOptions::current()->completeBeforeApproval || (int) $activity->version > 0) {
            return null;
        }
        $n = $this->notSubmitted((int) $activity->fiscal_year, (int) $activity->directorate_id);

        return $n > 0 ? $this->incompleteText($n) : null;
    }

    public function incompleteText(int $n): string
    {
        return trans_choice(
            ':n activity of the directorate is not submitted yet. Approval starts when the whole plan is complete.|:n activities of the directorate are not submitted yet. Approval starts when the whole plan is complete.',
            $n, ['n' => $n]
        );
    }

    /**
     * A warning to read before a report of the plan is printed: the plan is not complete, not fully approved or not finalized.
     * Null when the plan the report covers is fully approved (and finalized).
     */
    public function reportWarning(?User $user, int $year, ?int $unitId = null): ?string
    {
        $scope = $unitId ? $this->planning->subtree($unitId) : ($user ? app(PlanCycle::class)->scopeFor($user) : null);
        $roots = $this->perRoot($year, $scope);
        if ($roots === []) {
            return __('There are no activities in this plan yet.');
        }
        $drafts = array_sum(array_column($roots, 'drafts'));
        $pending = array_sum(array_column($roots, 'pending'));
        $open = count(array_filter($roots, fn ($r) => ! $r['finalized']));

        $parts = [];
        if ($drafts > 0) {
            $parts[] = trans_choice(':n activity is not submitted yet|:n activities are not submitted yet', $drafts, ['n' => $drafts]);
        }
        if ($pending > 0) {
            $parts[] = trans_choice(':n activity is waiting for a decision|:n activities are waiting for a decision', $pending, ['n' => $pending]);
        }
        if ($parts !== []) {
            return __('The plan is not fully approved: :what. The figures can still change.', ['what' => implode('; ', $parts)]);
        }
        if ($open > 0) {
            return trans_choice('All activities are decided, but :n directorate has not finalized its plan yet.|All activities are decided, but :n directorates have not finalized their plan yet.', $open, ['n' => $open]);
        }

        return null;
    }
}
