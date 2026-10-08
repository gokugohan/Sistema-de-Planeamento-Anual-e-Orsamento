<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\AuditLog;
use App\Models\FiscalEnvelope;
use App\Models\LineItem;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Distributes a received fiscal envelope over the APPROVED plan.
 *
 * Rules (Timor-Leste cycle: plan approved → OGE promulgated → envelope received →
 * internal distribution):
 *  - Only an Approved envelope can be distributed.
 *  - Only budget lines of activities with status Approved in that fiscal year take part.
 *  - Each economic category is distributed pro rata to the approved requests in it.
 *  - Nobody receives more than they planned: the ratio is capped at 1. Any surplus
 *    stays unallocated in the category.
 *  - Amounts are in cents; when a category is cut, rounding remainders go to the
 *    lines with the largest fractional part so the category total is exact.
 *  - Lines that are no longer eligible (activity no longer Approved, category not
 *    in the envelope) lose any previously distributed amount.
 *
 * preview() and distribute() use the same calculation, so what the user confirms
 * is exactly what gets written.
 */
class EnvelopeDistributor
{
    /** @return array<int, array> one entry per category allocation */
    public function preview(FiscalEnvelope $envelope): array
    {
        return $this->plan($envelope)['categories'];
    }

    /** @return array{categories: array, distributed: float, unallocated: float, lines: int, cleared: int} */
    public function distribute(FiscalEnvelope $envelope): array
    {
        if ($envelope->status !== FiscalEnvelope::STATUS_APPROVED) {
            throw new DomainException(__('Only an approved fiscal envelope can be distributed.'));
        }

        return DB::transaction(function () use ($envelope) {
            $plan = $this->plan($envelope);
            $eligibleIds = [];

            foreach ($plan['categories'] as $category) {
                foreach ($category['lines'] as $line) {
                    $eligibleIds[] = $line['line_item_id'];
                    AdjustedBudget::updateOrCreate(
                        ['line_item_id' => $line['line_item_id']],
                        ['approved_amount' => $line['new_approved'], 'status' => Activity::STATUS_APPROVED]
                    );
                }
            }

            // Clear stale amounts: lines in this year that are no longer eligible.
            $stale = AdjustedBudget::query()
                ->whereIn('line_item_id', LineItem::query()
                    ->whereHas('activity', fn ($q) => $q->where('fiscal_year', $envelope->fiscal_year))
                    ->select('id'))
                ->whereNotIn('line_item_id', $eligibleIds ?: [0])
                ->get();
            $stale->each->delete(); // model deletes: audited and lock-checked

            foreach ($envelope->categoryAllocations()->get() as $allocation) {
                $allocation->calculateVariance();
            }

            $summary = [
                'categories' => $plan['categories'],
                'distributed' => $plan['distributed'],
                'unallocated' => $plan['unallocated'],
                'lines' => count($eligibleIds),
                'cleared' => $stale->count(),
            ];

            AuditLog::create([
                'action' => 'envelope_distributed_proportional',
                'user_id' => auth()->id(),
                'description' => "Fiscal envelope {$envelope->fiscal_year} distributed over the approved plan",
                'metadata' => [
                    'fiscal_year' => $envelope->fiscal_year,
                    'total_amount' => (float) $envelope->total_amount,
                    'distributed' => $summary['distributed'],
                    'unallocated' => $summary['unallocated'],
                    'lines' => $summary['lines'],
                    'cleared' => $summary['cleared'],
                ],
            ]);

            return $summary;
        });
    }

    private function plan(FiscalEnvelope $envelope): array
    {
        $allocations = $envelope->categoryAllocations()->with('economicClassification')->get();

        $lines = LineItem::query()
            ->whereIn('economic_classification_id', $allocations->pluck('economic_classification_id'))
            ->whereHas('activity', fn ($q) => $q
                ->where('fiscal_year', $envelope->fiscal_year)
                ->where('status', Activity::STATUS_APPROVED))
            ->with(['activity:id,description,directorate_id', 'activity.directorate:id,code,name', 'adjustedBudget'])
            ->orderBy('id')
            ->get()
            ->groupBy('economic_classification_id');

        $categories = [];
        $distributed = 0;
        $unallocated = 0;

        foreach ($allocations as $allocation) {
            $class = $allocation->economicClassification;
            $items = $lines->get($allocation->economic_classification_id, collect());

            $allocatedCents = $this->cents($allocation->allocated_amount);
            $requestedCents = (int) $items->sum(fn ($i) => $this->cents($i->estimated_amount));
            $amounts = $this->share($items, $allocatedCents, $requestedCents);
            $givenCents = array_sum($amounts);

            $rows = [];
            foreach ($items as $item) {
                $current = (float) ($item->adjustedBudget->approved_amount ?? 0);
                $new = $amounts[$item->id] / 100.0;
                $rows[] = [
                    'line_item_id' => $item->id,
                    'activity_id' => $item->activity_id,
                    'activity_description' => $item->activity->description ?? '—',
                    'directorate' => $item->activity->directorate->code ?? '—',
                    'line_code' => $item->code,
                    'estimated' => (float) $item->estimated_amount,
                    'current_approved' => $current,
                    'new_approved' => $new,
                    'change' => round($new - $current, 2),
                ];
            }

            $categories[] = [
                'category_code' => $class->code ?? '',
                'category_name' => $class->name ?? '',
                'category_description' => $class->description ?? '',
                'allocated_amount' => $allocatedCents / 100.0,
                'total_requested' => $requestedCents / 100.0,
                'distributed' => $givenCents / 100.0,
                'unallocated' => ($allocatedCents - $givenCents) / 100.0,
                'ratio' => $requestedCents > 0 ? min(1, $allocatedCents / $requestedCents) : 0,
                'affected_count' => count($rows),
                'lines' => $rows,
            ];

            $distributed += $givenCents;
            $unallocated += $allocatedCents - $givenCents;
        }

        return ['categories' => $categories, 'distributed' => $distributed / 100.0, 'unallocated' => $unallocated / 100.0];
    }

    /**
     * Pro rata share in cents, capped at each line's request, with exact totals
     * via the largest-remainder method.
     *
     * @return array<int, int> line_item_id => cents
     */
    private function share($items, int $allocatedCents, int $requestedCents): array
    {
        $result = [];

        if ($requestedCents <= 0 || $allocatedCents <= 0) {
            foreach ($items as $item) {
                $result[$item->id] = 0;
            }

            return $result;
        }

        if ($allocatedCents >= $requestedCents) {
            foreach ($items as $item) {
                $result[$item->id] = $this->cents($item->estimated_amount); // fully funded, never more
            }

            return $result;
        }

        $fractions = [];
        foreach ($items as $item) {
            $exact = $this->cents($item->estimated_amount) * $allocatedCents / $requestedCents;
            $result[$item->id] = (int) floor($exact);
            $fractions[$item->id] = $exact - floor($exact);
        }

        $remainder = $allocatedCents - array_sum($result);
        arsort($fractions); // stable for equal fractions: earlier (lower id) first
        foreach (array_keys($fractions) as $id) {
            if ($remainder <= 0) {
                break;
            }
            $result[$id]++;
            $remainder--;
        }

        return $result;
    }

    private function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
