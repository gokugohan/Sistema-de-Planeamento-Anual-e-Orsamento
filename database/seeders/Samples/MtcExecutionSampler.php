<?php

namespace Database\Seeders\Samples;

use App\Models\BudgetExecution;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Execution figures for the sample plans, aware of today's date: a year that is over was executed to
 * the end (verified, all twelve months), the running year has its finished months verified and the
 * current month part-way (some of it still waiting for verification), and a year still to come has none.
 * Spending is monthly; indicator progress is quarterly.
 *
 * Every activity has its own pace, so the reports show a spread: units on schedule, units ahead and
 * units behind. The figures are derived from the activity id, so the same plan always gets the same
 * execution. They are ILLUSTRATIVE; nothing here comes from a published execution report.
 */
class MtcExecutionSampler
{
    /** Quarter one starts slowly in practice; the end of the year is busier. */
    private const SEASON = [1 => 0.7, 2 => 0.95, 3 => 1.05, 4 => 1.15];

    /**
     * @param  array<int, int>  $activityIds  the sample activities of the year
     */
    public function seed(int $year, array $activityIds, ?CarbonInterface $today = null): void
    {
        $today ??= now();
        if ($year > $today->year || ! $activityIds) {
            return; // a year that has not started has nothing executed
        }

        $current = $year < $today->year ? 4 : (int) ceil($today->month / 3);
        $finished = $year < $today->year;
        $fractionOfCurrent = $finished ? 1.0 : $this->fractionOfQuarter($today, $current);
        $currentMonth = $finished ? 12 : $today->month;
        $fractionOfMonth = $finished ? 1.0 : max(0.05, $today->day / $today->daysInMonth);

        $lines = DB::table('line_items as li')
            ->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')
            ->whereIn('li.activity_id', $activityIds)
            ->select('li.id', 'li.activity_id', 'ab.approved_amount')
            ->get();

        $stamp = now();
        [$reporter, $verifier] = $this->people();
        $rows = [];
        foreach ($lines as $line) {
            $approved = (float) $line->approved_amount;
            if ($approved <= 0) {
                continue;
            }
            $pace = $this->pace($line->activity_id, $finished);
            $committed = $paid = 0.0;

            for ($m = 1; $m <= $currentMonth; $m++) {
                $q = (int) ceil($m / 3);
                $inProgress = ! $finished && $m === $currentMonth;
                $share = $inProgress ? $fractionOfMonth : 1.0;
                $jitter = 0.85 + (crc32("{$line->id}:{$m}") % 31) / 100; // 0.85 - 1.15

                $planned = $approved / 12;
                $paidM = min($planned * $pace * self::SEASON[$q] * $jitter * $share, $approved - $paid);
                $committedM = min($paidM * (1.1 + (crc32("c{$line->id}{$m}") % 15) / 100), $approved - $committed);
                $committedM = max($committedM, $paidM);

                $committed += $committedM;
                $paid += $paidM;

                // The running month is still being reported: two thirds of it is verified, the rest waits.
                $verified = ! $inProgress || $line->activity_id % 3 !== 0;
                $rows[] = [
                    'line_item_id' => $line->id, 'month' => $m, 'quarter' => $q,
                    'committed' => round($committedM, 2), 'paid' => round($paidM, 2),
                    'status' => $verified ? BudgetExecution::VERIFIED : BudgetExecution::SUBMITTED,
                    'submitted_by' => $reporter, 'verified_by' => $verified ? $verifier : null,
                    'verified_at' => $verified ? $stamp : null,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('budget_executions')->upsert($chunk, ['line_item_id', 'month'], ['quarter', 'committed', 'paid', 'status', 'submitted_by', 'verified_by', 'verified_at', 'updated_at']);
        }

        $this->physicalProgress($activityIds, $current, $fractionOfCurrent, $finished, $stamp);
    }

    /** A unit director who reports and the head of the office who verifies, when the sample has them (null otherwise). @return array{0: ?int, 1: ?int} */
    private function people(): array
    {
        return [
            DB::table('users')->where('email', 'like', 'director.%')->orderBy('id')->value('id'),
            DB::table('users')->where('email', 'like', 'chefe.%')->orderBy('id')->value('id'),
        ];
    }

    /** Verified progress against the annual targets, following the same pace as the money. */
    private function physicalProgress(array $activityIds, int $current, float $fraction, bool $finished, $stamp): void
    {
        [$reporter, $verifier] = $this->people();
        $indicators = DB::table('indicators')->whereIn('activity_id', $activityIds)->where('annual_target', '>', 0)->get(['id', 'activity_id', 'annual_target', 'measure']);
        $rows = [];
        foreach ($indicators as $i) {
            $pace = $this->pace($i->activity_id, $finished);
            $done = 0.0;
            for ($q = 1; $q <= $current; $q++) {
                $inProgress = ! $finished && $q === $current;
                if ($i->measure === 'level') {
                    // a level is where it stands at the end of the quarter, rising towards the target
                    $reached = ($q - 1 + ($inProgress ? $fraction : 1.0)) / 4 * $pace * (0.85 + self::SEASON[$q] * 0.15);
                    $value = min($i->annual_target * $reached, $i->annual_target * 1.0);
                    $done = $value;
                } else {
                    $share = (0.25 * $pace * self::SEASON[$q]) * ($inProgress ? $fraction : 1.0);
                    $value = min($i->annual_target * $share, $i->annual_target * 1.05 - $done);
                    $done += $value;
                }
                $verified = ! $inProgress || $i->activity_id % 3 !== 0;
                $rows[] = [
                    'indicator_id' => $i->id, 'quarter' => $q, 'achieved_value' => round(max($value, 0), 2),
                    'status' => $verified ? 'Verified' : 'Submitted', 'submitted_by' => $reporter, 'verified_by' => $verified ? $verifier : null,
                    'verified_at' => $verified ? $stamp : null,
                    'created_at' => $stamp, 'updated_at' => $stamp,
                ];
            }
        }
        // indicator_progress has no unique key on (indicator, quarter): clear what a previous run wrote first
        DB::table('indicator_progress')->whereIn('indicator_id', $indicators->pluck('id'))->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('indicator_progress')->insert($chunk);
        }
    }

    /** How fast this activity spends against its schedule: about 0.55 (well behind) to 1.15 (ahead). */
    private function pace(int $activityId, bool $finished): float
    {
        $pace = 0.55 + (crc32("pace{$activityId}") % 61) / 100;

        return $finished ? max($pace, 0.88) : $pace; // a closed year was executed almost fully
    }

    private function fractionOfQuarter(CarbonInterface $today, int $quarter): float
    {
        $start = $today->copy()->setDate($today->year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
        $end = $start->copy()->addMonthsNoOverflow(3);

        return max(0.05, min(1.0, $start->diffInSeconds($today->copy()->startOfDay()->addDay()) / max(1, $start->diffInSeconds($end))));
    }
}
