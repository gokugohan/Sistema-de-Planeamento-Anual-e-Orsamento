<?php

namespace App\Services;

use App\Models\Directorate;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * The annual report of the plan for printing: planned and approved amounts by unit (rolled up the structure) and by
 * programme, subprogramme and economic category, with the activities and their indicators. The same figures as the
 * Reporting page (they come from PlanBreakdown), laid out as a document with a reference, who made it and when.
 */
class AnnualPlanReport
{
    public function __construct(private PlanBreakdown $breakdown, private PlanStatus $status) {}

    /** @return array<string, mixed> */
    public function build(int $year, User $user): array
    {
        $totals = $this->breakdown->unitTotals($year);
        $byParent = Directorate::query()->orderBy('code')->get(['id', 'parent_id', 'code', 'name'])->groupBy(fn ($d) => $d->parent_id ?? 0);

        $units = [];
        $walk = function ($unit, int $depth, array $path) use (&$walk, &$units, $totals, $byParent) {
            $t = $totals[$unit->id] ?? ['planned' => 0.0, 'approved' => 0.0];
            if ($t['planned'] <= 0 && $t['approved'] <= 0) {
                return;
            }
            $units[] = ['depth' => $depth, 'code' => (string) $unit->code, 'name' => (string) $unit->name] + $t
                + ['usage' => $t['planned'] > 0 ? $t['approved'] / $t['planned'] * 100 : 0.0];
            foreach ($byParent->get($unit->id, collect()) as $child) {
                if (! in_array($child->id, $path, true)) {
                    $walk($child, $depth + 1, [...$path, $unit->id]);
                }
            }
        };
        $planned = $approved = 0.0;
        foreach ($byParent->get(0, collect()) as $root) {
            $walk($root, 0, []);
            $planned += ($totals[$root->id]['planned'] ?? 0);
            $approved += ($totals[$root->id]['approved'] ?? 0);
        }

        return [
            'year' => $year,
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'units' => $units,
            'totals' => ['planned' => $planned, 'approved' => $approved, 'usage' => $planned > 0 ? $approved / $planned * 100 : 0.0],
            'programmes' => $this->breakdown->programmes($year, withActivities: true),
            'subprogrammes' => $this->breakdown->subprogrammes($year),
            'warning' => $this->status->reportWarning($user, $year),
            'reference' => sprintf('PAA-%d', $year),
            'generated_at' => now(), 'generated_by' => $user->name,
        ];
    }
}
