<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FundingSource;
use App\Models\Indicator;
use App\Models\IndicatorAction;
use App\Models\LineItem;
use App\Models\PlanBaseline;
use App\Models\SystemSetting;
use App\Support\PlanOptions;
use Illuminate\Support\Collection;

/**
 * The final report of the approved Annual Activity Plan (PAA) and budget proposal for a
 * fiscal year, as a summary or as the complete report with the plan table.
 *
 * An activity is in the report as its approved version: one being revised stays in it, shown as it was
 * approved (from the stored version), until the revision is approved. Activities still in the internal
 * workflow for the first time are counted separately so the reader can see whether the plan is complete.
 */
class ApprovedPlanReport
{
    /** With a baseline (an optional rule), the plan as it stood when the baseline was recorded. */
    public function build(int $year, ?PlanBaseline $baseline = null): array
    {
        $baseline?->loadMissing('recorder:id,name');
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name']);

        $activities = Activity::query()
            ->where('fiscal_year', $year)
            ->when($baseline, fn ($q) => $q->whereIn('id', array_map('intval', array_keys($baseline->versions))))
            ->unless($baseline, fn ($q) => $q->where(fn ($w) => $w->where('status', Activity::STATUS_APPROVED)->orWhere('version', '>', 0)))
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

        // an activity being revised is reported as the version in force, not as the draft of its revision;
        // with a baseline, every activity is reported as the version the baseline recorded
        $revising = $baseline ? $activities : $activities->filter(fn (Activity $a) => $a->status !== Activity::STATUS_APPROVED);
        $versions = $baseline
            ? ActivityVersion::whereIn('activity_id', $revising->pluck('id'))->get()->filter(fn ($v) => (int) ($baseline->versions[(string) $v->activity_id] ?? 0) === (int) $v->version)->keyBy('activity_id')
            : ActivityVersion::whereIn('activity_id', $revising->pluck('id'))->orderBy('version')->get()->groupBy('activity_id')->map->last();
        $inForce = [];
        foreach ($revising as $a) {
            if ($version = $versions->get($a->id)) {
                $this->asApproved($a, $version);
                $inForce[$a->id] = $version->approved_at?->timestamp;
            }
        }
        $activities = $activities->reject(fn (Activity $a) => ($baseline || $a->status !== Activity::STATUS_APPROVED) && ! isset($inForce[$a->id]))->values();

        // an optional rule: each activity is shown under the unit it belonged to when its version in force was approved
        if (PlanOptions::current()->structureAtApproval) {
            $latest = ActivityVersion::whereIn('activity_id', $activities->pluck('id'))->orderBy('version')->get()->groupBy('activity_id')->map->last();
            foreach ($activities as $a) {
                $path = $latest->get($a->id)?->snapshot['unit_path'] ?? [];
                if ($path !== []) {
                    $unit = (new Directorate)->forceFill($path[0]);
                    $unit->setRelation('parent', isset($path[1]) ? (new Directorate)->forceFill($path[1]) : null);
                    $a->setRelation('directorate', $unit);
                }
            }
        }

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

        $pending = $baseline ? [] : Activity::query()
            ->where('fiscal_year', $year)
            ->where('status', '!=', Activity::STATUS_APPROVED)
            ->where('version', 0)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $totals = $sum($rows);

        // Autonomous bodies (indirect administration) are part of the ministry's package: their plans are
        // inside the totals above and are shown as a subtotal.
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
            'revising' => $baseline ? 0 : count($inForce),
            'baseline' => $baseline ? ['id' => $baseline->id, 'recorded_at' => $baseline->recorded_at, 'note' => $baseline->note, 'by' => $baseline->recorder?->name] : null,
            'generated_at' => now('Asia/Dili'), // Timor-Leste time (TLT, UTC+9)
            'generated_by' => auth()->user()?->name,
        ];

        // A fingerprint of the plan content (identical plans give identical ones). It is not printed: it is kept in the audit
        // trail of each export, so that it is known which version of the plan a document came from.
        $report['fingerprint'] = substr(hash('sha256', json_encode([
            $year,
            $rows->map(fn ($r) => [$r['activity']->id, $inForce[$r['activity']->id] ?? $r['activity']->updated_at?->timestamp, $r['by_class']])->all(),
        ])), 0, 16);
        $report['reference'] = $baseline ? sprintf('PAA-%d-B%d', $year, $baseline->id) : sprintf('PAA-%d', $year);

        return $report;
    }

    /** Show the activity as the version in force says it is (in memory only: nothing is saved). */
    private function asApproved(Activity $activity, ActivityVersion $version): void
    {
        $snap = $version->snapshot;
        $a = $snap['activity'] ?? [];
        foreach (['code', 'description', 'objective', 'priority', 'planned_start', 'planned_end', 'execution_quarters', 'manager_name', 'location', 'quarter'] as $field) {
            if (array_key_exists($field, $a)) {
                $activity->setAttribute($field, $a[$field]);
            }
        }

        $sources = FundingSource::pluck('name', 'id');
        $activity->setRelation('lineItems', collect($snap['lines'] ?? [])->map(function ($l) use ($activity, $sources) {
            $line = (new LineItem)->forceFill([
                'id' => $l['id'] ?? null, 'activity_id' => $activity->id, 'economic_classification_id' => $l['economic_classification_id'] ?? null,
                'funding_source_id' => $l['funding_source_id'] ?? null, 'estimated_amount' => $l['estimated_amount'] ?? 0,
            ]);
            $line->setRelation('fundingSource', isset($l['funding_source_id']) && $sources->has($l['funding_source_id']) ? (new FundingSource)->forceFill(['id' => $l['funding_source_id'], 'name' => $sources[$l['funding_source_id']]]) : null);

            return $line;
        })->values());

        $activity->setRelation('indicators', collect($snap['indicators'] ?? [])->map(function ($i) use ($activity) {
            $indicator = (new Indicator)->forceFill([
                'id' => $i['id'] ?? null, 'activity_id' => $activity->id, 'name' => $i['name'] ?? '', 'type' => $i['type'] ?? null, 'unit' => $i['unit'] ?? null,
                'annual_target' => $i['annual_target'] ?? 0, 'baseline' => $i['baseline'] ?? null, 'target_text' => $i['target_text'] ?? null,
                'means_of_verification' => $i['means_of_verification'] ?? null, 'due_quarters' => $i['due_quarters'] ?? null,
            ]);
            $indicator->setRelation('actions', collect($i['actions'] ?? [])->map(
                fn ($x) => (new IndicatorAction)->forceFill(['position' => $x['position'] ?? 0, 'text' => $x['text'] ?? '', 'months' => $x['months'] ?? []])
            )->values());

            return $indicator;
        })->values());
    }
}
