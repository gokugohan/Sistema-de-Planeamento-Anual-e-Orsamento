<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BudgetExecution;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The verified values a spending figure had before it was corrected. A figure is one row that is edited in place
 * (reopened, changed, verified again); the earlier values survive in the audit trail, and this reads them back so
 * that a reader does not have to dig through it.
 */
class ExecutionHistory
{
    /**
     * @return array<int, array{executed: float, verified_by: ?string, verified_at: Carbon}>
     *                                                                                                     every time the figure was verified with values that are not the current ones, oldest first
     */
    public function earlierVerified(BudgetExecution $entry): array
    {
        $state = [];
        $verified = [];
        $rows = AuditLog::where('auditable_type', $entry->getMorphClass())->where('auditable_id', $entry->getKey())->orderBy('id')->get();

        foreach ($rows as $row) {
            if ($row->action === 'budgetexecution.created') {
                $state = (array) $row->new_values;
            } elseif ($row->action === 'budgetexecution.updated') {
                $state = (array) $row->new_values + $state;
            } else {
                continue;
            }
            if (($state['status'] ?? null) === BudgetExecution::VERIFIED && (($row->new_values['status'] ?? null) === BudgetExecution::VERIFIED)) {
                $verified[] = ['executed' => (float) ($state['executed'] ?? 0), 'verified_by' => $state['verified_by'] ?? null, 'verified_at' => $row->created_at];
            }
        }

        // the last verification is the current figure when it is still verified: only what came before it is "earlier"
        if ($entry->status === BudgetExecution::VERIFIED && $verified !== []) {
            array_pop($verified);
        }

        $names = User::whereIn('id', collect($verified)->pluck('verified_by')->filter())->pluck('name', 'id');

        return array_map(fn ($v) => ['verified_by' => $names[$v['verified_by']] ?? null] + $v, $verified);
    }
}
