<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\Directorate;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The monthly or quarterly performance report of one fiscal year, laid out the way the ministry's report
 * is printed: one wide table, programme by programme and subprogramme by subprogramme, with each activity's
 * indicators beside the budget, the execution of the period, the results achieved and the constraints.
 * Verified figures and verified narrative only, and only the units the reader may see.
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

        $activities = Activity::whereIn('id', array_keys($money))->where('status', Activity::STATUS_APPROVED)
            ->with(['directorate:id,code,name'])->orderBy('directorate_id')->orderBy('id')
            ->get(['id', 'code', 'description', 'directorate_id', 'program_id', 'subprogram_id']);

        // where the money comes from: per activity, each source with the amount it funds
        $funding = \DB::table('line_items as li')->join('funding_sources as fs', 'fs.id', '=', 'li.funding_source_id')
            ->whereIn('li.activity_id', $activities->pluck('id'))->groupBy('li.activity_id', 'fs.id', 'fs.name')
            ->selectRaw('li.activity_id, fs.name, SUM(li.estimated_amount) AS amount')->orderBy('fs.id')->get()->groupBy('activity_id');

        $indicators = Indicator::whereIn('activity_id', $activities->pluck('id'))->with('progress')->orderBy('id')->get()->groupBy('activity_id');
        $notes = ActivityReport::whereIn('activity_id', $activities->pluck('id'))->where('status', 'Verified')
            ->where('period_type', $type)->where('period', $period)->get()->keyBy('activity_id');
        // a month report also shows the quarter's rating and measures when that quarter is already written up
        $quarterNotes = $type === 'month'
            ? ActivityReport::whereIn('activity_id', $activities->pluck('id'))->where('status', 'Verified')->where('period_type', 'quarter')->where('period', $quarter)->get()->keyBy('activity_id')
            : collect();

        $rows = $activities->map(function (Activity $a) use ($money, $months, $lastMonth, $indicators, $notes, $quarterNotes, $quarter, $funding) {
            $m = $money[$a->id];
            $paid = array_sum(array_map(fn ($i) => $m['paid_m'][$i], $months));
            $note = $notes[$a->id] ?? null;
            $inds = ($indicators[$a->id] ?? collect());

            return [
                'activity' => $a,
                'approved' => $m['approved'],
                'paid' => $paid,
                'committed' => array_sum(array_map(fn ($i) => $m['committed_m'][$i], $months)),
                'pct' => $m['approved'] > 0 ? $paid / $m['approved'] * 100 : null,
                'funding' => ($funding[$a->id] ?? collect())->map(fn ($f) => __($f->name))->all(),
                'means' => $inds->pluck('means_of_verification')->filter()->unique()->implode('; '),
                'indicators' => $inds->map(function (Indicator $i) use ($quarter) {
                    $verified = $i->progress->where('status', 'Verified');
                    $standing = $i->standing($verified->where('quarter', '<=', $quarter)->pluck('achieved_value', 'quarter'));

                    return [
                        'name' => $i->name, 'unit' => $i->unit, 'baseline' => $i->baseline,
                        'target' => $i->target_text ?: number_format((float) $i->annual_target, 2).' '.$i->unit,
                        'in_quarter' => $verified->firstWhere('quarter', $quarter)?->achieved_value,
                        'standing' => $standing,
                    ];
                })->all(),
                'results' => $note?->results,
                'constraints' => $note?->constraints,
                'reasons_not_met' => $note?->reasons_not_met ?? ($quarterNotes[$a->id] ?? null)?->reasons_not_met,
                'corrective_measures' => $note?->corrective_measures ?? ($quarterNotes[$a->id] ?? null)?->corrective_measures,
                'rating' => $note?->rating ?? ($quarterNotes[$a->id] ?? null)?->rating,
            ];
        });

        $tally = fn (Collection $rs) => [
            'approved' => $rs->sum('approved'), 'paid' => $rs->sum('paid'),
            'pct' => $rs->sum('approved') > 0 ? $rs->sum('paid') / $rs->sum('approved') * 100 : null,
        ];

        $programs = Program::whereIn('id', $rows->pluck('activity.program_id')->unique())
            ->with(['alignments', 'resultIndicators'])->orderBy('oge_code')->get();
        $subprograms = Subprogram::whereIn('id', $rows->pluck('activity.subprogram_id')->filter()->unique())
            ->with(['alignments', 'resultIndicators'])->orderBy('code')->get()->groupBy('program_id');

        $tree = $programs->map(function (Program $p) use ($rows, $subprograms, $tally) {
            $inProgram = $rows->filter(fn ($r) => $r['activity']->program_id === $p->id);
            $subs = ($subprograms[$p->id] ?? collect())->map(function (Subprogram $s) use ($inProgram, $tally) {
                $own = $inProgram->filter(fn ($r) => $r['activity']->subprogram_id === $s->id)->values();

                return ['subprogram' => $s, 'rows' => $own] + $tally($own);
            });

            return [
                'program' => $p, 'subprograms' => $subs,
                'loose' => $inProgram->filter(fn ($r) => ! $r['activity']->subprogram_id)->values(), // no subprogramme
            ] + $tally($inProgram);
        })->values();

        $all = $tally($rows);

        return [
            'year' => $year, 'type' => $type, 'period' => $period, 'quarter' => $quarter,
            'label' => $type === 'quarter' ? 'Q'.$period : Carbon::create($year, $period, 1)->translatedFormat('F'),
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'tree' => $tree,
            'totals' => $all + ['activities' => $rows->count(), 'committed' => $rows->sum('committed')],
            'reference' => sprintf('PERF-%d-%s%02d', $year, $type === 'quarter' ? 'Q' : 'M', $period),
            'generated_at' => now(), 'generated_by' => $user->name,
        ];
    }
}
