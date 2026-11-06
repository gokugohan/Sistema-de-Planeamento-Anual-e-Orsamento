<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Program;
use App\Models\ReportingWindow;
use App\Models\SystemSetting;
use App\Services\PlanningWindow;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The fiscal year a page works in. Most of the year two plans are alive at once: the plan being carried out and reported
 * on (the execution year) and the plan being prepared (the planning year). Each page belongs to one of them, by its route
 * (CONTEXTS), and opens on that year without anybody choosing it. Another year can be looked at through ?year= in the
 * page's own address, which changes nothing anywhere else: there is no year kept in the session.
 */
class FiscalYear
{
    public const PLANNING = 'planning';

    public const EXECUTION = 'execution';

    public const OVERVIEW = 'overview';

    /** Which year each page works in, by route name (the first pattern that matches); unlisted pages plan. */
    public const CONTEXTS = [
        'monitoring.*' => self::EXECUTION,
        'reports.*' => self::EXECUTION,
        'dashboard' => self::OVERVIEW,
        'guide' => self::OVERVIEW,
        '*' => self::PLANNING,
    ];

    /** Pages that do not work in a year (or show the year of their own record): no year bar. */
    public const NO_YEAR = [
        'management.users', 'management.settings', 'management.optional-rules', 'management.reporting-windows', 'management.audit',
        'management.sample-data', 'management.funding-sources', 'profile', 'notifications*', 'guide', 'approvals.lists',
        'approvals.delegations', 'activities.show', 'activities.edit',
    ];

    /** Does this page work in a year (and so show which one)? */
    public static function pageHasYear(): bool
    {
        $name = self::pageRoute() ?? '';

        return $name !== '' && ! \Illuminate\Support\Str::is(self::NO_YEAR, $name);
    }

    /** @var array<int, int>|null */
    private static ?array $yearsMemo = null;

    /** A year fixed from outside a page (a console command, a test): it wins over everything else. */
    private static ?int $pinned = null;

    /** @var array<string, mixed> computed once per page request */
    private static array $memo = [];

    private static function remember(string $key, \Closure $compute): mixed
    {
        // once per page request; outside one (a console command, a test reading the years directly) always fresh
        $request = request();
        if ($request->route() === null) {
            return $compute();
        }
        $id = spl_object_id($request);
        if ((self::$memo['request'] ?? null) !== $id) {
            self::$memo = ['request' => $id];
        }

        return self::$memo[$key] ??= $compute();
    }

    public static function forget(): void
    {
        self::$yearsMemo = null;
        self::$memo = [];
    }

    public static function pin(?int $year): void
    {
        self::$pinned = $year;
    }

    public static function pinned(): ?int
    {
        return self::$pinned;
    }

    /** The year the page works in: the one asked for in its address, else the usual year of the page. */
    public static function current(): int
    {
        if (self::$pinned !== null) {
            return self::$pinned;
        }
        $asked = self::requested();

        return $asked ?? self::usual();
    }

    /** The year this page opens on when nobody asks for another. */
    public static function usual(?string $context = null): int
    {
        return match ($context ?? self::context()) {
            self::EXECUTION => self::execution(),
            self::OVERVIEW => self::default(),
            default => self::planning(),
        };
    }

    /** Is the page showing a year other than its usual one? */
    public static function isUnusual(): bool
    {
        return self::current() !== self::usual();
    }

    /** The year of ?year= in the address of the page (also during the page's own Livewire requests), when it is a year one can open. */
    public static function requested(): ?int
    {
        $query = [];
        parse_str((string) parse_url(self::pageUrl(), PHP_URL_QUERY), $query);
        $year = (int) ($query['year'] ?? 0);

        return $year && in_array($year, self::available(), true) ? $year : null; // no year in the address: no query
    }

    /** planning, execution or overview, from the route of the page. */
    public static function context(): string
    {
        return self::remember('context', function () {
            $name = self::pageRoute();
            if ($name === null) {
                return self::OVERVIEW; // not a page (a console command, a component tested on its own): the general default
            }
            foreach (self::CONTEXTS as $pattern => $context) {
                if (Str::is($pattern, $name)) {
                    return $context;
                }
            }

            return self::PLANNING;
        });
    }

    /** The address of this page in another year (the usual year drops the parameter). */
    public static function urlFor(int $year): string
    {
        $url = self::pageUrl();
        $parts = parse_url($url);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        unset($query['year'], $query['page']);
        if ($year !== self::usual()) {
            $query['year'] = $year;
        }
        $base = strtok($url, '?');

        return $query ? $base.'?'.http_build_query($query) : $base;
    }

    /**
     * The plan being carried out and reported on: this calendar year once its plan has approved activities; in the first
     * weeks of a year whose plan is not approved yet, last year's (whose last month is still being reported).
     */
    public static function execution(): int
    {
        return self::remember('execution', function () {
            $now = (int) now()->year;
            if (! self::hasApproved($now) && self::hasApproved($now - 1)) {
                return $now - 1;
            }

            return $now;
        });
    }

    /**
     * The plan being prepared: the year whose submission dates are open today (next year first); without such dates,
     * next year once this year's plan is approved or next year's activities are being prepared, else this year (an
     * institution planning its first year).
     */
    public static function planning(): int
    {
        return self::remember('planning', function () {
            $now = (int) now()->year;
            $open = ReportingWindow::where('period_type', 'plan')->whereIn('fiscal_year', [$now, $now + 1])
                ->whereDate('opens_on', '<=', now()->toDateString())->whereDate('closes_on', '>=', now()->toDateString())
                ->pluck('fiscal_year')->map(fn ($y) => (int) $y);
            if ($open->isNotEmpty()) {
                return $open->max();
            }

            // next year once this year's plan is approved, or once its activities are already being prepared
        return self::hasApproved($now) || Activity::query()->where('fiscal_year', $now + 1)->exists() ? $now + 1 : $now;
        });
    }

    private static function hasApproved(int $year): bool
    {
        return Activity::query()->where('fiscal_year', $year)->where('version', '>', 0)->exists();
    }

    /** The address of the page the person is on: for a Livewire request, the page it came from. */
    private static function pageUrl(): string
    {
        if (app()->bound('livewire') && app('livewire')->isLivewireRequest()) {
            // Livewire remembers the page's path but not its query: the browser's Referer (same site only) carries both
            $referer = (string) request()->headers->get('referer', '');
            if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === request()->getHost()) {
                return $referer;
            }

            return (string) app('livewire')->originalUrl();
        }

        return request()->fullUrl();
    }

    private static function pageRoute(): ?string
    {
        if (app()->bound('livewire') && app('livewire')->isLivewireRequest()) {
            try {
                return app('router')->getRoutes()->match(Request::create(self::pageUrl()))->getName();
            } catch (\Throwable) {
                return null;
            }
        }

        return request()->route()?->getName();
    }

    /**
     * The year an overview opens on: the calendar year while its plan is being carried out (it has approved activities);
     * otherwise the latest year that has a plan, never beyond next year; otherwise the calendar year.
     */
    public static function default(): int
    {
        return self::remember('default', function () {
            $current = (int) now()->year;
            if (self::hasApproved($current)) {
                return $current;
            }
            $planned = Activity::query()->where('fiscal_year', '<=', $current + 1)->max('fiscal_year');

            return $planned ? (int) $planned : $current;
        });
    }

    /**
     * Years the user can switch to: every year with a plan or with programs registered, plus this year and next; in testing mode also the five
     * years before and the two after next, so that any of them can be planned. @return array<int, int>
     */
    public static function available(): array
    {
        if (self::$yearsMemo !== null && ! app()->runningUnitTests()) {
            return self::$yearsMemo;
        }
        $current = (int) date('Y');
        $extra = SystemSetting::get('enable_paa_testing_mode', false) ? range($current - 5, $current + 3) : [];

        // a year being set up (its programs registered, no activity yet) can be chosen too
        return self::$yearsMemo = Activity::query()->select('fiscal_year')->distinct()
            ->union(Program::query()->select('fiscal_year')->distinct())->pluck('fiscal_year') // one query
            ->push($current, $current + 1, ...$extra)
            ->map(fn ($y) => (int) $y)->unique()->sortDesc()->values()->all();
    }

    /** Can new activities still be planned for this year (for this unit, through its directorate)? */
    public static function planningOpen(int $year, ?int $unitId = null): bool
    {
        $window = app(PlanningWindow::class);

        return $unitId === null ? $window->isOpen($year) : $window->isOpenFor($year, $unitId);
    }

    /**
     * Why the year takes no new activities: 'Year' when it is neither the current year nor the next one (and testing
     * mode is off), 'Finalized' when planning has ended for the year (or the unit's directorate); null while it is open.
     */
    public static function planningEndedBy(int $year, ?int $unitId = null): ?string
    {
        if (! PlanningWindow::yearAllowed($year)) {
            return 'Year';
        }
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
