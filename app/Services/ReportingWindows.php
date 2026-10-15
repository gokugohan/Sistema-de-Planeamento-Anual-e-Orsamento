<?php

namespace App\Services;

use App\Models\ReportingWindow;
use App\Models\User;

/**
 * Is a month (spending, monthly narrative) or a quarter (indicators, quarterly narrative) open for reporting?
 * A period with no window is open; with a window it is open from its first to its last day, and an
 * administrator can always report. Verifying is not limited by windows, only reporting is.
 */
class ReportingWindows
{
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

        return now()->startOfDay()->lt($w->opens_on)
            ? __('Reporting for this period opens on :d.', ['d' => $w->opens_on->format('d/m/Y')])
            : __('Reporting for this period closed on :d.', ['d' => $w->closes_on->format('d/m/Y')]);
    }
}
