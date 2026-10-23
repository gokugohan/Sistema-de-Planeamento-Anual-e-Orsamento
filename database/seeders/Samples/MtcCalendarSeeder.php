<?php

namespace Database\Seeders\Samples;

use App\Models\ReportingWindow;
use App\Services\SampleRegistry;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The reporting calendar of the sample ministry: for each year whose execution is sampled, every month may be reported from
 * its first day to the 10th of the month after, and every quarter's indicators from the quarter's first day to the 15th of
 * the month after it. Without dates the Report execution calendar would show every month as "no dates: open" next to
 * figures that were reported month by month. The dates are registered with the sample, so purging the sample removes them.
 * Planning dates (submission, approval, finalizing) are left unset on purpose: the demo accounts can plan and approve freely.
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

    private static function window(SampleRegistry $registry, int $year, string $type, int $period, Carbon $opens, Carbon $closes): void
    {
        $registry->upsert('mtc', ReportingWindow::class, ['fiscal_year' => $year, 'period_type' => $type, 'period' => $period], [
            'opens_on' => $opens->toDateString(), 'closes_on' => $closes->toDateString(), 'note' => 'Sample calendar',
        ]);
    }
}
