<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\User;

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
        if (! $unitId) {
            return false;
        }
        // an interim appointment widens what the user sees to the unit they stand in for
        if ($user->seats()->isNotEmpty() && in_array($unitId, $user->directedUnitIds(), true)) {
            return true;
        }
        if (! $user->directorate_id) {
            return false;
        }

        return in_array($unitId, Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? [], true);
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
            && $this->inScope($user, $activity->directorate_id)
            && ! $activity->isLocked();
    }
}
