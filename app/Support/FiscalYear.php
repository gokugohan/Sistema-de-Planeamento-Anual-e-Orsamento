<?php

namespace App\Support;

use App\Models\Activity;
use App\Services\PlanningWindow;

/**
 * The fiscal year the application works in, and the years one can switch to. A year exists because it has
 * activities in its plan (or is this year or next); nothing else decides it.
 */
class FiscalYear
{
    private static ?int $defaultMemo = null;

    /** @var array<int, int>|null */
    private static ?array $yearsMemo = null;

    public static function forget(): void
    {
        self::$defaultMemo = null;
        self::$yearsMemo = null;
    }

    /** The year chosen in the year switcher, or the default one. */
    public static function current(): int
    {
        $chosen = (int) session('fiscal_year', 0);

        return $chosen && in_array($chosen, self::available(), true) ? $chosen : self::default();
    }

    /**
     * The year to work in when the user has not picked one: the calendar year while its plan is being carried out
     * (it has approved activities); otherwise the latest year that has a plan, never beyond next year (so that in a
     * new institution planning its first year, the year being planned opens); otherwise the calendar year.
     */
    public static function default(): int
    {
        if (self::$defaultMemo !== null && ! app()->runningUnitTests()) {
            return self::$defaultMemo;
        }
        $current = (int) date('Y');
        if (Activity::query()->where('fiscal_year', $current)->where('version', '>', 0)->exists()) {
            return self::$defaultMemo = $current;
        }
        $planned = Activity::query()->where('fiscal_year', '<=', $current + 1)->max('fiscal_year');

        return self::$defaultMemo = $planned ? (int) $planned : $current;
    }

    /** Years the user can switch to: every year with a plan, plus this year and next. @return array<int, int> */
    public static function available(): array
    {
        if (self::$yearsMemo !== null && ! app()->runningUnitTests()) {
            return self::$yearsMemo;
        }
        $current = (int) date('Y');

        return self::$yearsMemo = Activity::query()->distinct()->pluck('fiscal_year')
            ->push($current, $current + 1)
            ->map(fn ($y) => (int) $y)->unique()->sortDesc()->values()->all();
    }

    /** Can new activities still be planned for this year (for this unit, through its directorate)? */
    public static function planningOpen(int $year, ?int $unitId = null): bool
    {
        $window = app(PlanningWindow::class);

        return $unitId === null ? $window->isOpen($year) : $window->isOpenFor($year, $unitId);
    }

    /** 'Finalized' when planning has ended for the year (or the unit's directorate), null while it is open. */
    public static function planningEndedBy(int $year, ?int $unitId = null): ?string
    {
        $window = app(PlanningWindow::class);

        return $unitId === null ? $window->endedBy($year) : $window->unitEndedBy($year, $unitId);
    }

    /** The first year from this one on whose plan is still open: where new activities go. */
    public static function nextPlanningYear(?int $unitId = null): int
    {
        $window = app(PlanningWindow::class);

        return $unitId === null ? $window->nextOpenYear() : $window->nextOpenYearFor($unitId);
    }
}
