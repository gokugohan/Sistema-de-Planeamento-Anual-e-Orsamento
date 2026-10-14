<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\Program;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures behind the annual performance report (Reporting > PAA): the plan of one fiscal year,
 * planned against approved (distributed) amounts, by organisational unit and by programme.
 *
 * Everything is added up by the database in one grouped query, and the page only prints the result.
 * The report used to load every activity, budget line and approval into memory and filter those
 * collections once per activity, which grew with the square of the number of activities and did not
 * finish with a few thousand of them.
 */
class PlanBreakdown
{
    /**
     * Planned and approved amount of every unit including the units below it.
     *
     * @return array<int, array{planned: float, approved: float}> keyed by directorate id
     */
    public function unitTotals(int $year): array
    {
        $own = $this->amounts($year)
            ->groupBy('a.directorate_id')
            ->selectRaw('a.directorate_id AS id, SUM(li.estimated_amount) AS planned, COALESCE(SUM(ab.approved_amount), 0) AS approved')
            ->get()->keyBy('id');

        $children = Directorate::query()->get(['id', 'parent_id'])->groupBy(fn ($d) => $d->parent_id ?? 0);
        $totals = [];
        $roll = function (int $id, array $path = []) use (&$roll, &$totals, $own, $children): array {
            $sum = [
                'planned' => (float) ($own[$id]->planned ?? 0),
                'approved' => (float) ($own[$id]->approved ?? 0),
            ];
            foreach ($children->get($id, collect()) as $child) {
                if (in_array($child->id, $path, true)) {
                    continue; // a cycle in the data must not loop forever
                }
                $sub = $roll($child->id, [...$path, $id]);
                $sum['planned'] += $sub['planned'];
                $sum['approved'] += $sub['approved'];
            }

            return $totals[$id] = $sum;
        };
        foreach ($children->get(0, collect()) as $root) {
            $roll($root->id);
        }

        return $totals;
    }

    /** Amount distributed to the activities of the year. */
    public function approvedTotal(int $year): float
    {
        return (float) $this->amounts($year)->selectRaw('COALESCE(SUM(ab.approved_amount), 0) AS approved')->value('approved');
    }

    /**
     * Programme > economic category, each with planned and approved amounts and the number of activities.
     * Only programmes that have budget lines in the year appear. The activities themselves are not
     * included (a plan can have thousands): ask for them per category with activities(), or pass
     * $withActivities to get them all at once for a printout or export.
     *
     * @return Collection<int, array{program: Program, planned: float, approved: float, classes: array}>
     */
    public function programmes(int $year, bool $withActivities = false): Collection
    {
        $rows = $this->amounts($year)
            ->groupBy('a.program_id', 'li.economic_classification_id')
            ->selectRaw('a.program_id, li.economic_classification_id AS class_id, COUNT(DISTINCT a.id) AS activity_count, SUM(li.estimated_amount) AS planned, COALESCE(SUM(ab.approved_amount), 0) AS approved')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $programs = Program::query()->whereIn('id', $rows->pluck('program_id')->unique())->orderBy('oge_code')->get()->keyBy('id');
        $classes = EconomicClassification::query()->orderBy('code')->get()->keyBy('id');

        $result = [];
        foreach ($rows->groupBy('program_id') as $programId => $byProgram) {
            $row = ['program' => $programs[$programId], 'planned' => 0.0, 'approved' => 0.0, 'classes' => []];
            foreach ($byProgram->sortBy(fn ($r) => $classes[$r->class_id]->code) as $r) {
                $row['classes'][$r->class_id] = [
                    'classification' => $classes[$r->class_id],
                    'planned' => (float) $r->planned,
                    'approved' => (float) $r->approved,
                    'count' => (int) $r->activity_count,
                    'activities' => $withActivities ? $this->activities($year, (int) $programId, (int) $r->class_id) : [],
                ];
                $row['planned'] += (float) $r->planned;
                $row['approved'] += (float) $r->approved;
            }
            $result[$programId] = $row;
        }

        return collect($result)->sortBy(fn ($r) => $r['program']->oge_code)->values();
    }

    /**
     * The activities behind one programme and economic category, with their indicators.
     *
     * @return array<int, array{activity: Activity, indicators: Collection, planned: float, approved: float}>
     */
    public function activities(int $year, int $programId, int $classId): array
    {
        $rows = $this->amounts($year)
            ->where('a.program_id', $programId)
            ->where('li.economic_classification_id', $classId)
            ->groupBy('a.id')
            ->selectRaw('a.id AS activity_id, SUM(li.estimated_amount) AS planned, COALESCE(SUM(ab.approved_amount), 0) AS approved')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $activities = Activity::query()->whereIn('id', $rows->pluck('activity_id'))
            ->with('directorate:id,code')->get(['id', 'description', 'priority', 'directorate_id'])->keyBy('id');
        $indicators = Indicator::query()->whereIn('activity_id', $activities->keys())
            ->get(['id', 'activity_id', 'name', 'annual_target', 'unit'])->groupBy('activity_id');

        return $rows->map(fn ($r) => [
            'activity' => $activities[$r->activity_id],
            'indicators' => $indicators->get($r->activity_id, collect()),
            'planned' => (float) $r->planned,
            'approved' => (float) $r->approved,
        ])->sortBy(fn ($r) => $r['activity']->directorate_id)->values()->all();
    }

    /** Production activities of the year joined to their budget lines and the amounts distributed to them. */
    private function amounts(int $year)
    {
        return DB::table('activities as a')
            ->join('line_items as li', fn ($j) => $j->on('li.activity_id', '=', 'a.id')->where('li.is_test_data', false))
            ->leftJoin('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')
            ->where('a.fiscal_year', $year)
            ->where('a.is_test_data', false);
    }
}
