<?php

namespace App\Services;

use App\Models\BudgetExecution;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Execution of the approved plan of one fiscal year: what was approved, what was planned to be spent by
 * each quarter (the approved amount spread over the quarters the activity runs in), and what was committed
 * and paid (verified entries only). The headline rate is paid / approved, the way budget execution is
 * reported; it is read against how much of the year has gone by.
 *
 * Two grouped queries feed everything (per activity: approved + quarters; per activity and quarter:
 * committed + paid), so the cost grows with the number of activities, not with budget lines or entries,
 * and nothing is loaded as a model.
 */
class ExecutionReport
{
    /**
     * @param  array<int, int>|null  $unitIds  limit to these units (already including the units below them)
     * @return array{year: int, today: CarbonInterface, quarter: int, month: int, fraction: float, activities: array<int, array>}
     */
    public function load(int $year, ?array $unitIds = null, ?int $classId = null, ?CarbonInterface $today = null, ?int $programId = null): array
    {
        $today ??= now();
        [$quarter, $fractionOfQuarter] = $this->position($year, $today);

        $scope = function ($q) use ($year, $unitIds, $classId, $programId) {
            $q->where('a.fiscal_year', $year);
            if ($programId) {
                $q->where('a.program_id', $programId);
            }
            if ($unitIds !== null) {
                $q->whereIn('a.directorate_id', $unitIds ?: [0]);
            }
            if ($classId) {
                $q->where('li.economic_classification_id', $classId);
            }
        };

        $planRows = DB::table('activities as a')
            ->join('line_items as li', 'li.activity_id', '=', 'a.id')
            ->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')
            ->tap($scope)
            ->groupBy('a.id', 'a.program_id', 'a.subprogram_id', 'a.directorate_id', 'a.description', 'a.execution_quarters')
            ->selectRaw('a.id, a.program_id, a.subprogram_id, a.directorate_id, a.description, a.execution_quarters, SUM(ab.approved_amount) AS approved')
            ->get();

        $activities = [];
        foreach ($planRows as $r) {
            $quarters = array_values(array_filter((array) json_decode((string) $r->execution_quarters, true), fn ($q) => in_array($q, [1, 2, 3, 4], true))) ?: [1, 2, 3, 4];
            $approved = (float) $r->approved;
            $planned = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
            foreach ($quarters as $q) {
                $planned[$q] = $approved / count($quarters);
            }
            $activities[$r->id] = [
                'id' => (int) $r->id, 'name' => $r->description, 'program_id' => (int) $r->program_id, 'subprogram_id' => (int) ($r->subprogram_id ?? 0), 'unit_id' => (int) $r->directorate_id,
                'approved' => $approved, 'planned' => $planned,
                'committed' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0], 'paid' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0],
                'committed_m' => array_fill(1, 12, 0.0), 'paid_m' => array_fill(1, 12, 0.0),
            ];
        }

        if ($activities) {
            DB::table('budget_executions as be')
                ->join('line_items as li', 'li.id', '=', 'be.line_item_id')
                ->join('activities as a', 'a.id', '=', 'li.activity_id')
                ->where('be.status', BudgetExecution::VERIFIED)
                ->tap($scope)
                ->groupBy('a.id', 'be.month')
                ->selectRaw('a.id, be.month, SUM(be.committed) AS committed, SUM(be.paid) AS paid')
                ->get()
                ->each(function ($r) use (&$activities) {
                    if (isset($activities[$r->id]) && $r->month >= 1 && $r->month <= 12) {
                        $m = (int) $r->month;
                        $q = (int) ceil($m / 3);
                        $activities[$r->id]['committed_m'][$m] = (float) $r->committed;
                        $activities[$r->id]['paid_m'][$m] = (float) $r->paid;
                        $activities[$r->id]['committed'][$q] += (float) $r->committed;
                        $activities[$r->id]['paid'][$q] += (float) $r->paid;
                    }
                });
        }

        $month = $today->year < $year ? 0 : ($today->year > $year ? 12 : $today->month);

        return ['year' => $year, 'today' => $today, 'quarter' => $quarter, 'month' => $month, 'fraction' => $fractionOfQuarter, 'activities' => $activities];
    }

    /**
     * Where the year stands: the quarter it is in (0 before the year, 4 after it) and how much of that
     * quarter has passed (1 for a quarter that is over).
     *
     * @return array{0: int, 1: float}
     */
    public function position(int $year, CarbonInterface $today): array
    {
        if ($today->year < $year) {
            return [0, 0.0];
        }
        if ($today->year > $year) {
            return [4, 1.0];
        }

        $quarter = (int) ceil($today->month / 3);
        $start = $today->copy()->setDate($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();
        $end = $start->copy()->addMonthsNoOverflow(3);

        return [$quarter, max(0.0, min(1.0, $start->diffInSeconds($today->copy()->startOfDay()->addDay()) / max(1, $start->diffInSeconds($end))))];
    }

    /**
     * Totals, and the cumulative planned / committed / paid at the end of each quarter (actual figures
     * only for quarters that have started).
     */
    public function summary(array $data): array
    {
        $approved = $committed = $paid = 0.0;
        $q = ['planned' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0], 'committed' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0], 'paid' => [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0]];
        $plannedToDate = 0.0;

        foreach ($data['activities'] as $a) {
            $approved += $a['approved'];
            $committed += array_sum($a['committed']);
            $paid += array_sum($a['paid']);
            $plannedToDate += $this->plannedToDate($a, $data);
            foreach ([1, 2, 3, 4] as $n) {
                $q['planned'][$n] += $a['planned'][$n];
                $q['committed'][$n] += $a['committed'][$n];
                $q['paid'][$n] += $a['paid'][$n];
            }
        }

        $quarters = [];
        $cum = ['planned' => 0.0, 'committed' => 0.0, 'paid' => 0.0];
        foreach ([1, 2, 3, 4] as $n) {
            foreach ($cum as $k => $v) {
                $cum[$k] += $q[$k][$n];
            }
            $started = $n <= $data['quarter'];
            $quarters[] = [
                'quarter' => $n,
                'planned' => $cum['planned'],
                'committed' => $started ? $cum['committed'] : null,
                'paid' => $started ? $cum['paid'] : null,
                'quarter_paid' => $started ? $q['paid'][$n] : null,
                'quarter_planned' => $q['planned'][$n],
            ];
        }

        $yearFraction = $data['quarter'] === 0 ? 0.0 : (($data['quarter'] - 1) + $data['fraction']) / 4;

        return [
            'approved' => $approved, 'committed' => $committed, 'paid' => $paid,
            'paid_rate' => $approved > 0 ? $paid / $approved * 100 : null,
            'committed_rate' => $approved > 0 ? $committed / $approved * 100 : null,
            'planned_to_date' => $plannedToDate,
            'planned_rate' => $approved > 0 ? $plannedToDate / $approved * 100 : null,
            'elapsed' => $yearFraction * 100,
            'quarter' => $data['quarter'],
            'quarters' => $quarters,
            'has_data' => $approved > 0,
            'has_actuals' => $committed > 0 || $paid > 0,
        ];
    }

    /**
     * Paid against approved, by programme or by unit.
     *
     * @param  'program_id'|'subprogram_id'|'unit_id'  $by
     * @return array<int, array{id: int, approved: float, paid: float, committed: float, planned_to_date: float}> keyed by id
     */
    public function grouped(array $data, string $by): array
    {
        $out = [];
        foreach ($data['activities'] as $a) {
            $g = &$out[$a[$by]];
            $g ??= ['id' => $a[$by], 'approved' => 0.0, 'paid' => 0.0, 'committed' => 0.0, 'planned_to_date' => 0.0];
            $g['approved'] += $a['approved'];
            $g['paid'] += array_sum($a['paid']);
            $g['committed'] += array_sum($a['committed']);
            $g['planned_to_date'] += $this->plannedToDate($a, $data);
            unset($g);
        }

        return $out;
    }

    /**
     * The activities furthest behind their own schedule: paid so far against what should have been paid
     * by now, largest shortfall first.
     *
     * @return array<int, array>
     */
    public function behind(array $data, int $limit = 5): array
    {
        $rows = [];
        foreach ($data['activities'] as $a) {
            $due = $this->plannedToDate($a, $data);
            $paid = array_sum($a['paid']);
            $gap = $due - $paid;
            if ($due > 0 && $gap > 0.5) {
                $rows[] = $a + ['due' => $due, 'paid_total' => $paid, 'gap' => $gap];
            }
        }
        usort($rows, fn ($x, $y) => $y['gap'] <=> $x['gap']);

        return array_slice($rows, 0, $limit);
    }

    /** What the activity should have paid by today: finished quarters in full, the current one pro rata. */
    public function plannedToDate(array $activity, array $data): float
    {
        $sum = 0.0;
        for ($n = 1; $n <= $data['quarter']; $n++) {
            $sum += $activity['planned'][$n] * ($n === $data['quarter'] ? $data['fraction'] : 1);
        }

        return $sum;
    }
}
