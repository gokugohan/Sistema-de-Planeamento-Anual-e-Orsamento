<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BudgetCycle;
use App\Models\BudgetCycleSubmissionLine;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records the ministry's path through the OGE cycle for one fiscal year:
 * indicative ceiling → submission to the CRO (frozen) → CRO decision (ministry
 * total) → final envelope (fiscal_envelopes, after promulgation).
 */
class BudgetCycleService
{
    public function __construct(private ApprovedPlanReport $reports) {}

    public function forYear(int $year): BudgetCycle
    {
        return BudgetCycle::firstOrCreate(['fiscal_year' => $year]);
    }

    public function recordCeiling(int $year, float $amount, ?string $reference, ?string $date): BudgetCycle
    {
        if ($amount < 0) {
            throw new DomainException(__('The ceiling cannot be negative.'));
        }

        $cycle = $this->forYear($year);
        $cycle->update(['indicative_ceiling' => $amount, 'ceiling_reference' => $reference ?: null, 'ceiling_date' => $date ?: null]);

        return $cycle;
    }

    /**
     * Freeze the current approved plan as the submission to the CRO. The frozen
     * reference is the same one printed on the approved-plan PDF, so the paper
     * copy and the system record can be matched.
     */
    public function recordSubmission(int $year): BudgetCycle
    {
        $cycle = $this->forYear($year);

        if ($cycle->hasCroDecision()) {
            throw new DomainException(__('The CRO decision is already recorded; the submission can no longer be replaced.'));
        }

        $report = $this->reports->build($year);
        if ($report['totals']['count'] === 0) {
            throw new DomainException(__('There are no approved activities to submit for :year.', ['year' => $year]));
        }

        $classCodes = EconomicClassification::pluck('code', 'id');

        DB::transaction(function () use ($cycle, $report, $year, $classCodes) {
            $cycle->submissionLines()->delete();

            Activity::query()
                ->where('fiscal_year', $year)
                ->where('status', Activity::STATUS_APPROVED)
                ->with(['directorate:id,code', 'program:id,oge_code', 'lineItems:id,activity_id,economic_classification_id,estimated_amount'])
                ->orderBy('id')
                ->chunk(200, function ($activities) use ($cycle, $classCodes) {
                    $rows = [];
                    $now = now();
                    foreach ($activities as $a) {
                        foreach ($a->lineItems as $li) {
                            $rows[] = [
                                'budget_cycle_id' => $cycle->id,
                                'activity_id' => $a->id,
                                'line_item_id' => $li->id,
                                'activity_description' => mb_substr($a->description, 0, 1000),
                                'directorate_code' => $a->directorate->code ?? '—',
                                'program_code' => $a->program->oge_code ?? null,
                                'class_code' => (string) ($classCodes[$li->economic_classification_id] ?? '?'),
                                'amount' => $li->estimated_amount,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                    if ($rows) {
                        BudgetCycleSubmissionLine::insert($rows);
                    }
                });

            $cycle->update([
                'submission_reference' => $report['reference'],
                'submission_fingerprint' => $report['fingerprint'],
                'submitted_total' => $report['totals']['total'],
                'submitted_activities' => $report['totals']['count'],
                'submitted_at' => now(),
                'submitted_by' => auth()->id(),
            ]);
        });

        return $cycle->fresh();
    }

    public function recordCroDecision(int $year, float $total, string $date, ?string $reference, ?string $notes): BudgetCycle
    {
        $cycle = $this->forYear($year);

        if (! $cycle->isSubmitted()) {
            throw new DomainException(__('Record the submission to the CRO first.'));
        }
        if ($total < 0) {
            throw new DomainException(__('The CRO total cannot be negative.'));
        }
        if (FiscalEnvelope::where('fiscal_year', $year)->whereIn('status', [FiscalEnvelope::STATUS_APPROVED, FiscalEnvelope::STATUS_CLOSED])->exists()) {
            throw new DomainException(__('The envelope for :year is already approved; the CRO decision can no longer change.', ['year' => $year]));
        }

        $cycle->update([
            'cro_total' => $total,
            'cro_decision_date' => $date,
            'cro_reference' => $reference ?: null,
            'cro_notes' => $notes ?: null,
            'cro_recorded_by' => auth()->id(),
        ]);

        return $cycle;
    }

    /**
     * Figures for each stage, with differences, for display and reporting.
     *
     * @return array{cycle: BudgetCycle, envelope: ?FiscalEnvelope, approved_now: float, stages: array, submitted_by_class: array, final_by_class: array, plan_changed_since_submission: bool}
     */
    public function summary(int $year): array
    {
        // Read-only: viewing a year must not create a record for it.
        $cycle = BudgetCycle::firstOrNew(['fiscal_year' => $year])->load('submitter:id,name');
        $envelope = FiscalEnvelope::where('fiscal_year', $year)->with('categoryAllocations.economicClassification')->first();
        $report = $this->reports->build($year);

        $diff = fn ($a, $b) => ($a === null || $b === null) ? null : round((float) $a - (float) $b, 2);

        $ceiling = $cycle->indicative_ceiling !== null ? (float) $cycle->indicative_ceiling : null;
        $submitted = $cycle->submitted_total !== null ? (float) $cycle->submitted_total : null;
        $cro = $cycle->cro_total !== null ? (float) $cycle->cro_total : null;
        $final = $envelope ? (float) $envelope->total_amount : null;

        $submittedByClass = $cycle->submissionLines()
            ->selectRaw('class_code, SUM(amount) as total')
            ->groupBy('class_code')
            ->pluck('total', 'class_code')
            ->map(fn ($v) => (float) $v)
            ->all();

        $finalByClass = $envelope
            ? $envelope->categoryAllocations->mapWithKeys(fn ($a) => [$a->economicClassification->code ?? '?' => (float) $a->allocated_amount])->all()
            : [];

        return [
            'cycle' => $cycle,
            'envelope' => $envelope,
            'approved_now' => (float) $report['totals']['total'],
            'current_fingerprint' => $report['fingerprint'],
            'plan_changed_since_submission' => $cycle->isSubmitted() && $cycle->submission_fingerprint !== $report['fingerprint'],
            // After the CRO decides, the approved plan must be brought within that
            // amount BEFORE the budget goes to Parliament. Positive = plan exceeds it.
            'fit_gap' => $cycle->hasCroDecision() ? round((float) $report['totals']['total'] - (float) $cycle->cro_total, 2) : null,
            'stages' => [
                'ceiling' => $ceiling,
                'submitted' => $submitted,
                'submitted_vs_ceiling' => $diff($submitted, $ceiling),
                'cro' => $cro,
                'cro_vs_submitted' => $diff($cro, $submitted),
                'final' => $final,
                'final_vs_cro' => $diff($final, $cro),
            ],
            'submitted_by_class' => $submittedByClass,
            'final_by_class' => $finalByClass,
        ];
    }
}
