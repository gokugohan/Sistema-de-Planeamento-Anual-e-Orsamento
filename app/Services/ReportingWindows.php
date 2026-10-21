<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ReportingWindow;
use App\Models\User;

/**
 * Is the annual plan (type 'plan': creating, editing and submitting activities), a month (spending, monthly
 * narrative) or a quarter (indicators, quarterly narrative) open?
 * A period with no window is open; with a window it is open from its first to its last day, and an
 * administrator can always report. Verifying is not limited by windows, only reporting is.
 */
class ReportingWindows
{
    public const PLAN = 'plan';

    /** Revisions of approved activities: no window means they can be made at any time (an optional window). */
    public const REVISION = 'revision';

    public function find(int $year, string $type, int $period): ?ReportingWindow
    {
        return ReportingWindow::where(['fiscal_year' => $year, 'period_type' => $type, 'period' => $period])->first();
    }

    public function allows(?User $user, int $year, string $type, int $period): bool
    {
        if ($user?->isAdministrator()) {
            return true;
        }
        $window = $this->find($year, $type, $period);

        return ! $window || (now()->startOfDay()->gte($window->opens_on) && now()->startOfDay()->lte($window->closes_on));
    }

    /** A sentence for the screen when the period is closed, or null when it is open. */
    public function closedReason(?User $user, int $year, string $type, int $period): ?string
    {
        if ($this->allows($user, $year, $type, $period)) {
            return null;
        }
        $w = $this->find($year, $type, $period);
        $before = now()->startOfDay()->lt($w->opens_on);

        if ($type === self::REVISION) {
            return $before
                ? __('Revisions of approved activities open on :d.', ['d' => $w->opens_on->format('d/m/Y')])
                : __('Revisions of approved activities closed on :d.', ['d' => $w->closes_on->format('d/m/Y')]);
        }
        if ($type === self::PLAN) {
            return $before
                ? __('Submission of the annual plan opens on :d.', ['d' => $w->opens_on->format('d/m/Y')])
                : __('Submission of the annual plan closed on :d.', ['d' => $w->closes_on->format('d/m/Y')]);
        }

        return $before
            ? __('Reporting for this period opens on :d.', ['d' => $w->opens_on->format('d/m/Y')])
            : __('Reporting for this period closed on :d.', ['d' => $w->closes_on->format('d/m/Y')]);
    }

    /** Why the annual plan of this year cannot be created, edited or submitted now (null when it can). */
    public function planClosedReason(?User $user, int $year): ?string
    {
        return $this->closedReason($user, $year, self::PLAN, 1);
    }

    /**
     * The same for one activity. A revision of an approved activity (version above 0) is not new planning, so it is not
     * held back by the submission window of the annual plan: changes after approval are exactly what comes later.
     */
    public function planClosedReasonFor(?User $user, Activity $activity): ?string
    {
        return (int) $activity->version > 0
            ? $this->closedReason($user, (int) $activity->fiscal_year, self::REVISION, 1) // its own window, if the institution set one
            : $this->planClosedReason($user, (int) $activity->fiscal_year);
    }
}
