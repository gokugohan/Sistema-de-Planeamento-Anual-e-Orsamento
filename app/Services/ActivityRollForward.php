<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\Indicator;
use App\Models\LineItem;
use Illuminate\Support\Facades\DB;

/**
 * Starts a year's plan from the previous year's: recurring activities (training, inspections,
 * maintenance) are copied as drafts with their budget lines and indicators, dates moved one year
 * on and all progress and approval history left behind. The technical officer then adjusts the numbers.
 *
 * An activity already present in the target year (same unit, programme and text) is skipped, so
 * running it twice never duplicates.
 */
class ActivityRollForward
{
    /**
     * @param  array<int>|null  $unitIds  limit the copy to these units (null = all)
     * @return array{copied: int, skipped: int}
     */
    public function copy(int $fromYear, int $toYear, ?array $unitIds = null): array
    {
        if ($toYear <= $fromYear) {
            throw new \DomainException(__('The target year must be after the source year.'));
        }
        if (app(PlanningWindow::class)->endedBy($toYear)) {
            throw new \DomainException(__('Planning for :year has ended (every directorate has finalized its plan), so activities cannot be copied into it.', ['year' => $toYear]));
        }

        if ($reason = app(ReportingWindows::class)->planClosedReason(auth()->user(), $toYear)) {
            throw new \DomainException($reason);
        }

        $copied = $skipped = 0;
        $shift = $toYear - $fromYear;
        $units = Directorate::pluck('code', 'id');
        $programs = app(ProgramYears::class);
        $programMap = [];

        DB::transaction(function () use ($fromYear, $toYear, $unitIds, $shift, $units, $programs, &$programMap, &$copied, &$skipped) {
            Activity::where('fiscal_year', $fromYear)
                ->when($unitIds !== null, fn ($q) => $q->whereIn('directorate_id', $unitIds))
                ->with(['lineItems', 'indicators', 'program', 'subprogram'])
                ->orderBy('id')
                ->chunkById(100, function ($activities) use ($toYear, $shift, $units, $programs, &$programMap, &$copied, &$skipped) {
                    foreach ($activities as $a) {
                        // the programme of the new year with the same code (registered now if it is missing)
                        $program = $programMap[$a->program_id] ??= $programs->forYearOf($a->program, $toYear);
                        $programId = $program->id;
                        $subprogramId = $a->subprogram ? $programs->subprogramFor($a->subprogram, $program)->id : null;
                        if (! app(PlanningWindow::class)->isOpenFor($toYear, $a->directorate_id)) {
                            $skipped++; // the directorate has finalized its plan for that year

                            continue;
                        }
                        $exists = Activity::where('fiscal_year', $toYear)->where('directorate_id', $a->directorate_id)
                            ->where('program_id', $programId)->where('description', $a->description)->exists();
                        if ($exists) {
                            $skipped++;

                            continue;
                        }

                        $key = $a->directorate_id;
                        $code = Activity::nextCode($toYear, $units[$key] ?? 'ACT'); // the highest number in use plus one, copies made in this run included

                        $copy = Activity::create([
                            'fiscal_year' => $toYear,
                            'status' => Activity::STATUS_DRAFT,
                            'code' => $code,
                            'is_test_data' => $a->is_test_data,
                            'program_id' => $programId,
                            'subprogram_id' => $subprogramId,
                            'directorate_id' => $a->directorate_id,
                            'description' => $a->description,
                            'objective' => $a->objective,
                            'manager_name' => $a->manager_name,
                            'location' => $a->location,
                            'priority' => $a->priority,
                            'quarter' => $a->quarter,
                            'execution_quarters' => $a->execution_quarters,
                            'period_type' => $a->period_type,
                            'planned_start' => $a->planned_start->copy()->addYears($shift),
                            'planned_end' => $a->planned_end->copy()->addYears($shift),
                            'procurement_required' => $a->procurement_required,
                            'gender_responsive' => $a->gender_responsive,
                        ]);
                        foreach ($a->lineItems as $l) {
                            LineItem::create(['activity_id' => $copy->id, 'economic_classification_id' => $l->economic_classification_id, 'funding_source_id' => $l->funding_source_id, 'account_code' => $l->account_code, 'estimated_amount' => $l->estimated_amount, 'is_test_data' => $l->is_test_data]);
                        }
                        foreach ($a->indicators as $i) {
                            Indicator::create(['activity_id' => $copy->id, 'name' => $i->name, 'type' => $i->type, 'unit' => $i->unit, 'measure' => $i->measure, 'annual_target' => $i->annual_target]);
                        }
                        $copied++;
                    }
                });
        });

        return ['copied' => $copied, 'skipped' => $skipped];
    }
}
