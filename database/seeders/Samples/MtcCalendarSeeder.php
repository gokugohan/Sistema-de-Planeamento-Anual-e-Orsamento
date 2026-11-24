<?php

namespace Database\Seeders\Samples;

use App\Models\BudgetDecision;
use App\Models\ReportingWindow;
use App\Services\BudgetDecisions;
use App\Services\SampleRegistry;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The reporting calendar of the sample ministry: for each year whose execution is sampled, every month may be reported from
 * its first day to the 10th of the month after, and every quarter's indicators from the quarter's first day to the 15th of
 * the month after it. Without dates the Report execution calendar would show every month as "no dates: open" next to
 * figures that were reported month by month. The dates are registered with the sample, so purging the sample removes them.
 * A plan on the legal calendar (submitted or approved) gets the legal dates: the plans in May and June of the year before,
 * the ceiling, the law. A plan loaded for practice (drafts or halfway) gets submission dates around today instead.
 * Approval and finalizing are left unset on purpose, so the demo accounts can decide freely.
 *
 * Run alone (`php artisan db:seed --class="Database\Seeders\Samples\MtcCalendarSeeder"`) it adds the dates for every year
 * that already has reported amounts.
 */
class MtcCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $registry = app(SampleRegistry::class);
        DB::table('budget_executions as be')->join('line_items as li', 'li.id', '=', 'be.line_item_id')->join('activities as a', 'a.id', '=', 'li.activity_id')
            ->distinct()->orderBy('a.fiscal_year')->pluck('a.fiscal_year')
            ->each(fn ($year) => self::seedYear((int) $year, $registry));
    }

    public static function seedYear(int $year, SampleRegistry $registry): void
    {
        foreach (range(1, 12) as $m) {
            $opens = Carbon::create($year, $m, 1);
            self::window($registry, $year, 'month', $m, $opens, $opens->copy()->addMonthNoOverflow()->day(10));
        }
        foreach (range(1, 4) as $q) {
            $opens = Carbon::create($year, ($q - 1) * 3 + 1, 1);
            self::window($registry, $year, 'quarter', $q, $opens, $opens->copy()->addMonthsNoOverflow(3)->day(15));
        }
    }

    /**
     * The legal calendar of a year's plan and budget envelope: the plans submitted in May and June of the year before,
     * the ceiling by 20 July, the State Budget law from the proposal (1 October) to the end of the year, a supplementary
     * budget during the year (App\Services\BudgetDecisions::legalDates).
     */
    public static function seedLegal(int $year, SampleRegistry $registry): void
    {
        $legal = BudgetDecisions::legalDates($year);
        self::window($registry, $year, 'plan', 1, Carbon::parse($legal['plan'][0]), Carbon::parse($legal['plan'][1]));
        foreach (BudgetDecision::ORDER as $stage) {
            self::window($registry, $year, 'budget', BudgetDecisions::calendarPeriod($stage), Carbon::parse($legal[$stage][0]), Carbon::parse($legal[$stage][1]));
        }
    }

    /** The submission dates of a plan loaded for practice: from a month ago to two months ahead. */
    public static function seedSubmission(int $year, SampleRegistry $registry): void
    {
        self::window($registry, $year, 'plan', 1, now()->subMonth()->startOfDay(), now()->addMonths(2)->startOfDay());
    }

    private static function window(SampleRegistry $registry, int $year, string $type, int $period, Carbon $opens, Carbon $closes): void
    {
        $registry->upsert('mtc', ReportingWindow::class, ['fiscal_year' => $year, 'period_type' => $type, 'period' => $period], [
            'opens_on' => $opens->toDateString(), 'closes_on' => $closes->toDateString(), 'note' => 'Sample calendar',
        ]);
    }
}
