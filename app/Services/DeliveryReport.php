<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Delivery against annual targets, from verified quarterly progress only.
 *
 * An indicator's standing after a quarter depends on how it is measured: a cumulative one adds the
 * quarters up, a level takes the latest. Its achievement is that standing over its annual target, capped
 * at 100%; the delivery of a set of indicators is the average of their achievements, so an indicator
 * counted in thousands does not drown one counted in units. Indicators with no verified progress count
 * as 0%. Everything is added up by the database in one grouped query.
 */
class DeliveryReport
{
    /**
     * @param  Builder  $activityIds  a query selecting the ids of the activities to include
     * @return array{n: int, achievement: ?float, quarters: array<int, float>} achievement after the year's last quarter; quarters = the average after each quarter
     */
    public function summary(Builder $activityIds): array
    {
        $row = DB::query()->fromSub($this->perIndicator($activityIds), 't')
            ->selectRaw('COUNT(*) AS n, '.$this->averages())
            ->first();

        $n = (int) $row->n;

        return [
            'n' => $n,
            'achievement' => $n ? round((float) $row->p4, 1) : null,
            'quarters' => $n ? array_map(fn ($q) => round((float) $row->{"p{$q}"}, 1), [1, 2, 3, 4]) : [0.0, 0.0, 0.0, 0.0],
        ];
    }

    /**
     * Indicators of approved activities that are due in $quarter (every quarter unless they say otherwise)
     * and have no verified figure for it yet.
     *
     * @param  Builder  $activityIds  a query selecting the ids of the activities to include
     */
    public function dueWithoutFigure(Builder $activityIds, int $quarter): int
    {
        return DB::table('indicators as i')
            ->join('activities as a', 'a.id', '=', 'i.activity_id')
            ->whereIn('i.activity_id', $activityIds)
            ->where('a.status', 'Approved')
            ->where(fn ($q) => $q->whereNull('i.due_quarters')->orWhereRaw('JSON_CONTAINS(i.due_quarters, ?)', [(string) $quarter]))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('indicator_progress as p')
                ->whereColumn('p.indicator_id', 'i.id')->where('p.quarter', $quarter)->where('p.status', 'Verified'))
            ->count();
    }

    /** Average achievement of each programme's indicators for a year. @return array<int, float> keyed by programme id */
    public function byProgramme(int $year): array
    {
        $ids = DB::table('activities')->where('fiscal_year', $year)->select('id');

        return DB::query()->fromSub($this->perIndicator($ids), 't')->groupBy('program_id')
            ->selectRaw('program_id, '.$this->averages())
            ->get()->mapWithKeys(fn ($r) => [(int) $r->program_id => (float) $r->p4])->all();
    }

    /** One row per indicator with a target: its verified value in each quarter (null where nothing is verified). */
    private function perIndicator(Builder $activityIds): Builder
    {
        $quarter = fn (int $q) => "SUM(CASE WHEN p.status = 'Verified' AND p.quarter = {$q} THEN p.achieved_value END) AS v{$q}";

        return DB::table('indicators as i')
            ->join('activities as a', 'a.id', '=', 'i.activity_id')
            ->leftJoin('indicator_progress as p', 'p.indicator_id', '=', 'i.id')
            ->whereIn('i.activity_id', $activityIds)
            ->where('i.annual_target', '>', 0)
            ->groupBy('i.id', 'i.annual_target', 'i.measure', 'a.program_id')
            ->selectRaw('a.program_id, i.annual_target, i.measure, '.implode(', ', array_map($quarter, [1, 2, 3, 4])));
    }

    /** The averages after each quarter, as SQL over the per-indicator rows. */
    private function averages(): string
    {
        return implode(', ', array_map(fn ($q) => "AVG(LEAST(100, {$this->standingSql($q)} / annual_target * 100)) AS p{$q}", [1, 2, 3, 4]));
    }

    /** SQL for where an indicator stands after quarter $n: the sum of v1..vn, or the latest of them for a level. */
    private function standingSql(int $n): string
    {
        $cols = array_map(fn ($q) => "v{$q}", range(1, $n));
        $sum = implode(' + ', array_map(fn ($c) => "COALESCE({$c}, 0)", $cols));
        $latest = 'COALESCE('.implode(', ', array_reverse($cols)).', 0)';

        return "(CASE WHEN measure = 'level' THEN {$latest} ELSE {$sum} END)";
    }
}
