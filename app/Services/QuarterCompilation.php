<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compiling the quarter: the director of a unit takes what the unit reported in the three months and in the
 * quarter, edits it into the text that goes in the ministry's report, and signs it off. The unit's own text
 * is kept as it was; the compiled text sits beside it and is the one printed once it exists.
 */
class QuarterCompilation
{
    /** The approved activities this user may compile: their units and below (an administrator: all). */
    public function activities(User $user, int $year): Builder
    {
        $query = Activity::query()->where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED);

        return $user->isAdministrator() ? $query : $query->whereIn('directorate_id', $user->directedUnitIds() ?: [0]);
    }

    public function canCompile(User $user, Activity $activity): bool
    {
        return $activity->status === Activity::STATUS_APPROVED
            && ($user->isAdministrator() || in_array($activity->directorate_id, $user->directedUnitIds(), true));
    }

    /** What the unit reported for the quarter: its three months, and the quarter itself, verified text only. */
    public function sources(Activity $activity, int $quarter): array
    {
        $rows = ActivityReport::where('activity_id', $activity->id)->where('status', 'Verified')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('period_type', 'month')->whereBetween('period', [$quarter * 3 - 2, $quarter * 3]))
                ->orWhere(fn ($w) => $w->where('period_type', 'quarter')->where('period', $quarter)))
            ->orderBy('period_type')->orderBy('period')->get();

        return [
            'months' => $rows->where('period_type', 'month')->values(),
            'quarter' => $rows->firstWhere('period_type', 'quarter'),
        ];
    }

    /** A first draft of the compiled text, from the sources: the director then edits it. @return array{results: string, constraints: string} */
    public function draft(Activity $activity, int $quarter): array
    {
        $s = $this->sources($activity, $quarter);
        $results = [];
        $constraints = [];
        foreach ($s['months'] as $r) {
            $name = Carbon::create($activity->fiscal_year, $r->period, 1)->translatedFormat('F');
            if (filled($r->results)) {
                $results[] = "{$name}: {$r->results}";
            }
            if (filled($r->constraints)) {
                $constraints[] = "{$name}: {$r->constraints}";
            }
        }
        if ($s['quarter']) {
            if (filled($s['quarter']->results)) {
                $results[] = $s['quarter']->results;
            }
            if (filled($s['quarter']->constraints)) {
                $constraints[] = $s['quarter']->constraints;
            }
        }

        return ['results' => implode("\n", $results), 'constraints' => implode("\n", $constraints)];
    }

    public function find(Activity $activity, int $quarter): ?ActivityReport
    {
        return ActivityReport::where(['activity_id' => $activity->id, 'period_type' => 'quarter', 'period' => $quarter])->first();
    }

    public function save(User $user, Activity $activity, int $quarter, ?string $results, ?string $constraints): ActivityReport
    {
        abort_unless($this->canCompile($user, $activity), 403);
        $clean = fn (?string $v) => ($v = trim((string) $v)) === '' ? null : $v;

        $report = ActivityReport::firstOrNew(['activity_id' => $activity->id, 'period_type' => 'quarter', 'period' => $quarter]);
        $report->status = $report->status ?: 'Draft';
        $report->compiled_results = $clean($results);
        $report->compiled_constraints = $clean($constraints);
        $report->compiled_by = ($report->compiled_results || $report->compiled_constraints) ? $user->id : null;
        $report->compiled_at = ($report->compiled_results || $report->compiled_constraints) ? now() : null;
        $report->save();

        return $report;
    }
}
