<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\AdjustedBudget;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The versions of an activity of the plan. Each approval makes a version with a copy of what was approved; the
 * amounts of its budget lines become the amounts in force (adjusted_budgets), against which spending is reported.
 *
 * A revision changes an approved activity: it needs a reason, the activity goes back to Draft and through the whole
 * approval chain, and until it is approved again the previous version stays in force (its amounts are untouched, even
 * those of a line the revision removes: that line is only set aside, as a trashed line, until the approval).
 * Nothing is overwritten: every version stays on record and can be compared with the others.
 */
class ActivityVersions
{
    /** Record the approval just given: a new version, and its amounts in force. */
    public function approved(Activity $activity, User $by): ActivityVersion
    {
        $activity->loadMissing(['lineItems.economicClassification:id,code,name', 'lineItems.fundingSource:id,name', 'indicators.actions']);
        $number = (int) ActivityVersion::where('activity_id', $activity->id)->max('version') + 1;

        $version = ActivityVersion::create([
            'activity_id' => $activity->id,
            'version' => $number,
            'snapshot' => $this->snapshot($activity),
            'total' => (float) $activity->lineItems->sum('estimated_amount'),
            'reason' => $number > 1 ? $activity->revision_reason : null,
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);

        // the amounts of this version are now in force
        foreach ($activity->lineItems as $line) {
            AdjustedBudget::updateOrCreate(['line_item_id' => $line->id], ['approved_amount' => $line->estimated_amount, 'status' => 'Approved']);
        }

        // lines the revision removed leave the plan with it, and their amounts in force go with them
        $activity->lineItems()->onlyTrashed()->get()->each->forceDelete();

        $activity->forceFill(['version' => $number, 'revision_reason' => null])->saveQuietly();

        return $version;
    }

    /** A copy of what an activity says now: the activity, its indicators with their actions, its budget lines. */
    public function snapshot(Activity $activity): array
    {
        return [
            'activity' => [
                'code' => $activity->code, 'description' => $activity->description, 'objective' => $activity->objective,
                'directorate_id' => $activity->directorate_id, 'program_id' => $activity->program_id, 'subprogram_id' => $activity->subprogram_id,
                'priority' => $activity->priority, 'planned_start' => $activity->planned_start?->toDateString(), 'planned_end' => $activity->planned_end?->toDateString(),
                'execution_quarters' => $activity->execution_quarters, 'manager_name' => $activity->manager_name,
            ],
            'indicators' => $activity->indicators->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'type' => $i->type, 'unit' => $i->unit, 'annual_target' => (float) $i->annual_target,
                'baseline' => $i->baseline, 'means_of_verification' => $i->means_of_verification, 'due_quarters' => $i->due_quarters,
                'actions' => $i->actions->map(fn ($a) => ['position' => $a->position, 'text' => $a->text, 'months' => $a->months])->values()->all(),
            ])->values()->all(),
            'lines' => $activity->lineItems->map(fn ($l) => [
                'id' => $l->id, 'economic_classification_id' => $l->economic_classification_id,
                'class_code' => $l->economicClassification?->code, 'class_name' => $l->economicClassification?->name,
                'funding_source_id' => $l->funding_source_id, 'source_name' => $l->fundingSource?->name,
                'indicator_id' => $l->indicator_id, 'estimated_amount' => (float) $l->estimated_amount,
            ])->values()->all(),
        ];
    }

    /** @return Collection<int, ActivityVersion> every approved version, the latest first */
    public function of(Activity $activity): Collection
    {
        return ActivityVersion::with('approver:id,name')->where('activity_id', $activity->id)->orderByDesc('version')->get();
    }

    /** Is a revision of an approved activity in progress (it has an approved version but is not approved now)? */
    public static function revising(Activity $activity): bool
    {
        return (int) $activity->version > 0 && $activity->status !== Activity::STATUS_APPROVED;
    }

    /**
     * What changed between the version in force and the activity now, line by line and indicator by indicator,
     * for the reviewers of a revision. @return array{lines: array<int, array>, indicators: array<int, array>, total_from: float, total_to: float}
     */
    public function changes(Activity $activity): array
    {
        $activity->loadMissing(['lineItems.economicClassification:id,code,name', 'lineItems.fundingSource:id,name', 'indicators.actions']);
        $last = ActivityVersion::where('activity_id', $activity->id)->orderByDesc('version')->first();
        $before = collect($last?->snapshot['lines'] ?? [])->keyBy('id');
        $now = collect($this->snapshot($activity)['lines'])->keyBy('id');

        $lines = [];
        foreach ($before->keys()->merge($now->keys())->unique() as $id) {
            $b = $before->get($id);
            $n = $now->get($id);
            $from = (float) ($b['estimated_amount'] ?? 0);
            $to = (float) ($n['estimated_amount'] ?? 0);
            if ($b && $n && abs($from - $to) < 0.005 && $b['economic_classification_id'] === $n['economic_classification_id'] && $b['funding_source_id'] === $n['funding_source_id']) {
                continue;
            }
            $row = $n ?? $b;
            $lines[] = ['label' => trim(($row['class_code'] ?? '').' '.($row['class_name'] ?? '')), 'source' => $row['source_name'] ?? null, 'from' => $b ? $from : null, 'to' => $n ? $to : null];
        }

        $beforeInd = collect($last?->snapshot['indicators'] ?? [])->keyBy('id');
        $indicators = [];
        foreach ($activity->indicators as $i) {
            $b = $beforeInd->get($i->id);
            if (! $b) {
                $indicators[] = ['name' => $i->name, 'change' => __('New indicator, target :t', ['t' => (float) $i->annual_target])];
            } elseif (abs((float) $b['annual_target'] - (float) $i->annual_target) >= 0.005 || $b['name'] !== $i->name) {
                $indicators[] = ['name' => $i->name, 'change' => __('Target :from → :to', ['from' => (float) $b['annual_target'], 'to' => (float) $i->annual_target])];
            }
        }
        foreach ($beforeInd->keys()->diff($activity->indicators->pluck('id')) as $gone) {
            $indicators[] = ['name' => $beforeInd[$gone]['name'], 'change' => __('Removed')];
        }

        return ['lines' => $lines, 'indicators' => $indicators, 'total_from' => (float) ($last?->total ?? 0), 'total_to' => (float) $activity->lineItems->sum('estimated_amount')];
    }
}
