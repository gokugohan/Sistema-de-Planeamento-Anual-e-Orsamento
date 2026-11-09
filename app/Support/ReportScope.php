<?php

namespace App\Support;

use App\Models\Directorate;
use App\Models\User;
use App\Services\PlanCycle;
use Illuminate\Support\Collection;

/**
 * Which part of the plan a report covers: the whole institution, or one unit with the units below it.
 *
 * Whoever sees the whole plan may choose any unit, or none (the whole institution). Everyone else sees the units the
 * plan cycle gives them (their unit and those below it, the units they head, the lists they are on: PlanCycle::scopeFor),
 * and their report is always narrowed to one of those, by default the highest. A unit outside what the user may see is
 * refused.
 */
final class ReportScope
{
    /**
     * @param  array<int, int>|null  $ids  the units the figures cover (the unit and those below it), null for every unit
     * @param  bool  $fixed  the user cannot see the whole institution, so the report is always one unit's
     */
    private function __construct(public readonly ?Directorate $unit, public readonly ?array $ids, public readonly bool $fixed) {}

    public static function for(User $user, mixed $requested = null): self
    {
        $allowed = app(PlanCycle::class)->scopeFor($user); // null: every unit
        $id = $requested !== null && $requested !== '' ? (int) $requested : null;

        if ($id !== null) {
            $unit = Directorate::find($id);
            abort_unless($unit && ($allowed === null || in_array($unit->id, $allowed, true)), 403);
        } elseif ($allowed === null) {
            return new self(null, null, false);
        } else {
            $unit = self::choicesWithin($allowed)->first();
            if (! $unit) {
                return new self(null, [], true); // a user who sees no unit: the reports open, with nothing in them
            }
        }

        return new self($unit, $unit->getSelfAndDescendantIds(), $allowed !== null);
    }

    /** The units the user may choose, as [unit, depth] in the order of the structure. @return Collection<int, array{unit: Directorate, depth: int}> */
    public static function choices(User $user): Collection
    {
        $allowed = app(PlanCycle::class)->scopeFor($user);
        $all = Directorate::orderBy('code')->get(['id', 'code', 'name', 'parent_id']);
        $children = $all->groupBy(fn ($d) => $d->parent_id ?? 0);
        $out = collect();
        $walk = function ($parentId, int $depth, array $path) use (&$walk, $children, $allowed, $out) {
            foreach ($children->get($parentId, collect()) as $d) {
                if (in_array($d->id, $path, true)) {
                    continue;
                }
                $visible = $allowed === null || in_array($d->id, $allowed, true);
                if ($visible) {
                    $out->push(['unit' => $d, 'depth' => $depth]);
                }
                $walk($d->id, $visible ? $depth + 1 : $depth, [...$path, $d->id]);
            }
        };
        $walk(0, 0, []);

        return $out;
    }

    /** The highest of the given units (one whose parent is not among them), in code order. */
    private static function choicesWithin(array $ids): Collection
    {
        $units = Directorate::whereIn('id', $ids)->orderBy('code')->get();

        return $units->filter(fn ($u) => ! $u->parent_id || ! in_array($u->parent_id, $ids, true))->values();
    }

    /** For a document's reference and file name: "-DNSMI", or nothing for the whole institution. */
    public function suffix(): string
    {
        return $this->unit ? '-'.preg_replace('/[^A-Za-z0-9-]/', '', (string) $this->unit->code) : '';
    }

    /** The parameter to keep in links (null for the whole institution). */
    public function param(): ?int
    {
        return $this->unit?->id;
    }
}
