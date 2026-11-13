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

        $rows = $this->rows(Activity::where('fiscal_year', $year)->whereIn('directorate_id', $unitIds ?: [0]), $approvedOnly);
        $tree = $this->tree($rows);

        $lists = app(ApproverLists::class);
        $names = fn (string $step) => $unit ? $lists->namesFor($unit->id, $step) : null;

        return [
            'year' => $year, 'unit' => $unit, 'approved_only' => $approvedOnly, 'tree' => $tree, 'by_program' => false,
            // what the choice leaves out, so an empty plan can say why
            'left_out' => $approvedOnly ? Activity::where('fiscal_year', $year)->whereIn('directorate_id', $unitIds ?: [0])->where('status', '!=', Activity::STATUS_APPROVED)->count() : 0,
            'total' => (float) $rows->sum('total'), 'activities' => $rows->count(),
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'prepared_by' => $names(ApproverAssignment::PREPARE), 'approved_by' => $names(ApproverAssignment::APPROVE),
            'reference' => sprintf('IMP-%d-%s', $year, $unit?->code ?? 'ALL'),
            'generated_at' => now(), 'generated_by' => $user->name,
        ];
    }

    /** The programmes that have activities in the year among the units the user may see. @return Collection<int, Program> */
    public function programs(User $user, int $year): Collection
    {
        $ids = $this->visible($user, Activity::where('fiscal_year', $year))->distinct()->pluck('program_id');

        return Program::whereIn('id', $ids)->orderBy('oge_code')->get(['id', 'oge_code', 'name']);
    }

    /**
     * The plan of the year in the same form, organised by programme instead of by unit: every unit the user may see
     * (the whole institution for those who see every plan), all programmes or one. Each activity names its unit.
     *
     * @return array<string, mixed>
     */
    public function byProgram(User $user, int $year, ?int $programId, bool $approvedOnly = true): array
    {
        $program = $programId ? $this->programs($user, $year)->firstWhere('id', $programId) : null;
        $query = fn () => $this->visible($user, Activity::where('fiscal_year', $year))->when($program, fn ($q) => $q->where('program_id', $program->id));
        $rows = $this->rows($query(), $approvedOnly);

        return [
            'year' => $year, 'unit' => null, 'program' => $program, 'approved_only' => $approvedOnly, 'tree' => $this->tree($rows), 'by_program' => true,
            'left_out' => $approvedOnly ? $query()->where('status', '!=', Activity::STATUS_APPROVED)->count() : 0,
            'total' => (float) $rows->sum('total'), 'activities' => $rows->count(),
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            // the plan of several units: signed by hand, no one person's name printed
            'prepared_by' => null, 'approved_by' => null,
            'reference' => sprintf('PAA-PROG-%d-%s', $year, $program?->oge_code ?? 'ALL'),
            'generated_at' => now(), 'generated_by' => $user->name,
        ];
    }

    /** Only the activities of the units the user may see (all of them for whoever sees every plan). */
    private function visible(User $user, $query)
    {
        $scope = app(PlanCycle::class)->scopeFor($user);

        return $query->when($scope !== null, fn ($q) => $q->whereIn('directorate_id', $scope ?: [0]));
    }

    /** Each activity with its indicators, the budget of each indicator, the budget not tied to one, and its total. */
    private function rows($query, bool $approvedOnly): Collection
    {
        $activities = $query
            // approved only, or every activity whatever its stage (drafts too: the document then says it is a draft)
            ->when($approvedOnly, fn ($q) => $q->where('status', Activity::STATUS_APPROVED))
            ->with(['directorate:id,code,name', 'indicators.actions', 'lineItems:id,activity_id,indicator_id,estimated_amount'])
            ->orderBy('directorate_id')->orderBy('id')->get();

        return $activities->map(function (Activity $a) {
            $byIndicator = $a->lineItems->whereNotNull('indicator_id')->groupBy('indicator_id')->map(fn ($l) => (float) $l->sum('estimated_amount'));

            return [
                'activity' => $a,
                'indicators' => $a->indicators->map(fn ($i) => ['indicator' => $i, 'budget' => (float) ($byIndicator[$i->id] ?? 0)])->all(),
                'whole' => (float) $a->lineItems->whereNull('indicator_id')->sum('estimated_amount'),
                'total' => (float) $a->lineItems->sum('estimated_amount'),
            ];
        });
    }

    /** Programme, then its subprogrammes, then the activities; activities without a subprogramme last. */
    private function tree(Collection $rows): Collection
    {
        $programs = Program::whereIn('id', $rows->pluck('activity.program_id')->unique())->orderBy('oge_code')->get(['id', 'oge_code', 'name']);
        $subs = Subprogram::whereIn('id', $rows->pluck('activity.subprogram_id')->filter()->unique())->orderBy('code')->get(['id', 'program_id', 'code', 'name'])->groupBy('program_id');

        return $programs->map(function (Program $p) use ($rows, $subs) {
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
    }
}
