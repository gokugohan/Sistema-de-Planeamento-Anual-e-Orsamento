<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\BudgetExecution;
use App\Models\Indicator;
use Illuminate\Support\Facades\DB;

/**
 * How one approved activity is doing: what was executed each month against its approved budget,
 * where each indicator stands against its target, and the latest narrative. Verified figures only; what is
 * still waiting for verification is counted, not added. Three small queries, whatever the size of the activity.
 */
class ActivityExecutionSummary
{
    /** @return array<string, mixed> */
    public function for(Activity $activity): array
    {
        $lineIds = DB::table('line_items as li')->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')->where('li.activity_id', $activity->id)->pluck('li.id');
        $approved = (float) DB::table('adjusted_budgets as ab')->join('line_items as li', 'li.id', '=', 'ab.line_item_id')->where('li.activity_id', $activity->id)->sum('ab.approved_amount');

        $entries = BudgetExecution::whereIn('line_item_id', $lineIds)->get(['month', 'executed', 'status']);
        $verified = $entries->where('status', BudgetExecution::VERIFIED)->groupBy('month');
        $months = [];
        $cumulative = 0.0;
        for ($m = 1; $m <= 12; $m++) {
            $rows = $verified[$m] ?? collect();
            $executed = (float) $rows->sum('executed');
            $cumulative += $executed;
            $months[$m] = ['executed' => $executed, 'cumulative' => $cumulative, 'reported' => $rows->isNotEmpty()];
        }
        $executedTotal = $cumulative;

        $indicators = Indicator::where('activity_id', $activity->id)->with('progress')->orderBy('id')->get()->map(function (Indicator $i) {
            $verified = $i->progress->where('status', 'Verified');
            $standing = $i->standing($verified->pluck('achieved_value', 'quarter'));

            return [
                'name' => $i->name, 'unit' => $i->unit, 'target' => (float) $i->annual_target, 'standing' => $standing,
                'pct' => $i->annual_target > 0 ? min(100, $standing / $i->annual_target * 100) : null,
                'quarters' => $verified->pluck('achieved_value', 'quarter')->all(),
            ];
        })->all();

        $notes = ActivityReport::where('activity_id', $activity->id)->where('status', 'Verified')
            ->orderByDesc('period_type')->orderByDesc('period')->limit(4)->get();

        return [
            'approved' => $approved,
            'executed' => $executedTotal,
            'rate' => $approved > 0 ? $executedTotal / $approved * 100 : null,
            'months' => $months,
            'indicators' => $indicators,
            'notes' => $notes,
            'waiting' => $entries->where('status', BudgetExecution::SUBMITTED)->count(),
        ];
    }
}
