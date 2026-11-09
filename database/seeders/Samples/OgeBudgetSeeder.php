<?php

namespace Database\Seeders\Samples;

use App\Models\Activity;
use App\Services\SampleRegistry;

/**
 * The annual plan of one State Budget entity (OgeCatalog), loaded the way the ministry sample loads its own: the same
 * activities, indicators, budget lines, approval states, versions, reported spending and calendar (MtcBudgetSeeder).
 * The books give the 2027 plan only, so every year loaded takes it, with its dates moved to that year.
 */
class OgeBudgetSeeder extends MtcBudgetSeeder
{
    private string $entity = '';

    /** @param  array<int, int|string>|null  $years  the years to load (null: the plan's own year) */
    public function run(SampleRegistry $registry, ?array $units = null, bool $illustrative = false, string $state = 'approved', ?array $years = null, string $entity = ''): void
    {
        $this->entity = $entity;
        OgeCatalog::entity($entity); // fails early for an unknown entity

        parent::run($registry, null, false, $state, $years);
    }

    protected function planFor(int $year, ?array $years, ?array $units, bool $illustrative): array
    {
        return ['year' => OgeCatalog::YEAR, 'entities' => [$this->entity => OgeCatalog::entity($this->entity)], 'amendments' => []];
    }

    protected function yearsToLoad(?array $years): array
    {
        return self::years($years ?? [OgeCatalog::YEAR]);
    }

    protected function ownerUnit(string $entity, array $div): string
    {
        return OgeCatalog::ownerUnit($entity, $div);
    }

    protected function entityLabel(string $entity): string
    {
        return OgeCatalog::entity($entity)['abbr'];
    }

    /**
     * The plan prints the activities funded by a new Government Policy Priority with an "X" right after the
     * subprogramme code (e.g. 50202X1); Volume X names the priority, which is not read here.
     */
    protected function originFor(int $year, string $code): array
    {
        return preg_match('/^[0-9A-Z]{5}X\d/', $code)
            ? ['origin' => Activity::ORIGIN_POLICY, 'policy_ref' => 'PPG (OGE 2027, Vol. X)']
            : ['origin' => Activity::ORIGIN_BASELINE, 'policy_ref' => null];
    }
}
