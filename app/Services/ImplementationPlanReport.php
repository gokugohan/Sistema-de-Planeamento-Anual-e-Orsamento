<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The implementation plan of one unit for one year, in the ministry's form: per programme and subprogramme, each
 * activity with its indicators, the numbered actions under each indicator, the months each action is carried out
 * in, and the budget allocated per indicator. Budget lines not tied to an indicator are shown as the activity's own.
 */
class ImplementationPlanReport
{
    /** The units the user may print the plan of that have activities in the year (a unit stands for itself and the units below it). @return Collection<int, Directorate> */
    public function units(User $user, int $year): Collection
    {
        $scope = app(PlanCycle::class)->scopeFor($user);
        $ids = Activity::where('fiscal_year', $year)->when($scope !== null, fn ($q) => $q->whereIn('directorate_id', $scope ?: [0]))->distinct()->pluck('directorate_id');
        if ($ids->isEmpty()) {
            return collect();
        }
        $all = Directorate::get(['id', 'code', 'name', 'parent_id'])->keyBy('id');
        // the units that have activities, and the ones above them that the user may see
        $shown = collect();
        foreach ($ids as $id) {
            for ($u = $all[$id] ?? null, $n = 0; $u && $n < 10; $u = $u->parent_id ? ($all[$u->parent_id] ?? null) : null, $n++) {
                if ($scope === null || in_array($u->id, $scope, true)) {
                    $shown[$u->id] = $u;
                }
            }
        }

        return $shown->sortBy('code')->values();
    }

    /** @return array<string, mixed> */
    public function build(User $user, int $year, ?int $unitId, bool $approvedOnly = true): array
    {
        $unit = $this->units($user, $year)->firstWhere('id', $unitId) ?? $this->units($user, $year)->first();
        $unitIds = $unit ? Directorate::find($unit->id)->getSelfAndDescendantIds() : [];

        $activities = Activity::where('fiscal_year', $year)->whereIn('directorate_id', $unitIds ?: [0])
            ->when($approvedOnly, fn ($q) => $q->where('status', Activity::STATUS_APPROVED), fn ($q) => $q->where('status', '!=', Activity::STATUS_DRAFT))
            ->with(['directorate:id,code,name', 'indicators.actions', 'lineItems:id,activity_id,indicator_id,estimated_amount'])
            ->orderBy('directorate_id')->orderBy('id')->get();

        $rows = $activities->map(function (Activity $a) {
            $byIndicator = $a->lineItems->whereNotNull('indicator_id')->groupBy('indicator_id')->map(fn ($l) => (float) $l->sum('estimated_amount'));

            return [
                'activity' => $a,
                'indicators' => $a->indicators->map(fn ($i) => ['indicator' => $i, 'budget' => (float) ($byIndicator[$i->id] ?? 0)])->all(),
                'whole' => (float) $a->lineItems->whereNull('indicator_id')->sum('estimated_amount'),
                'total' => (float) $a->lineItems->sum('estimated_amount'),
            ];
        });

        $programs = Program::whereIn('id', $rows->pluck('activity.program_id')->unique())->orderBy('oge_code')->get(['id', 'oge_code', 'name']);
        $subs = Subprogram::whereIn('id', $rows->pluck('activity.subprogram_id')->filter()->unique())->orderBy('code')->get(['id', 'program_id', 'code', 'name'])->groupBy('program_id');

        $tree = $programs->map(function (Program $p) use ($rows, $subs) {
            $inP = $rows->filter(fn ($r) => $r['activity']->program_id === $p->id);

            return [
                'program' => $p,
                'subprograms' => ($subs[$p->id] ?? collect())->map(function (Subprogram $s) use ($inP) {
                    $own = $inP->filter(fn ($r) => $r['activity']->subprogram_id === $s->id)->values();

                    return ['subprogram' => $s, 'rows' => $own, 'total' => (float) $own->sum('total')];
                }),
                'loose' => $inP->filter(fn ($r) => ! $r['activity']->subprogram_id)->values(),
                'total' => (float) $inP->sum('total'),
            ];
        })->values();

        $lists = app(ApproverLists::class);
        $names = fn (string $step) => $unit ? $lists->namesFor($unit->id, $step) : null;

        return [
            'year' => $year, 'unit' => $unit, 'approved_only' => $approvedOnly, 'tree' => $tree,
            'total' => (float) $rows->sum('total'), 'activities' => $rows->count(),
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'prepared_by' => $names(ApproverAssignment::PREPARE), 'approved_by' => $names(ApproverAssignment::APPROVE),
            'reference' => sprintf('IMP-%d-%s', $year, $unit?->code ?? 'ALL'),
            'generated_at' => now(), 'generated_by' => $user->name,
        ];
    }
}
