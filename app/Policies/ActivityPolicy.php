<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ApproverLists;
use App\Support\PlanOptions;

/**
 * Who may see and change an activity.
 *
 * Visibility follows the unit tree: a user sees their own unit and everything below it, unless
 * they hold `view_all_paa`. Content (fields, budget lines, indicators) can only change while the
 * activity is a draft or was returned, and never once the planning cycle is closed.
 */
class ActivityPolicy
{
    private const EDITABLE = [Activity::STATUS_DRAFT, Activity::STATUS_REJECTED];

    /** Is $unitId the user's unit or below it? */
    public function inScope(User $user, ?int $unitId): bool
    {
        if ($user->can('view_all_paa') || $user->isAdministrator()) {
            return true;
        }
        // where the institution has one finance function, its officers work on every unit (an optional rule)
        if (PlanOptions::current()->financeInstitutionWide && $user->hasRole('Finance')) {
            return true;
        }
        if (! $unitId) {
            return false;
        }
        // an interim appointment widens what the user sees to the unit they stand in for
        if ($user->seats()->isNotEmpty() && in_array($unitId, $user->directedUnitIds(), true)) {
            return true;
        }
        if ($user->directorate_id && in_array($unitId, Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? [], true)) {
            return true;
        }

        // named in the approval list of a unit: its activities, and those of the units below it, are theirs to see
        return in_array($unitId, app(ApproverLists::class)->unitIdsOf($user), true);
    }

    public function viewAny(User $user): bool
    {
        return $user->can('view_paa');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can('view_paa') && $this->inScope($user, $activity->directorate_id);
    }

    public function create(User $user): bool
    {
        return $user->can('create_activities') && ($user->directorate_id || $user->isAdministrator());
    }

    public function update(User $user, Activity $activity): bool
    {
        return $user->can('edit_activities')
            && $this->inScope($user, $activity->directorate_id)
            && in_array($activity->status, self::EDITABLE, true)
            && ! $activity->isLocked();
    }

    public function delete(User $user, Activity $activity): bool
    {
        return ($user->can('delete_activities') || $user->can('edit_activities'))
            && $activity->status === Activity::STATUS_DRAFT
            && (int) $activity->version === 0 // an activity that was approved once is part of the plan's record: it is revised, not deleted
            && $this->inScope($user, $activity->directorate_id)
            && ! $activity->isLocked();
    }
}
