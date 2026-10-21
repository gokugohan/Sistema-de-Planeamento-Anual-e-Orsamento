<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\PlanBaseline;
use App\Models\User;
use App\Support\PlanOptions;
use DomainException;

/**
 * Baselines of the approved plan (an optional rule, PlanOptions::baselines). A baseline records, for one fiscal year,
 * which approved version of each activity was in force at that moment. It is a reference for reporting only: it
 * approves nothing and changes no activity. Only an administrator records one.
 */
class PlanBaselines
{
    public function record(User $user, int $year, ?string $note = null): PlanBaseline
    {
        abort_unless(PlanOptions::current()->baselines && $user->isAdministrator(), 403);

        $ids = Activity::where('fiscal_year', $year)->where('version', '>', 0)->pluck('id');
        $versions = ActivityVersion::whereIn('activity_id', $ids)->orderBy('version')->get()->groupBy('activity_id')->map->last();
        if ($versions->isEmpty()) {
            throw new DomainException(__('There is no approved activity to record for :year.', ['year' => $year]));
        }

        return PlanBaseline::create([
            'fiscal_year' => $year,
            'versions' => $versions->mapWithKeys(fn (ActivityVersion $v) => [(string) $v->activity_id => (int) $v->version])->all(),
            'activity_count' => $versions->count(),
            'total' => (float) $versions->sum(fn (ActivityVersion $v) => (float) $v->total),
            'note' => filled($note) ? mb_substr(trim($note), 0, 2000) : null,
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);
    }
}
