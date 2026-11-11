<?php

namespace App\Services;

use App\Models\Directorate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Branding;

/**
 * What the organization chart draws, for the page and its print: the units the person may see (UnitWorkspace), each
 * with its kind and its head, laid out as staff and line under the institution.
 *
 *  - the line: the directorates (general, national, regional), in the row below the institution, each with the units
 *    below it; every top-level unit is in the row when the institution has no directorate at the top;
 *  - the staff: the other top-level units (cabinets, support units, secretariats), in a block on the trunk;
 *  - the autonomous bodies (indirect administration), supervised, on a dashed line.
 */
class OrgChart
{
    /** The kinds of unit that carry the line of command; the others at the top are staff (advice and support). */
    public const LINE = ['General Directorate', 'Directorate General', 'National Directorate', 'Regional Office'];

    public function __construct(private UnitWorkspace $workspace) {}

    /** The same people as the Units page: administrators, whoever sees the whole plan, and heads (their own branch). */
    public static function allowed(User $user): bool
    {
        return $user->isAdministrator() || $user->can('view_all_paa') || $user->isDirector();
    }

    /** @return array<string, mixed> */
    public function build(User $user, int $year): array
    {
        $rows = collect($this->workspace->tree($user, $year));
        $units = Directorate::whereIn('id', $rows->pluck('id'))->get(['id', 'type', 'administration', 'legal_form', 'director_name'])->keyBy('id');

        // who heads each unit: the people with the Director role placed in it, else the name on the unit's record
        $heads = User::role('Director')->whereIn('directorate_id', $rows->pluck('id'))->whereNull('deactivated_at')
            ->orderBy('name')->get(['id', 'name', 'directorate_id'])->groupBy('directorate_id');

        $nodes = $rows->map(function ($r) use ($units, $heads) {
            $u = $units[$r['id']] ?? null;
            $head = ($heads[$r['id']] ?? collect())->pluck('name')->implode(', ') ?: (string) $u?->director_name;

            return $r + [
                'type' => $u?->type,
                'autonomous' => $u?->isAutonomous() ?? false,
                'legal_form' => $u?->legal_form,
                'title' => $u ? $u->leaderTitle() : __('Head'),
                'head' => $head,
                'initials' => $head !== '' ? collect(preg_split('/\s+/', trim(explode(',', $head)[0])))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('') : '',
                'total' => (int) ($r['counts']['total'] ?? 0),
            ];
        });

        // the top of what the person sees (a head sees their own unit as the top), then each unit's children
        $ids = $nodes->pluck('id')->all();
        $roots = $nodes->filter(fn ($n) => ! $n['parent_id'] || ! in_array($n['parent_id'], $ids, true))->values();
        $children = $nodes->filter(fn ($n) => $n['parent_id'] && in_array($n['parent_id'], $ids, true))->groupBy('parent_id');
        $direct = $roots->where('autonomous', false);
        $line = $direct->filter(fn ($n) => in_array($n['type'], self::LINE, true));

        return [
            'year' => $year,
            'organization' => SystemSetting::getLocalized('org_name', config('app.name')),
            'abbr' => SystemSetting::getLocalized('org_abbr', ''),
            'logo' => Branding::logoDataUri(),
            'staff' => $line->isEmpty() ? collect() : $direct->reject(fn ($n) => in_array($n['type'], self::LINE, true))->values(),
            'own' => $line->isEmpty() ? $direct->values() : $line->values(),
            'bodies' => $roots->where('autonomous', true)->values(),
            'children' => $children,
            'count' => $nodes->count(),
        ];
    }

    /** The view helpers of a box: the lights, the edge colour by kind of unit and the kind in words. */
    public static function helpers(): array
    {
        return [
            'light' => ['none' => ['bg-zinc-100 text-zinc-600', __('No activity yet')], 'ok' => ['bg-emerald-50 text-emerald-800', __('All approved')], 'work' => ['bg-amber-50 text-amber-800', __('Work in progress')], 'alert' => ['bg-rose-50 text-rose-800', __('Needs correction')]],
            'accent' => fn ($n) => $n['autonomous'] ? 'oc-a-body' : match ($n['type']) {
                'Cabinet' => 'oc-a-cab', 'General Directorate', 'Directorate General' => 'oc-a-dg', 'National Directorate' => 'oc-a-dn',
                'Department' => 'oc-a-dep', 'Regional Office' => 'oc-a-reg', default => 'oc-a-unit',
            },
            'typeWord' => fn ($n) => $n['autonomous'] ? __('Autonomous body').($n['legal_form'] ? ' · '.$n['legal_form'] : '') : ($n['type'] ? __($n['type']) : ''),
        ];
    }
}
