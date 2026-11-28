<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * The add-on modules of the plan. Planning and approving the plan is the core and is always there; these three are
 * switched on by the administrator (Settings > Modules):
 *
 *   execution    reporting what an activity spent and achieved, verifying it, compiling the quarter
 *   performance  executed and delivered against the plan (needs execution: without reports it has nothing to show)
 *   budget       the budget envelope: decisions on the budget, their share among the units, the adjustment round.
 *                It is the envelope choice of the optional rules (PlanOptions), so there is one switch for it.
 *
 * A module that is off is hidden everywhere (menu, dashboard, inbox, activity page, calendar) and its pages answer
 * "not found", but nothing is deleted: switching it on again brings back what was recorded.
 * A new installation has every module off; loading the sample data switches them on.
 */
final class Modules
{
    public const EXECUTION = 'execution';

    public const PERFORMANCE = 'performance';

    public const BUDGET = 'budget';

    public const ALL = [self::EXECUTION, self::PERFORMANCE, self::BUDGET];

    private const KEY = 'modules';

    /** Routes and Livewire components (name prefixes) that belong to a module. */
    public const ROUTES = [
        self::EXECUTION => ['monitoring.report', 'monitoring.approval', 'monitoring.execution', 'monitoring.progress', 'reports.compile'],
        self::PERFORMANCE => ['monitoring.index', 'reports.performance', 'reports.performance.pdf', 'reports.performance.xlsx'],
    ];

    public const COMPONENTS = [
        self::EXECUTION => ['pages.monitoring.report-execution', 'pages.monitoring.execution-spending', 'pages.monitoring.execution-progress',
            'pages.monitoring.execution-narrative', 'pages.monitoring.indicator-approval', 'pages.reports.compile-quarter'],
        self::PERFORMANCE => ['pages.monitoring.execution-dashboard'],
    ];

    /** Tests: every module on unless a test says otherwise (reset in TestCase). @var array<string, bool>|null */
    private static ?array $fake = null;

    public static function on(string $module): bool
    {
        if (self::$fake !== null && array_key_exists($module, self::$fake)) {
            return self::$fake[$module];
        }

        return match ($module) {
            self::BUDGET => PlanOptions::current()->budgetDecisions,
            // performance reads the execution reports: off with them
            self::PERFORMANCE => self::stored(self::PERFORMANCE) && self::on(self::EXECUTION),
            self::EXECUTION => self::stored(self::EXECUTION),
            default => false,
        };
    }

    public static function off(string $module): bool
    {
        return ! self::on($module);
    }

    /** @return array<string, bool> */
    public static function states(): array
    {
        return array_combine(self::ALL, array_map(fn ($m) => self::on($m), self::ALL));
    }

    /**
     * Save the switches. The budget envelope is the optional rule's envelope choice: switching it on records the
     * decisions (the plainer use; sharing them among the units is chosen in Optional rules), switching it off sets
     * the envelope to "not used". Performance cannot be on without execution.
     */
    public static function save(bool $execution, bool $performance, bool $budget): void
    {
        SystemSetting::set(self::KEY, [
            self::EXECUTION => $execution,
            self::PERFORMANCE => $performance && $execution,
        ], 'json', 'The add-on modules the administrator switched on');

        $options = PlanOptions::current();
        if ($budget !== $options->budgetDecisions) {
            $options->withEnvelope($budget ? 'record' : 'off')->save();
        }
    }

    /** The module a route belongs to, if any. */
    public static function forRoute(?string $route): ?string
    {
        foreach (self::ROUTES as $module => $routes) {
            if (in_array($route, $routes, true)) {
                return $module;
            }
        }

        return null;
    }

    /** The module a Livewire component belongs to, if any. */
    public static function forComponent(?string $name): ?string
    {
        foreach (self::COMPONENTS as $module => $names) {
            if (in_array($name, $names, true)) {
                return $module;
            }
        }

        return null;
    }

    /** @param array<string, bool>|null $states */
    public static function fake(?array $states): void
    {
        self::$fake = $states;
    }

    private static function stored(string $module): bool
    {
        $s = SystemSetting::get(self::KEY);

        return is_array($s) && (bool) ($s[$module] ?? false);
    }
}
