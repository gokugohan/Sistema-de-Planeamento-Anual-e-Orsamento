<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\EconomicClassification;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;

/**
 * The final report of the approved Annual Activity Plan (PAA) and budget proposal for a
 * fiscal year, as a summary or as the complete report with the plan table.
 *
 * Only activities with status Approved are included. Activities still in the
 * internal workflow are counted separately so the reader can see whether the
 * plan is complete.
 */
class ApprovedPlanReport
{
    public function build(int $year): array
    {
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name']);

        $activities = Activity::query()
            ->where('fiscal_year', $year)
            ->where('status', Activity::STATUS_APPROVED)
            ->with([
                'directorate:id,code,name,type,parent_id,administration,legal_form',
                'directorate.parent:id,code,name',
                'program:id,oge_code,name,objective',
                'program.alignments', 'program.resultIndicators',
                'subprogram:id,program_id,code,name,objective',
                'subprogram.alignments', 'subprogram.resultIndicators',
                'indicators:id,activity_id,name,type,unit,annual_target,baseline,target_text,means_of_verification,due_quarters',
                'indicators.actions',
                'lineItems:id,activity_id,economic_classification_id,funding_source_id,estimated_amount',
                'lineItems.fundingSource:id,name',
            ])
            ->orderBy('directorate_id')
            ->orderBy('id')
            ->get();

        $empty = fn () => array_fill_keys($classes->pluck('id')->all(), 0.0);

        // Per-activity category amounts, computed once.
        $rows = $activities->map(function (Activity $a) use ($empty) {
            $byClass = $empty();
            foreach ($a->lineItems as $li) {
                $byClass[$li->economic_classification_id] = ($byClass[$li->economic_classification_id] ?? 0) + (float) $li->estimated_amount;
            }

            return ['activity' => $a, 'by_class' => $byClass, 'total' => array_sum($byClass)];
        });

        $sum = function (Collection $group) use ($empty) {
            $byClass = $empty();
            foreach ($group as $r) {
                foreach ($r['by_class'] as $id => $v) {
                    $byClass[$id] += $v;
                }
            }

            return ['by_class' => $byClass, 'total' => array_sum($byClass), 'count' => $group->count()];
        };

        $byProgram = $rows
            ->groupBy(fn ($r) => $r['activity']->program_id)
            ->map(fn ($g) => ['code' => $g->first()['activity']->program->oge_code ?? '', 'name' => $g->first()['activity']->program->name ?? '—'] + $sum($g))
            ->sortBy('code')
            ->values();

        // Group by the top of the hierarchy (Direção-Geral / Gabinete), then by unit.
        $byUnit = $rows
            ->groupBy(fn ($r) => $r['activity']->directorate->parent_id ?? $r['activity']->directorate_id)
            ->map(function ($g) use ($sum) {
                $d = $g->first()['activity']->directorate;
                $root = $d->parent ?? $d;
                $units = $g->groupBy(fn ($r) => $r['activity']->directorate_id)
                    ->map(fn ($u) => [
                        'code' => $u->first()['activity']->directorate->code,
                        'name' => $u->first()['activity']->directorate->name,
                        'rows' => $u->values(),
                    ] + $sum($u))
                    ->sortBy('code')
                    ->values();

                return ['code' => $root->code, 'name' => $root->name, 'autonomous' => $root->isAutonomous(), 'legal_form' => $root->legal_form, 'units' => $units] + $sum($g);
            })
            ->sortBy('code')
            ->values();

        // The ministry's template: programme, then subprogramme, then each activity with its indicators.
        $tree = $rows->groupBy(fn ($r) => $r['activity']->program_id)->map(function ($g) use ($sum) {
            $program = $g->first()['activity']->program;
            $subs = $g->filter(fn ($r) => $r['activity']->subprogram_id)->groupBy(fn ($r) => $r['activity']->subprogram_id)
                ->map(fn ($s) => ['subprogram' => $s->first()['activity']->subprogram, 'rows' => $s->values()] + $sum($s))
                ->sortBy(fn ($s) => $s['subprogram']->code)->values();

            return ['program' => $program, 'subprograms' => $subs, 'loose' => $g->filter(fn ($r) => ! $r['activity']->subprogram_id)->values()] + $sum($g);
        })->sortBy(fn ($p) => $p['program']->oge_code)->values();

        $pending = Activity::query()
            ->where('fiscal_year', $year)
            ->where('status', '!=', Activity::STATUS_APPROVED)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $totals = $sum($rows);

        // Autonomous bodies (indirect administration) are part of the ministry's package: their plans are
        // inside the totals above and are shown as a subtotal, because the ministry passes their share on
        // once it has received the envelope.
        $autonomous = $sum($rows->filter(fn ($r) => $r['activity']->directorate->isAutonomous()));

        $report = [
            'year' => $year,
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'organization_abbr' => SystemSetting::getLocalized('org_abbr', ''),
            'classes' => $classes,
            'totals' => $totals,
            'autonomous' => $autonomous,
            'by_program' => $byProgram,
            'tree' => $tree,
            'by_unit' => $byUnit,
            'indicator_count' => $activities->sum(fn ($a) => $a->indicators->count()),
            'pending' => $pending,
            'generated_at' => now('Asia/Dili'), // Timor-Leste time (TLT, UTC+9)
            'generated_by' => auth()->user()?->name,
        ];

        // Fingerprint of the plan content: identical plans give identical
        // fingerprints, so a printed copy can be checked against the system.
        $report['fingerprint'] = substr(hash('sha256', json_encode([
            $year,
            $rows->map(fn ($r) => [$r['activity']->id, $r['activity']->updated_at?->timestamp, $r['by_class']])->all(),
        ])), 0, 16);
        $report['reference'] = sprintf('PAA-%d-%s', $year, strtoupper($report['fingerprint']));

        return $report;
    }
}
