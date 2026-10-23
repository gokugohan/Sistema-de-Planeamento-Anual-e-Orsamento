<?php

namespace App\Services;

use App\Models\ApprovalDelegation;
use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\User;
use App\Support\StructureCache;

/**
 * The people of one unit, for its page: who belongs to it (with their role and the steps they are named for), who stands
 * in for its director today, and, when it has no director of its own, who directs it from above. Names and roles are for
 * everybody who may open the unit; e-mail addresses only for administrators and the director of the unit.
 */
class UnitPeople
{
    /** @return array<string, mixed> */
    public function for(Directorate $unit, User $viewer): array
    {
        $base = StructureCache::remember('unit-people:'.$unit->id, 300, fn () => $this->base($unit));
        $admin = $viewer->isAdministrator();

        return $base + [
            'show_email' => $admin || (bool) $viewer->seatFor($unit),
            'may_manage' => $admin,
            'may_lists' => $admin || (bool) $viewer->seatFor($unit),
        ];
    }

    /** What is true of the unit whoever looks at it (kept in the cache). @return array<string, mixed> */
    private function base(Directorate $unit): array
    {
        $members = User::where('directorate_id', $unit->id)->with('roles:id,name,display_name')->orderBy('name')->get(['id', 'name', 'email', 'directorate_id']);
        $named = ApproverAssignment::where('directorate_id', $unit->id)->get(['user_id', 'step'])->groupBy('user_id');

        $rows = $members->map(function (User $u) use ($named) {
            $role = $u->roles->first();

            return [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
                'role' => $role ? __($role->display_name ?: $role->name) : __('No Role'),
                'director' => $u->roles->contains('name', 'Director'),
                'steps' => $named->get($u->id, collect())->pluck('step')->unique()->values()->all(),
            ];
        })->sortByDesc('director')->values()->all();

        $standIn = ApprovalDelegation::active()->where('directorate_id', $unit->id)->with('user:id,name,email')->get()
            ->filter(fn ($d) => $d->user)->map(fn ($d) => ['name' => $d->user->name, 'email' => $d->user->email, 'until' => $d->ends_on])->values()->all();

        $hasDirector = (bool) collect($rows)->firstWhere('director', true);
        $above = null;
        if (! $hasDirector) {
            foreach (array_slice(app(ReportingWindows::class)->chain($unit->id), 1) as $ancestorId) {
                $heads = User::where('directorate_id', $ancestorId)->role('Director')->orderBy('name')->get(['id', 'name']);
                if ($heads->isNotEmpty()) {
                    $above = ['unit' => Directorate::find($ancestorId, ['id', 'code', 'name']), 'people' => $heads->pluck('name')->all()];
                    break;
                }
            }
        }

        return [
            'members' => $rows,
            'stand_in' => $standIn,
            'has_director' => $hasDirector,
            'above' => $above,
        ];
    }
}
