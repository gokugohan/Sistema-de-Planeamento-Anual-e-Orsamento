<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\Directorate;
use App\Models\Indicator;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * The monthly or quarterly performance report of one fiscal year, compiled by unit: for each approved
 * activity what was committed and paid in the period and so far against the approved budget, how the
 * indicators stand, and the narrative the unit wrote. Verified figures and verified narrative only, and
 * only the units the reader may see.
 */
class PerformanceReport
{
    /** @return array<string, mixed> */
    public function build(int $year, string $type, int $period, User $user): array
    {
        $type = $type === 'quarter' ? 'quarter' : 'month';
        $period = max(1, min($period, $type === 'quarter' ? 4 : 12));
        $months = $type === 'quarter' ? range($period * 3 - 2, $period * 3) : [$period];
        $lastMonth = max($months);
        $quarter = $type === 'quarter' ? $period : (int) ceil($period / 3);

        $unitIds = ($user->can('view_all_paa') || $user->isAdministrator())
            ? null
            : (Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? []);

        $money = app(ExecutionReport::class)->load($year, $unitIds)['activities'];
        $activityIds = array_keys($money);

        $activities = Activity::whereIn('id', $activityIds)->where('status', Activity::STATUS_APPROVED)
            ->with(['directorate:id,code,name', 'program:id,oge_code,name'])->orderBy('directorate_id')->orderBy('id')
            ->get(['id', 'code', 'description', 'directorate_id', 'program_id']);

        $indicators = Indicator::whereIn('activity_id', $activities->pluck('id'))->with('progress')->orderBy('id')->get()->groupBy('activity_id');
        $narratives = ActivityReport::whereIn('activity_id', $activities->pluck('id'))->where('status', 'Verified')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('period_type', 'month')->where('period', $period))->orWhere(fn ($w) => $w->where('period_type', 'quarter')->where('period', $quarter)))
            ->get()->groupBy('activity_id');

        $rows = $activities->map(function (Activity $a) use ($money, $months, $lastMonth, $indicators, $narratives, $type, $quarter) {
            $m = $money[$a->id];
            $paidSoFar = array_sum(array_slice($m['paid_m'], 0, $lastMonth));
            $committedSoFar = array_sum(array_slice($m['committed_m'], 0, $lastMonth));

            return [
                'activity' => $a,
                'approved' => $m['approved'],
                'committed' => array_sum(array_map(fn ($i) => $m['committed_m'][$i], $months)),
                'paid' => array_sum(array_map(fn ($i) => $m['paid_m'][$i], $months)),
                'committed_so_far' => $committedSoFar,
                'paid_so_far' => $paidSoFar,
                'rate' => $m['approved'] > 0 ? $paidSoFar / $m['approved'] * 100 : null,
                'indicators' => ($indicators[$a->id] ?? collect())->map(function (Indicator $i) use ($quarter) {
                    $verified = $i->progress->where('status', 'Verified');
                    $standing = $i->standing($verified->where('quarter', '<=', $quarter)->pluck('achieved_value', 'quarter'));

                    return [
                        'name' => $i->name, 'unit' => $i->unit, 'target' => (float) $i->annual_target,
                        'in_quarter' => $verified->firstWhere('quarter', $quarter)?->achieved_value,
                        'standing' => $standing,
                        'pct' => $i->annual_target > 0 ? min(100, $standing / $i->annual_target * 100) : null,
                    ];
                })->all(),
                'month_note' => ($narratives[$a->id] ?? collect())->firstWhere('period_type', 'month'),
                'quarter_note' => ($narratives[$a->id] ?? collect())->firstWhere('period_type', 'quarter'),
            ];
        });

        $units = $rows->groupBy(fn ($r) => $r['activity']->directorate_id)->map(function ($group) {
            $sum = fn (string $k) => $group->sum($k);

            return [
                'unit' => $group->first()['activity']->directorate,
                'rows' => $group,
                'approved' => $sum('approved'), 'committed' => $sum('committed'), 'paid' => $sum('paid'), 'paid_so_far' => $sum('paid_so_far'),
            ];
        })->values();

        $approved = $rows->sum('approved');

        return [
            'year' => $year, 'type' => $type, 'period' => $period, 'quarter' => $quarter,
            'label' => $type === 'quarter' ? 'Q'.$period : \Carbon\Carbon::create($year, $period, 1)->translatedFormat('F'),
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'units' => $units,
            'totals' => [
                'activities' => $rows->count(), 'approved' => $approved, 'committed' => $rows->sum('committed'), 'paid' => $rows->sum('paid'),
                'paid_so_far' => $rows->sum('paid_so_far'), 'rate' => $approved > 0 ? $rows->sum('paid_so_far') / $approved * 100 : null,
            ],
            'generated_at' => now(), 'generated_by' => $user->name,
        ];
    }
}
