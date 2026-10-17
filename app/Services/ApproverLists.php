<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivitySignature;
use App\Models\ApproverAssignment;
use App\Models\Directorate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The named people for the steps of the approval chain. A step is governed by the list of the unit of the
 * activity or, if that unit has none, of the nearest unit above it that has one. Where no list applies the
 * default rule stays in force (the director acts), so a unit that never makes a list is not affected.
 * Administrators are never locked out by a list.
 *
 * Everything works from unit ids and plain queries (no lazy relations), so it is safe in any request.
 */
class ApproverLists
{
    /** @var array<string, Collection> */
    private array $cache = [];

    /** The unit (the one given or the nearest above it) whose list for $step applies, with its people in order. @return array{unit_id: int, people: Collection}|null */
    public function listFor(?int $unitId, string $step): ?array
    {
        $guard = 0;
        while ($unitId && $guard++ < 20) {
            $key = $unitId.'|'.$step;
            $this->cache[$key] ??= ApproverAssignment::where(['directorate_id' => $unitId, 'step' => $step])->with('user:id,name')->orderBy('position')->orderBy('id')->get();
            if ($this->cache[$key]->isNotEmpty()) {
                return ['unit_id' => $unitId, 'people' => $this->cache[$key]];
            }
            $unitId = Directorate::whereKey($unitId)->value('parent_id');
        }

        return null;
    }

    /** The list of the unit above this one (its parent, or higher), for the step done by the unit above. */
    public function listAbove(?int $unitId, string $step): ?array
    {
        return $unitId ? $this->listFor(Directorate::whereKey($unitId)->value('parent_id'), $step) : null;
    }

    /** null: no list applies (the default rule decides); true/false: is this user on the list that applies? */
    public function designated(User $user, ?int $unitId, string $step): ?bool
    {
        $list = $this->listFor($unitId, $step);

        return $list === null ? null : $this->onList($user, $list, $unitId, $step);
    }

    public function designatedAbove(User $user, ?int $unitId, string $step): ?bool
    {
        $list = $this->listAbove($unitId, $step);
        $parent = $unitId ? Directorate::whereKey($unitId)->value('parent_id') : null;

        return $list === null ? null : $this->onList($user, $list, $parent, $step);
    }

    /**
     * On the list, or standing in (an interim appointment) for the head of that unit, which counts for the steps
     * the head decides (certify and approve) but not for preparing: so the work does not stop while the head is away.
     */
    private function onList(User $user, array $list, ?int $unitId, string $step): bool
    {
        if ($list['people']->contains('user_id', $user->id)) {
            return true;
        }

        return $step !== ApproverAssignment::PREPARE && $unitId !== null && in_array($unitId, $user->interimUnitIds(), true);
    }

    /** The unit ids (with the units below them) of every list the user is on: what they may see and act on. @return array<int, int> */
    public function unitIdsOf(User $user): array
    {
        $own = ApproverAssignment::where('user_id', $user->id)->pluck('directorate_id');
        if ($own->isEmpty()) {
            return [];
        }

        return Directorate::whereIn('id', $own)->get()->flatMap(fn (Directorate $d) => $d->getSelfAndDescendantIds())->unique()->values()->all();
    }

    private function namesOf(?array $list): ?string
    {
        return $list ? $list['people']->map(fn ($a) => $a->user?->name)->filter()->implode(', ') : null;
    }

    /** In words, who is on the list that applies for this activity and step (null: the default rule). */
    public function names(Activity $activity, string $step): ?string
    {
        return $this->namesOf($this->listFor($activity->directorate_id, $step));
    }

    /** The names on the list that applies to this unit and step (null: no list). */
    public function namesFor(int $unitId, string $step): ?string
    {
        return $this->namesOf($this->listFor($unitId, $step));
    }

    public function namesAbove(Activity $activity, string $step): ?string
    {
        return $this->namesOf($this->listAbove($activity->directorate_id, $step));
    }

    /** Does this list ask everyone named to sign, in order? (A list of one is the same either way.) */
    public function sequential(?array $list): bool
    {
        return $list !== null && $list['people']->count() > 1 && $list['people']->contains('sequential', true);
    }

    /** The people of an in-order list who have not signed this activity in this stage yet, in order. @return Collection<int, ApproverAssignment> */
    public function unsigned(array $list, int $activityId, string $stage): Collection
    {
        $signed = ActivitySignature::where(['activity_id' => $activityId, 'stage' => $stage, 'state' => ActivitySignature::ACTIVE])->pluck('user_id');

        return $list['people']->reject(fn ($a) => $signed->contains($a->user_id))->values();
    }

    /** Whose signature is next: the first listed person who has not signed. */
    public function nextSigner(array $list, int $activityId, string $stage): ?ApproverAssignment
    {
        return $this->unsigned($list, $activityId, $stage)->first();
    }

    /** Is it this user's turn to sign: they are the next signer, or stand in as the interim of the unit of the list. */
    public function inTurn(User $user, array $list, int $activityId, string $stage): bool
    {
        $next = $this->nextSigner($list, $activityId, $stage);

        return $next !== null && ($next->user_id === $user->id || in_array($list['unit_id'], $user->interimUnitIds(), true));
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
